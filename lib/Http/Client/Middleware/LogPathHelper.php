<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client\Middleware;

class LogPathHelper {
	/**
	 * Derives per-host log file paths from request meta info.
	 *
	 * @param array $meta Minimal: 'uri' or 'requestHeaders' => ['Host' => 'host[:port]']
	 * @param string $baseDir base directory for per-host log files
	 * @param string|null $reqId unused, reserved for future use
	 * @return array [jsonPath, plainPath]
	 */
	public static function getPathsFromMeta(array $meta, string $baseDir, ?string $reqId = null): array {
		$baseDir = rtrim($baseDir, '/');

		$uri = $meta['uri'] ?? null;

		$host = null;
		$port = null;

		if ($uri) {
			$parts = parse_url($uri);
			if (!empty($parts['host'])) {
				$host = $parts['host'];
				$port = $parts['port'] ?? null;
			}
		}

		if ($host === null && !empty($meta['requestHeaders']['Host'])) {
			$hostHeader = $meta['requestHeaders']['Host'];
			$hostHeader = is_array($hostHeader) ? ($hostHeader[0] ?? '') : (string)$hostHeader;
			if ($hostHeader !== '') {
				if (strpos($hostHeader, ':') !== false) {
					[$host, $port] = explode(':', $hostHeader, 2) + [1 => null];
				} else {
					$host = $hostHeader;
				}
			}
		}

		if (empty($host)) {
			$host = 'unknown-host';
		}

		$host = preg_replace('/[^A-Za-z0-9._-]/', '_', $host);
		$portPart = '';
		if (!empty($port)) {
			$port = preg_replace('/[^0-9]/', '', (string)$port);
			if ($port !== '') {
				$portPart = '_' . $port;
			}
		}

		$token = $host . $portPart;

		if (!is_dir($baseDir)) {
			@mkdir($baseDir, 0755, true);
		}

		$jsonPath = $baseDir . '/' . $token . '.json';
		$plainPath = $baseDir . '/' . $token . '.log';

		return [$jsonPath, $plainPath];
	}
}
