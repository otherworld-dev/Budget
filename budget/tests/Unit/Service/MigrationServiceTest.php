<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MigrationService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class MigrationServiceTest extends TestCase {
	private MigrationService $service;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private CategoryMapper $categoryMapper;
	private BillMapper $billMapper;
	private ImportRuleMapper $importRuleMapper;
	private SettingMapper $settingMapper;
	private IDBConnection $db;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->importRuleMapper = $this->createMock(ImportRuleMapper::class);
		$this->settingMapper = $this->createMock(SettingMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		// The table-level machinery (#351) runs raw query-builder statements;
		// these tests exercise structure, not SQL — give it inert builders
		$this->db->method('getQueryBuilder')->willReturnCallback(function () {
			$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
			$expr->method('eq')->willReturn('eq');
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'delete', 'insert', 'update', 'set', 'setValue', 'orderBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			$qb->method('executeStatement')->willReturn(0);
			return $qb;
		});
		$this->db->method('executeStatement')->willReturn(0);

		$this->service = new MigrationService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->categoryMapper,
			$this->billMapper,
			$this->importRuleMapper,
			$this->settingMapper,
			$this->db
		);
	}

	// ===== previewImport() =====

	public function testPreviewImportValidZip(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode([
				'version' => '1.0.0',
				'appId' => 'budget',
				'exportedAt' => '2026-03-01T00:00:00+00:00',
				'counts' => [],
			]),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
			'bills.json' => json_encode([]),
			'import_rules.json' => json_encode([]),
			'settings.json' => json_encode([]),
		]);

		$result = $this->service->previewImport($zipContent);

		$this->assertTrue($result['valid']);
		$this->assertEquals('1.0.0', $result['manifest']['version']);
		$this->assertEmpty($result['warnings']);
	}

	public function testPreviewImportWarnsOnNewerVersion(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode([
				'version' => '2.0.0',
				'appId' => 'budget',
			]),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$result = $this->service->previewImport($zipContent);

		$this->assertTrue($result['valid']);
		$this->assertNotEmpty($result['warnings']);
		$this->assertStringContainsString('newer', $result['warnings'][0]);
	}

	/**
	 * 3.0's archive carries columns 2.54 doesn't have, and 2.54 writes every
	 * key it finds, so the format version must move on for 2.54's preview to
	 * warn. Older backups still import here without a warning.
	 */
	public function testTheFormatVersionMovedOnAndOlderBackupsDoNotWarn(): void {
		$this->assertTrue(version_compare(MigrationService::EXPORT_VERSION, '1.2.0', '>'), 'The archive gained columns since 1.2.0');

		foreach (['1.0.0', '1.2.0', MigrationService::EXPORT_VERSION] as $version) {
			$result = $this->service->previewImport($this->createTestZip([
				'manifest.json' => json_encode(['version' => $version, 'appId' => 'budget']),
				'categories.json' => '[]',
				'accounts.json' => '[]',
				'transactions.json' => '[]',
			]));
			$this->assertSame([], $result['warnings'], "A $version backup previews without a warning");
		}

		$result = $this->service->previewImport($this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.4.0', 'appId' => 'budget']),
			'categories.json' => '[]',
			'accounts.json' => '[]',
			'transactions.json' => '[]',
		]));
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('1.4.0', $result['warnings'][0]);
	}

	public function testPreviewImportCountsEntities(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([
				['id' => 1, 'name' => 'Food', 'type' => 'expense'],
				['id' => 2, 'name' => 'Salary', 'type' => 'income'],
			]),
			'accounts.json' => json_encode([
				['id' => 1, 'name' => 'Checking', 'type' => 'checking'],
			]),
			'transactions.json' => json_encode([]),
		]);

		$result = $this->service->previewImport($zipContent);

		$this->assertEquals(2, $result['counts']['categories']);
		$this->assertEquals(1, $result['counts']['accounts']);
		$this->assertEquals(0, $result['counts']['transactions']);
	}

	// ===== importAll() - Validation =====

	public function testImportAllRejectsInvalidZip(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid ZIP file');

		$this->service->importAll('user1', 'not-a-zip');
	}

	public function testImportAllRejectsMissingManifest(): void {
		$zipContent = $this->createTestZip([
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required file: manifest.json');

		$this->service->importAll('user1', $zipContent);
	}

	public function testImportAllRejectsWrongApp(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'wrong_app']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('wrong application');

		$this->service->importAll('user1', $zipContent);
	}

	public function testImportAllRejectsInvalidCategory(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([
				['id' => 1, 'name' => '', 'type' => 'expense'],
			]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid category');

		$this->service->importAll('user1', $zipContent);
	}

	public function testImportAllRejectsInvalidAccount(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([
				['id' => 1, 'name' => 'Checking', 'type' => ''],
			]),
			'transactions.json' => json_encode([]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid account');

		$this->service->importAll('user1', $zipContent);
	}

	public function testImportAllRejectsInvalidTransaction(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([
				['accountId' => 1, 'amount' => 50.00],
			]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid transaction');

		$this->service->importAll('user1', $zipContent);
	}

	/**
	 * An archive whose files hold the wrong kinds of value (an id that is a
	 * list, a data set that is a string, a setting that is an object) used
	 * to crash the restore part way with a PHP TypeError: a 500 with a stack
	 * trace (R6-5). It is refused up front now, before anything is touched.
	 *
	 * @param array<string, mixed> $files file name => decoded content
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('typeConfusedArchives')]
	public function testImportRefusesATypeConfusedArchiveBeforeTouchingAnything(array $files): void {
		$zipContent = $this->createTestZip(array_map('json_encode', $files + [
			'manifest.json' => ['version' => '1.3.0', 'appId' => 'budget'],
			'categories.json' => [],
			'accounts.json' => [],
			'transactions.json' => [],
		]));
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->importAll('user1', $zipContent);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function typeConfusedArchives(): array {
		$account = ['id' => 1, 'name' => 'A', 'type' => 'checking'];
		return [
			'an account id that is a list' => [['accounts.json' => [['id' => [1]] + $account]]],
			'a transaction account id that is an object' => [['accounts.json' => [$account], 'transactions.json' => [['accountId' => ['a' => 1], 'amount' => 1, 'date' => '2026-01-01']]]],
			'a data set that is a string' => [['accounts.json' => 'not a list']],
			'a category that is a string' => [['categories.json' => ['x']]],
			'a setting that is an object' => [['settings.json' => ['k' => ['nested' => 1]]]],
			'a bill whose account is a list' => [['bills.json' => [['id' => 1, 'name' => 'Rent', 'accountId' => [1]]]]],
			'a table row whose foreign key is a list' => [['tx_splits.json' => [['id' => 1, 'transaction_id' => [5], 'amount' => '1.00']]]],
			'a manifest that is a string' => [['manifest.json' => 'budget']],
		];
	}

	/**
	 * Whatever goes wrong part way, PHP errors included, the restore's
	 * database transaction is rolled back: only \Exception used to be.
	 */
	public function testImportAllRollsBackOnAnyError(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.3.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);
		foreach ([$this->transactionMapper, $this->billMapper, $this->importRuleMapper, $this->accountMapper] as $mapper) {
			$mapper->method('findAll')->willReturn([]);
		}
		$this->categoryMapper->method('findAll')->willThrowException(new \TypeError('boom'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(\TypeError::class);
		$this->service->importAll('user1', $zipContent);
	}

	public function testImportAllRejectsInvalidJson(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => '{invalid json',
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid JSON');

		$this->service->importAll('user1', $zipContent);
	}

	// ===== importAll() - Success =====

	public function testImportAllClearsExistingDataAndImports(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([
				['id' => 100, 'name' => 'Food', 'type' => 'expense'],
			]),
			'accounts.json' => json_encode([
				['id' => 200, 'name' => 'Checking', 'type' => 'checking'],
			]),
			'transactions.json' => json_encode([
				['accountId' => 200, 'amount' => 50.00, 'date' => '2026-01-01', 'categoryId' => 100],
			]),
			'bills.json' => json_encode([]),
			'import_rules.json' => json_encode([]),
			'settings.json' => json_encode(['currency' => 'USD']),
		]);

		// Expect existing data to be cleared
		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		// Expect new data to be inserted with remapped IDs
		$insertedCategory = new Category();
		$insertedCategory->setId(1);
		$this->categoryMapper->expects($this->once())
			->method('insert')
			->willReturn($insertedCategory);

		$insertedAccount = new Account();
		$insertedAccount->setId(2);
		$this->accountMapper->expects($this->once())
			->method('insert')
			->willReturn($insertedAccount);

		$this->transactionMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Transaction $t) {
				return $t->getAccountId() === 2 && $t->getCategoryId() === 1;
			}));

		$this->settingMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Setting $s) {
				return $s->getKey() === 'currency' && $s->getValue() === 'USD';
			}));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->importAll('user1', $zipContent);

		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['counts']['categories']);
		$this->assertEquals(1, $result['counts']['accounts']);
		$this->assertEquals(1, $result['counts']['transactions']);
		$this->assertEquals(1, $result['counts']['settings']);
	}

	public function testImportAllPreservesAllBillFields(): void {
		// Restores used to copy only a handful of bill fields — custom
		// recurrence patterns, transfer routing, auto-pay and more were
		// silently dropped from every backup import
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([
				['id' => 200, 'name' => 'Checking', 'type' => 'checking'],
				['id' => 201, 'name' => 'Savings', 'type' => 'savings'],
			]),
			'transactions.json' => json_encode([]),
			'bills.json' => json_encode([
				[
					'id' => 300,
					'name' => 'Hypothek',
					'description' => 'Mortgage interest',
					'amount' => 2912.00,
					'frequency' => 'custom',
					'customRecurrencePattern' => '{"months":[3,6,9,12]}',
					'dueDay' => 28,
					'accountId' => 200,
					'destinationAccountId' => 201,
					'isTransfer' => true,
					'transferDescriptionPattern' => 'HYP {month}',
					'autoPayEnabled' => true,
					'reminderDays' => 5,
					'tagIds' => [7, 8],
					'startDate' => '2026-01-01',
					'endDate' => '2030-12-31',
					'remainingPayments' => 12,
					'splitTemplate' => [['categoryId' => 100, 'percent' => 100]],
					'excludedFromForecast' => true,
					'createTransaction' => false,
					'isActive' => true,
					'lastPaidDate' => '2026-06-28',
					'nextDueDate' => '2026-09-28',
				],
			]),
			'import_rules.json' => json_encode([]),
			'settings.json' => json_encode([]),
		]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		$this->accountMapper->method('insert')->willReturnCallback(function (Account $a) {
			static $i = 0;
			$a->setId([2, 3][$i++]);
			return $a;
		});

		$this->billMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Bill $b) {
				$this->assertSame('{"months":[3,6,9,12]}', $b->getCustomRecurrencePattern());
				$this->assertSame('Mortgage interest', $b->getDescription());
				$this->assertTrue((bool)$b->getIsTransfer());
				$this->assertSame(2, $b->getAccountId());
				$this->assertSame(3, $b->getDestinationAccountId());
				$this->assertSame('HYP {month}', $b->getTransferDescriptionPattern());
				$this->assertTrue((bool)$b->getAutoPayEnabled());
				$this->assertSame(5, $b->getReminderDays());
				// Tag ids remap through the imported tags (#351); this archive
				// carries none, so unmappable references are dropped rather
				// than kept as ids that mean nothing on this server
				$this->assertSame([], $b->getTagIdsArray());
				$this->assertSame('2026-01-01', $b->getStartDate());
				$this->assertSame('2030-12-31', $b->getEndDate());
				$this->assertSame(12, $b->getRemainingPayments());
				// The template's categories remap like every other category
				// reference; this archive carries no categories, so the part
				// stays and loses its category
				$this->assertSame([['categoryId' => null, 'percent' => 100]], $b->getSplitTemplateArray());
				$this->assertTrue((bool)$b->getExcludedFromForecast());
				$this->assertFalse((bool)$b->getCreateTransaction());
				$this->assertSame('2026-06-28', $b->getLastPaidDate());
				$this->assertSame('2026-09-28', $b->getNextDueDate());
				return true;
			}));

		$result = $this->service->importAll('user1', $zipContent);
		$this->assertTrue($result['success']);
	}

	public function testImportAllRollsBackOnError(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')
			->willThrowException(new \RuntimeException('DB error'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->service->importAll('user1', $zipContent);
	}

	// ===== importAll() - Category Topological Sort =====

	public function testImportAllHandlesParentChildCategories(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([
				['id' => 2, 'name' => 'Groceries', 'type' => 'expense', 'parentId' => 1],
				['id' => 1, 'name' => 'Food', 'type' => 'expense', 'parentId' => null],
			]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		$insertedParent = new Category();
		$insertedParent->setId(10);
		$insertedChild = new Category();
		$insertedChild->setId(11);

		$insertOrder = [];
		$this->categoryMapper->method('insert')
			->willReturnCallback(function (Category $c) use (&$insertOrder, $insertedParent, $insertedChild) {
				$insertOrder[] = $c->getName();
				if ($c->getName() === 'Food') {
					return $insertedParent;
				}
				// Child should have remapped parent ID
				$this->assertEquals(10, $c->getParentId());
				return $insertedChild;
			});

		$this->service->importAll('user1', $zipContent);

		$this->assertEquals(['Food', 'Groceries'], $insertOrder);
	}

	// ===== exportAll() =====

	public function testExportAllCreatesValidZip(): void {
		$category = $this->createMock(Category::class);
		$category->method('jsonSerialize')->willReturn(['id' => 1, 'name' => 'Food', 'type' => 'expense']);
		$this->categoryMapper->method('findAll')->willReturn([$category]);

		$account = $this->createMock(Account::class);
		$account->method('toArrayFull')->willReturn(['id' => 1, 'name' => 'Checking', 'type' => 'checking']);
		$this->accountMapper->method('findAll')->willReturn([$account]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);

		$setting = new Setting();
		$setting->setKey('currency');
		$setting->setValue('USD');
		$this->settingMapper->method('findAll')->willReturn([$setting]);

		$result = $this->service->exportAll('user1');

		$this->assertStringContainsString('budget_export_', $result['filename']);
		$this->assertEquals('application/zip', $result['contentType']);
		$this->assertNotEmpty($result['content']);

		// Verify the ZIP contents
		$tempFile = tempnam(sys_get_temp_dir(), 'test_export_');
		file_put_contents($tempFile, $result['content']);
		$zip = new \ZipArchive();
		$zip->open($tempFile);

		$manifest = json_decode($zip->getFromName('manifest.json'), true);
		$this->assertEquals('budget', $manifest['appId']);
		$this->assertEquals(MigrationService::EXPORT_VERSION, $manifest['version']);
		$this->assertEquals(1, $manifest['counts']['categories']);
		$this->assertEquals(1, $manifest['counts']['accounts']);

		$categories = json_decode($zip->getFromName('categories.json'), true);
		$this->assertCount(1, $categories);
		$this->assertEquals('Food', $categories[0]['name']);

		$zip->close();
		unlink($tempFile);
	}

	/**
	 * The export used to load every transaction as an entity and encode the
	 * lot in one go, so a ledger of about 110,000 rows ran out of a 512 MB
	 * memory limit and no backup could be made (T6-1). It now reads the
	 * ledger a page at a time, after the last id it wrote, and never asks
	 * the mapper for everything.
	 */
	public function testExportReadsTheLedgerAPageAtATime(): void {
		$this->transactionMapper->expects($this->never())->method('findAll');
		foreach ([$this->categoryMapper, $this->accountMapper, $this->billMapper, $this->importRuleMapper, $this->settingMapper] as $mapper) {
			$mapper->method('findAll')->willReturn([]);
		}
		$total = 1500;
		$pagesAfter = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($total, &$pagesAfter) {
			$state = ['table' => null, 'params' => []];
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'where', 'andWhere', 'innerJoin', 'orderBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('from')->willReturnCallback(function (string $table) use (&$state, $qb) {
				$state['table'] = $table;
				return $qb;
			});
			$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
			$qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$state) {
				$state['params'][] = $value;
				return ':p';
			});
			$qb->method('executeQuery')->willReturnCallback(function () use (&$state, $total, &$pagesAfter) {
				$rows = [];
				if ($state['table'] === 'budget_transactions') {
					$after = (int)end($state['params']);
					$pagesAfter[] = $after;
					for ($id = $after + 1; $id <= min($total, $after + 1000); $id++) {
						$rows[] = ['id' => $id, 'account_id' => 7, 'date' => '2026-01-01', 'description' => "Row $id",
							'amount' => '1.50', 'type' => 'debit', 'status' => 'cleared', 'is_split' => 0, 'reconciled' => 0];
					}
				}
				$result = $this->createMock(\OCP\DB\IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
				return $result;
			});
			return $qb;
		});
		$service = new MigrationService($this->accountMapper, $this->transactionMapper, $this->categoryMapper,
			$this->billMapper, $this->importRuleMapper, $this->settingMapper, $db);

		$archive = $this->readTestZip($service->exportAll('user1')['content']);

		$this->assertSame([0, 1000], $pagesAfter, 'Two pages, the second one after the last id of the first');
		$transactions = json_decode($archive['transactions.json'], true);
		$this->assertCount($total, $transactions);
		$this->assertSame(range(1, $total), array_column($transactions, 'id'));
		// The same shape the archive always had
		$this->assertSame(array_keys((new Transaction())->jsonSerialize()), array_keys($transactions[0]));
		$this->assertSame(1.5, $transactions[0]['amount']);
		$this->assertSame(7, $transactions[0]['accountId']);
		$this->assertSame($total, json_decode($archive['manifest.json'], true)['counts']['transactions']);
	}

	/**
	 * @return array<string, string> entry name => contents
	 */
	private function readTestZip(string $content): array {
		$path = tempnam(sys_get_temp_dir(), 'test_export_');
		file_put_contents($path, $content);
		$zip = new \ZipArchive();
		$zip->open($path);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$entries[$name] = $zip->getFromName($name);
		}
		$zip->close();
		unlink($path);
		return $entries;
	}

	// ===== Helpers =====

	// ===== complete coverage (#351) =====

	public function testImportTransactionsReturnsIdMapAndCollectsLinks(): void {
		$nextId = 100;
		$this->transactionMapper->method('insert')->willReturnCallback(function (Transaction $t) use (&$nextId) {
			$t->setId($nextId++);
			return $t;
		});

		$rows = [
			['accountId' => 1, 'date' => '2026-01-01', 'amount' => 50.0, 'type' => 'debit',
				'id' => 11, 'linkedTransactionId' => 12, 'billId' => 7],
			['accountId' => 1, 'date' => '2026-01-01', 'amount' => 50.0, 'type' => 'credit',
				'id' => 12, 'linkedTransactionId' => 11],
		];

		$method = new \ReflectionMethod($this->service, 'importTransactions');
		$method->setAccessible(true);
		$result = $method->invoke($this->service, 'user1', $rows, ['accounts' => [1 => 5], 'categories' => []]);

		$this->assertSame([11 => 100, 12 => 101], $result['map']);
		$this->assertSame([100 => 12, 101 => 11], $result['links']);
		$this->assertSame([100 => 7], $result['billRefs']);
	}

	public function testRemapRowRemapsAndDrops(): void {
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$method->setAccessible(true);
		$idMaps = ['transactions' => [11 => 100], 'categories' => [3 => 30], 'accounts' => [1 => 5]];

		$spec = ['fk' => [
			'transaction_id' => ['map' => 'transactions', 'onMissing' => 'drop'],
			'category_id' => ['map' => 'categories', 'onMissing' => 'null'],
		]];

		// Both mappable
		$row = $method->invoke($this->service, ['id' => 9, 'transaction_id' => 11, 'category_id' => 3, 'amount' => '5.00'], $spec, $idMaps);
		$this->assertSame(100, $row['transaction_id']);
		$this->assertSame(30, $row['category_id']);

		// Nullable fk unmappable -> null; row survives
		$row = $method->invoke($this->service, ['id' => 9, 'transaction_id' => 11, 'category_id' => 999], $spec, $idMaps);
		$this->assertNull($row['category_id']);

		// Required fk unmappable -> row dropped
		$row = $method->invoke($this->service, ['id' => 9, 'transaction_id' => 999, 'category_id' => 3], $spec, $idMaps);
		$this->assertNull($row);
	}

	public function testRemapRowHandlesJsonFks(): void {
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$method->setAccessible(true);
		$idMaps = ['accounts' => [1 => 5, 2 => 6]];

		$spec = ['jsonFk' => [
			'selected_debt_ids' => ['map' => 'accounts', 'shape' => 'idList'],
			'rate_overrides' => ['map' => 'accounts', 'shape' => 'idKeyedObject'],
		]];

		$row = $method->invoke($this->service, [
			'id' => 1,
			'selected_debt_ids' => json_encode([1, 2, 999]),
			'rate_overrides' => json_encode([1 => 4.5, 999 => 9.9]),
		], $spec, $idMaps);

		$this->assertSame([5, 6], json_decode($row['selected_debt_ids'], true));
		$this->assertSame([5 => 4.5], json_decode($row['rate_overrides'], true));
	}

	public function testRemapRowHandlesIdValuedObjects(): void {
		// ImportTemplate.account_mapping: sourceValue => accountId
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$method->setAccessible(true);
		$idMaps = ['accounts' => [1 => 5]];

		$spec = ['jsonFk' => [
			'account_mapping' => ['map' => 'accounts', 'shape' => 'idValuedObject'],
		]];

		$row = $method->invoke($this->service, [
			'id' => 1,
			'account_mapping' => json_encode(['Main Account' => 1, 'Old Account' => 999]),
		], $spec, $idMaps);

		$this->assertSame(['Main Account' => 5], json_decode($row['account_mapping'], true));
	}

	public function testImportBillsReturnsIdMapAndRemapsTagIds(): void {
		$nextId = 200;
		$this->billMapper->method('insert')->willReturnCallback(function (Bill $b) use (&$nextId) {
			$b->setId($nextId++);
			return $b;
		});

		$bills = [[
			'id' => 40, 'name' => 'Rent', 'amount' => 100.0,
			'accountId' => 1, 'tagIds' => [3, 999],
		]];
		$idMaps = ['accounts' => [1 => 5], 'categories' => [], 'tags' => [3 => 30]];

		$method = new \ReflectionMethod($this->service, 'importBills');
		$method->setAccessible(true);
		$map = $method->invoke($this->service, 'user1', $bills, $idMaps);

		$this->assertSame([40 => 200], $map);
	}

	// ===== markSplitParents() — restore resolves is_split from the imported parts (#360) =====

	/** A select QB whose fetch yields the given rows, recording bound params. */
	private function makeSelectQb(array $rows, array &$namedParams): \OCP\DB\QueryBuilder\IQueryBuilder {
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('in')->willReturn('in-expr');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) use (&$namedParams) {
			$namedParams[] = [$value, $type];
			return ':p';
		});
		$result = $this->createMock(\OCP\DB\IResult::class);
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	/** An update QB recording bound params; asserts UPDATE targets budget_transactions. */
	private function makeUpdateQb(array &$namedParams): \OCP\DB\QueryBuilder\IQueryBuilder {
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('in')->willReturn('in-expr');
		$expr->method('eq')->willReturn('eq-expr');
		$expr->method('isNull')->willReturn('isnull-expr');
		$expr->method('orX')->willReturn($this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class));
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) use (&$namedParams) {
			$namedParams[] = [$value, $type];
			return ':p';
		});
		$qb->expects($this->once())->method('update')->with('budget_transactions')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement');
		return $qb;
	}

	private function makeServiceWith(IDBConnection $db): MigrationService {
		return new MigrationService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->categoryMapper,
			$this->billMapper,
			$this->importRuleMapper,
			$this->settingMapper,
			$db
		);
	}

	public function testMarkSplitParentsResolvesTheFlagFromTheImportedParts(): void {
		// tx_splits imports through the generic table machinery with no idea
		// that a parent's is_split flag exists, and the flag written earlier
		// by importTransactions() can't be trusted (a pre-#351 backup carries
		// none at all, and a post-#351 restore of a pre-#351 archive claims
		// true for parts that never made it into the backup). This reads back
		// which of the freshly imported transactions actually got split rows,
		// marks exactly those true — and clears the claim on the ones that
		// got none, or a restore keeps manufacturing stray-true rows (#360).
		$db = $this->createMock(IDBConnection::class);

		$selectNamedParams = [];
		// Of the four freshly imported transactions, only 101 and 103
		// actually received a split row.
		$selectQb = $this->makeSelectQb([
			['transaction_id' => 101],
			['transaction_id' => 103],
		], $selectNamedParams);

		$trueNamedParams = [];
		$trueQb = $this->makeUpdateQb($trueNamedParams);
		$falseNamedParams = [];
		$falseQb = $this->makeUpdateQb($falseNamedParams);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $trueQb, $falseQb);

		$service = $this->makeServiceWith($db);

		$method = new \ReflectionMethod($service, 'markSplitParents');
		$method->setAccessible(true);
		$method->invoke($service, [11 => 100, 12 => 101, 13 => 102, 14 => 103]);

		// The SELECT looked across every freshly imported transaction id...
		$this->assertSame([[100, 101, 102, 103], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY], $selectNamedParams[0]);
		// ...one UPDATE set is_split = true on only the ones that came back...
		$this->assertSame([true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL], $trueNamedParams[0]);
		$this->assertSame([[101, 103], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY], $trueNamedParams[1]);
		// ...and the other cleared is_split on the ones that got no parts.
		$this->assertSame([false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL], $falseNamedParams[0]);
		$this->assertSame([[100, 102], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY], $falseNamedParams[1]);
	}

	public function testMarkSplitParentsClearsTheFlagWhenNoPartsCameBackAtAll(): void {
		// None of the freshly imported transactions had a split row (the
		// ordinary case, but also a restore of an archive made while the
		// splits table was missing from the backup registry, #351, whose
		// transactions still CLAIM is_split) — no true-update runs, and the
		// claims are cleared so the restore stops manufacturing stray-true
		// rows (#360).
		$db = $this->createMock(IDBConnection::class);

		$selectNamedParams = [];
		$selectQb = $this->makeSelectQb([], $selectNamedParams);

		$falseNamedParams = [];
		$falseQb = $this->makeUpdateQb($falseNamedParams);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $falseQb);

		$service = $this->makeServiceWith($db);

		$method = new \ReflectionMethod($service, 'markSplitParents');
		$method->setAccessible(true);
		$method->invoke($service, [11 => 100]);

		$this->assertSame([false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL], $falseNamedParams[0]);
		$this->assertSame([[100], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY], $falseNamedParams[1]);
	}

	public function testFilterRowColumnsRejectsHostileNames(): void {
		// Column names in an uploaded archive are attacker-controlled and are
		// used as SQL identifiers — anything but plain snake_case is dropped
		$method = new \ReflectionMethod($this->service, 'filterRowColumns');
		$method->setAccessible(true);

		$row = $method->invoke($this->service, [
			'currency' => 'GBP',
			'rate_per_eur' => '1.17',
			'evil"; DROP TABLE oc_users;--' => 'x',
			'Robert(); --' => 'y',
			'UPPER_CASE' => 'z',
			'0leading_digit' => 'w',
		]);

		$this->assertSame(['currency' => 'GBP', 'rate_per_eur' => '1.17'], $row);
	}

	public function testExtraTablesRegistryIsInternallyConsistent(): void {
		$refl = new \ReflectionClass(MigrationService::class);
		$pre = $refl->getConstant('EXTRA_TABLES_PRE');
		$post = $refl->getConstant('EXTRA_TABLES_POST');

		// Maps produced before each phase's entries run
		$produced = ['categories' => 1, 'accounts' => 1];
		$all = [];
		foreach ($pre as $key => $spec) {
			$all[$key] = $spec;
		}
		// Bespoke imports between the phases produce these
		$producedMid = ['transactions' => 1, 'tags' => 1, 'tag_sets' => 1, 'bills' => 1];
		foreach ($post as $key => $spec) {
			$all[$key] = $spec;
		}

		foreach ($pre as $key => $spec) {
			foreach (array_merge($spec['fk'] ?? [], $spec['jsonFk'] ?? []) as $col => $fkSpec) {
				$this->assertArrayHasKey($fkSpec['map'], $produced + ['tag_sets' => 1, 'tags' => 1],
					"pre-phase $key.$col references map '{$fkSpec['map']}' not yet produced");
			}
			if (isset($spec['idMap'])) {
				$produced[$spec['idMap']] = 1;
			}
		}
		$produced += $producedMid;
		foreach ($post as $key => $spec) {
			foreach (array_merge($spec['fk'] ?? [], $spec['jsonFk'] ?? []) as $col => $fkSpec) {
				$this->assertArrayHasKey($fkSpec['map'], $produced,
					"post-phase $key.$col references map '{$fkSpec['map']}' not yet produced");
			}
			foreach ($spec['jsonKeyFk'] ?? [] as $col => $keys) {
				foreach ($keys as $ref => $fkSpec) {
					$this->assertArrayHasKey($fkSpec['map'], $produced,
						"post-phase $key.$col.$ref references map '{$fkSpec['map']}' not yet produced");
				}
			}
			foreach ($spec['snapshotRefs'] ?? [] as $col => $refs) {
				foreach ($refs as $ref => $map) {
					$this->assertArrayHasKey($map, $produced,
						"post-phase $key.$col.$ref references map '$map' not yet produced");
				}
			}
			if (isset($spec['idMap'])) {
				$produced[$spec['idMap']] = 1;
			}
		}

		// Every entry names a real budget_ table and unique zip file key
		$this->assertSame(count($all), count(array_unique(array_keys($all))));
		foreach ($all as $key => $spec) {
			$this->assertStringStartsWith('budget_', $spec['table'], "$key table name");
		}
	}

	/**
	 * Both project tables round-trip, with their category and project ids
	 * remapped (#391). The consistency test above only catches ordering, not
	 * a table left out, which is how #351 lost tags and splits for years.
	 */
	public function testProjectsAreInTheBackupRegistry(): void {
		$post = (new \ReflectionClass(MigrationService::class))->getConstant('EXTRA_TABLES_POST');

		$this->assertSame('budget_projects', $post['projects']['table']);
		$this->assertSame('user', $post['projects']['scope']);
		$this->assertSame('projects', $post['projects']['idMap']);
		$this->assertSame('categories', $post['projects']['fk']['category_id']['map']);

		$this->assertSame('budget_project_allocs', $post['project_allocs']['table']);
		// Scoped by its own user_id: through budget_projects it would be
		// cleared after its parents were gone and never found again
		$this->assertSame('user', $post['project_allocs']['scope']);
		$this->assertSame('projects', $post['project_allocs']['fk']['project_id']['map']);
		$this->assertSame('categories', $post['project_allocs']['fk']['category_id']['map']);

		// Allocations import after the projects they remap to
		$keys = array_keys($post);
		$this->assertLessThan(array_search('project_allocs', $keys, true), array_search('projects', $keys, true));
	}

	/**
	 * Restoring over existing data used to delete transactions one at a time
	 * with the bare mapper, skipping deleteWithChildren(), and never touched
	 * budget_attachments (not in the registry) — every restore orphaned the
	 * user's attachment rows for good.
	 */
	public function testImportAllClearsAttachmentRowsAndDeletesTransactionsInBulk(): void {
		$deletedTables = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$deletedTables) {
			$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
			$expr->method('eq')->willReturn('eq');
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'insert', 'update', 'set', 'setValue'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('delete')->willReturnCallback(function (string $table) use (&$deletedTables, $qb) {
				$deletedTables[] = $table;
				return $qb;
			});
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			$qb->method('executeStatement')->willReturn(0);
			return $qb;
		});
		$db->method('executeStatement')->willReturn(0);
		$service = new MigrationService(
			$this->accountMapper, $this->transactionMapper, $this->categoryMapper,
			$this->billMapper, $this->importRuleMapper, $this->settingMapper, $db
		);

		$this->transactionMapper->expects($this->once())->method('deleteAll')->with('user1')->willReturn(3);
		$this->transactionMapper->expects($this->never())->method('delete');
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		$service->importAll('user1', $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]));

		$this->assertContains('budget_attachments', $deletedTables);
	}

	/** The same service with limits small enough to test without allocating hundreds of MB. */
	private function smallLimitService(): MigrationService {
		return new class($this->accountMapper, $this->transactionMapper, $this->categoryMapper, $this->billMapper, $this->importRuleMapper, $this->settingMapper, $this->db) extends MigrationService {
			public const MAX_ENTRY_BYTES = 1000;
			public const MAX_TOTAL_BYTES = 2500;
		};
	}

	private function backupWith(array $extra): string {
		return $this->createTestZip($extra + [
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => '[]',
			'accounts.json' => '[]',
			'transactions.json' => '[]',
		]);
	}

	/**
	 * A backup is untrusted input: a few KB of zip can unpack to gigabytes,
	 * and every entry used to be read whole into memory. An entry over the
	 * limit is refused before anything is read or deleted.
	 */
	public function testImportRefusesAnEntryThatUnpacksTooLarge(): void {
		// Highly compressible: tiny on disk, over the limit unpacked
		$content = $this->backupWith(['transactions.json' => '[' . str_repeat(' ', 1001) . ']']);

		$this->transactionMapper->expects($this->never())->method('deleteAll');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->smallLimitService()->importAll('user1', $content);
			$this->fail('An oversized entry must be refused');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('transactions.json is larger than', $e->getMessage());
		}
	}

	public function testImportRefusesEntriesThatTogetherUnpackTooLarge(): void {
		$content = $this->backupWith([
			'bills.json' => '[' . str_repeat(' ', 900) . ']',
			'import_rules.json' => '[' . str_repeat(' ', 900) . ']',
			'settings.json' => '{' . str_repeat(' ', 900) . '}',
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('add up to more than');
		$this->smallLimitService()->previewImport($content);
	}

	public function testABackupWithinTheLimitsStillPreviews(): void {
		$content = $this->backupWith(['bills.json' => '[' . str_repeat(' ', 900) . ']']);

		$this->assertTrue($this->smallLimitService()->previewImport($content)['valid']);
	}

	public function testTheRealLimitsAreGenerous(): void {
		$this->assertSame(200 * 1024 * 1024, MigrationService::MAX_ENTRY_BYTES);
		$this->assertGreaterThan(MigrationService::MAX_ENTRY_BYTES, MigrationService::MAX_TOTAL_BYTES);
	}

	private function createTestZip(array $files): string {
		$tempFile = tempnam(sys_get_temp_dir(), 'test_zip_');
		$zip = new \ZipArchive();
		$zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$content = file_get_contents($tempFile);
		unlink($tempFile);

		return $content;
	}

	/**
	 * Two flags the restore path used to drop on the floor (#372). The
	 * exclude-from-reports flag had been lost on every restore since #286:
	 * importAccounts() rebuilds the entity field by field and never copied it.
	 */
	public function testImportAllRestoresTheClosedAndExcludedFlags(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([]),
			'accounts.json' => json_encode([
				['id' => 200, 'name' => 'Old current', 'type' => 'checking', 'closed' => true, 'excludedFromReports' => true],
			]),
			'transactions.json' => json_encode([]),
			'bills.json' => json_encode([]),
			'import_rules.json' => json_encode([]),
			'settings.json' => json_encode([]),
		]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		$captured = null;
		$this->accountMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Account $a) use (&$captured) {
				$captured = $a;
				$a->setId(2);
				return $a;
			});

		$result = $this->service->importAll('user1', $zipContent);

		$this->assertTrue($result['success']);
		$this->assertTrue($captured->getClosed(), 'closed must survive a restore');
		$this->assertTrue($captured->getExcludedFromReports(), 'excludedFromReports must survive a restore');
	}

	/**
	 * The category flags were exported but never read back, so a restore put
	 * every category back into reports and budgets, undoing the Exclude from
	 * budgeting that creating a project ticks (#391), and dropped rollover.
	 */
	public function testImportAllRestoresTheCategoryFlags(): void {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => '1.0.0', 'appId' => 'budget']),
			'categories.json' => json_encode([
				['id' => 1, 'name' => 'Renovation', 'type' => 'expense', 'parentId' => null,
					'excludedFromReports' => true, 'excludedFromBudget' => true,
					'budgetRollover' => true, 'rolloverStart' => '2026-01'],
				['id' => 2, 'name' => 'Groceries', 'type' => 'expense', 'parentId' => null],
			]),
			'accounts.json' => json_encode([]),
			'transactions.json' => json_encode([]),
		]);

		$this->transactionMapper->method('findAll')->willReturn([]);
		$this->billMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([]);

		$captured = [];
		$this->categoryMapper->method('insert')
			->willReturnCallback(function (Category $c) use (&$captured) {
				$captured[$c->getName()] = $c;
				$c->setId(count($captured) + 10);
				return $c;
			});

		$result = $this->service->importAll('user1', $zipContent);

		$this->assertTrue($result['success']);
		$renovation = $captured['Renovation'];
		$this->assertTrue($renovation->getExcludedFromReports(), 'excludedFromReports must survive a restore');
		$this->assertTrue($renovation->getExcludedFromBudget(), 'excludedFromBudget must survive a restore');
		$this->assertTrue($renovation->getBudgetRollover(), 'budgetRollover must survive a restore');
		$this->assertSame('2026-01', $renovation->getRolloverStart());

		// An older backup without the keys restores the defaults
		$groceries = $captured['Groceries'];
		$this->assertFalse((bool)$groceries->getExcludedFromReports());
		$this->assertFalse((bool)$groceries->getExcludedFromBudget());
		$this->assertFalse((bool)$groceries->getBudgetRollover());
		$this->assertNull($groceries->getRolloverStart());
	}

	/**
	 * A pension schedule's Post now undo state names the contribution it
	 * recorded. Copied as it was, the old id named nothing after a restore,
	 * and Undo put the dates back while the money stayed, so the occurrence
	 * posted twice.
	 */
	public function testPensionScheduleUndoStateFollowsItsContribution(): void {
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$spec = MigrationService::EXTRA_TABLES_POST['pen_recur'];
		$idMaps = ['pensions' => [3 => 30], 'accounts' => [], 'pen_contribs' => [41 => 410]];
		$state = ['nextDueDate' => '2026-10-01', 'lastPostedDate' => null, 'isActive' => true,
			'contributionId' => 41, 'contributionDate' => '2026-10-02', 'amount' => 200.0];

		$row = $method->invoke($this->service, ['id' => 5, 'pension_id' => 3, 'post_undo_state' => json_encode($state)], $spec, $idMaps);
		$restored = json_decode($row['post_undo_state'], true);
		$this->assertSame(410, $restored['contributionId']);
		$this->assertSame('2026-10-01', $restored['nextDueDate']);

		// A contribution the backup doesn't hold: nothing left to undo
		$state['contributionId'] = 99;
		$row = $method->invoke($this->service, ['id' => 5, 'pension_id' => 3, 'post_undo_state' => json_encode($state)], $spec, $idMaps);
		$this->assertNotNull($row, 'The schedule itself is kept');
		$this->assertNull($row['post_undo_state']);

		// Not a snapshot at all
		$row = $method->invoke($this->service, ['id' => 5, 'pension_id' => 3, 'post_undo_state' => 'garbage'], $spec, $idMaps);
		$this->assertNull($row['post_undo_state']);
	}

	/**
	 * A restore keeps bank connections but gives every account a new id, so
	 * a mapping left alone pointed at nothing and bank sync silently stopped.
	 * It follows its account only when the backup holds that same account.
	 */
	public function testBankMappingsFollowTheirAccountOnlyWhenItIsTheSameOne(): void {
		$created = '2026-01-01 10:00:00';
		$archived = [
			['id' => 7, 'name' => 'Current', 'createdAt' => '2026-01-01T10:00:00+00:00'],
			['id' => 8, 'name' => 'Savings', 'createdAt' => $created],
		];
		$mappings = [
			// The same account, restored under a new id
			['id' => 1, 'accountId' => 7, 'name' => 'Current', 'createdAt' => $created],
			// Same id, but a different account (a backup from another server)
			['id' => 2, 'accountId' => 8, 'name' => 'Joint', 'createdAt' => $created],
			// An account the backup doesn't hold
			['id' => 3, 'accountId' => 9, 'name' => 'Old card', 'createdAt' => $created],
		];

		$targets = MigrationService::bankMappingTargets($mappings, $archived, [7 => 70, 8 => 80]);

		$this->assertSame([1 => 70, 2 => null, 3 => null], $targets);
	}

	/**
	 * Import rules lost their group, kept tag ids that no longer existed (a
	 * matching import row then failed after it was saved), and kept the old
	 * account id in conditions on the account, so they never matched again.
	 */
	public function testImportRulesComeBackWithTheirGroupAndRestoredIds(): void {
		$inserted = [];
		$this->importRuleMapper->method('insert')->willReturnCallback(function (\OCA\Budget\Db\ImportRule $r) use (&$inserted) {
			$r->setId(100 + count($inserted));
			$inserted[] = $r;
			return $r;
		});
		$idMaps = ['categories' => [3 => 30], 'accounts' => [1 => 10, 2 => 20], 'tags' => [7 => 70, 8 => 80]];
		$rule = [
			'id' => 5, 'name' => 'Coffee', 'pattern' => '', 'field' => 'description', 'matchType' => 'contains',
			'schemaVersion' => 2, 'groupName' => 'Eating out', 'categoryId' => 3,
			'actions' => ['version' => 2, 'actions' => [
				['type' => 'set_category', 'value' => 3],
				['type' => 'set_account', 'value' => 2],
				['type' => 'add_tags', 'value' => [7, 8, 999], 'behavior' => 'merge'],
				['type' => 'add_tags', 'value' => [999]],
				['type' => 'set_category', 'value' => 999],
				['type' => 'set_vendor', 'value' => 'Cafe'],
			]],
			'criteria' => ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => '1'],
				['type' => 'condition', 'field' => 'account', 'matchType' => 'equals', 'pattern' => '1'],
				['operator' => 'OR', 'conditions' => [
					['type' => 'condition', 'field' => 'account', 'matchType' => 'equals', 'pattern' => 2, 'negate' => true],
					['type' => 'condition', 'field' => 'account', 'matchType' => 'equals', 'pattern' => '999'],
				]],
			]]],
		];

		$method = new \ReflectionMethod($this->service, 'importImportRules');
		$method->invoke($this->service, 'user1', [$rule], $idMaps);

		$restored = $inserted[0];
		$this->assertSame('Eating out', $restored->getGroupName());
		$this->assertSame(30, $restored->getCategoryId());
		$this->assertSame([
			['type' => 'set_category', 'value' => 30],
			['type' => 'set_account', 'value' => 20],
			['type' => 'add_tags', 'value' => [70, 80], 'behavior' => 'merge'],
			['type' => 'set_vendor', 'value' => 'Cafe'],
		], $restored->getParsedActions()['actions'], 'Targets that did not come back are left out');

		$conditions = $restored->getParsedCriteria()['root']['conditions'];
		$this->assertSame('1', $conditions[0]['pattern'], 'Only account conditions are ids');
		$this->assertSame('10', $conditions[1]['pattern']);
		$this->assertSame(20, $conditions[2]['conditions'][0]['pattern']);
		$this->assertTrue($conditions[2]['conditions'][0]['negate']);
		$this->assertSame('0', $conditions[2]['conditions'][1]['pattern'], 'An account the backup does not hold matches nothing');
	}

	public function testLegacyFlatRuleActionsAreRemappedToo(): void {
		$inserted = [];
		$this->importRuleMapper->method('insert')->willReturnCallback(function (\OCA\Budget\Db\ImportRule $r) use (&$inserted) {
			$r->setId(100 + count($inserted));
			$inserted[] = $r;
			return $r;
		});
		$method = new \ReflectionMethod($this->service, 'importImportRules');
		$method->invoke($this->service, 'user1', [
			['id' => 1, 'name' => 'A', 'actions' => ['categoryId' => 3, 'vendor' => 'X']],
			['id' => 2, 'name' => 'B', 'categoryId' => 999, 'actions' => ['categoryId' => 999, 'vendor' => 'Y']],
		], ['categories' => [3 => 30], 'accounts' => []]);

		$this->assertSame(['categoryId' => 30, 'vendor' => 'X'], $inserted[0]->getParsedActions());
		$this->assertNull($inserted[1]->getCategoryId());
		$this->assertSame(['vendor' => 'Y'], $inserted[1]->getParsedActions());
		$this->assertNull($inserted[1]->getGroupName());
	}

	/**
	 * A saved report's account and tag filters name ids, which a restore
	 * changes; copied as they were the report filtered on nothing (or on
	 * someone else's account on another server).
	 */
	public function testSavedReportFiltersFollowTheRestoredIds(): void {
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$spec = MigrationService::EXTRA_TABLES_POST['saved_reports'];
		$config = ['reportType' => 'summary', 'accountIds' => [1, 2, 999], 'tagIds' => [7, 999], 'includeUntagged' => true];

		$row = $method->invoke($this->service, ['id' => 3, 'name' => 'Monthly', 'config' => json_encode($config)], $spec,
			['accounts' => [1 => 10, 2 => 20], 'tags' => [7 => 70]]);

		$restored = json_decode($row['config'], true);
		$this->assertSame([10, 20], $restored['accountIds']);
		$this->assertSame([70], $restored['tagIds']);
		$this->assertSame('summary', $restored['reportType']);
		$this->assertTrue($restored['includeUntagged']);

		// An older config without the keys, or one that isn't JSON, is left alone
		$row = $method->invoke($this->service, ['id' => 3, 'config' => '{"type":"summary"}'], $spec, ['accounts' => [], 'tags' => []]);
		$this->assertSame(['type' => 'summary'], json_decode($row['config'], true));
	}

	/**
	 * "In credit" says which way a liability's OPENING balance points, not
	 * today's balance. The restore signed today's balance with it and then
	 * worked the opening balance out from that, so any card or loan whose
	 * ledger had crossed zero came back on the wrong side: a card opened at
	 * 0 owed and now 40.22 in credit came back 40.22 owed with an opening
	 * balance of -80.44 (T3-1). The archive's own signed numbers stand, and
	 * the balance is rebuilt from the opening balance and the ledger.
	 */
	public function testALiabilityComesBackOnTheSameSideWhicheverWayItsLedgerWent(): void {
		$accounts = $this->restoreAccounts('1.3.0', [
			// Opened at 0 owed, now in credit
			['id' => 1, 'name' => 'Card', 'type' => 'credit_card', 'currency' => 'GBP', 'balance' => 40.22, 'openingBalance' => 0.0, 'liabilityInCredit' => false],
			// Opened in credit, now owing
			['id' => 2, 'name' => 'Loan', 'type' => 'loan', 'currency' => 'GBP', 'balance' => -20.0, 'openingBalance' => 10.0, 'liabilityInCredit' => true],
			// Owed, never declared either way (2.54.0 and older)
			['id' => 3, 'name' => 'Mortgage', 'type' => 'mortgage', 'currency' => 'GBP', 'balance' => -900.0, 'openingBalance' => -1000.0, 'liabilityInCredit' => null],
			// An asset is left as it was
			['id' => 4, 'name' => 'Current', 'type' => 'checking', 'currency' => 'GBP', 'balance' => -5.0, 'openingBalance' => 20.0],
		], [1 => 40.22, 2 => -30.0, 3 => 100.0, 4 => -25.0]);

		$this->assertSame(['opening' => 0.0, 'balance' => '40.22', 'inCredit' => false], $accounts['Card']);
		$this->assertSame(['opening' => 10.0, 'balance' => '-20.00', 'inCredit' => true], $accounts['Loan']);
		$this->assertSame(['opening' => -1000.0, 'balance' => '-900.00', 'inCredit' => null], $accounts['Mortgage']);
		$this->assertSame(['opening' => 20.0, 'balance' => '-5.00', 'inCredit' => null], $accounts['Current']);
	}

	/**
	 * Before format 1.1.0 a liability stored what was owed as a positive
	 * number. The upgrade to 1.1.0 negated positive liability balances and
	 * opening balances, and a restore of such a backup does the same, so it
	 * ends up as the upgraded account would. A backup older still, with no
	 * opening balance at all, keeps its balance and has the opening balance
	 * worked out from the ledger.
	 */
	public function testALegacyBackupsPositiveDebtsComeBackAsOwed(): void {
		$accounts = $this->restoreAccounts('1.0.0', [
			['id' => 1, 'name' => 'Card', 'type' => 'credit_card', 'currency' => 'GBP', 'balance' => 150.0, 'openingBalance' => 200.0],
			['id' => 2, 'name' => 'Loan', 'type' => 'loan', 'currency' => 'GBP', 'balance' => 300.0],
			['id' => 3, 'name' => 'Current', 'type' => 'checking', 'currency' => 'GBP', 'balance' => 80.0, 'openingBalance' => 100.0],
		], [1 => 50.0, 2 => 25.0, 3 => -20.0]);

		$this->assertSame(['opening' => -200.0, 'balance' => '-150.00', 'inCredit' => null], $accounts['Card']);
		$this->assertSame(['opening' => -325.0, 'balance' => '-300.00', 'inCredit' => null], $accounts['Loan']);
		$this->assertSame(['opening' => 100.0, 'balance' => '80.00', 'inCredit' => null], $accounts['Current']);
	}

	/**
	 * Restore $archived (accounts with no transactions in the archive; the
	 * ledger's net per old account id is given) and report each account's
	 * final opening balance, stored balance and in-credit flag by name.
	 *
	 * @param array<int, array<string, mixed>> $archived
	 * @param array<int, float> $netByOldId
	 * @return array<string, array{opening: float|null, balance: string|null, inCredit: bool|null}>
	 */
	private function restoreAccounts(string $version, array $archived, array $netByOldId): array {
		$zipContent = $this->createTestZip([
			'manifest.json' => json_encode(['version' => $version, 'appId' => 'budget']),
			'categories.json' => '[]',
			'accounts.json' => json_encode($archived),
			'transactions.json' => '[]',
		]);
		foreach ([$this->transactionMapper, $this->billMapper, $this->importRuleMapper, $this->accountMapper, $this->categoryMapper] as $mapper) {
			$mapper->method('findAll')->willReturn([]);
		}

		/** @var array<int, Account> $byNewId */
		$byNewId = [];
		$netByNewId = [];
		$balances = [];
		$this->accountMapper->method('insert')->willReturnCallback(function (Account $a) use (&$byNewId, &$netByNewId, $archived, $netByOldId) {
			$id = 100 + count($byNewId);
			$a->setId($id);
			$byNewId[$id] = clone $a;
			foreach ($archived as $row) {
				if ($row['name'] === $a->getName()) {
					$netByNewId[$id] = $netByOldId[$row['id']];
				}
			}
			return $a;
		});
		$this->accountMapper->method('findById')->willReturnCallback(function (int $id) use (&$byNewId) {
			return clone $byNewId[$id];
		});
		$this->accountMapper->method('update')->willReturnCallback(function (Account $a) use (&$byNewId, &$balances) {
			$byNewId[$a->getId()] = clone $a;
			$balances[$a->getId()] = sprintf('%.2f', $a->getBalance());
			return $a;
		});
		$this->accountMapper->method('updateBalance')->willReturnCallback(function (int $id, $balance) use (&$byNewId, &$balances) {
			$balances[$id] = (string)$balance;
			return $byNewId[$id];
		});
		$this->transactionMapper->method('getNetChangeAll')->willReturnCallback(function (int $id) use (&$netByNewId) {
			return $netByNewId[$id];
		});

		$this->service->importAll('user1', $zipContent);

		$result = [];
		foreach ($byNewId as $id => $account) {
			$result[$account->getName()] = [
				'opening' => $account->getOpeningBalance(),
				'balance' => $balances[$id] ?? null,
				'inCredit' => $account->getLiabilityInCredit(),
			];
		}
		return $result;
	}
}
