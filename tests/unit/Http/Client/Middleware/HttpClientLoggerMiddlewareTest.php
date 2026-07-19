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
		array $redactParams = [],
		array $redactPathPatterns = [],
		string $serverReqId = '',
	): HttpClientLoggerMiddleware {
		return new HttpClientLoggerMiddleware(
			new NullLogger(),
			sys_get_temp_dir() . '/aahc-mw-unused',
			$logLevel,
			'both',
			$excludeDomains,
			$redactHeaders,
			$redactParams,
			$redactPathPatterns,
			$serverReqId,
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

	public function testShouldLogClampsOutOfRangeLevels(): void {
		$tooHigh = $this->middleware(3);
		$this->assertFalse($this->invokePrivate($tooHigh, 'shouldLog', [404]));
		$this->assertTrue($this->invokePrivate($tooHigh, 'shouldLog', [500]));

		$tooLow = $this->middleware(-1);
		$this->assertTrue($this->invokePrivate($tooLow, 'shouldLog', [200]));
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
			new Request('GET', 'https://example.com/', ['X-Nextcloud-ReqId' => $reqId]),
			[],
		)->wait();

		$this->assertNotInstanceOf(CountingStream::class, $response->getBody());
		$this->assertNull(TransferStatsStore::get($reqId));
	}

	public function testGeneratedRequestIdCarriesServerRequestIdPrefix(): void {
		$mw = $this->middleware(2, [], [], [], [], 'server-req');
		$seen = null;

		$handler = $mw(function (Request $request, array $options) use (&$seen) {
			$seen = $request->getHeaderLine('X-Nextcloud-ReqId');
			return new FulfilledPromise(new Response(200, [], 'x'));
		});
		$handler(new Request('GET', 'https://example.com/'), [])->wait();

		$this->assertStringStartsWith('server-req-', (string)$seen);
	}

	public function testMixedCaseContentLengthZeroLogsImmediately(): void {
		$dir = sys_get_temp_dir() . '/aahc-cl-' . bin2hex(random_bytes(4));
		$mw = new HttpClientLoggerMiddleware(new NullLogger(), $dir);

		$handler = $mw(function (Request $request, array $options) {
			return new FulfilledPromise(new Response(200, ['Content-length' => '0'], ''));
		});

		try {
			$response = $handler(new Request('GET', 'https://example.com/ping'), [])->wait();

			$this->assertNotInstanceOf(CountingStream::class, $response->getBody());

			$lines = file($dir . '/example.com.json', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$this->assertNotFalse($lines);
			$entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame(200, $entry['status']);
			$this->assertNull($entry['compressionStats']['decompressed_bytes']);
		} finally {
			foreach (glob($dir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
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
			$this->invokePrivate($mw, 'writeImmediate', ['req-wi-1', $meta, ['size_download' => 10]]);

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
				'Authorization' => '***REMOVED SENSITIVE VALUE***',
				'Proxy-Authorization' => '***REMOVED SENSITIVE VALUE***',
				'Cookie' => '***REMOVED SENSITIVE VALUE***',
				'SET-COOKIE' => '***REMOVED SENSITIVE VALUE***',
				'x-api-key' => '***REMOVED SENSITIVE VALUE***',
				'X-Auth-Token' => '***REMOVED SENSITIVE VALUE***',
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
				'X-Secret' => '***REMOVED SENSITIVE VALUE***',
				'X-Internal' => '***REMOVED SENSITIVE VALUE***',
				'Authorization' => '***REMOVED SENSITIVE VALUE***',
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

	public function testRedactUriRedactsDefaultParamsCaseInsensitively(): void {
		$mw = $this->middleware();
		$this->assertSame(
			'https://api.example.com/v1/data?access_token=***REMOVED SENSITIVE VALUE***&page=2',
			$this->invokePrivate($mw, 'redactUri', ['https://api.example.com/v1/data?access_token=geheim&page=2']),
		);
		$this->assertSame(
			'https://api.example.com/v1/data?Access_Token=***REMOVED SENSITIVE VALUE***',
			$this->invokePrivate($mw, 'redactUri', ['https://api.example.com/v1/data?Access_Token=geheim']),
		);
		$this->assertSame(
			'https://maps.googleapis.com/maps/api/geocode/json?address=Berlin&key=***REMOVED SENSITIVE VALUE***',
			$this->invokePrivate($mw, 'redactUri', ['https://maps.googleapis.com/maps/api/geocode/json?address=Berlin&key=AIzaSyExample']),
		);
	}

	public function testRedactUriRedactsConfiguredExtraParams(): void {
		$mw = $this->middleware(0, [], [], ['session_key']);
		$this->assertSame(
			'https://x.test/p?session_key=***REMOVED SENSITIVE VALUE***&token=***REMOVED SENSITIVE VALUE***',
			$this->invokePrivate($mw, 'redactUri', ['https://x.test/p?session_key=abc&token=xyz']),
		);
	}

	public function testRedactUriLeavesValuelessParamsAndFragment(): void {
		$mw = $this->middleware();
		$this->assertSame(
			'https://x.test/p?token&flag=1#frag',
			$this->invokePrivate($mw, 'redactUri', ['https://x.test/p?token&flag=1#frag']),
		);
	}

	public function testRedactUriRedactsPrivatePathSegments(): void {
		$mw = $this->middleware();
		$this->assertSame(
			'https://calendar.google.com/calendar/ical/user%40googlemail.com/private-***REMOVED SENSITIVE VALUE***/basic.ics',
			$this->invokePrivate($mw, 'redactUri', [
				'https://calendar.google.com/calendar/ical/user%40googlemail.com/private-0123456789abcdef0123456789abcdef/basic.ics',
			]),
		);
	}

	public function testRedactUriAppliesConfiguredPathPatterns(): void {
		$mw = $this->middleware(0, [], [], [], ['#(?<=/)sess-[0-9a-f]+#']);
		$this->assertSame(
			'https://x.test/feed/***REMOVED SENSITIVE VALUE***/data.xml',
			$this->invokePrivate($mw, 'redactUri', ['https://x.test/feed/sess-abc123/data.xml']),
		);
	}

	public function testRedactUriSkipsInvalidPathPatterns(): void {
		$mw = $this->middleware(0, [], [], [], ['#[unclosed']);
		$this->assertSame(
			'https://x.test/feed/data.xml',
			$this->invokePrivate($mw, 'redactUri', ['https://x.test/feed/data.xml']),
		);
	}

	public function testRedactUriWithoutQueryIsUnchanged(): void {
		$mw = $this->middleware();
		$this->assertSame(
			'https://x.test/plain/path',
			$this->invokePrivate($mw, 'redactUri', ['https://x.test/plain/path']),
		);
	}

	public function testRedactHandlerStatsRedactsUrlAndRedirectUrl(): void {
		$mw = $this->middleware();
		$this->assertSame(
			[
				'url' => 'https://calendar.google.com/calendar/ical/user%40googlemail.com/private-***REMOVED SENSITIVE VALUE***/basic.ics',
				'redirect_url' => 'https://x.test/cb?token=***REMOVED SENSITIVE VALUE***',
				'http_code' => 200,
			],
			$this->invokePrivate($mw, 'redactHandlerStats', [[
				'url' => 'https://calendar.google.com/calendar/ical/user%40googlemail.com/private-0123456789abcdef0123456789abcdef/basic.ics',
				'redirect_url' => 'https://x.test/cb?token=geheim',
				'http_code' => 200,
			]]),
		);
	}

	public function testRedactHandlerStatsLeavesEmptyRedirectUrl(): void {
		$mw = $this->middleware();
		$this->assertSame(
			['url' => 'https://x.test/plain', 'redirect_url' => ''],
			$this->invokePrivate($mw, 'redactHandlerStats', [
				['url' => 'https://x.test/plain', 'redirect_url' => ''],
			]),
		);
	}

	public function testLoggedHandlerStatsUrlIsRedacted(): void {
		$dir = sys_get_temp_dir() . '/aahc-hs-' . bin2hex(random_bytes(4));
		$mw = new HttpClientLoggerMiddleware(new NullLogger(), $dir);

		$handler = $mw(function (Request $request, array $options) {
			$response = new Response(204);
			$options[RequestOptions::ON_STATS](
				new TransferStats($request, $response, 0.1, null, [
					'url' => (string)$request->getUri(),
					'http_code' => 204,
				]),
			);
			return new FulfilledPromise($response);
		});

		try {
			$handler(new Request('GET', 'https://example.com/hook?token=geheim'), [])->wait();

			$lines = file($dir . '/example.com.json', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$this->assertNotFalse($lines);
			$entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame(
				'https://example.com/hook?token=***REMOVED SENSITIVE VALUE***',
				$entry['handlerStats']['url'],
			);
		} finally {
			foreach (glob($dir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
	}

	public function testLoggedUriIsRedacted(): void {
		$dir = sys_get_temp_dir() . '/aahc-uri-' . bin2hex(random_bytes(4));
		$mw = new HttpClientLoggerMiddleware(new NullLogger(), $dir);

		$handler = $mw(function (Request $request, array $options) {
			return new FulfilledPromise(new Response(204));
		});

		try {
			$handler(new Request('GET', 'https://example.com/hook?token=geheim&id=7'), [])->wait();

			$lines = file($dir . '/example.com.json', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$this->assertNotFalse($lines);
			$entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame('https://example.com/hook?token=***REMOVED SENSITIVE VALUE***&id=7', $entry['uri']);
		} finally {
			foreach (glob($dir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
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
