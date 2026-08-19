<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRule;
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
				$this->assertTrue((bool) $b->getIsTransfer());
				$this->assertSame(2, $b->getAccountId());
				$this->assertSame(3, $b->getDestinationAccountId());
				$this->assertSame('HYP {month}', $b->getTransferDescriptionPattern());
				$this->assertTrue((bool) $b->getAutoPayEnabled());
				$this->assertSame(5, $b->getReminderDays());
				$this->assertSame([7, 8], $b->getTagIdsArray());
				$this->assertSame('2026-01-01', $b->getStartDate());
				$this->assertSame('2030-12-31', $b->getEndDate());
				$this->assertSame(12, $b->getRemainingPayments());
				$this->assertSame([['categoryId' => 100, 'percent' => 100]], $b->getSplitTemplateArray());
				$this->assertTrue((bool) $b->getExcludedFromForecast());
				$this->assertFalse((bool) $b->getCreateTransaction());
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
		$this->assertEquals('1.1.0', $manifest['version']);
		$this->assertEquals(1, $manifest['counts']['categories']);
		$this->assertEquals(1, $manifest['counts']['accounts']);

		$categories = json_decode($zip->getFromName('categories.json'), true);
		$this->assertCount(1, $categories);
		$this->assertEquals('Food', $categories[0]['name']);

		$zip->close();
		unlink($tempFile);
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
}
