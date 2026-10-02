<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\PensionLegQueries;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The two records of one pension payment: the bank leg the app books and
 * the bank's own row from an import or bank sync.
 */
class PensionLegQueriesTest extends IntegrationTestCase {
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->accountId = $this->makeAccount()->getId();
	}

	public function testImportedCandidatesAreUnclaimedImportedRowsOfTheSameAmount(): void {
		$match = $this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-1', 'description' => 'NEST']);
		// Not candidates: typed in by hand, another amount, the other direction,
		// outside the window, already a pension leg, a bill's, a transfer leg, scheduled
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00']);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.01', 'import_id' => 'csv-2']);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-3', 'type' => 'credit']);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-20', 'amount' => '200.00', 'import_id' => 'csv-4']);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-5', 'pension_contrib_id' => 999001]);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-6', 'bill_id' => 999002]);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-7', 'linked_transaction_id' => 999003]);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-02', 'amount' => '200.00', 'import_id' => 'csv-8', 'status' => 'scheduled']);

		$rows = $this->service(PensionLegQueries::class)->findImportedCandidates($this->accountId, 'debit', 200.0, '2026-09-26', '2026-10-06');

		$this->assertSame([$match], array_column($rows, 'id'));
		$this->assertSame('2026-10-02', $rows[0]['date']);
		$this->assertSame('NEST', $rows[0]['description']);
	}

	public function testAppCreatedLegsArePensionLegsWithNoImportId(): void {
		$leg = $this->makeTransaction($this->accountId, ['date' => '2026-10-01', 'amount' => '200.00', 'pension_contrib_id' => 999004]);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-01', 'amount' => '200.00', 'pension_contrib_id' => 999005, 'import_id' => 'csv-9']);
		$this->makeTransaction($this->accountId, ['date' => '2026-10-01', 'amount' => '200.00']);

		$rows = $this->service(PensionLegQueries::class)->findAppCreatedLegs($this->accountId, '2026-09-25', '2026-10-07');

		$this->assertSame([$leg], array_column($rows, 'id'));
		$this->assertSame(999004, $rows[0]['pensionContribId']);
		$this->assertEqualsWithDelta(200.0, $rows[0]['amount'], 0.001);
		$this->assertFalse($rows[0]['reconciled']);
	}
}
