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
}
