<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('PHPUNIT_RUN', 1);

$ncBase = __DIR__ . '/../../../lib/base.php';
if (file_exists($ncBase)) {
	// Running inside a Nextcloud server: boot it so tests may use OCP/OC.
	require_once $ncBase;

	\OC::$composerAutoloader->addPsr4('Test\\', \OC::$SERVERROOT . '/tests/lib/', true);
	\OC::$composerAutoloader->addPsr4('Tests\\', \OC::$SERVERROOT . '/tests/', true);

	\OC_App::loadApp('admin_audit_http_client');

	\OC_Hook::clear();
} else {
	// Standalone checkout (no server alongside): the app autoloader plus the
	// OCP/Guzzle dev dependencies are enough for the pure unit tests.
	require_once __DIR__ . '/../vendor/autoload.php';
	require_once __DIR__ . '/../vendor-bin/nextcloud-ocp/vendor/autoload.php';

	// The nextcloud/ocp package is a stub collection without an autoload
	// section (psalm scans it as extra files), so OCP\… must be mapped by
	// hand for tests that mock OCP interfaces.
	spl_autoload_register(function (string $class): void {
		if (str_starts_with($class, 'OCP\\')) {
			$file = __DIR__ . '/../vendor-bin/nextcloud-ocp/vendor/nextcloud/ocp/'
				. str_replace('\\', '/', $class) . '.php';
			if (file_exists($file)) {
				require_once $file;
			}
		}
	});

	// \OC::$SERVERROOT appears as a default argument in resolveLogDir(); PHP
	// evaluates default arguments on every call, so the class must exist even
	// when the mocked config never lets that default win.
	if (!class_exists('OC')) {
		class OC {
			public static string $SERVERROOT = '/srv/nextcloud';
		}
	}
}
