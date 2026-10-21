<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000126Date20261021;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Migration 126's backfill against the real database (#399): the rows a
 * file import stored take their payee as import_vendor ('' for none), so
 * an edit made after the update is followed on a re-import; every other row
 * stays NULL, and a second run changes nothing.
 */
class ImportVendorBackfillTest extends IntegrationTestCase {
	private function runMigration(): void {
		// getTable() is declared to return Doctrine's Table on older servers
		// and OCP\DB\Schema\ITable on newer ones: hand back a mock of
		// whichever this server declares
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$tableClass = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
		if ($tableClass !== null) {
			$table = $this->createMock($tableClass);
			$table->method('hasColumn')->willReturn(true);
		} else {
			$table = new class {
				public function hasColumn(string $name): bool {
					return true;
				}
			};
		}
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		(new Version001000126Date20261021($this->db()))->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function importVendorOf(int $id): ?string {
		$value = $this->db()->executeQuery('SELECT import_vendor FROM *PREFIX*budget_transactions WHERE id = ?', [$id])->fetchOne();
		return $value === null || $value === false ? null : (string)$value;
	}

	public function testOnlyTheRowsAFileImportStoredAreFilled(): void {
		$account = $this->makeAccount()->getId();
		$rows = [
			'csv' => $this->makeTransaction($account, ['import_id' => 'hash_1a2b', 'vendor' => 'Starbucks']),
			'csv repeat' => $this->makeTransaction($account, ['import_id' => 'hash_1a2b_occ2', 'vendor' => 'Starbucks']),
			'ofx' => $this->makeTransaction($account, ['import_id' => 'ofx_fitid_20261014001', 'vendor' => 'COOP CITY']),
			'csv, empty payee' => $this->makeTransaction($account, ['import_id' => 'hash_3c4d', 'vendor' => '']),
			'csv, no payee' => $this->makeTransaction($account, ['import_id' => 'hash_5e6f', 'vendor' => null]),
			'csv, already kept' => $this->makeTransaction($account, ['import_id' => 'hash_7a8b', 'vendor' => 'Dremel', 'import_vendor' => 'DREMEL 426']),
			'bank sync' => $this->makeTransaction($account, ['import_id' => 'gocardless:abc', 'vendor' => 'Tesco']),
			'typed in' => $this->makeTransaction($account, ['vendor' => 'Bakery']),
			'not a hash' => $this->makeTransaction($account, ['import_id' => 'hashX1', 'vendor' => 'Kiosk']),
		];

		$this->runMigration();

		$this->assertSame('Starbucks', $this->importVendorOf($rows['csv']));
		$this->assertSame('Starbucks', $this->importVendorOf($rows['csv repeat']));
		$this->assertSame('COOP CITY', $this->importVendorOf($rows['ofx']));
		// Oracle stores '' as NULL: there such a row stays NULL, as before
		$none = $this->db()->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_ORACLE ? null : '';
		$this->assertSame($none, $this->importVendorOf($rows['csv, empty payee']));
		$this->assertSame($none, $this->importVendorOf($rows['csv, no payee']));
		$this->assertSame('DREMEL 426', $this->importVendorOf($rows['csv, already kept']));
		$this->assertNull($this->importVendorOf($rows['bank sync']));
		$this->assertNull($this->importVendorOf($rows['typed in']));
		$this->assertNull($this->importVendorOf($rows['not a hash']), 'The _ in the prefix is not a wildcard');
	}

	public function testASecondRunChangesNothing(): void {
		$account = $this->makeAccount()->getId();
		$id = $this->makeTransaction($account, ['import_id' => 'hash_9c0d', 'vendor' => 'Starbucks']);
		$noPayee = $this->makeTransaction($account, ['import_id' => 'hash_9c0e', 'vendor' => null]);
		$this->runMigration();

		// The payees edited after the update; the second run must not take them
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET vendor = ? WHERE id = ?', ['Starbucks Zürich', $id]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET vendor = ? WHERE id = ?', ['Dremel', $noPayee]);
		$this->runMigration();

		$this->assertSame('Starbucks', $this->importVendorOf($id));
		if ($this->db()->getDatabaseProvider() !== \OCP\IDBConnection::PLATFORM_ORACLE) {
			$this->assertSame('', $this->importVendorOf($noPayee));
		}
	}
}
