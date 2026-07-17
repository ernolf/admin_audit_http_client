<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Http\Client;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\HttpClientLoggerMiddleware;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class LoggingClientService implements IClientService {
	public function __construct(
		private IClientService $inner,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
	}

	public function newClient(): IClient {
		$client = $this->inner->newClient();

		try {
			$ref = new \ReflectionProperty(\OC\Http\Client\Client::class, 'client');
			$ref->setAccessible(true);
			/** @var \GuzzleHttp\Client $guzzle */
			$guzzle = $ref->getValue($client);

			$handler = $guzzle->getConfig('handler');
			if ($handler instanceof \GuzzleHttp\HandlerStack) {
				$handler->unshift(
					new HttpClientLoggerMiddleware(
						$this->logger,
						$this->resolveLogDir(),
						(int)$this->config->getSystemValue('loglevel_audit_http_client', 0),
						$this->config->getSystemValueString('audit_http_client_logs', 'both'),
						(array)$this->config->getSystemValue('audit_http_client_logs_exclude_domain', []),
					),
					'admin_audit_http_client'
				);
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'admin_audit_http_client: could not inject middleware: ' . $e->getMessage()
			);
		}

		return $client;
	}

	private function resolveLogDir(): string {
		$explicit = $this->config->getSystemValueString('logdir_audit_http_client', '');
		if ($explicit !== '') {
			return rtrim($explicit, '/');
		}

		$dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'), '/');

		$auditLog = $this->config->getSystemValueString('logfile_audit', '');
		if ($auditLog !== '') {
			$dir = rtrim(dirname($auditLog), '/');
			return $dir === $dataDir ? $dataDir . '/admin_audit_http_client_logs' : $dir . '/client';
		}

		$logFile = $this->config->getSystemValueString('logfile', '');
		if ($logFile !== '') {
			$dir = rtrim(dirname($logFile), '/');
			return $dir === $dataDir ? $dataDir . '/admin_audit_http_client_logs' : $dir . '/client';
		}

		return $dataDir . '/admin_audit_http_client_logs';
	}
}
