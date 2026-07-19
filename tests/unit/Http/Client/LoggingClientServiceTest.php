<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client;

use OCA\AdminAuditHttpClient\Http\Client\LoggingClientService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LoggingClientServiceTest extends TestCase {
	private function service(array $stringValues, array $values = []): LoggingClientService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default = ''): string => $stringValues[$key] ?? $default,
		);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = '') => $values[$key] ?? $default,
		);

		return new LoggingClientService(
			$this->createMock(IClientService::class),
			new NullLogger(),
			$config,
			$this->createMock(IRequest::class),
		);
	}

	private function resolveLogDir(LoggingClientService $service): string {
		$ref = new \ReflectionMethod($service, 'resolveLogDir');
		return $ref->invoke($service);
	}

	public function testExplicitLogdirWinsAndTrailingSlashIsTrimmed(): void {
		$service = $this->service(
			['audit_http_client_logdir' => '/var/log/nextcloud/http_client/'],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/var/log/nextcloud/http_client', $this->resolveLogDir($service));
	}

	public function testAuditLogSiblingOutsideTheDataDirectory(): void {
		$service = $this->service(
			['logfile_audit' => '/var/log/nextcloud/audit.log'],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/var/log/nextcloud/client', $this->resolveLogDir($service));
	}

	public function testAuditLogInsideTheDataDirectoryUsesTheDataDirSubfolder(): void {
		$service = $this->service(
			['logfile_audit' => '/srv/nc/data/audit.log'],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/srv/nc/data/admin_audit_http_client_logs', $this->resolveLogDir($service));
	}

	public function testLogfileSiblingWhenNoAuditLogIsConfigured(): void {
		$service = $this->service(
			['logfile' => '/var/log/nextcloud/nextcloud.log'],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/var/log/nextcloud/client', $this->resolveLogDir($service));
	}

	public function testDefaultsToTheDataDirSubfolder(): void {
		$service = $this->service(
			[],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/srv/nc/data/admin_audit_http_client_logs', $this->resolveLogDir($service));
	}

	public function testAuditLogWinsOverLogfile(): void {
		$service = $this->service(
			[
				'logfile_audit' => '/var/log/nextcloud-audit/audit.log',
				'logfile' => '/var/log/nextcloud/nextcloud.log',
			],
			['datadirectory' => '/srv/nc/data'],
		);
		$this->assertSame('/var/log/nextcloud-audit/client', $this->resolveLogDir($service));
	}
}
