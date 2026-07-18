<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

use Psr\Log\LoggerInterface;

/**
 * Single write path for all log sinks. Appends with an exclusive lock and
 * reports the first failed write per process as a warning — without that, a
 * full or unwritable log directory would go entirely unnoticed.
 */
class LogWriter {
	private static bool $failureLogged = false;

	public static function write(
		string $format,
		string $jsonFile,
		array $entry,
		string $plainFile,
		string $plainLine,
		LoggerInterface $logger,
	): void {
		if (in_array($format, ['json', 'both'], true)) {
			self::append($jsonFile, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, $logger);
		}
		if (in_array($format, ['plain', 'both'], true)) {
			self::append($plainFile, $plainLine, $logger);
		}
	}

	private static function append(string $file, string $line, LoggerInterface $logger): void {
		if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) {
			return;
		}
		if (!self::$failureLogged) {
			self::$failureLogged = true;
			$logger->warning(
				'admin_audit_http_client: could not write to ' . $file . ' - further write failures are not reported'
			);
		}
	}
}
