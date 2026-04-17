<?php

declare(strict_types=1);

namespace OCA\Budget\AppInfo;

use OCA\Budget\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
        // Register notification notifier
        $context->registerNotifierService(Notifier::class);

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
                $c->get(\OCA\Budget\Db\SavingsGoalMapper::class),
                $c->get(\OCA\Budget\Db\TransactionTagMapper::class)
            );
        });
        $context->registerServiceAlias('GoalsService', \OCA\Budget\Service\GoalsService::class);

        // ==========================================
        // Security Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\EncryptionService::class, function($c) {
            return new \OCA\Budget\Service\EncryptionService(
                $c->get(\OCP\Security\ICrypto::class),
                $c->get(\Psr\Log\LoggerInterface::class)
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

        // Auth Mapper (Password Protection)
        $context->registerService(\OCA\Budget\Db\AuthMapper::class, function($c) {
            return new \OCA\Budget\Db\AuthMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerServiceAlias('AuthMapper', \OCA\Budget\Db\AuthMapper::class);

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

        $context->registerService(\OCA\Budget\Db\TransactionSplitMapper::class, function($c) {
            return new \OCA\Budget\Db\TransactionSplitMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('TransactionSplitMapper', \OCA\Budget\Db\TransactionSplitMapper::class);

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

        $context->registerService(\OCA\Budget\Service\SettingService::class, function($c) {
            return new \OCA\Budget\Service\SettingService(
                $c->get(\OCA\Budget\Db\SettingMapper::class)
            );
        });
        $context->registerServiceAlias('SettingService', \OCA\Budget\Service\SettingService::class);

        // Auth Service (Password Protection) - Depends on SettingService
        $context->registerService(\OCA\Budget\Service\AuthService::class, function($c) {
            return new \OCA\Budget\Service\AuthService(
                $c->get(\OCA\Budget\Db\AuthMapper::class),
                $c->get(\OCA\Budget\Service\SettingService::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('AuthService', \OCA\Budget\Service\AuthService::class);

        // ==========================================
        // Validation Service
        // ==========================================

        $context->registerService(\OCA\Budget\Service\ValidationService::class, function($c) {
            return new \OCA\Budget\Service\ValidationService(
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('ValidationService', \OCA\Budget\Service\ValidationService::class);

        // ==========================================
        // Import Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\Import\FileValidator::class, function($c) {
            return new \OCA\Budget\Service\Import\FileValidator(
                $c->get(\OCP\IL10N::class)
            );
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
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Service\Import\CriteriaEvaluator::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Import\CriteriaEvaluator::class, function($c) {
            return new \OCA\Budget\Service\Import\CriteriaEvaluator(
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Import\RuleActionApplicator::class, function($c) {
            return new \OCA\Budget\Service\Import\RuleActionApplicator(
                $c->get(\OCA\Budget\Service\TransactionTagService::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\Psr\Log\LoggerInterface::class)
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
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Report\ReportExporter::class, function($c) {
            return new \OCA\Budget\Service\Report\ReportExporter(
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class)
            );
        });

        $context->registerService(\OCA\Budget\Service\Report\TagReportService::class, function($c) {
            return new \OCA\Budget\Service\Report\TagReportService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\TagSetMapper::class),
                $c->get(\OCA\Budget\Db\TagMapper::class)
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

        $context->registerService(\OCA\Budget\Service\Income\RecurringIncomeDetector::class, function($c) {
            return new \OCA\Budget\Service\Income\RecurringIncomeDetector(
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
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('AccountService', \OCA\Budget\Service\AccountService::class);

        $context->registerService(\OCA\Budget\Service\TransactionService::class, function($c) {
            return new \OCA\Budget\Service\TransactionService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionTagMapper::class),
                $c->get(\OCA\Budget\Db\ExpenseShareMapper::class)
            );
        });
        $context->registerServiceAlias('TransactionService', \OCA\Budget\Service\TransactionService::class);

        $context->registerService(\OCA\Budget\Service\TransactionSplitService::class, function($c) {
            return new \OCA\Budget\Service\TransactionSplitService(
                $c->get(\OCA\Budget\Db\TransactionSplitMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });
        $context->registerServiceAlias('TransactionSplitService', \OCA\Budget\Service\TransactionSplitService::class);

        $context->registerService(\OCA\Budget\Service\CategoryService::class, function($c) {
            return new \OCA\Budget\Service\CategoryService(
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\TagSetMapper::class),
                $c->get(\OCA\Budget\Db\TagMapper::class),
                $c->get(\OCA\Budget\Db\TransactionTagMapper::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('CategoryService', \OCA\Budget\Service\CategoryService::class);

        // ==========================================
        // Tag Set Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\TagSetMapper::class, function($c) {
            return new \OCA\Budget\Db\TagSetMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('TagSetMapper', \OCA\Budget\Db\TagSetMapper::class);

        $context->registerService(\OCA\Budget\Db\TagMapper::class, function($c) {
            return new \OCA\Budget\Db\TagMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('TagMapper', \OCA\Budget\Db\TagMapper::class);

        $context->registerService(\OCA\Budget\Db\TransactionTagMapper::class, function($c) {
            return new \OCA\Budget\Db\TransactionTagMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('TransactionTagMapper', \OCA\Budget\Db\TransactionTagMapper::class);

        $context->registerService(\OCA\Budget\Service\TagSetService::class, function($c) {
            return new \OCA\Budget\Service\TagSetService(
                $c->get(\OCA\Budget\Db\TagSetMapper::class),
                $c->get(\OCA\Budget\Db\TagMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionTagMapper::class),
                $c->get(\OCA\Budget\Db\SavingsGoalMapper::class)
            );
        });
        $context->registerServiceAlias('TagSetService', \OCA\Budget\Service\TagSetService::class);

        $context->registerService(\OCA\Budget\Service\TransactionTagService::class, function($c) {
            return new \OCA\Budget\Service\TransactionTagService(
                $c->get(\OCA\Budget\Db\TransactionTagMapper::class),
                $c->get(\OCA\Budget\Db\TagMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerServiceAlias('TransactionTagService', \OCA\Budget\Service\TransactionTagService::class);

        $context->registerService(\OCA\Budget\Service\ImportRuleService::class, function($c) {
            return new \OCA\Budget\Service\ImportRuleService(
                $c->get(\OCA\Budget\Db\ImportRuleMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Budget\Service\Import\CriteriaEvaluator::class),
                $c->get(\OCA\Budget\Service\Import\RuleActionApplicator::class)
            );
        });
        $context->registerServiceAlias('ImportRuleService', \OCA\Budget\Service\ImportRuleService::class);

        $context->registerService(\OCA\Budget\Service\BillService::class, function($c) {
            return new \OCA\Budget\Service\BillService(
                $c->get(\OCA\Budget\Db\BillMapper::class),
                $c->get(\OCA\Budget\Service\Bill\FrequencyCalculator::class),
                $c->get(\OCA\Budget\Service\Bill\RecurringBillDetector::class),
                $c->get(\OCA\Budget\Service\TransactionService::class),
                $c->get(\OCP\IL10N::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class),
                $c->get(\OCA\Budget\Service\TransactionSplitService::class)
            );
        });
        $context->registerServiceAlias('BillService', \OCA\Budget\Service\BillService::class);

        $context->registerService(\OCA\Budget\Service\ReportService::class, function($c) {
            return new \OCA\Budget\Service\ReportService(
                $c->get(\OCA\Budget\Service\Report\ReportCalculator::class),
                $c->get(\OCA\Budget\Service\Report\ReportAggregator::class),
                $c->get(\OCA\Budget\Service\Report\ReportExporter::class),
                $c->get(\OCA\Budget\Service\Report\TagReportService::class)
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
                $c->get(\OCA\Budget\Service\Import\ImportRuleApplicator::class),
                $c->get(\OCP\IL10N::class)
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
                $c->get(\OCA\Budget\Db\PensionContributionMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class)
            );
        });
        $context->registerServiceAlias('PensionService', \OCA\Budget\Service\PensionService::class);

        $context->registerService(\OCA\Budget\Service\PensionProjector::class, function($c) {
            return new \OCA\Budget\Service\PensionProjector(
                $c->get(\OCA\Budget\Db\PensionAccountMapper::class),
                $c->get(\OCA\Budget\Service\PensionService::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class)
            );
        });
        $context->registerServiceAlias('PensionProjector', \OCA\Budget\Service\PensionProjector::class);

        // ==========================================
        // Asset Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\AssetMapper::class, function($c) {
            return new \OCA\Budget\Db\AssetMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('AssetMapper', \OCA\Budget\Db\AssetMapper::class);

        $context->registerService(\OCA\Budget\Db\AssetSnapshotMapper::class, function($c) {
            return new \OCA\Budget\Db\AssetSnapshotMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('AssetSnapshotMapper', \OCA\Budget\Db\AssetSnapshotMapper::class);

        $context->registerService(\OCA\Budget\Service\AssetService::class, function($c) {
            return new \OCA\Budget\Service\AssetService(
                $c->get(\OCA\Budget\Db\AssetMapper::class),
                $c->get(\OCA\Budget\Db\AssetSnapshotMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class)
            );
        });
        $context->registerServiceAlias('AssetService', \OCA\Budget\Service\AssetService::class);

        $context->registerService(\OCA\Budget\Service\AssetProjector::class, function($c) {
            return new \OCA\Budget\Service\AssetProjector(
                $c->get(\OCA\Budget\Db\AssetMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class)
            );
        });
        $context->registerServiceAlias('AssetProjector', \OCA\Budget\Service\AssetProjector::class);

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
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Service\CurrencyConversionService::class),
                $c->get(\OCA\Budget\Service\AssetService::class),
                $c->get(\OCA\Budget\Service\PensionService::class)
            );
        });
        $context->registerServiceAlias('NetWorthService', \OCA\Budget\Service\NetWorthService::class);

        // ==========================================
        // Interest Accrual Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\InterestRateMapper::class, function($c) {
            return new \OCA\Budget\Db\InterestRateMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('InterestRateMapper', \OCA\Budget\Db\InterestRateMapper::class);

        $context->registerService(\OCA\Budget\Service\InterestService::class, function($c) {
            return new \OCA\Budget\Service\InterestService(
                $c->get(\OCA\Budget\Db\InterestRateMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class)
            );
        });
        $context->registerServiceAlias('InterestService', \OCA\Budget\Service\InterestService::class);

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
                $c->get(\OCA\Budget\Service\Bill\FrequencyCalculator::class),
                $c->get(\OCA\Budget\Service\Income\RecurringIncomeDetector::class),
                $c->get(\OCA\Budget\Service\TransactionService::class)
            );
        });
        $context->registerServiceAlias('RecurringIncomeService', \OCA\Budget\Service\RecurringIncomeService::class);

        // ==========================================
        // Budget Alert Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\BudgetAlertService::class, function($c) {
            return new \OCA\Budget\Service\BudgetAlertService(
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\TransactionSplitMapper::class),
                $c->get(\OCA\Budget\Service\SettingService::class)
            );
        });
        $context->registerServiceAlias('BudgetAlertService', \OCA\Budget\Service\BudgetAlertService::class);

        // ==========================================
        // Debt Payoff Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\DebtPayoffService::class, function($c) {
            return new \OCA\Budget\Service\DebtPayoffService(
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });
        $context->registerServiceAlias('DebtPayoffService', \OCA\Budget\Service\DebtPayoffService::class);

        // ==========================================
        // Year-over-Year Services
        // ==========================================

        $context->registerService(\OCA\Budget\Service\YearOverYearService::class, function($c) {
            return new \OCA\Budget\Service\YearOverYearService(
                $c->get(\OCA\Budget\Db\TransactionMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class)
            );
        });
        $context->registerServiceAlias('YearOverYearService', \OCA\Budget\Service\YearOverYearService::class);

        // ==========================================
        // Shared Expense Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\ContactMapper::class, function($c) {
            return new \OCA\Budget\Db\ContactMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ContactMapper', \OCA\Budget\Db\ContactMapper::class);

        $context->registerService(\OCA\Budget\Db\ExpenseShareMapper::class, function($c) {
            return new \OCA\Budget\Db\ExpenseShareMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ExpenseShareMapper', \OCA\Budget\Db\ExpenseShareMapper::class);

        $context->registerService(\OCA\Budget\Db\SettlementMapper::class, function($c) {
            return new \OCA\Budget\Db\SettlementMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('SettlementMapper', \OCA\Budget\Db\SettlementMapper::class);

        $context->registerService(\OCA\Budget\Service\SharedExpenseService::class, function($c) {
            return new \OCA\Budget\Service\SharedExpenseService(
                $c->get(\OCA\Budget\Db\ContactMapper::class),
                $c->get(\OCA\Budget\Db\ExpenseShareMapper::class),
                $c->get(\OCA\Budget\Db\SettlementMapper::class),
                $c->get(\OCA\Budget\Db\TransactionMapper::class)
            );
        });
        $context->registerServiceAlias('SharedExpenseService', \OCA\Budget\Service\SharedExpenseService::class);

        // ==========================================
        // Budget Sharing Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\ShareMapper::class, function($c) {
            return new \OCA\Budget\Db\ShareMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ShareMapper', \OCA\Budget\Db\ShareMapper::class);

        $context->registerService(\OCA\Budget\Db\ShareItemMapper::class, function($c) {
            return new \OCA\Budget\Db\ShareItemMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ShareItemMapper', \OCA\Budget\Db\ShareItemMapper::class);

        $context->registerService(\OCA\Budget\Service\ShareService::class, function($c) {
            return new \OCA\Budget\Service\ShareService(
                $c->get(\OCA\Budget\Db\ShareMapper::class),
                $c->get(\OCA\Budget\Db\ShareItemMapper::class),
                $c->get(\OCA\Budget\Service\AuditService::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\Notification\IManager::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('ShareService', \OCA\Budget\Service\ShareService::class);

        $context->registerService(\OCA\Budget\Service\GranularShareService::class, function($c) {
            return new \OCA\Budget\Service\GranularShareService(
                $c->get(\OCA\Budget\Db\ShareMapper::class),
                $c->get(\OCA\Budget\Db\ShareItemMapper::class),
                $c->get(\OCA\Budget\Db\AccountMapper::class),
                $c->get(\OCA\Budget\Db\BillMapper::class),
                $c->get(\OCA\Budget\Db\CategoryMapper::class),
                $c->get(\OCA\Budget\Db\RecurringIncomeMapper::class),
                $c->get(\OCA\Budget\Db\SavingsGoalMapper::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('GranularShareService', \OCA\Budget\Service\GranularShareService::class);

        // ==========================================
        // Exchange Rate Services
        // ==========================================

        $context->registerService(\OCA\Budget\Db\ExchangeRateMapper::class, function($c) {
            return new \OCA\Budget\Db\ExchangeRateMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ExchangeRateMapper', \OCA\Budget\Db\ExchangeRateMapper::class);

        $context->registerService(\OCA\Budget\Db\ManualExchangeRateMapper::class, function($c) {
            return new \OCA\Budget\Db\ManualExchangeRateMapper($c->get(\OCP\IDBConnection::class));
        });
        $context->registerServiceAlias('ManualExchangeRateMapper', \OCA\Budget\Db\ManualExchangeRateMapper::class);

        $context->registerService(\OCA\Budget\Service\ExchangeRateService::class, function($c) {
            return new \OCA\Budget\Service\ExchangeRateService(
                $c->get(\OCA\Budget\Db\ExchangeRateMapper::class),
                $c->get(\OCP\Http\Client\IClientService::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerServiceAlias('ExchangeRateService', \OCA\Budget\Service\ExchangeRateService::class);

        $context->registerService(\OCA\Budget\Service\ManualExchangeRateService::class, function($c) {
            return new \OCA\Budget\Service\ManualExchangeRateService(
                $c->get(\OCA\Budget\Db\ManualExchangeRateMapper::class),
                $c->get(\OCA\Budget\Service\ExchangeRateService::class),
                $c->get(\OCA\Budget\Service\SettingService::class),
                $c->get(\OCP\IL10N::class)
            );
        });
        $context->registerServiceAlias('ManualExchangeRateService', \OCA\Budget\Service\ManualExchangeRateService::class);

        $context->registerService(\OCA\Budget\Service\CurrencyConversionService::class, function($c) {
            return new \OCA\Budget\Service\CurrencyConversionService(
                $c->get(\OCA\Budget\Service\ExchangeRateService::class),
                $c->get(\OCA\Budget\Service\SettingService::class),
                $c->get(\OCA\Budget\Db\ManualExchangeRateMapper::class)
            );
        });
        $context->registerServiceAlias('CurrencyConversionService', \OCA\Budget\Service\CurrencyConversionService::class);
    }

    public function boot(IBootContext $context): void {
        // Minimal boot - test if this allows the app to load
    }
}
