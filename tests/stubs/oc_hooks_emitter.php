<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// For Psalm: nextcloud/ocp's IRootFolder extends this private interface of the server, which the
// package does not contain.

namespace OC\Hooks;

interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
