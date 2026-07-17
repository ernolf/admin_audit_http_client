<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Integration;

use GuzzleHttp\HandlerStack;
use OCA\AdminAuditHttpClient\Http\Client\LoggingClientService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs against a booted Nextcloud server (see tests/bootstrap.php). Verifies
 * the reflection-based injection into the private Guzzle client of
 * \OC\Http\Client\Client — this breaks immediately when the server changes
 * that private structure.
 */
class MiddlewareInjectionTest extends TestCase {
	public function testMiddlewareIsInjectedIntoHandlerStack(): void {
		$server = \OC::$server;
		$service = new LoggingClientService(
			$server->get(IClientService::class),
			$server->get(LoggerInterface::class),
			$server->get(IConfig::class),
		);

		$client = $service->newClient();

		$ref = new \ReflectionProperty(\OC\Http\Client\Client::class, 'client');
		$guzzle = $ref->getValue($client);
		$this->assertInstanceOf(\GuzzleHttp\Client::class, $guzzle);

		$handler = $guzzle->getConfig('handler');
		$this->assertInstanceOf(HandlerStack::class, $handler);

		$stackRef = new \ReflectionProperty(HandlerStack::class, 'stack');
		$names = array_column($stackRef->getValue($handler), 1);
		$this->assertContains('admin_audit_http_client', $names);
	}

	public function testEnabledAppDecoratesClientService(): void {
		// The CI job enables the app before running phpunit, so boot() must
		// have re-registered IClientService with the decorating service.
		$this->assertInstanceOf(
			LoggingClientService::class,
			\OC::$server->get(IClientService::class),
		);
	}
}
