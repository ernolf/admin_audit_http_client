<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use GuzzleHttp\Psr7\Utils;
use OCA\AdminAuditHttpClient\Http\Client\Middleware\CountingStream;
use OCA\AdminAuditHttpClient\Http\Client\Middleware\TransferStatsStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CountingStreamTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = sys_get_temp_dir() . '/aahc-stream-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		foreach (glob($this->baseDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->baseDir);
		parent::tearDown();
	}

	private function meta(): array {
		return [
			'uri' => 'https://example.com/data',
			'time' => '2026-07-17T00:00:00+00:00',
			'method' => 'GET',
			'status' => 200,
			'http' => 'HTTP/2',
			'requestHeaders' => ['User-Agent' => 'test-agent'],
			'responseHeaders' => [],
		];
	}

	private function stream(string $body, string $reqId, string $logFormat = 'both'): CountingStream {
		return new CountingStream(
			Utils::streamFor($body),
			$reqId,
			$this->meta(),
			$this->baseDir,
			new NullLogger(),
			$logFormat,
		);
	}

	private function readJsonLines(): array {
		$lines = file($this->baseDir . '/example.com.json', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertNotFalse($lines);
		return array_map(
			fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
			$lines,
		);
	}

	public function testCountsConsumedBytesAndLogsOnClose(): void {
		$reqId = uniqid('req', true);
		$stream = $this->stream('hello world', $reqId);

		$this->assertSame('hello world', $stream->getContents());
		$stream->close();

		$entries = $this->readJsonLines();
		$this->assertCount(1, $entries);
		$this->assertSame(11, $entries[0]['compressionStats']['decompressed_bytes']);
		$this->assertTrue($entries[0]['stream_consumed']);
		$this->assertSame($reqId, $entries[0]['reqId']);

		$plain = file_get_contents($this->baseDir . '/example.com.log');
		$this->assertNotFalse($plain);
		$this->assertStringNotContainsString('[stream-incomplete]', $plain);
	}

	public function testDestructLogsIncompleteStream(): void {
		$reqId = uniqid('req', true);
		$stream = $this->stream('hello world', $reqId);

		$this->assertSame('hello', $stream->read(5));
		unset($stream);

		$entries = $this->readJsonLines();
		$this->assertCount(1, $entries);
		$this->assertSame(5, $entries[0]['compressionStats']['decompressed_bytes']);
		$this->assertFalse($entries[0]['stream_consumed']);

		$plain = file_get_contents($this->baseDir . '/example.com.log');
		$this->assertNotFalse($plain);
		$this->assertStringContainsString('[stream-incomplete]', $plain);
	}

	public function testToStringAfterPartialReadCountsBodyOnce(): void {
		$reqId = uniqid('req', true);
		$stream = $this->stream('hello world', $reqId);

		$this->assertSame('hello', $stream->read(5));
		$this->assertSame('hello world', (string)$stream);
		$stream->close();

		$entries = $this->readJsonLines();
		$this->assertSame(11, $entries[0]['compressionStats']['decompressed_bytes']);
	}

	public function testCloseTwiceWritesOnlyOneEntry(): void {
		$reqId = uniqid('req', true);
		$stream = $this->stream('abc', $reqId);

		$stream->getContents();
		$stream->close();
		$stream->close();

		$this->assertCount(1, $this->readJsonLines());
	}

	public function testMergesHandlerStatsFromStoreAndClearsIt(): void {
		$reqId = uniqid('req', true);
		TransferStatsStore::set($reqId, ['size_download' => 5]);

		$stream = $this->stream('hello', $reqId);
		$stream->getContents();
		$stream->close();

		$entries = $this->readJsonLines();
		$this->assertSame(5, $entries[0]['compressionStats']['compressed_bytes']);
		$this->assertSame(5, $entries[0]['compressionStats']['decompressed_bytes']);
		$this->assertEquals(1.0, $entries[0]['compressionStats']['ratio']);
		$this->assertSame(['size_download' => 5], $entries[0]['handlerStats']);

		$this->assertNull(TransferStatsStore::get($reqId));
	}

	public function testJsonOnlyFormatWritesNoPlainLog(): void {
		$reqId = uniqid('req', true);
		$stream = $this->stream('abc', $reqId, 'json');

		$stream->getContents();
		$stream->close();

		$this->assertFileExists($this->baseDir . '/example.com.json');
		$this->assertFileDoesNotExist($this->baseDir . '/example.com.log');
	}
}
