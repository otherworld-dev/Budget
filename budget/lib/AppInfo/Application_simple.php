<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class ApplicationSimple extends App implements IBootstrap {
    public const APP_ID = 'budget';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Minimal registration to test if basic app loads
    }

    public function boot(IBootContext $context): void {
        // Minimal boot to test if basic app works
    }
}