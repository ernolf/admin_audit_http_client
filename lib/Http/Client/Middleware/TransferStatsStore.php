<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

/**
 * Process-local correlation store for cURL transfer stats. Guzzle invokes the
 * on_stats callback and the response handlers within the same PHP process, so
 * a static array is sufficient; entries are removed as soon as the request's
 * log entry has been written or logging is skipped.
 */
final class TransferStatsStore {
	/** @var array<string, array> */
	private static array $stats = [];

	public static function set(string $reqId, array $stats): void {
		self::$stats[$reqId] = $stats;
	}

	public static function get(string $reqId): ?array {
		return self::$stats[$reqId] ?? null;
	}

	public static function clear(string $reqId): void {
		unset(self::$stats[$reqId]);
	}
}
