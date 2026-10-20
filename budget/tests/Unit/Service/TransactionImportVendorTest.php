<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The payee an import stored (Transaction::$importVendor, #399): written when
 * the row is created, never by an edit, kept by a backup, and shown nowhere.
 */
class TransactionImportVendorTest extends TestCase {
	private TransactionMapper $mapper;
	private TransactionService $service;
	/** @var Transaction[] What the mapper was asked to write */
	private array $written = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(TransactionMapper::class);
		$this->mapper->method('insert')->willReturnCallback(function (Transaction $t) {
			$t->setId(count($this->written) + 1);
			$this->written[] = $t;
			return $t;
		});
		$this->mapper->method('update')->willReturnCallback(function (Transaction $t) {
			$this->written[] = $t;
			return $t;
		});
		$accountMapper = $this->createMock(AccountMapper::class);
		$account = new Account();
		$account->setId(10);
		$account->setUserId('user1');
		$account->setCurrency('EUR');
		$accountMapper->method('find')->willReturn($account);

		$this->service = new TransactionService(
			$this->mapper,
			$accountMapper,
			$this->createMock(\OCA\Budget\Db\TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new \OCA\Budget\Service\UserClock($this->createMock(\OCP\IConfig::class))
		);
	}

	private function imported(string $vendor, string $importVendor): Transaction {
		return $this->service->create('user1', 10, '2026-10-06', 'Dremel Trennscheibe 426', 12.40, 'debit',
			vendor: $vendor, importId: 'hash_abc', deferBalanceUpdate: true, importVendor: $importVendor);
	}

	public function testCreateStoresIt(): void {
		$this->imported('Wolfcraft', 'Wolfcraft');
		$this->assertSame('Wolfcraft', $this->written[0]->getImportVendor());
	}

	public function testARowTypedInHasNone(): void {
		$this->service->create('user1', 10, '2026-10-06', 'Coffee', 3.50, 'debit', vendor: 'Café', deferBalanceUpdate: true);
		$this->assertNull($this->written[0]->getImportVendor());
	}

	public function testEditingThePayeeLeavesItAlone(): void {
		$row = $this->imported('', '');
		$this->mapper->method('find')->willReturn($row);

		$this->service->update(1, 'user1', ['vendor' => 'Dremel', 'description' => 'Trennscheibe']);

		$updated = end($this->written);
		$this->assertSame('Dremel', $updated->getVendor());
		$this->assertSame('', $updated->getImportVendor());
	}

	/** Not even when an update names it: no edit route may change it */
	public function testAnUpdateCannotSetIt(): void {
		$row = $this->imported('Starbucks', 'Starbucks');
		$this->mapper->method('find')->willReturn($row);

		$this->service->update(1, 'user1', ['vendor' => 'Starbucks Zürich', 'importVendor' => 'Starbucks Zürich']);
		$this->service->bulkEdit('user1', [1], ['importVendor' => null]);

		$this->assertSame('Starbucks', end($this->written)->getImportVendor());
	}

	/** Nothing shows it: the web UI's JSON and the public v1 API leave it out */
	public function testItIsShownNowhere(): void {
		$row = $this->imported('Dremel', 'Dremel');

		$this->assertArrayNotHasKey('importVendor', $row->jsonSerialize());
		$api = ApiSerializer::transaction($row, 'EUR');
		$this->assertArrayNotHasKey('import_vendor', $api);
		$this->assertArrayNotHasKey('importVendor', $api);
	}

	// ===== backup =====

	/** A MigrationService over a database whose only rows are these transactions */
	private function migration(array $rows, ?TransactionMapper $transactionMapper = null): MigrationService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($rows) {
			$table = null;
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'orderBy', 'setMaxResults', 'delete', 'insert', 'update', 'set', 'setValue'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('from')->willReturnCallback(function (string $from) use (&$table, $qb) {
				$table = $from;
				return $qb;
			});
			$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeQuery')->willReturnCallback(function () use (&$table, $rows) {
				$result = $this->createMock(\OCP\DB\IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($table === 'budget_transactions' ? $rows : [], [false]));
				return $result;
			});
			$qb->method('executeStatement')->willReturn(0);
			return $qb;
		});
		$mappers = [];
		foreach ([CategoryMapper::class, AccountMapper::class, BillMapper::class, ImportRuleMapper::class, SettingMapper::class] as $class) {
			$mapper = $this->createMock($class);
			$mapper->method('findAll')->willReturn([]);
			$mappers[$class] = $mapper;
		}
		return new MigrationService($mappers[AccountMapper::class], $transactionMapper ?? $this->createMock(TransactionMapper::class),
			$mappers[CategoryMapper::class], $mappers[BillMapper::class], $mappers[ImportRuleMapper::class], $mappers[SettingMapper::class], $db);
	}

	private static function row(int $id, ?string $importVendor): array {
		return ['id' => $id, 'account_id' => 7, 'date' => '2026-10-06', 'description' => 'Dremel Trennscheibe 426',
			'vendor' => 'Dremel', 'amount' => '12.40', 'type' => 'debit', 'status' => 'cleared', 'is_split' => 0,
			'reconciled' => 0, 'import_id' => 'hash_abc', 'import_vendor' => $importVendor];
	}

	/** @return list<array<string, mixed>> transactions.json of an export */
	private function exported(MigrationService $service): array {
		$path = tempnam(sys_get_temp_dir(), 'test_export_');
		file_put_contents($path, $service->exportAll('user1')['content']);
		$zip = new \ZipArchive();
		$zip->open($path);
		$transactions = json_decode((string)$zip->getFromName('transactions.json'), true);
		$zip->close();
		unlink($path);
		return $transactions;
	}

	public function testABackupHoldsIt(): void {
		$transactions = $this->exported($this->migration([self::row(1, ''), self::row(2, 'Dremel Trennscheibe'), self::row(3, null)]));

		$this->assertSame(['', 'Dremel Trennscheibe', null], array_column($transactions, 'importVendor'));
		$this->assertSame('Dremel', $transactions[1]['vendor']);
	}

	/** @return Transaction[] What a restore of these archived rows inserts */
	private function restored(array $rows): array {
		$inserted = [];
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Transaction $t) use (&$inserted) {
			$t->setId(100 + count($inserted));
			$inserted[] = $t;
			return $t;
		});
		$service = $this->migration([], $mapper);
		$method = new \ReflectionMethod($service, 'importTransactions');
		$method->invoke($service, 'user1', $rows, ['accounts' => [7 => 5], 'categories' => []]);
		return $inserted;
	}

	public function testARestoreBringsItBack(): void {
		$archived = $this->exported($this->migration([self::row(1, ''), self::row(2, 'Dremel Trennscheibe'), self::row(3, null)]));

		$inserted = $this->restored($archived);

		$this->assertSame(['', 'Dremel Trennscheibe', null], array_map(fn (Transaction $t) => $t->getImportVendor(), $inserted));
		$this->assertSame(['Dremel', 'Dremel', 'Dremel'], array_map(fn (Transaction $t) => $t->getVendor(), $inserted));
	}

	/** A backup made before it existed restores as rows imported before then: not known */
	public function testAnOlderBackupRestoresWithoutIt(): void {
		$old = ['id' => 1, 'accountId' => 7, 'date' => '2026-10-06', 'description' => 'Dremel Trennscheibe 426',
			'vendor' => 'Dremel', 'amount' => 12.4, 'type' => 'debit', 'importId' => 'hash_abc'];

		$inserted = $this->restored([$old, ['importVendor' => ['not', 'a', 'string'], 'id' => 2] + $old]);

		$this->assertNull($inserted[0]->getImportVendor());
		$this->assertNull($inserted[1]->getImportVendor());
		$this->assertSame('hash_abc', $inserted[0]->getImportId());
	}
}
