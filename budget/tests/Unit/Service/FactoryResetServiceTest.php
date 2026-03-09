<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AssetMapper;
use OCA\Budget\Db\AssetSnapshotMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\SettlementMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\FactoryResetService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FactoryResetServiceTest extends TestCase {
    private FactoryResetService $service;
    private IDBConnection $db;

    // Mapper mocks
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private TransactionSplitMapper $transactionSplitMapper;
    private BillMapper $billMapper;
    private CategoryMapper $categoryMapper;
    private RecurringIncomeMapper $recurringIncomeMapper;
    private ImportRuleMapper $importRuleMapper;
    private SettingMapper $settingMapper;
    private ContactMapper $contactMapper;
    private ExpenseShareMapper $expenseShareMapper;
    private $settlementMapper;
    private SavingsGoalMapper $savingsGoalMapper;
    private PensionAccountMapper $pensionAccountMapper;
    private PensionContributionMapper $pensionContributionMapper;
    private $pensionSnapshotMapper;
    private NetWorthSnapshotMapper $netWorthSnapshotMapper;
    private $assetMapper;
    private $assetSnapshotMapper;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->transactionSplitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->billMapper = $this->createMock(BillMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->recurringIncomeMapper = $this->createMock(RecurringIncomeMapper::class);
        $this->importRuleMapper = $this->createMock(ImportRuleMapper::class);
        $this->settingMapper = $this->createMock(SettingMapper::class);
        $this->contactMapper = $this->createMock(ContactMapper::class);
        $this->expenseShareMapper = $this->createMock(ExpenseShareMapper::class);
        $this->settlementMapper = $this->createMock(SettlementMapper::class);
        $this->savingsGoalMapper = $this->createMock(SavingsGoalMapper::class);
        $this->pensionAccountMapper = $this->createMock(PensionAccountMapper::class);
        $this->pensionContributionMapper = $this->createMock(PensionContributionMapper::class);
        $this->pensionSnapshotMapper = $this->createMock(PensionSnapshotMapper::class);
        $this->netWorthSnapshotMapper = $this->createMock(NetWorthSnapshotMapper::class);
        $this->assetMapper = $this->createMock(AssetMapper::class);
        $this->assetSnapshotMapper = $this->createMock(AssetSnapshotMapper::class);

        $this->service = new FactoryResetService(
            $this->accountMapper,
            $this->transactionMapper,
            $this->transactionSplitMapper,
            $this->billMapper,
            $this->categoryMapper,
            $this->recurringIncomeMapper,
            $this->importRuleMapper,
            $this->settingMapper,
            $this->contactMapper,
            $this->expenseShareMapper,
            $this->settlementMapper,
            $this->savingsGoalMapper,
            $this->pensionAccountMapper,
            $this->pensionContributionMapper,
            $this->pensionSnapshotMapper,
            $this->netWorthSnapshotMapper,
            $this->assetMapper,
            $this->assetSnapshotMapper,
            $this->db
        );
    }

    public function testExecuteFactoryResetDeletesAllAndCommits(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        // Set up all mappers to return counts
        $this->expenseShareMapper->method('deleteAll')->willReturn(2);
        $this->transactionSplitMapper->method('deleteAll')->willReturn(5);
        $this->settlementMapper->method('deleteAll')->willReturn(1);
        $this->transactionMapper->method('deleteAll')->willReturn(20);
        $this->billMapper->method('deleteAll')->willReturn(3);
        $this->recurringIncomeMapper->method('deleteAll')->willReturn(2);
        $this->pensionContributionMapper->method('deleteAll')->willReturn(4);
        $this->pensionSnapshotMapper->method('deleteAll')->willReturn(6);
        $this->assetSnapshotMapper->method('deleteAll')->willReturn(3);
        $this->accountMapper->method('deleteAll')->willReturn(4);
        $this->pensionAccountMapper->method('deleteAll')->willReturn(2);
        $this->assetMapper->method('deleteAll')->willReturn(1);
        $this->contactMapper->method('deleteAll')->willReturn(3);
        $this->savingsGoalMapper->method('deleteAll')->willReturn(1);
        $this->categoryMapper->method('deleteAll')->willReturn(10);
        $this->importRuleMapper->method('deleteAll')->willReturn(5);
        $this->settingMapper->method('deleteAll')->willReturn(8);
        $this->netWorthSnapshotMapper->method('deleteAll')->willReturn(12);

        $counts = $this->service->executeFactoryReset('user1');

        $this->assertEquals(20, $counts['transactions']);
        $this->assertEquals(4, $counts['accounts']);
        $this->assertEquals(10, $counts['categories']);
        $this->assertEquals(8, $counts['settings']);
    }

    public function testExecuteFactoryResetRollsBackOnError(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $this->expenseShareMapper->method('deleteAll')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB error');

        $this->service->executeFactoryReset('user1');
    }

    public function testExecuteFactoryResetHandlesMissingTables(): void {
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        // Most mappers succeed
        $this->transactionSplitMapper->method('deleteAll')->willReturn(0);
        $this->transactionMapper->method('deleteAll')->willReturn(0);
        $this->billMapper->method('deleteAll')->willReturn(0);
        $this->recurringIncomeMapper->method('deleteAll')->willReturn(0);
        $this->pensionContributionMapper->method('deleteAll')->willReturn(0);
        $this->accountMapper->method('deleteAll')->willReturn(0);
        $this->pensionAccountMapper->method('deleteAll')->willReturn(0);
        $this->contactMapper->method('deleteAll')->willReturn(0);
        $this->savingsGoalMapper->method('deleteAll')->willReturn(0);
        $this->categoryMapper->method('deleteAll')->willReturn(0);
        $this->importRuleMapper->method('deleteAll')->willReturn(0);
        $this->settingMapper->method('deleteAll')->willReturn(0);
        $this->netWorthSnapshotMapper->method('deleteAll')->willReturn(0);

        // Some tables don't exist yet
        $this->expenseShareMapper->method('deleteAll')
            ->willThrowException(new \Exception('no such table: budget_expense_shares'));
        $this->settlementMapper->method('deleteAll')
            ->willThrowException(new \Exception('no such table: budget_settlements'));
        $this->pensionSnapshotMapper->method('deleteAll')
            ->willThrowException(new \Exception("Table 'budget_pen_snaps' doesn't exist"));
        $this->assetSnapshotMapper->method('deleteAll')
            ->willThrowException(new \Exception('no such table: budget_asset_snapshots'));
        $this->assetMapper->method('deleteAll')
            ->willThrowException(new \Exception('no such table: budget_assets'));

        $counts = $this->service->executeFactoryReset('user1');

        // Missing tables should return 0
        $this->assertEquals(0, $counts['expenseShares']);
        $this->assertEquals(0, $counts['settlements']);
    }
}
