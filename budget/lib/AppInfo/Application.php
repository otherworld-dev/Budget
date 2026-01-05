<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'budget';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Service registrations for dependency injection
        $context->registerService('GoalsService', function() {
            return new \OCA\Budget\Service\GoalsService();
        });

        // Security services - Encryption and Audit
        $context->registerService(\OCA\Budget\Service\EncryptionService::class, function($c) {
            return new \OCA\Budget\Service\EncryptionService(
                $c->get(\OCP\Security\ICrypto::class)
            );
        });

        $context->registerService(\OCA\Budget\Db\AuditLogMapper::class, function($c) {
            return new \OCA\Budget\Db\AuditLogMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\AuditService::class, function($c) {
            return new \OCA\Budget\Service\AuditService(
                $c->get(\OCA\Budget\Db\AuditLogMapper::class),
                $c->get(\OCP\IRequest::class)
            );
        });

        // Register core services and mappers for account functionality
        $context->registerService('AccountMapper', function($c) {
            return new \OCA\Budget\Db\AccountMapper(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Budget\Service\EncryptionService::class)
            );
        });

        $context->registerService('TransactionMapper', function($c) {
            return new \OCA\Budget\Db\TransactionMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService('CategoryMapper', function($c) {
            return new \OCA\Budget\Db\CategoryMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService('ImportRuleMapper', function($c) {
            return new \OCA\Budget\Db\ImportRuleMapper($c->get(\OCP\IDBConnection::class));
        });

        $context->registerService('ValidationService', function() {
            return new \OCA\Budget\Service\ValidationService();
        });

        $context->registerService('AccountService', function($c) {
            return new \OCA\Budget\Service\AccountService(
                $c->get('AccountMapper'),
                $c->get('TransactionMapper')
            );
        });

        $context->registerService('TransactionService', function($c) {
            return new \OCA\Budget\Service\TransactionService(
                $c->get('TransactionMapper'),
                $c->get('AccountMapper'),
                $c->get('CategoryMapper')
            );
        });

        $context->registerService('CategoryService', function($c) {
            return new \OCA\Budget\Service\CategoryService(
                $c->get('CategoryMapper')
            );
        });

        $context->registerService('ImportRuleService', function($c) {
            return new \OCA\Budget\Service\ImportRuleService(
                $c->get('ImportRuleMapper'),
                $c->get('CategoryMapper')
            );
        });
    }

    public function boot(IBootContext $context): void {
        // Minimal boot - test if this allows the app to load
    }
}