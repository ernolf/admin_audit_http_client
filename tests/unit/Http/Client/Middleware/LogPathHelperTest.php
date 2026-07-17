<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\LogPathHelper;
use PHPUnit\Framework\TestCase;

class LogPathHelperTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = sys_get_temp_dir() . '/aahc-paths-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		foreach (glob($this->baseDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->baseDir);
		parent::tearDown();
	}

	public function testPathsFromUriWithPort(): void {
		[$json, $plain] = LogPathHelper::getPathsFromMeta(
			['uri' => 'https://example.com:8443/some/path'],
			$this->baseDir,
		);
		$this->assertSame($this->baseDir . '/example.com_8443.json', $json);
		$this->assertSame($this->baseDir . '/example.com_8443.log', $plain);
		$this->assertDirectoryExists($this->baseDir);
	}

	public function testPathsFromUriWithoutPort(): void {
		[$json, $plain] = LogPathHelper::getPathsFromMeta(
			['uri' => 'https://example.com/'],
			$this->baseDir,
		);
		$this->assertSame($this->baseDir . '/example.com.json', $json);
		$this->assertSame($this->baseDir . '/example.com.log', $plain);
	}

	public function testHostHeaderFallbackWithPort(): void {
		[$json] = LogPathHelper::getPathsFromMeta(
			['requestHeaders' => ['Host' => 'foo.bar:8080']],
			$this->baseDir,
		);
		$this->assertSame($this->baseDir . '/foo.bar_8080.json', $json);
	}

	public function testHostHeaderFallbackAcceptsArrayValues(): void {
		[$json] = LogPathHelper::getPathsFromMeta(
			['requestHeaders' => ['Host' => ['foo.bar']]],
			$this->baseDir,
		);
		$this->assertSame($this->baseDir . '/foo.bar.json', $json);
	}

	public function testMissingHostFallsBackToUnknownHost(): void {
		[$json, $plain] = LogPathHelper::getPathsFromMeta([], $this->baseDir);
		$this->assertSame($this->baseDir . '/unknown-host.json', $json);
		$this->assertSame($this->baseDir . '/unknown-host.log', $plain);
	}

	public function testHostIsSanitized(): void {
		[$json] = LogPathHelper::getPathsFromMeta(
			['requestHeaders' => ['Host' => 'my.host!']],
			$this->baseDir,
		);
		$this->assertSame($this->baseDir . '/my.host_.json', $json);
	}

	public function testTrailingSlashOnBaseDirIsTrimmed(): void {
		[$json] = LogPathHelper::getPathsFromMeta(
			['uri' => 'https://example.com/'],
			$this->baseDir . '/',
		);
		$this->assertSame($this->baseDir . '/example.com.json', $json);
	}
}
