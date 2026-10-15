<?php

declare(strict_types=1);

/**
 * Minimal \OC\Hooks\Emitter stub for unit testing.
 *
 * OCP\Files\IRootFolder extends this server-internal interface, which is not
 * shipped in the nextcloud/ocp dev package — without it, IRootFolder cannot
 * be mocked.
 */

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
	interface Emitter {
		public function listen($scope, $method, callable $callback);
		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
