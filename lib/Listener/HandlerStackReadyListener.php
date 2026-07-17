<?php

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AdminAuditHttpClient\Listener;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\HttpClientLoggerMiddleware;
use Psr\Log\LoggerInterface;

/**
 * Prepared for future use — currently inactive.
 *
 * The app injects its middleware via PHP Reflection on LoggingClientService (see
 * Application::boot()). This listener is the cleaner alternative: it activates once
 * Nextcloud core dispatches HttpClientHandlerStackReadyEvent from ClientService::newClient().
 *
 * Migration steps when the core event becomes available:
 *   1. Add one dispatch() call to ClientService::newClient() in Nextcloud core
 *   2. Remove the registerService() override in Application::boot()
 *   3. Register this listener in Application::register()
 *   4. Uncomment the handle() body below
 *   HttpClientLoggerMiddleware is not touched.
 *
 * See architecture & roadmap: https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513
 */
class HandlerStackReadyListener {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function handle(object $event): void {
		//		$event->getStack()->unshift(
		//			new HttpClientLoggerMiddleware($this->logger),
		//			'admin_audit_http_client'
		//		);
	}
}
