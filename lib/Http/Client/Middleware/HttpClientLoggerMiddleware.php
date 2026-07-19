<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use OCP\IConfig;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class HttpClientLoggerMiddleware {
	/**
	 * Same placeholder the server itself uses when it strips sensitive
	 * values from config output.
	 */
	private const REDACTED = IConfig::SENSITIVE_VALUE;

	/**
	 * Header values that would leak credentials to disk; always replaced by
	 * IConfig::SENSITIVE_VALUE, with no opt-out.
	 */
	private const DEFAULT_REDACT_HEADERS = [
		'authorization',
		'proxy-authorization',
		'cookie',
		'set-cookie',
		'x-api-key',
		'x-auth-token',
	];

	/**
	 * Query parameter values that would leak credentials to disk when the URI
	 * is logged; always replaced by IConfig::SENSITIVE_VALUE, with no opt-out.
	 */
	private const DEFAULT_REDACT_PARAMS = [
		'access_token',
		'api_key',
		'apikey',
		'auth',
		'client_secret',
		'key',
		'password',
		'secret',
		'signature',
		'token',
		'x-amz-credential',
		'x-amz-security-token',
		'x-amz-signature',
	];

	/**
	 * PCRE patterns whose matches in the URI path are replaced by
	 * IConfig::SENSITIVE_VALUE.
	 * Google marks its secret iCal URLs with a "private-<hash>" path segment;
	 * anyone holding such a URL can read the calendar.
	 */
	private const DEFAULT_REDACT_PATH_PATTERNS = [
		'#(?<=/private-)[^/]+#',
	];

	private LoggerInterface $logger;
	private string $logBaseDir;
	private int $logLevel;
	private string $logFormat;
	private array $excludeDomains;
	private array $redactHeaders;
	private array $redactParams;
	private array $redactPathPatterns;
	private string $serverReqId;

	public function __construct(
		LoggerInterface $logger,
		string $logBaseDir,
		int $logLevel = 0,
		string $logFormat = 'both',
		array $excludeDomains = [],
		array $redactHeaders = [],
		array $redactParams = [],
		array $redactPathPatterns = [],
		string $serverReqId = '',
	) {
		$this->logger = $logger;
		$this->logBaseDir = rtrim($logBaseDir, '/');
		// Values outside the documented 0-2 range fall back to the nearest
		// valid level instead of silently behaving like "log everything".
		$this->logLevel = min(max($logLevel, 0), 2);
		$this->logFormat = $logFormat;
		$this->excludeDomains = $excludeDomains;
		$this->serverReqId = $serverReqId;

		$this->redactHeaders = $this->mergeRedactList(self::DEFAULT_REDACT_HEADERS, $redactHeaders);
		$this->redactParams = $this->mergeRedactList(self::DEFAULT_REDACT_PARAMS, $redactParams);

		// Patterns are regexes: merge verbatim, without case normalization.
		$this->redactPathPatterns = self::DEFAULT_REDACT_PATH_PATTERNS;
		foreach ($redactPathPatterns as $pattern) {
			$pattern = (string)$pattern;
			if ($pattern !== '' && !in_array($pattern, $this->redactPathPatterns, true)) {
				$this->redactPathPatterns[] = $pattern;
			}
		}
	}

	private function mergeRedactList(array $defaults, array $extra): array {
		$merged = $defaults;
		foreach ($extra as $name) {
			$name = strtolower(trim((string)$name));
			if ($name !== '' && !in_array($name, $merged, true)) {
				$merged[] = $name;
			}
		}
		return $merged;
	}

	public function __invoke(callable $handler): callable {
		return function (RequestInterface $request, array $options) use ($handler) {
			if (!empty($this->excludeDomains) && $this->isExcluded($request->getUri()->getHost())) {
				return $handler($request, $options);
			}

			// Request ID: reuse a caller-provided header, otherwise derive a
			// unique ID per outgoing request. Prefixing the server request ID
			// makes log entries correlatable with nextcloud.log while the
			// random suffix keeps concurrent outgoing requests apart.
			if ($request->hasHeader('X-Nextcloud-ReqId')) {
				$reqId = $request->getHeaderLine('X-Nextcloud-ReqId');
			} else {
				try {
					$suffix = bin2hex(random_bytes(4));
				} catch (\Throwable $e) {
					$suffix = uniqid('', true);
				}
				$reqId = $this->serverReqId === '' ? $suffix : $this->serverReqId . '-' . $suffix;
			}

			// Inject X-Nextcloud-ReqId header so remote servers can correlate
			if (!$request->hasHeader('X-Nextcloud-ReqId')) {
				$request = $request->withHeader('X-Nextcloud-ReqId', $reqId);
			}

			// Attach on_stats callback to capture cURL transfer stats
			if (!isset($options[RequestOptions::ON_STATS])) {
				$options[RequestOptions::ON_STATS] = function (TransferStats $stats) use ($reqId): void {
					TransferStatsStore::set($reqId, $this->redactHandlerStats($stats->getHandlerStats()));
				};
			}

			$reqHeaders = $this->normalizeHeaders($request->getHeaders());

			$promise = $handler($request, $options);

			return $promise->then(
				function (ResponseInterface $response) use ($request, $reqHeaders, $reqId) {
					try {
						$respHeaders = $this->normalizeHeaders($response->getHeaders());

						$handlerStats = TransferStatsStore::get($reqId);

						$meta = [
							'reqId' => $reqId,
							'time' => date('c'),
							'method' => $request->getMethod(),
							'uri' => $this->redactUri((string)$request->getUri()),
							'status' => $response->getStatusCode(),
							'http' => 'HTTP/' . $response->getProtocolVersion(),
							'requestHeaders' => $this->compactHeaders($reqHeaders),
							'responseHeaders' => $this->compactHeaders($respHeaders),
						];

						$intStatus = $meta['status'];

						// Determine whether to write immediately (no body expected)
						$immediate = false;

						// 1xx, 204, 304 — no body
						if (($intStatus >= 100 && $intStatus < 200) || $intStatus === 204 || $intStatus === 304) {
							$immediate = true;
						}

						// Content-Length: 0 — no body
						if (!$immediate) {
							$cl = null;
							foreach ($meta['responseHeaders'] as $k => $v) {
								if (strtolower((string)$k) === 'content-length') {
									$cl = is_array($v) ? ($v[0] ?? null) : $v;
									break;
								}
							}
							if ($cl !== null && is_numeric($cl) && (int)$cl === 0) {
								$immediate = true;
							}
						}

						// 4xx / 5xx — always log immediately
						if (!$immediate && $intStatus >= 400) {
							$immediate = true;
						}

						if ($immediate) {
							if ($this->shouldLog($intStatus)) {
								$this->writeImmediate($reqId, $meta, $handlerStats);
							}
							TransferStatsStore::clear($reqId);
						} else {
							$wrapped = false;
							if ($this->shouldLog($intStatus)) {
								try {
									$response = $response->withBody(
										new CountingStream($response->getBody(), $reqId, $meta, $this->logBaseDir, $this->logger, $this->logFormat)
									);
									$wrapped = true;
								} catch (\Throwable $e) {
									$this->logger->debug('HttpClientLoggerMiddleware: CountingStream wrap failed: ' . $e->getMessage());
								}
							}
							if (!$wrapped) {
								TransferStatsStore::clear($reqId);
							}
						}
					} catch (\Throwable $e) {
						$this->logger->debug('HttpClientLoggerMiddleware failed: ' . $e->getMessage());
					}

					return $response;
				},
				function ($reason) use ($request, $reqHeaders, $reqId) {
					try {
						$uri = $this->redactUri((string)$request->getUri());
						$reqCompact = $this->compactHeaders($reqHeaders);

						$entry = [
							'reqId' => $reqId,
							'time' => date('c'),
							'method' => $request->getMethod(),
							'uri' => $uri,
							'error' => is_object($reason) ? get_class($reason) : (string)$reason,
							'requestHeaders' => $reqCompact,
							'event' => 'error',
						];

						$plain = sprintf(
							"%s %s %s %s %s\n",
							$reqId,
							date('c'),
							$request->getMethod(),
							$uri,
							is_object($reason) ? get_class($reason) : (string)$reason
						);

						$metaForPaths = [
							'uri' => $uri,
							'requestHeaders' => $reqCompact,
						];
						[$jsonFile, $plainFile] = LogPathHelper::getPathsFromMeta($metaForPaths, $this->logBaseDir, $reqId);

						LogWriter::write($this->logFormat, $jsonFile, $entry, $plainFile, $plain, $this->logger);

						TransferStatsStore::clear($reqId);
					} catch (\Throwable $e) {
						$this->logger->debug('HttpClientLoggerMiddleware error-path failed: ' . $e->getMessage());
					}

					if ($reason instanceof \Throwable) {
						throw $reason;
					}
					throw new \RuntimeException(
						'HTTP request rejected: ' . (is_object($reason) ? get_class($reason) : (string)$reason)
					);
				}
			);
		};
	}

	private function writeImmediate(string $reqId, array $meta, ?array $handlerStats): void {
		try {
			$compressed = null;
			$encoding = 'none';

			if (is_array($handlerStats) && !empty($handlerStats['size_download'])) {
				$compressed = (int)round($handlerStats['size_download']);
			}

			$compact = $meta['responseHeaders'];

			foreach ($compact as $k => $v) {
				$lk = strtolower($k);
				if (in_array($lk, ['content-encoding', 'x-encoded-content-encoding', 'x-content-encoding'], true)) {
					$encoding = is_array($v) ? ($v[0] ?? 'none') : (string)$v;
					break;
				}
			}

			if ($compressed === null) {
				foreach ($compact as $k => $v) {
					$lk = strtolower($k);
					if (in_array($lk, ['x-encoded-content-length', 'x-compressed-length', 'content-length'], true)) {
						$val = is_array($v) ? ($v[0] ?? null) : $v;
						if ($val !== null && is_numeric($val)) {
							$compressed = (int)$val;
							break;
						}
					}
				}
			}

			// No body is consumed on the immediate path, so there is no
			// decompressed byte count and no meaningful ratio.
			$merged = [
				'reqId' => $reqId,
				'time' => $meta['time'],
				'method' => $meta['method'],
				'uri' => $meta['uri'],
				'status' => $meta['status'],
				'http' => $meta['http'],
				'requestHeaders' => $meta['requestHeaders'],
				'responseHeaders' => $meta['responseHeaders'],
				'handlerStats' => is_array($handlerStats) ? $handlerStats : [],
				'compressionStats' => [
					'encoding' => $encoding,
					'compressed_bytes' => $compressed,
					'decompressed_bytes' => null,
					'ratio' => null,
				],
			];

			$reqHdrs = $merged['requestHeaders'] ?? [];
			$headerNames = '-';
			if (!empty($reqHdrs) && is_array($reqHdrs)) {
				$headerNames = implode(',', array_keys($reqHdrs));
				if (strlen($headerNames) > 80) {
					$headerNames = substr($headerNames, 0, 77) . '…';
				}
			}
			$userAgent = '-';
			foreach (['User-Agent', 'user-agent'] as $uaKey) {
				if (isset($reqHdrs[$uaKey])) {
					$ua = $reqHdrs[$uaKey];
					$userAgent = is_array($ua) ? ($ua[0] ?? '-') : (string)$ua;
					break;
				}
			}

			$plain = sprintf(
				"%s %s %s %s %s %s compressed=%s decompressed=%s ratio=%s encoding=%s Hdrs=%s \"%s\"\n",
				$reqId,
				$meta['time'],
				$meta['method'],
				$meta['uri'],
				$meta['http'] ?? '-',
				$meta['status'] ?? '-',
				$compressed ?? '-',
				'-',
				'-',
				$encoding,
				$headerNames,
				$userAgent
			);

			[$jsonFile, $plainFile] = LogPathHelper::getPathsFromMeta($meta, $this->logBaseDir, $reqId);

			LogWriter::write($this->logFormat, $jsonFile, $merged, $plainFile, $plain, $this->logger);
		} catch (\Throwable $e) {
			$this->logger->debug('HttpClientLoggerMiddleware: writeImmediate failed: ' . $e->getMessage());
		}
	}

	private function shouldLog(int $status): bool {
		return match ($this->logLevel) {
			2 => $status >= 500,
			1 => $status >= 400,
			default => true,
		};
	}

	private function isExcluded(string $host): bool {
		$host = strtolower($host);
		foreach ($this->excludeDomains as $pattern) {
			$pattern = strtolower(trim((string)$pattern));
			if ($pattern === '') {
				continue;
			}
			if (str_starts_with($pattern, '*.')) {
				$suffix = substr($pattern, 1);
				if (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.')) {
					return true;
				}
			} elseif ($host === $pattern) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redacts credentials from a URI before it is logged: values of
	 * credential-bearing query parameters become IConfig::SENSITIVE_VALUE (names keep their
	 * original spelling, matching is case-insensitive, parameters without a
	 * value and the fragment are left untouched), and path portions matching
	 * the configured patterns are replaced likewise. Invalid patterns are
	 * skipped.
	 */
	private function redactUri(string $uri): string {
		$qPos = strpos($uri, '?');
		$base = $qPos === false ? $uri : substr($uri, 0, $qPos);

		foreach ($this->redactPathPatterns as $pattern) {
			$replaced = @preg_replace($pattern, self::REDACTED, $base);
			if (is_string($replaced)) {
				$base = $replaced;
			}
		}

		if ($qPos === false) {
			return $base;
		}

		$query = substr($uri, $qPos + 1);

		$fragment = '';
		$fPos = strpos($query, '#');
		if ($fPos !== false) {
			$fragment = substr($query, $fPos);
			$query = substr($query, 0, $fPos);
		}

		$pairs = explode('&', $query);
		foreach ($pairs as $i => $pair) {
			$eq = strpos($pair, '=');
			if ($eq === false) {
				continue;
			}
			$name = substr($pair, 0, $eq);
			if (in_array(strtolower(rawurldecode($name)), $this->redactParams, true)) {
				$pairs[$i] = $name . '=' . self::REDACTED;
			}
		}

		return $base . '?' . implode('&', $pairs) . $fragment;
	}

	/**
	 * curl's transfer stats repeat the request URI: "url" holds the effective
	 * URI after redirects, "redirect_url" a pending redirect target. Logged
	 * verbatim they would bypass redactUri(), so both are redacted before the
	 * stats reach the store.
	 */
	private function redactHandlerStats(array $handlerStats): array {
		foreach (['url', 'redirect_url'] as $key) {
			if (isset($handlerStats[$key]) && is_string($handlerStats[$key]) && $handlerStats[$key] !== '') {
				$handlerStats[$key] = $this->redactUri($handlerStats[$key]);
			}
		}
		return $handlerStats;
	}

	private function normalizeHeaders(array $hdrs): array {
		$out = [];
		foreach ($hdrs as $k => $v) {
			if (!is_array($v)) {
				$v = [$v];
			}
			$out[$k] = array_map('strval', $v);
		}
		return $out;
	}

	private function compactHeaders(array $hdrs): array {
		$out = [];
		foreach ($hdrs as $k => $v) {
			$lk = strtolower($k);

			if (in_array($lk, $this->redactHeaders, true)) {
				$out[$k] = self::REDACTED;
				continue;
			}

			if (!is_array($v)) {
				$v = [$v];
			}
			$vals = array_map('strval', array_values($v));

			if (count($vals) > 1) {
				$out[$k] = $vals;
				continue;
			}

			$val = $vals[0] ?? '';

			if (in_array($lk, ['etag', 'if-none-match'], true)) {
				$val = trim($val);
				$val = preg_replace('/^W\//', '', $val);
				$val = trim($val, "\"'");
			}

			if (in_array($lk, [
				'x-encoded-content-length', 'x-encoded-contentlength', 'x-compressed-length',
				'x-compressedlength', 'content-length', 'x-unencoded-content-length',
				'x-decompressed-content-length',
			], true)) {
				if (is_numeric($val)) {
					$val = (int)$val;
				}
			}

			$out[$k] = $val;
		}
		return $out;
	}
}
