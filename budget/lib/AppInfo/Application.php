<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCA\Budget\Dashboard\BudgetOverviewWidget;
use OCA\Budget\Dashboard\UpcomingBillsWidget;
use OCA\Budget\Notification\Notifier;
use OCA\Budget\Search\TransactionSearchProvider;
use OCA\Budget\Service\SchemaVersionService;
use OCA\Budget\SetupChecks\BudgetSchemaCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\AppData\IAppDataFactory;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

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
        $context->registerSearchProvider(TransactionSearchProvider::class);
        $context->registerDashboardWidget(UpcomingBillsWidget::class);
        $context->registerDashboardWidget(BudgetOverviewWidget::class);
        $context->registerSetupCheck(BudgetSchemaCheck::class);

        // IAppData cannot be autowired — it requires the app ID via factory
        $context->registerService(IAppData::class, function ($c) {
            return $c->get(IAppDataFactory::class)->get(self::APP_ID);
        });

        // Takes the app version and its own migration directory, neither of
        // which can be autowired (#333).
        $context->registerService(SchemaVersionService::class, function ($c) {
            return new SchemaVersionService(
                $c->get(IConfig::class),
                $c->get(IDBConnection::class),
                $c->get(IL10N::class),
                $c->get(IAppManager::class)->getAppVersion(self::APP_ID),
                __DIR__ . '/../Migration'
            );
        });
    }

    public function boot(IBootContext $context): void {
    }
}
