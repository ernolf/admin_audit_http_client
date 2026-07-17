<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\AppInfo;

use OCA\AdminAuditHttpClient\Http\Client\LoggingClientService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('admin_audit_http_client');
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();
		$inner = $server->get(IClientService::class);
		$logger = $context->getAppContainer()->get(LoggerInterface::class);
		$config = $server->get(IConfig::class);
		$request = $server->get(IRequest::class);

		/**
		 * registerService on the server container is the only way to replace
		 * a server-wide service. OCP <= 34 marks it deprecated; OCP 35 narrows
		 * getServerContainer() to ContainerInterface, hiding it statically.
		 *
		 * @psalm-suppress UndefinedInterfaceMethod
		 * @psalm-suppress DeprecatedMethod
		 */
		$server->registerService(
			IClientService::class,
			fn () => new LoggingClientService($inner, $logger, $config, $request)
		);
	}
}
