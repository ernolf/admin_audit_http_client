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
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class LoggingClientService implements IClientService {
	public function __construct(
		private IClientService $inner,
		private LoggerInterface $logger,
		private IConfig $config,
		private IRequest $request,
	) {
	}

	// NC 35 added an optional $handler override parameter to
	// IClientService::newClient(); the extra optional parameter is also a
	// valid implementation of the parameter-less interface on NC 32-34.
	public function newClient(?callable $handler = null): IClient {
		/** @psalm-suppress TooManyArguments the stable32 OCP stubs predate the handler parameter */
		$client = $handler === null
			? $this->inner->newClient()
			: $this->inner->newClient($handler);

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
						(int)$this->config->getSystemValue('audit_http_client_loglevel', 0),
						$this->config->getSystemValueString('audit_http_client_format', 'both'),
						(array)$this->config->getSystemValue('audit_http_client_exclude_domains', []),
						(array)$this->config->getSystemValue('audit_http_client_redact_headers', []),
						$this->request->getId(),
					),
					'admin_audit_http_client'
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'admin_audit_http_client: could not inject middleware: ' . $e->getMessage()
			);
		}

		return $client;
	}

	private function resolveLogDir(): string {
		$explicit = $this->config->getSystemValueString('audit_http_client_logdir', '');
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
