<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCA\Budget\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;

class Application extends App implements IBootstrap {
    public const APP_ID = 'budget';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);

        // Load composer autoloader for dependencies like TCPDF
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
    }

    public function register(IRegistrationContext $context): void {
        $context->registerNotifierService(Notifier::class);

        // IAppData cannot be autowired — it requires the app ID via factory
        $context->registerService(IAppData::class, function ($c) {
            return $c->get(IAppDataFactory::class)->get(self::APP_ID);
        });
    }

    public function boot(IBootContext $context): void {
    }
}
