<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\Files\IAppData;

class Application extends App implements IBootstrap {
    public const APP_ID = 'budget';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register services, event listeners, middleware, etc.
        // This is called early in the app lifecycle
        
        // Include composer autoloader if needed
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            include_once $autoloadPath;
        }
        
        // Register userId service
        $context->registerService('userId', function() {
            /** @var IUserSession $userSession */
            $userSession = \OC::$server->get(IUserSession::class);
            $user = $userSession->getUser();
            return $user ? $user->getUID() : '';
        });

        // Register mappers
        $context->registerService(\OCA\Budget\Db\AccountMapper::class, function($c) {
            return new \OCA\Budget\Db\AccountMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService(\OCA\Budget\Db\CategoryMapper::class, function($c) {
            return new \OCA\Budget\Db\CategoryMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService(\OCA\Budget\Db\TransactionMapper::class, function($c) {
            return new \OCA\Budget\Db\TransactionMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService(\OCA\Budget\Db\ImportRuleMapper::class, function($c) {
            return new \OCA\Budget\Db\ImportRuleMapper($c->get(\OCP\IDBConnection::class));
        });

        // Register services
        $context->registerService(\OCA\Budget\Service\AccountService::class, function($c) {
            return new \OCA\Budget\Service\AccountService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\CategoryService::class, function($c) {
            return new \OCA\Budget\Service\CategoryService(
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\TransactionService::class, function($c) {
            return new \OCA\Budget\Service\TransactionService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\ImportService::class, function($c) {
            return new \OCA\Budget\Service\ImportService(
                $c->get(\OCP\Files\IAppData::class),
                $c->get(\OCA\Budget\Service\TransactionService::class),
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\ImportRuleService::class, function($c) {
            return new \OCA\Budget\Service\ImportRuleService(
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\ReportService::class, function($c) {
            return new \OCA\Budget\Service\ReportService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\ForecastService::class, function($c) {
            return new \OCA\Budget\Service\ForecastService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });
    }

    public function boot(IBootContext $context): void {
        // This is called later, when the server is fully initialized
        $server = $context->getServerContainer();
        
        // Register navigation entry
        $navigationManager = $server->get(INavigationManager::class);
        $navigationManager->add(function () use ($server) {
            $urlGenerator = $server->get(IURLGenerator::class);
            $l10n = $server->get(IL10N::class);

            return [
                'id' => self::APP_ID,
                'order' => 10,
                'href' => $urlGenerator->linkToRoute('budget.page.index'),
                'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
                'name' => $l10n->t('Budget'),
            ];
        });
    }
}