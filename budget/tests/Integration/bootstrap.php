<?php

declare(strict_types=1);

/**
 * Boots a real Nextcloud for the integration suite.
 *
 * Unlike the unit suite (fully mocked OCP), these tests run the app's real
 * services and mappers against the server's configured database, so SQL
 * dialect problems, missing columns and cross-table invariants show up here.
 *
 * The app must be installed and enabled on that server
 * (occ app:enable budget) before running.
 */

use OCP\App\IAppManager;
use OCP\Server;

$serverRoot = getenv('NEXTCLOUD_ROOT') ?: null;
if ($serverRoot === null) {
	// Works from apps/budget and apps-extra/budget alike
	$dir = __DIR__;
	while ($dir !== dirname($dir)) {
		$dir = dirname($dir);
		if (is_file($dir . '/lib/base.php') && is_file($dir . '/version.php')) {
			$serverRoot = $dir;
			break;
		}
	}
}

if ($serverRoot === null || !is_file($serverRoot . '/lib/base.php')) {
	fwrite(STDERR, "Budget integration tests need a Nextcloud server: could not find lib/base.php above "
		. __DIR__ . ". Set NEXTCLOUD_ROOT to the server root.\n");
	exit(1);
}

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once $serverRoot . '/lib/base.php';

\OC::$composerAutoloader->addPsr4('OCA\\Budget\\Tests\\', dirname(__DIR__) . '/', true);

$appManager = Server::get(IAppManager::class);
if (!$appManager->isEnabledForUser('budget')) {
	fwrite(STDERR, "The budget app is not enabled on this server. Run: php occ app:enable budget\n");
	exit(1);
}
$appManager->loadApp('budget');
