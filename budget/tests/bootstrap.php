<?php

declare(strict_types=1);

// Load composer autoloader
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Disable authoritative classmap mode for tests so PSR-4 fallback works
// (the classmap-authoritative config in composer.json prevents autoloading
// of nextcloud/ocp stubs which don't declare their own autoload section)
$autoloader->setClassMapAuthoritative(false);

// Load Doctrine DBAL stubs (required by OCP interfaces like IQueryBuilder)
require_once __DIR__ . '/stubs/doctrine_dbal.php';

// Load OC server stub (required by OCP\Server::get() in background jobs)
require_once __DIR__ . '/stubs/oc_server.php';

// Register test namespace so shared test helpers (e.g. MockPhpInputStream) are autoloaded
$autoloader->addPsr4('OCA\\Budget\\Tests\\', __DIR__);

// Register the OCP stub namespace from nextcloud/ocp dev dependency
$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpPath)) {
    $autoloader->addPsr4('OCP\\', $ocpPath . '/OCP');
    $autoloader->addPsr4('OC\\', $ocpPath . '/OC');
}
