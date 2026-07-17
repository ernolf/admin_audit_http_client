<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAuditHttpClient\Tests\Unit\Http\Client\Middleware;

use OCA\AdminAuditHttpClient\Http\Client\Middleware\TransferStatsStore;
use PHPUnit\Framework\TestCase;

class TransferStatsStoreTest extends TestCase {
	public function testSetGetRoundTrip(): void {
		$reqId = uniqid('req', true);
		TransferStatsStore::set($reqId, ['size_download' => 42]);

		$this->assertSame(['size_download' => 42], TransferStatsStore::get($reqId));

		TransferStatsStore::clear($reqId);
	}

	public function testGetUnknownIdReturnsNull(): void {
		$this->assertNull(TransferStatsStore::get(uniqid('missing', true)));
	}

	public function testClearRemovesEntry(): void {
		$reqId = uniqid('req', true);
		TransferStatsStore::set($reqId, ['a' => 1]);
		TransferStatsStore::clear($reqId);

		$this->assertNull(TransferStatsStore::get($reqId));
	}
}
