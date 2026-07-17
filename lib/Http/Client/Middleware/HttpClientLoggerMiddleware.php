<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class HttpClientLoggerMiddleware {
	private LoggerInterface $logger;
	private string $logBaseDir;
	private int $logLevel;
	private string $logFormat;
	private array $excludeDomains;

	public function __construct(
		LoggerInterface $logger,
		string $logBaseDir,
		int $logLevel = 0,
		string $logFormat = 'both',
		array $excludeDomains = [],
	) {
		$this->logger = $logger;
		$this->logBaseDir = rtrim($logBaseDir, '/');
		$this->logLevel = $logLevel;
		$this->logFormat = $logFormat;
		$this->excludeDomains = $excludeDomains;
	}

	public function __invoke(callable $handler): callable {
		return function (RequestInterface $request, array $options) use ($handler) {
			if (!empty($this->excludeDomains) && $this->isExcluded($request->getUri()->getHost())) {
				return $handler($request, $options);
			}

			// Generate/extract request ID
			try {
				$reqId = $request->hasHeader('X-Nextcloud-ReqId')
					? $request->getHeaderLine('X-Nextcloud-ReqId')
					: (isset($options['nc_request_id']) ? (string)$options['nc_request_id'] : bin2hex(random_bytes(6)));
			} catch (\Throwable $e) {
				$reqId = isset($options['nc_request_id']) ? (string)$options['nc_request_id'] : uniqid('', true);
			}

			// Inject X-Nextcloud-ReqId header so remote servers can correlate
			if (!$request->hasHeader('X-Nextcloud-ReqId')) {
				$request = $request->withHeader('X-Nextcloud-ReqId', $reqId);
			}

			// Attach on_stats callback to capture cURL transfer stats
			if (!isset($options[RequestOptions::ON_STATS])) {
				$options[RequestOptions::ON_STATS] = function (TransferStats $stats) use ($reqId): void {
					try {
						TransferStatsStore::set($reqId, $stats->getHandlerStats());
					} catch (\Throwable $e) {
						// best-effort: never break request handling
					}
				};
			}

			$reqHeaders = $this->normalizeHeaders($request->getHeaders());

			$promise = $handler($request, $options);

			return $promise->then(
				function (ResponseInterface $response) use ($request, $reqHeaders, $reqId) {
					try {
						$respHeaders = $this->normalizeHeaders($response->getHeaders());

						$handlerStats = null;
						try {
							$stored = TransferStatsStore::get($reqId);
							if (is_array($stored)) {
								$handlerStats = $stored['handlerStats'] ?? $stored;
							}
						} catch (\Throwable $e) {
							$this->logger->debug('HttpClientLoggerMiddleware: TransferStatsStore access failed: ' . $e->getMessage());
						}

						$meta = [
							'reqId' => $reqId,
							'time' => date('c'),
							'method' => $request->getMethod(),
							'uri' => (string)$request->getUri(),
							'status' => $response->getStatusCode(),
							'http' => 'HTTP/' . $response->getProtocolVersion(),
							'requestHeaders' => $this->compactHeaders($reqHeaders),
							'responseHeaders' => $this->compactHeaders($respHeaders),
						];

						// Persist meta so on_stats data (arriving asynchronously) can be merged
						try {
							$stored = TransferStatsStore::get($reqId) ?? [];
							$stored['meta'] = $meta;
							if (is_array($handlerStats)) {
								$stored['handlerStats'] = $handlerStats;
							}
							TransferStatsStore::set($reqId, $stored);
						} catch (\Throwable $e) {
							$this->logger->debug('HttpClientLoggerMiddleware: failed to persist meta: ' . $e->getMessage());
						}

						$intStatus = $meta['status'];

						// Determine whether to write immediately (no body expected)
						$immediate = false;

						// 1xx, 204, 304 — no body
						if (($intStatus >= 100 && $intStatus < 200) || $intStatus === 204 || $intStatus === 304) {
							$immediate = true;
						}

						// Content-Length: 0 — no body
						if (!$immediate) {
							$compact = $this->compactHeaders($respHeaders);
							$cl = $compact['content-length'] ?? $compact['Content-Length'] ?? null;
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
								$this->writeImmediate($reqId, $meta, $respHeaders, $handlerStats);
							}
							try {
								TransferStatsStore::clear($reqId);
							} catch (\Throwable $e) {
							}
						} else {
							if ($this->shouldLog($intStatus)) {
								try {
									$response = $response->withBody(
										new CountingStream($response->getBody(), $reqId, $meta, $this->logBaseDir, $this->logger, $this->logFormat)
									);
								} catch (\Throwable $e) {
									$this->logger->debug('HttpClientLoggerMiddleware: CountingStream wrap failed: ' . $e->getMessage());
								}
							}
						}
					} catch (\Throwable $e) {
						$this->logger->debug('HttpClientLoggerMiddleware failed: ' . $e->getMessage());
					}

					return $response;
				},
				function ($reason) use ($request, $reqHeaders, $reqId) {
					try {
						$entry = [
							'reqId' => $reqId,
							'time' => date('c'),
							'method' => $request->getMethod(),
							'uri' => (string)$request->getUri(),
							'error' => is_object($reason) ? get_class($reason) : (string)$reason,
							'requestHeaders' => $this->compactHeaders($reqHeaders),
							'event' => 'error',
						];

						$plain = sprintf(
							"%s %s %s %s %s\n",
							$reqId,
							date('c'),
							$request->getMethod(),
							(string)$request->getUri(),
							is_object($reason) ? get_class($reason) : (string)$reason
						);

						$metaForPaths = [
							'uri' => (string)$request->getUri(),
							'requestHeaders' => $this->compactHeaders($reqHeaders),
						];
						[$jsonFile, $plainFile] = LogPathHelper::getPathsFromMeta($metaForPaths, $this->logBaseDir, $reqId);

						if (in_array($this->logFormat, ['json', 'both'], true)) {
							@file_put_contents($jsonFile, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
						}
						if (in_array($this->logFormat, ['plain', 'both'], true)) {
							@file_put_contents($plainFile, $plain, FILE_APPEND | LOCK_EX);
						}

						try {
							TransferStatsStore::clear($reqId);
						} catch (\Throwable $e) {
						}
					} catch (\Throwable $e) {
						$this->logger->debug('HttpClientLoggerMiddleware error-path failed: ' . $e->getMessage());
					}

					if ($reason instanceof \Throwable) {
						throw $reason;
					}
					throw new \RuntimeException('HTTP request rejected: ' . (string)$reason);
				}
			);
		};
	}

	private function writeImmediate(string $reqId, array $meta, array $respHeaders, ?array $handlerStats): void {
		try {
			$compressed = null;
			$decompressed = 0;
			$encoding = 'none';
			$ratio = null;

			if (is_array($handlerStats) && !empty($handlerStats['size_download'])) {
				$compressed = (int)round($handlerStats['size_download']);
			}

			$compact = $this->compactHeaders($respHeaders);

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

			$decompressed = $compressed ?? 0;
			if ($compressed !== null && $decompressed > 0) {
				$ratio = round($compressed / $decompressed, 2);
			}

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
					'decompressed_bytes' => $decompressed,
					'ratio' => $ratio,
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
				$decompressed,
				$ratio ?? '-',
				$encoding,
				$headerNames,
				$userAgent
			);

			[$jsonFile, $plainFile] = LogPathHelper::getPathsFromMeta($meta, $this->logBaseDir, $reqId);

			if (in_array($this->logFormat, ['json', 'both'], true)) {
				@file_put_contents($jsonFile, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
			}
			if (in_array($this->logFormat, ['plain', 'both'], true)) {
				@file_put_contents($plainFile, $plain, FILE_APPEND | LOCK_EX);
			}
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
			if (!is_array($v)) {
				$v = [$v];
			}
			$vals = array_map('strval', array_values($v));
			$lk = strtolower($k);

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
