<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A bill's pre-booked row stands for an occurrence nobody has paid yet. It
 * used to be cleared by the scheduled job on its due date while the bill
 * stayed unpaid, so Mark Paid, auto-pay or an import match then booked the
 * same occurrence a second time, and Skip left the debit in place. Until
 * the bill settles it, the row is neither booked nor spending.
 */
class BillPlaceholderScopeTest extends IntegrationTestCase {
	private int $accountId;
	private int $food;

	protected function setUp(): void {
		parent::setUp();
		$this->accountId = $this->makeAccount(['name' => 'Current'])->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
	}

	public function testTheScheduledJobLeavesAnUnpaidBillsRowAlone(): void {
		$placeholder = $this->makeTransaction($this->accountId, ['status' => 'scheduled', 'date' => '2026-01-10', 'bill_id' => 999001]);
		$manual = $this->makeTransaction($this->accountId, ['status' => 'scheduled', 'date' => '2026-01-10']);

		$due = array_map(fn ($t) => $t->getId(), $this->service(TransactionMapper::class)->findScheduledDueForTransition());

		$this->assertContains($manual, $due, 'A manually entered future row still clears on its date');
		$this->assertNotContains($placeholder, $due, 'An unpaid bill row is the bill\'s to settle');
	}

	public function testAnUnpaidBillsPastRowIsNotSpending(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-02-01']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '500.00', 'date' => '2026-02-03',
			'status' => 'scheduled', 'bill_id' => 999002]);
		// A manual row whose date has passed counts before the job clears it
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '4.00', 'date' => '2026-02-04',
			'status' => 'scheduled']);

		$net = $this->service(TransactionReportQueries::class)->getCategoryNetByMonth($this->userId, '2026-01-01', '2026-03-31');

		$this->assertEqualsWithDelta(-14.0, $net[$this->food]['2026-02'], 0.001);
	}
}
