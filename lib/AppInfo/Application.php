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
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('admin_audit_http_client');
	}

	public function register(IRegistrationContext $context): void {
		//		Prepared for future use — see HandlerStackReadyListener.php and
		//		https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513
		//		$context->registerEventListener(
		//			\OCA\AdminAuditHttpClient\Event\HttpClientHandlerStackReadyEvent::class,
		//			\OCA\AdminAuditHttpClient\Listener\HandlerStackReadyListener::class
		//		);
	}

	public function boot(IBootContext $context): void {
		$server = \OC::$server;
		$inner = $server->get(IClientService::class);
		$logger = $context->getAppContainer()->get(LoggerInterface::class);
		$config = $server->get(IConfig::class);

		$server->registerService(
			IClientService::class,
			fn () => new LoggingClientService($inner, $logger, $config)
		);
	}
}
