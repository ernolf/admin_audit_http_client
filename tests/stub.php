<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Psalm stubs for internal (OC\…) classes that are not part of the public OCP
// surface provided by nextcloud/ocp. The middleware injection relies on these
// internals by design; keep the stubs minimal.

namespace {
	class OC {
		public static string $SERVERROOT;
	}
}

namespace OC\Http\Client {
	class Client {
	}
}
