<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps a response body stream to count decompressed bytes and write the log
 * entry once the body has been fully consumed (close) or garbage collected
 * (__destruct fallback for streams that are never explicitly closed).
 */
class CountingStream implements StreamInterface {
	private int $bytesRead = 0;
	private bool $logged = false;

	public function __construct(
		private StreamInterface $inner,
		private string $reqId,
		private array $meta,
		private string $logBaseDir,
		private LoggerInterface $logger,
		private string $logFormat = 'both',
	) {
	}

	public function __destruct() {
		if (!$this->logged) {
			$this->writeLog(false);
		}
	}

	public function close(): void {
		if (!$this->logged) {
			$this->writeLog(true);
		}
		$this->inner->close();
	}

	public function detach(): mixed {
		if (!$this->logged) {
			$this->writeLog(false);
		}
		return $this->inner->detach();
	}

	public function read(int $length): string {
		$chunk = $this->inner->read($length);
		$this->bytesRead += strlen($chunk);
		return $chunk;
	}

	public function getContents(): string {
		$contents = $this->inner->getContents();
		$this->bytesRead += strlen($contents);
		return $contents;
	}

	public function __toString(): string {
		try {
			$contents = (string)$this->inner;
			$this->bytesRead += strlen($contents);
			return $contents;
		} catch (\Throwable) {
			return '';
		}
	}

	public function getSize(): ?int {
		return $this->inner->getSize();
	}

	public function tell(): int {
		return $this->inner->tell();
	}

	public function eof(): bool {
		return $this->inner->eof();
	}

	public function isSeekable(): bool {
		return $this->inner->isSeekable();
	}

	public function seek(int $offset, int $whence = SEEK_SET): void {
		$this->inner->seek($offset, $whence);
	}

	public function rewind(): void {
		$this->inner->rewind();
	}

	public function isWritable(): bool {
		return $this->inner->isWritable();
	}

	public function write(string $string): int {
		return $this->inner->write($string);
	}

	public function isReadable(): bool {
		return $this->inner->isReadable();
	}

	public function getMetadata(?string $key = null): mixed {
		return $this->inner->getMetadata($key);
	}

	private function writeLog(bool $complete): void {
		$this->logged = true;
		try {
			$handlerStats = null;
			try {
				$stored = TransferStatsStore::get($this->reqId);
				if (is_array($stored)) {
					$handlerStats = $stored['handlerStats'] ?? null;
				}
			} catch (\Throwable) {
				// best-effort
			}

			$compressed = null;
			$encoding = 'none';
			$ratio = null;

			if (is_array($handlerStats) && !empty($handlerStats['size_download'])) {
				$compressed = (int)round($handlerStats['size_download']);
			}

			$compact = $this->meta['responseHeaders'] ?? [];
			foreach ($compact as $k => $v) {
				if (in_array(strtolower((string)$k), ['content-encoding', 'x-encoded-content-encoding', 'x-content-encoding'], true)) {
					$encoding = is_array($v) ? ($v[0] ?? 'none') : (string)$v;
					break;
				}
			}

			$decompressed = $this->bytesRead;
			if ($compressed !== null && $decompressed > 0) {
				$ratio = round($compressed / $decompressed, 2);
			}

			$reqHdrs = $this->meta['requestHeaders'] ?? [];
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

			$merged = [
				'reqId' => $this->reqId,
				'time' => $this->meta['time'],
				'method' => $this->meta['method'],
				'uri' => $this->meta['uri'],
				'status' => $this->meta['status'],
				'http' => $this->meta['http'],
				'requestHeaders' => $this->meta['requestHeaders'],
				'responseHeaders' => $this->meta['responseHeaders'],
				'handlerStats' => is_array($handlerStats) ? $handlerStats : [],
				'compressionStats' => [
					'encoding' => $encoding,
					'compressed_bytes' => $compressed,
					'decompressed_bytes' => $decompressed,
					'ratio' => $ratio,
				],
				'stream_consumed' => $complete,
			];

			$incomplete = $complete ? '' : ' [stream-incomplete]';
			$plain = sprintf(
				"%s %s %s %s %s %s compressed=%s decompressed=%s ratio=%s encoding=%s Hdrs=%s \"%s\"%s\n",
				$this->reqId,
				$this->meta['time'],
				$this->meta['method'],
				$this->meta['uri'],
				$this->meta['http'] ?? '-',
				$this->meta['status'] ?? '-',
				$compressed ?? '-',
				$decompressed,
				$ratio ?? '-',
				$encoding,
				$headerNames,
				$userAgent,
				$incomplete
			);

			[$jsonFile, $plainFile] = LogPathHelper::getPathsFromMeta($this->meta, $this->logBaseDir, $this->reqId);

			if (in_array($this->logFormat, ['json', 'both'], true)) {
				@file_put_contents($jsonFile, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
			}
			if (in_array($this->logFormat, ['plain', 'both'], true)) {
				@file_put_contents($plainFile, $plain, FILE_APPEND | LOCK_EX);
			}

			try {
				TransferStatsStore::clear($this->reqId);
			} catch (\Throwable) {
			}
		} catch (\Throwable $e) {
			$this->logger->debug('CountingStream: writeLog failed: ' . $e->getMessage());
		}
	}
}
