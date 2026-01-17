<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCA\Budget\BackgroundJob\CleanupAuditLogsJob;
use OCA\Budget\BackgroundJob\CleanupImportFilesJob;
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
        // Register background jobs
        $context->registerService(CleanupImportFilesJob::class, function($c) {
            return new CleanupImportFilesJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCP\Files\IAppData::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService(CleanupAuditLogsJob::class, function($c) {
            return new CleanupAuditLogsJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Budget\Db\AuditLogMapper::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // ==========================================
        // Foundation Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\DateHelper::class, function() {
            return new \OCA\Budget\Service\DateHelper();
        });

        $context->registerService(\OCA\Budget\Db\QueryFilterBuilder::class, function() {
            return new \OCA\Budget\Db\QueryFilterBuilder();
        });

        $context->registerService(\OCA\Budget\Db\SavingsGoalMapper::class, function($c) {
            return new \OCA\Budget\Db\SavingsGoalMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('SavingsGoalMapper', \OCA\Budget\Db\SavingsGoalMapper::class);

        $context->registerService(\OCA\Budget\Service\GoalsService::class, function($c) {
            return new \OCA\Budget\Service\GoalsService(
                $c->get(\OCA\Budget\Db\SavingsGoalMapper::class)
            );
        });
        $context->registerServiceAlias('GoalsService', \OCA\Budget\Service\GoalsService::class);

        // ==========================================
        // Security Services
        // ==========================================

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

        // ==========================================
        // Core Mappers
        // ==========================================

        $context->registerService(\OCA\Budget\Db\AccountMapper::class, function($c) {
            return new \OCA\Budget\Db\AccountMapper(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Budget\Service\EncryptionService::class)
            );
        });
        $context->registerServiceAlias('AccountMapper', \OCA\Budget\Db\AccountMapper::class);

        $context->registerService(\OCA\Budget\Db\TransactionMapper::class, function($c) {
            return new \OCA\Budget\Db\TransactionMapper(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Budget\Db\QueryFilterBuilder::class)
            );
        });
        $context->registerServiceAlias('TransactionMapper', \OCA\Budget\Db\TransactionMapper::class);

        $context->registerService(\OCA\Budget\Db\CategoryMapper::class, function($c) {
            return new \OCA\Budget\Db\CategoryMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('CategoryMapper', \OCA\Budget\Db\CategoryMapper::class);

        $context->registerService(\OCA\Budget\Db\BillMapper::class, function($c) {
            return new \OCA\Budget\Db\BillMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('BillMapper', \OCA\Budget\Db\BillMapper::class);

        $context->registerService(\OCA\Budget\Db\ImportRuleMapper::class, function($c) {
            return new \OCA\Budget\Db\ImportRuleMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ImportRuleMapper', \OCA\Budget\Db\ImportRuleMapper::class);

        $context->registerService(\OCA\Budget\Db\SettingMapper::class, function($c) {
            return new \OCA\Budget\Db\SettingMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('SettingMapper', \OCA\Budget\Db\SettingMapper::class);

        // ==========================================
        // Validation Service
        // ==========================================

        $context->registerService(\OCA\Budget\Service\ValidationService::class, function() {
            return new \OCA\Budget\Service\ValidationService();
        });
        $context->registerServiceAlias('ValidationService', \OCA\Budget\Service\ValidationService::class);

        // ==========================================
        // Import Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\Import\FileValidator::class, function() {
            return new \OCA\Budget\Service\Import\FileValidator();
        });

        $context->registerService(\OCA\Budget\Service\Import\ParserFactory::class, function() {
            return new \OCA\Budget\Service\Import\ParserFactory();
        });

        $context->registerService(\OCA\Budget\Service\Import\TransactionNormalizer::class, function() {
            return new \OCA\Budget\Service\Import\TransactionNormalizer();
        });

        $context->registerService(\OCA\Budget\Service\Import\DuplicateDetector::class, function($c) {
            return new \OCA\Budget\Service\Import\DuplicateDetector(
                $c->get(\OCA\Budget\Service\TransactionService::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Import\ImportRuleApplicator::class, function($c) {
            return new \OCA\Budget\Service\Import\ImportRuleApplicator(
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class)
            );
        });

        // ==========================================
        // Report Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\Report\ReportCalculator::class, function($c) {
            return new \OCA\Budget\Service\Report\ReportCalculator(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Report\ReportAggregator::class, function($c) {
            return new \OCA\Budget\Service\Report\ReportAggregator(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Report\ReportExporter::class, function($c) {
            return new \OCA\Budget\Service\Report\ReportExporter(
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class)
            );
        });

        // ==========================================
        // Forecast Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\Forecast\TrendCalculator::class, function() {
            return new \OCA\Budget\Service\Forecast\TrendCalculator();
        });

        $context->registerService(\OCA\Budget\Service\Forecast\PatternAnalyzer::class, function($c) {
            return new \OCA\Budget\Service\Forecast\PatternAnalyzer(
                $c->get(\OCA\Budget\Service\Forecast\TrendCalculator::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Forecast\ScenarioBuilder::class, function($c) {
            return new \OCA\Budget\Service\Forecast\ScenarioBuilder(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Forecast\ForecastProjector::class, function($c) {
            return new \OCA\Budget\Service\Forecast\ForecastProjector(
                $c->get(\OCA\Budget\Service\Forecast\TrendCalculator::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });

        // ==========================================
        // Bill Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\Bill\FrequencyCalculator::class, function() {
            return new \OCA\Budget\Service\Bill\FrequencyCalculator();
        });

        $context->registerService(\OCA\Budget\Service\Bill\RecurringBillDetector::class, function($c) {
            return new \OCA\Budget\Service\Bill\RecurringBillDetector(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Service\Bill\FrequencyCalculator::class)
            );
        });

        // ==========================================
        // Core Domain Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\AccountService::class, function($c) {
            return new \OCA\Budget\Service\AccountService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });
        $context->registerServiceAlias('AccountService', \OCA\Budget\Service\AccountService::class);

        $context->registerService(\OCA\Budget\Service\TransactionService::class, function($c) {
            return new \OCA\Budget\Service\TransactionService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });
        $context->registerServiceAlias('TransactionService', \OCA\Budget\Service\TransactionService::class);

        $context->registerService(\OCA\Budget\Service\CategoryService::class, function($c) {
            return new \OCA\Budget\Service\CategoryService(
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });
        $context->registerServiceAlias('CategoryService', \OCA\Budget\Service\CategoryService::class);

        $context->registerService(\OCA\Budget\Service\ImportRuleService::class, function($c) {
            return new \OCA\Budget\Service\ImportRuleService(
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });
        $context->registerServiceAlias('ImportRuleService', \OCA\Budget\Service\ImportRuleService::class);

        $context->registerService(\OCA\Budget\Service\BillService::class, function($c) {
            return new \OCA\Budget\Service\BillService(
                $c->get(\OCA\Budget\Db\BillMapper::class),
                $c->get(\OCA\Budget\Service\Bill\FrequencyCalculator::class),
                $c->get(\OCA\Budget\Service\Bill\RecurringBillDetector::class)
            );
        });
        $context->registerServiceAlias('BillService', \OCA\Budget\Service\BillService::class);

        $context->registerService(\OCA\Budget\Service\ReportService::class, function($c) {
            return new \OCA\Budget\Service\ReportService(
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class),
                $c->get(\OCA\Budget\Service\Report\ReportAggregator::class),
                $c->get(\OCA\Budget\Service\Report\ReportExporter::class)
            );
        });
        $context->registerServiceAlias('ReportService', \OCA\Budget\Service\ReportService::class);

        $context->registerService(\OCA\Budget\Service\ForecastService::class, function($c) {
            return new \OCA\Budget\Service\ForecastService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Service\Forecast\PatternAnalyzer::class),
                $c->get(\OCA\Budget\Service\Forecast\TrendCalculator::class),
                $c->get(\OCA\Budget\Service\Forecast\ScenarioBuilder::class),
                $c->get(\OCA\Budget\Service\Forecast\ForecastProjector::class),
                $c->get(\OCP\ICacheFactory::class)
            );
        });
        $context->registerServiceAlias('ForecastService', \OCA\Budget\Service\ForecastService::class);

        $context->registerService(\OCA\Budget\Service\ImportService::class, function($c) {
            return new \OCA\Budget\Service\ImportService(
                $c->get(\OCP\Files\IAppData::class),
                $c->get(\OCA\Budget\Service\TransactionService::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Service\Import\FileValidator::class),
                $c->get(\OCA\Budget\Service\Import\ParserFactory::class),
                $c->get(\OCA\Budget\Service\Import\TransactionNormalizer::class),
                $c->get(\OCA\Budget\Service\Import\DuplicateDetector::class),
                $c->get(\OCA\Budget\Service\Import\ImportRuleApplicator::class)
            );
        });
        $context->registerServiceAlias('ImportService', \OCA\Budget\Service\ImportService::class);

        $context->registerService(\OCA\Budget\Service\MigrationService::class, function($c) {
            return new \OCA\Budget\Service\MigrationService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\BillMapper::class),
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Db\SettingMapper::class),
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerServiceAlias('MigrationService', \OCA\Budget\Service\MigrationService::class);

        // ==========================================
        // Pension Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\PensionAccountMapper::class, function($c) {
            return new \OCA\Budget\Db\PensionAccountMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('PensionAccountMapper', \OCA\Budget\Db\PensionAccountMapper::class);

        $context->registerService(\OCA\Budget\Db\PensionSnapshotMapper::class, function($c) {
            return new \OCA\Budget\Db\PensionSnapshotMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('PensionSnapshotMapper', \OCA\Budget\Db\PensionSnapshotMapper::class);

        $context->registerService(\OCA\Budget\Db\PensionContributionMapper::class, function($c) {
            return new \OCA\Budget\Db\PensionContributionMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('PensionContributionMapper', \OCA\Budget\Db\PensionContributionMapper::class);

        $context->registerService(\OCA\Budget\Service\PensionService::class, function($c) {
            return new \OCA\Budget\Service\PensionService(
                $c->get(\OCA\Budget\Db\PensionAccountMapper::class),
                $c->get(\OCA\Budget\Db\PensionSnapshotMapper::class),
                $c->get(\OCA\Budget\Db\PensionContributionMapper::class)
            );
        });
        $context->registerServiceAlias('PensionService', \OCA\Budget\Service\PensionService::class);

        $context->registerService(\OCA\Budget\Service\PensionProjector::class, function($c) {
            return new \OCA\Budget\Service\PensionProjector(
                $c->get(\OCA\Budget\Db\PensionAccountMapper::class),
                $c->get(\OCA\Budget\Service\PensionService::class)
            );
        });
        $context->registerServiceAlias('PensionProjector', \OCA\Budget\Service\PensionProjector::class);

        // ==========================================
        // Net Worth Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\NetWorthSnapshotMapper::class, function($c) {
            return new \OCA\Budget\Db\NetWorthSnapshotMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('NetWorthSnapshotMapper', \OCA\Budget\Db\NetWorthSnapshotMapper::class);

        $context->registerService(\OCA\Budget\Service\NetWorthService::class, function($c) {
            return new \OCA\Budget\Service\NetWorthService(
                $c->get(\OCA\Budget\Db\NetWorthSnapshotMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class)
            );
        });
        $context->registerServiceAlias('NetWorthService', \OCA\Budget\Service\NetWorthService::class);

        $context->registerService(\OCA\Budget\BackgroundJob\NetWorthSnapshotJob::class, function($c) {
            return new \OCA\Budget\BackgroundJob\NetWorthSnapshotJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Budget\Service\NetWorthService::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // ==========================================
        // Recurring Income Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\RecurringIncomeMapper::class, function($c) {
            return new \OCA\Budget\Db\RecurringIncomeMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('RecurringIncomeMapper', \OCA\Budget\Db\RecurringIncomeMapper::class);

        $context->registerService(\OCA\Budget\Service\RecurringIncomeService::class, function($c) {
            return new \OCA\Budget\Service\RecurringIncomeService(
                $c->get(\OCA\Budget\Db\RecurringIncomeMapper::class),
                $c->get(\OCA\Budget\Service\Bill\FrequencyCalculator::class)
            );
        });
        $context->registerServiceAlias('RecurringIncomeService', \OCA\Budget\Service\RecurringIncomeService::class);
    }

    public function boot(IBootContext $context): void {
        // Minimal boot - test if this allows the app to load
    }
}
