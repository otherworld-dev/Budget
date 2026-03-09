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

// Register the OCP stub namespace from nextcloud/ocp dev dependency
$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpPath)) {
    $autoloader->addPsr4('OCP\\', $ocpPath . '/OCP');
    $autoloader->addPsr4('OC\\', $ocpPath . '/OC');
}
