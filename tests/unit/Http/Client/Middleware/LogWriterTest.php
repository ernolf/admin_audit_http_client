<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\LogWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LogWriterTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = sys_get_temp_dir() . '/aahc-writer-' . bin2hex(random_bytes(4));
		mkdir($this->baseDir);
	}

	protected function tearDown(): void {
		foreach (glob($this->baseDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->baseDir);
		parent::tearDown();
	}

	public function testWritesJsonAndPlainForFormatBoth(): void {
		$json = $this->baseDir . '/host.json';
		$plain = $this->baseDir . '/host.log';

		LogWriter::write('both', $json, ['a' => 1], $plain, "plain line\n", new NullLogger());

		$this->assertSame('{"a":1}' . PHP_EOL, file_get_contents($json));
		$this->assertSame("plain line\n", file_get_contents($plain));
	}

	public function testRespectsFormatSelection(): void {
		$json = $this->baseDir . '/host.json';
		$plain = $this->baseDir . '/host.log';

		LogWriter::write('json', $json, ['a' => 1], $plain, "plain line\n", new NullLogger());

		$this->assertFileExists($json);
		$this->assertFileDoesNotExist($plain);
	}

	public function testWarnsExactlyOnceOnWriteFailure(): void {
		$blocker = $this->baseDir . '/blocker';
		touch($blocker);

		$logger = $this->createMock(LoggerInterface::class);
		// Both appends fail (paths below a regular file); once() proves the
		// second failure is suppressed.
		$logger->expects($this->once())->method('warning');

		LogWriter::write('both', $blocker . '/x.json', ['a' => 1], $blocker . '/x.log', "line\n", $logger);
	}
}
