<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * budget_transactions.import_vendor, the payee an import stored (#399),
 * against the real database: written by create(), read back by
 * findImportComparables(), left alone by an edit, and carried by a backup.
 */
class ImportVendorColumnTest extends IntegrationTestCase {
	private function importVendorOf(int $id): ?string {
		$value = $this->db()->executeQuery('SELECT import_vendor FROM *PREFIX*budget_transactions WHERE id = ?', [$id])->fetchOne();
		return $value === null ? null : (string)$value;
	}

	private function isOracle(): bool {
		return $this->db()->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_ORACLE;
	}

	public function testItIsWrittenAndReadBackForTheComparison(): void {
		$account = $this->makeAccount()->getId();
		$service = $this->service(TransactionService::class);
		$withPayee = $service->create($this->userId, $account, '2026-10-06', 'Wolfcraft Drahtbürste', 9.95, 'debit',
			vendor: 'Wolfcraft', importId: 'hash_one', deferBalanceUpdate: true, importVendor: 'Wolfcraft')->getId();
		$none = $service->create($this->userId, $account, '2026-10-06', 'Dremel Trennscheibe 426', 12.40, 'debit',
			importId: 'hash_two', deferBalanceUpdate: true, importVendor: '')->getId();
		$typedIn = $this->makeTransaction($account, ['date' => '2026-10-06', 'vendor' => 'Shop']);

		$rows = array_column($this->service(TransactionMapper::class)->findImportComparables($account, '2026-10-01', '2026-10-31'), null, 'id');

		$this->assertSame('Wolfcraft', $rows[$withPayee]['import_vendor']);
		// Oracle stores an empty string as NULL: such a row is then compared
		// by its payee, as one imported before the column existed
		$this->assertSame($this->isOracle() ? null : '', $rows[$none]['import_vendor']);
		$this->assertNull($rows[$typedIn]['import_vendor']);
	}

	public function testEditingThePayeeLeavesIt(): void {
		$account = $this->makeAccount()->getId();
		$service = $this->service(TransactionService::class);
		$id = $service->create($this->userId, $account, '2026-10-06', 'Dremel Trennscheibe 426', 12.40, 'debit',
			vendor: 'DREMEL 426', importId: 'hash_two', deferBalanceUpdate: true, importVendor: 'DREMEL 426')->getId();

		$service->update($id, $this->userId, ['vendor' => 'Dremel', 'importVendor' => 'Dremel']);
		$service->bulkEdit($this->userId, [$id], ['vendor' => 'Hornbach']);

		$this->assertSame('DREMEL 426', $this->importVendorOf($id));
		$this->assertSame('Hornbach', $service->find($id, $this->userId)->getVendor());
	}

	public function testABackupRoundTripKeepsIt(): void {
		$account = $this->makeAccount()->getId();
		$this->makeTransaction($account, ['description' => 'Imported', 'vendor' => 'Dremel', 'import_id' => 'hash_a', 'import_vendor' => 'DREMEL 426']);
		$this->makeTransaction($account, ['description' => 'Before', 'vendor' => 'Shop', 'import_id' => 'hash_b']);
		$target = $this->newUserId();

		$migration = $this->service(MigrationService::class);
		$migration->importAll($target, $migration->exportAll($this->userId)['content']);

		$rows = $this->db()->executeQuery(
			'SELECT t.description, t.vendor, t.import_vendor FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id WHERE a.user_id = ? ORDER BY t.description',
			[$target]
		)->fetchAll();
		$this->assertSame(['Before', 'Imported'], array_column($rows, 'description'));
		$this->assertNull($rows[0]['import_vendor']);
		$this->assertSame('DREMEL 426', $rows[1]['import_vendor']);
		$this->assertSame('Dremel', $rows[1]['vendor']);
	}
}
