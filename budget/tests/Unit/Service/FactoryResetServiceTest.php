<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\MigrationService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FactoryResetServiceTest extends TestCase {
    private FactoryResetService $service;
    private IDBConnection $db;
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private BillMapper $billMapper;
    private CategoryMapper $categoryMapper;
    private ImportRuleMapper $importRuleMapper;
    private SettingMapper $settingMapper;
    private AttachmentMapper $attachmentMapper;

    /** @var list<array{table: string, sql: ?string, params: array}> every DELETE, in order */
    private array $deletes = [];
    /** Tables whose DELETE should throw, with the message */
    private array $failingTables = [];

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->billMapper = $this->createMock(BillMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->importRuleMapper = $this->createMock(ImportRuleMapper::class);
        $this->settingMapper = $this->createMock(SettingMapper::class);
        $this->attachmentMapper = $this->createMock(AttachmentMapper::class);

        // Query-builder deletes (user-scoped registry tables)
        $this->db->method('getQueryBuilder')->willReturnCallback(function () {
            $table = null;
            $params = [];
            $expr = $this->createMock(IExpressionBuilder::class);
            $expr->method('eq')->willReturnCallback(fn ($col, $val) => "$col = $val");
            $qb = $this->createMock(IQueryBuilder::class);
            $qb->method('expr')->willReturn($expr);
            $qb->method('delete')->willReturnCallback(function (string $t) use (&$table, $qb) {
                $table = $t;
                return $qb;
            });
            $qb->method('where')->willReturnSelf();
            $qb->method('createNamedParameter')->willReturnCallback(function ($v) use (&$params) {
                $params[] = $v;
                return ':p' . count($params);
            });
            $qb->method('executeStatement')->willReturnCallback(function () use (&$table, &$params) {
                return $this->recordDelete($table, null, $params);
            });
            return $qb;
        });
        // Raw deletes (join-scoped registry tables)
        $this->db->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) {
            preg_match('/^DELETE FROM \*PREFIX\*([a-z_]+)/', $sql, $m);
            return $this->recordDelete($m[1] ?? '?', $sql, $params);
        });

        // Bespoke entities go through their mappers; log them in the same sequence
        foreach ([
            'budget_transactions' => $this->transactionMapper,
            'budget_bills' => $this->billMapper,
            'budget_import_rules' => $this->importRuleMapper,
            'budget_accounts' => $this->accountMapper,
            'budget_categories' => $this->categoryMapper,
            'budget_settings' => $this->settingMapper,
            'budget_attachments' => $this->attachmentMapper,
        ] as $table => $mapper) {
            $mapper->method('deleteAll')->willReturnCallback(
                fn (string $userId) => $this->recordDelete($table, null, [$userId])
            );
        }

        $this->service = new FactoryResetService(
            $this->accountMapper,
            $this->transactionMapper,
            $this->billMapper,
            $this->categoryMapper,
            $this->importRuleMapper,
            $this->settingMapper,
            $this->attachmentMapper,
            $this->db,
        );
    }

    private function recordDelete(string $table, ?string $sql, array $params): int {
        if (isset($this->failingTables[$table])) {
            throw new \Exception($this->failingTables[$table]);
        }
        $this->deletes[] = ['table' => $table, 'sql' => $sql, 'params' => $params];
        return 1;
    }

    /** @return string[] */
    private function deletedTables(): array {
        return array_column($this->deletes, 'table');
    }

    private function registryTables(): array {
        return array_column(MigrationService::EXTRA_TABLES_PRE + MigrationService::EXTRA_TABLES_POST, 'table');
    }

    /**
     * Every table registered for backup must be wiped by a reset, scoped to
     * the user. The old hand-written list missed ten of them.
     */
    public function testResetClearsEveryRegisteredTableForTheUser(): void {
        $this->service->executeFactoryReset('user1');

        foreach ($this->registryTables() as $table) {
            $this->assertContains($table, $this->deletedTables(), "factory reset must clear $table");
        }
        foreach ($this->deletes as $delete) {
            $this->assertContains('user1', $delete['params'], "{$delete['table']} delete must be scoped to the user");
        }
    }

    public function testResetClearsTheBespokeEntitiesAndAttachmentRows(): void {
        $this->service->executeFactoryReset('user1');

        foreach (['budget_transactions', 'budget_bills', 'budget_import_rules', 'budget_accounts',
                  'budget_categories', 'budget_settings', 'budget_attachments'] as $table) {
            $this->assertContains($table, $this->deletedTables());
        }
    }

    /**
     * Transaction tags and splits are only reachable by joining through
     * transactions -> accounts; tag sets through categories. Deleting a
     * parent first orphans them for good (#359).
     */
    public function testJoinScopedTablesAreClearedBeforeTheirParents(): void {
        $this->service->executeFactoryReset('user1');
        $order = array_flip($this->deletedTables());

        foreach (MigrationService::EXTRA_TABLES_PRE + MigrationService::EXTRA_TABLES_POST as $spec) {
            if (($spec['scope'] ?? 'user') === 'user') {
                continue;
            }
            foreach ($spec['scope']['joins'] as [$parent]) {
                $this->assertLessThan($order[$parent], $order[$spec['table']],
                    "{$spec['table']} must be cleared before $parent");
            }
        }
        $this->assertLessThan($order['budget_accounts'], $order['budget_transactions'],
            'transactions are found through their account');
    }

    public function testTransactionTagsAreClearedThroughTheUsersTransactions(): void {
        $this->service->executeFactoryReset('user1');

        $tagDelete = array_values(array_filter($this->deletes, fn ($d) => $d['table'] === 'budget_transaction_tags'))[0];
        $this->assertStringContainsString('transaction_id IN (SELECT id FROM *PREFIX*budget_transactions', $tagDelete['sql']);
        $this->assertStringContainsString('budget_accounts WHERE user_id = ?', $tagDelete['sql']);
    }

    public function testExecuteFactoryResetCommitsAndReportsCounts(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $counts = $this->service->executeFactoryReset('user1');

        $this->assertSame(1, $counts['transactions']);
        $this->assertSame(1, $counts['accounts']);
        $this->assertSame(1, $counts['categories']);
        $this->assertSame(1, $counts['settings']);
        $this->assertSame(1, $counts['attachments']);
        $this->assertSame(1, $counts['transaction_tags']);
        $this->assertSame(1, $counts['tag_sets']);
    }

    public function testExecuteFactoryResetRollsBackOnError(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $this->failingTables['budget_expense_shares'] = 'DB error';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB error');

        $this->service->executeFactoryReset('user1');
    }

    public function testExecuteFactoryResetHandlesMissingTables(): void {
        $this->failingTables['budget_expense_shares'] = 'no such table: oc_budget_expense_shares';
        $this->failingTables['budget_pen_snaps'] = "Table 'nc.oc_budget_pen_snaps' doesn't exist";
        $this->failingTables['budget_attachments'] = 'no such table: oc_budget_attachments';

        $counts = $this->service->executeFactoryReset('user1');

        $this->assertSame(0, $counts['expense_shares']);
        $this->assertSame(0, $counts['pen_snaps']);
        $this->assertSame(0, $counts['attachments']);
        $this->assertSame(1, $counts['transactions']);
    }
}
