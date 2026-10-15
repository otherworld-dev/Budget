<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The transfer search pairs a row with any same-amount, opposite-type row in
 * another account a few days away. It used to take a bill's pre-booked next
 * payment and the rows bills and incomes book as candidates too, so after an
 * import a salary and a rent bill of the same amount became a "transfer" and
 * the month's income read nothing.
 */
class TransferMatchingScopeTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private int $current;
	private int $joint;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->current = $this->makeAccount(['name' => 'Current'])->getId();
		$this->joint = $this->makeAccount(['name' => 'Joint'])->getId();
	}

	public function testOnlyRowsSomeoneEnteredOrImportedAreOffered(): void {
		$credit = $this->makeTransaction($this->current, ['type' => 'credit', 'amount' => '300.00', 'date' => '2026-03-14']);
		$plain = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-15']);
		$placeholder = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-16',
			'status' => 'scheduled', 'bill_id' => 999101]);
		$futureManual = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-16', 'status' => 'scheduled']);
		$billPayment = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-13', 'bill_id' => 999101]);
		$billNoted = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-13',
			'notes' => 'Auto-generated from bill: Rent']);
		$legacyNull = $this->makeTransaction($this->joint, ['amount' => '300.00', 'date' => '2026-03-15', 'status' => null]);

		$auto = $this->ids($this->mapper->findPotentialMatches($this->userId, $credit, $this->current, 300.0, 'credit', '2026-03-14', 'GBP'));
		$dialog = $this->ids($this->mapper->findPotentialMatches($this->userId, $credit, $this->current, 300.0, 'credit', '2026-03-14', 'GBP', 3, true, null, true));

		$this->assertEqualsCanonicalizing([$plain, $legacyNull], $auto);
		$this->assertEqualsCanonicalizing([$plain, $legacyNull, $billPayment, $billNoted], $dialog);
		foreach ([$placeholder, $futureManual] as $scheduled) {
			$this->assertNotContains($scheduled, $dialog, 'A scheduled row is never a transfer leg');
		}
	}

	public function testAnIncomesRowIsNotOfferedAutomatically(): void {
		$debit = $this->makeTransaction($this->joint, ['amount' => '1200.00', 'date' => '2026-03-30']);
		$salary = $this->makeTransaction($this->current, ['type' => 'credit', 'amount' => '1200.00', 'date' => '2026-03-28',
			'notes' => 'Auto-generated from income: Salary']);

		$this->assertSame([], $this->mapper->findPotentialMatches($this->userId, $debit, $this->joint, 1200.0, 'debit', '2026-03-30', 'GBP'));
		$this->assertSame([$salary], $this->ids($this->mapper->findPotentialMatches(
			$this->userId, $debit, $this->joint, 1200.0, 'debit', '2026-03-30', 'GBP', 3, false, null, true
		)));
	}

	public function testTheSweepStartsOnlyFromTheGivenRowsAndNeverFromABillsRow(): void {
		$imported = $this->makeTransaction($this->current, ['type' => 'credit', 'amount' => '50.00', 'date' => '2026-03-10']);
		$this->makeTransaction($this->joint, ['amount' => '50.00', 'date' => '2026-03-11']);
		// An older pair nobody imported just now
		$old = $this->makeTransaction($this->current, ['type' => 'credit', 'amount' => '70.00', 'date' => '2026-02-10']);
		$this->makeTransaction($this->joint, ['amount' => '70.00', 'date' => '2026-02-11']);
		// A bill's recorded payment with a same-amount credit next to it
		$billRow = $this->makeTransaction($this->joint, ['amount' => '90.00', 'date' => '2026-01-10', 'bill_id' => 999102]);
		$this->makeTransaction($this->current, ['type' => 'credit', 'amount' => '90.00', 'date' => '2026-01-10']);

		$scoped = $this->mapper->findUnlinkedWithMatches($this->userId, 3, 100, 0, null, [$imported, $billRow]);
		$everything = $this->mapper->findUnlinkedWithMatches($this->userId, 3, 100, 0);

		$this->assertSame([$imported], array_map(fn ($item) => (int)$item['transaction']['id'], $scoped['transactions']));
		$sources = array_map(fn ($item) => (int)$item['transaction']['id'], $everything['transactions']);
		$this->assertContains($old, $sources);
		$this->assertNotContains($billRow, $sources);
		foreach ($everything['transactions'] as $item) {
			$this->assertNotContains($billRow, array_map(fn ($m) => (int)$m['id'], $item['matches']));
		}
	}

	/**
	 * @param \OCA\Budget\Db\Transaction[] $transactions
	 * @return int[]
	 */
	private function ids(array $transactions): array {
		return array_map(fn ($tx) => (int)$tx->getId(), $transactions);
	}
}
