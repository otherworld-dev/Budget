<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000097Date20260825;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Version001000097Date20260825 deletes split, attachment, tag and expense
 * share rows whose transaction is gone (#359). It is a data-changing
 * migration that runs once on every install, so it is run here against real
 * rows: every orphan goes, every live child stays.
 *
 * The migration is global (it has no user scope), so this relies on the
 * suite running against a throwaway database with no other orphans in it.
 */
class OrphanCleanupMigrationTest extends IntegrationTestCase {
	private const CHILD_TABLES = [
		'budget_tx_splits',
		'budget_attachments',
		'budget_transaction_tags',
		'budget_expense_shares',
	];

	public function testRemovesOrphansAndKeepsChildrenOfLiveTransactions(): void {
		$account = $this->makeAccount()->getId();
		$category = $this->makeCategory();
		$live = $this->makeTransaction($account);
		$liveChildren = $this->hangChildrenOn($live, $category);

		$gone = $this->makeTransaction($account);
		$orphans = $this->hangChildrenOn($gone, $category);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_transactions WHERE id = ?', [$gone]);

		$messages = $this->runMigration();

		foreach ($orphans as [$table, $id]) {
			$this->assertNull($this->fetchRow($table, $id), "Orphan {$table} row {$id} was not removed");
		}
		foreach ($liveChildren as [$table, $id]) {
			$this->assertNotNull($this->fetchRow($table, $id), "{$table} row {$id} of a live transaction was removed");
		}
		foreach (self::CHILD_TABLES as $table) {
			$this->assertSame(0, $this->countOrphans($table, 'transaction_id'));
		}
		$this->assertContains('Removed 2 orphaned row(s) from budget_tx_splits', $messages);
		$this->assertContains('Removed 1 orphaned row(s) from budget_attachments', $messages);
	}

	public function testIsANoOpOnACleanDatabaseAndSafeToRunTwice(): void {
		$account = $this->makeAccount()->getId();
		$live = $this->makeTransaction($account);
		$children = $this->hangChildrenOn($live, $this->makeCategory());

		$first = $this->runMigration();
		$second = $this->runMigration();

		$this->assertSame([], $first);
		$this->assertSame([], $second);
		foreach ($children as [$table, $id]) {
			$this->assertNotNull($this->fetchRow($table, $id));
		}
	}

	public function testHandlesMoreOrphansThanOneDeleteBatch(): void {
		$account = $this->makeAccount()->getId();
		$gone = $this->makeTransaction($account);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_transactions WHERE id = ?', [$gone]);
		// Above the migration's 500-id chunk, so the batching is exercised
		for ($i = 0; $i < 520; $i++) {
			$this->makeSplit($gone, null, '1.00');
		}

		$messages = $this->runMigration();

		$this->assertSame(0, $this->countRows('budget_tx_splits', ['transaction_id' => $gone]));
		$this->assertContains('Removed 520 orphaned row(s) from budget_tx_splits', $messages);
	}

	/**
	 * @return string[] the info lines the migration printed
	 */
	private function runMigration(): array {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
			$messages[] = $message;
		});

		$migration = new Version001000097Date20260825($this->db());
		$migration->postSchemaChange($output, static fn () => $schema, []);

		return $messages;
	}
}
