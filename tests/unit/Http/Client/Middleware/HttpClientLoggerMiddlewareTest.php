<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use OCA\AdminAuditHttpClient\Http\Client\Middleware\CountingStream;
use OCA\AdminAuditHttpClient\Http\Client\Middleware\HttpClientLoggerMiddleware;
use OCA\AdminAuditHttpClient\Http\Client\Middleware\TransferStatsStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HttpClientLoggerMiddlewareTest extends TestCase {
	private function middleware(
		int $logLevel = 0,
		array $excludeDomains = [],
		array $redactHeaders = [],
	): HttpClientLoggerMiddleware {
		return new HttpClientLoggerMiddleware(
			new NullLogger(),
			sys_get_temp_dir() . '/aahc-mw-unused',
			$logLevel,
			'both',
			$excludeDomains,
			$redactHeaders,
		);
	}

	private function invokePrivate(object $object, string $method, array $args): mixed {
		$ref = new \ReflectionMethod($object, $method);
		return $ref->invokeArgs($object, $args);
	}

	public function testShouldLogLevelZeroLogsEverything(): void {
		$mw = $this->middleware(0);
		foreach ([200, 301, 404, 500] as $status) {
			$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [$status]));
		}
	}

	public function testShouldLogLevelOneLogsClientAndServerErrors(): void {
		$mw = $this->middleware(1);
		$this->assertFalse($this->invokePrivate($mw, 'shouldLog', [200]));
		$this->assertFalse($this->invokePrivate($mw, 'shouldLog', [301]));
		$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [400]));
		$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [404]));
		$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [500]));
	}

	public function testShouldLogLevelTwoLogsServerErrorsOnly(): void {
		$mw = $this->middleware(2);
		$this->assertFalse($this->invokePrivate($mw, 'shouldLog', [200]));
		$this->assertFalse($this->invokePrivate($mw, 'shouldLog', [404]));
		$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [500]));
		$this->assertTrue($this->invokePrivate($mw, 'shouldLog', [503]));
	}

	public function testIsExcludedExactMatchIsCaseInsensitive(): void {
		$mw = $this->middleware(0, ['exact.test']);
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['exact.test']));
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['EXACT.TEST']));
		$this->assertFalse($this->invokePrivate($mw, 'isExcluded', ['other.test']));
	}

	public function testIsExcludedWildcardMatchesSubdomainsAndApex(): void {
		$mw = $this->middleware(0, ['*.example.com']);
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['sub.example.com']));
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['a.b.example.com']));
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['example.com']));
		$this->assertFalse($this->invokePrivate($mw, 'isExcluded', ['notexample.com']));
		$this->assertFalse($this->invokePrivate($mw, 'isExcluded', ['example.com.evil.org']));
	}

	public function testIsExcludedTrimsPatternsAndSkipsEmptyOnes(): void {
		$mw = $this->middleware(0, ['', '  spaced.test  ']);
		$this->assertTrue($this->invokePrivate($mw, 'isExcluded', ['spaced.test']));
		$this->assertFalse($this->invokePrivate($mw, 'isExcluded', ['anything.else']));
	}

	public function testStatsEntryIsClearedWhenResponseIsNotLogged(): void {
		$mw = $this->middleware(1);
		$reqId = 'req-leak-' . bin2hex(random_bytes(4));

		$handler = $mw(function (Request $request, array $options) {
			$response = new Response(200, [], 'streamed body');
			if (isset($options[RequestOptions::ON_STATS])) {
				$options[RequestOptions::ON_STATS](
					new TransferStats($request, $response, 0.1, null, ['size_download' => 4]),
				);
			}
			return new FulfilledPromise($response);
		});

		$response = $handler(
			new Request('GET', 'https://example.com/'),
			['nc_request_id' => $reqId],
		)->wait();

		$this->assertNotInstanceOf(CountingStream::class, $response->getBody());
		$this->assertNull(TransferStatsStore::get($reqId));
	}

	public function testWriteImmediateOmitsDecompressedBytesAndRatio(): void {
		$dir = sys_get_temp_dir() . '/aahc-wi-' . bin2hex(random_bytes(4));
		$mw = new HttpClientLoggerMiddleware(new NullLogger(), $dir);

		$meta = [
			'reqId' => 'req-wi-1',
			'time' => '2026-07-17T00:00:00+00:00',
			'method' => 'GET',
			'uri' => 'https://example.com/ping',
			'status' => 204,
			'http' => 'HTTP/2',
			'requestHeaders' => [],
			'responseHeaders' => [],
		];

		try {
			$this->invokePrivate($mw, 'writeImmediate', ['req-wi-1', $meta, [], ['size_download' => 10]]);

			$lines = file($dir . '/example.com.json', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$this->assertNotFalse($lines);
			$entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame(10, $entry['compressionStats']['compressed_bytes']);
			$this->assertNull($entry['compressionStats']['decompressed_bytes']);
			$this->assertNull($entry['compressionStats']['ratio']);
		} finally {
			foreach (glob($dir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
	}

	public function testNormalizeHeadersWrapsScalarsAndStringifiesValues(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['A' => ['x'], 'B' => ['1', '2']],
			$this->invokePrivate($mw, 'normalizeHeaders', [['A' => 'x', 'B' => [1, 2]]]),
		);
	}

	public function testCompactHeadersFlattensSingleValues(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['Accept' => 'text/html'],
			$this->invokePrivate($mw, 'compactHeaders', [['Accept' => ['text/html']]]),
		);
	}

	public function testCompactHeadersKeepsMultiValuesAsArray(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['X-Multi' => ['a', 'b']],
			$this->invokePrivate($mw, 'compactHeaders', [['X-Multi' => ['a', 'b']]]),
		);
	}

	public function testCompactHeadersNormalizesEtags(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['ETag' => 'abc'],
			$this->invokePrivate($mw, 'compactHeaders', [['ETag' => ['W/"abc"']]]),
		);
		$this->assertSame(
			['If-None-Match' => 'v1'],
			$this->invokePrivate($mw, 'compactHeaders', [['If-None-Match' => ['"v1"']]]),
		);
	}

	public function testCompactHeadersRedactsSensitiveDefaultsCaseInsensitive(): void {
		$mw = $this->middleware();
		$this->assertSame(
			[
				'Authorization' => '[redacted]',
				'Proxy-Authorization' => '[redacted]',
				'Cookie' => '[redacted]',
				'SET-COOKIE' => '[redacted]',
				'x-api-key' => '[redacted]',
				'X-Auth-Token' => '[redacted]',
				'Accept' => 'text/html',
			],
			$this->invokePrivate($mw, 'compactHeaders', [[
				'Authorization' => ['Bearer secret-token'],
				'Proxy-Authorization' => ['Basic abc'],
				'Cookie' => ['session=abc'],
				'SET-COOKIE' => ['a=1', 'b=2'],
				'x-api-key' => 'key123',
				'X-Auth-Token' => ['token'],
				'Accept' => ['text/html'],
			]]),
		);
	}

	public function testCompactHeadersRedactsConfiguredExtraHeaders(): void {
		$mw = $this->middleware(0, [], ['X-Secret', '  x-internal  ']);
		$this->assertSame(
			[
				'X-Secret' => '[redacted]',
				'X-Internal' => '[redacted]',
				'Authorization' => '[redacted]',
				'Accept' => 'text/html',
			],
			$this->invokePrivate($mw, 'compactHeaders', [[
				'X-Secret' => ['v'],
				'X-Internal' => ['w'],
				'Authorization' => ['Bearer secret'],
				'Accept' => ['text/html'],
			]]),
		);
	}

	public function testCompactHeadersCastsNumericLengths(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['Content-Length' => 123],
			$this->invokePrivate($mw, 'compactHeaders', [['Content-Length' => ['123']]]),
		);
		$this->assertSame(
			['Content-Length' => 'abc'],
			$this->invokePrivate($mw, 'compactHeaders', [['Content-Length' => ['abc']]]),
		);
	}
}
