<?php
/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

class TransferStatsStore {
	/** @var \OCP\IMemcache|null */
	private static $cache = null;

	private static array $fallback = [];

	private static function ensureCache(): void {
		if (self::$cache !== null) {
			return;
		}

		try {
			$server = \OC::$server;
			if ($server !== null && method_exists($server, 'getMemCacheFactory')) {
				$factory = $server->getMemCacheFactory();
				self::$cache = $factory->create('admin_audit_http_client');
			}
		} catch (\Throwable $e) {
			self::$cache = null;
		}
	}

	public static function set(string $reqId, array $stats): void {
		self::ensureCache();
		if (self::$cache !== null) {
			try {
				$val = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				self::$cache->set($reqId, $val, 0);
				return;
			} catch (\Throwable $e) {
				// fall through to fallback
			}
		}

		self::$fallback[$reqId] = $stats;
	}

	public static function get(string $reqId): ?array {
		self::ensureCache();
		if (self::$cache !== null) {
			try {
				$val = self::$cache->get($reqId);
				if ($val === null) {
					return null;
				}
				if (is_string($val)) {
					$decoded = json_decode($val, true);
					return is_array($decoded) ? $decoded : null;
				}
				if (is_array($val)) {
					return $val;
				}
				return null;
			} catch (\Throwable $e) {
				// fall through to fallback
			}
		}

		return self::$fallback[$reqId] ?? null;
	}

	public static function clear(string $reqId): void {
		self::ensureCache();
		if (self::$cache !== null) {
			try {
				self::$cache->delete($reqId);
				return;
			} catch (\Throwable $e) {
				// fall through to fallback
			}
		}
		unset(self::$fallback[$reqId]);
	}
}
