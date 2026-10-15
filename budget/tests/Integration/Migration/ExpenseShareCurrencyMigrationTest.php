<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000123Date20261004;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Version001000123Date20261004 gives a split with a contact that was stored
 * without a currency (a transaction in an account shared with the user,
 * split 50/50 or from the transaction list) its account's currency. Run
 * against real rows: only rows without a currency change.
 */
class ExpenseShareCurrencyMigrationTest extends IntegrationTestCase {
	public function testFillsInTheAccountsCurrencyAndLeavesTheRestAlone(): void {
		$euro = $this->makeAccount(['currency' => 'EUR'])->getId();
		$pound = $this->makeAccount(['currency' => 'GBP'])->getId();
		$contact = $this->makeContact();
		$missing = $this->makeExpenseShare($this->makeTransaction($euro), $contact);
		$set = $this->makeExpenseShare($this->makeTransaction($euro), $contact);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_expense_shares SET currency = ? WHERE id = ?', ['USD', $set]);
		$other = $this->makeExpenseShare($this->makeTransaction($pound), $contact);
		$goneTx = $this->makeTransaction($pound);
		$orphan = $this->makeExpenseShare($goneTx, $contact);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_transactions WHERE id = ?', [$goneTx]);

		$this->runMigration();
		$this->runMigration();

		$this->assertSame('EUR', $this->fetchRow('budget_expense_shares', $missing)['currency']);
		$this->assertSame('USD', $this->fetchRow('budget_expense_shares', $set)['currency']);
		$this->assertSame('GBP', $this->fetchRow('budget_expense_shares', $other)['currency']);
		$this->assertNull($this->fetchRow('budget_expense_shares', $orphan)['currency']);
	}

	private function runMigration(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		$migration = new Version001000123Date20261004($this->db());
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
