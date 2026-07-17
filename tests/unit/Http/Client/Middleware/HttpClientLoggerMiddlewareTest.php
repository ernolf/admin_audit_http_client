<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\HttpClientLoggerMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HttpClientLoggerMiddlewareTest extends TestCase {
	private function middleware(int $logLevel = 0, array $excludeDomains = []): HttpClientLoggerMiddleware {
		return new HttpClientLoggerMiddleware(
			new NullLogger(),
			sys_get_temp_dir() . '/aahc-mw-unused',
			$logLevel,
			'both',
			$excludeDomains,
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
