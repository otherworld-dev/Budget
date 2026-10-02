<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A bill paid by linking a bank row, then deleted and set up again, never
 * offered that row in the new bill's Mark Paid: the row still carried the
 * dead bill's id, and only rows with no bill are candidates. The user then
 * recorded the payment a second time.
 */
class DeletedBillPaymentsTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->accountId = $this->makeAccount()->getId();
	}

	private function makeBill(string $name): int {
		return $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => $name, 'amount' => '120.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'is_active' => true, 'created_at' => $this->now(),
		]);
	}

	public function testAPaymentOfABillThatNoLongerExistsIsACandidateAgain(): void {
		$live = $this->makeBill('Water');
		$livePayment = $this->makeTransaction($this->accountId, ['date' => '2026-03-27', 'amount' => '120.00', 'bill_id' => $live]);
		// A bill id with no bill behind it, as a delete before this fix left
		$orphaned = $this->makeTransaction($this->accountId, ['date' => '2026-03-27', 'amount' => '120.00', 'bill_id' => 2147480001]);
		$free = $this->makeTransaction($this->accountId, ['date' => '2026-03-28', 'amount' => '120.00']);

		$ids = array_map(fn ($tx) => (int)$tx->getId(), $this->mapper->findBillPaymentCandidates($this->accountId, '2026-03-30'));

		$this->assertEqualsCanonicalizing([$orphaned, $free], $ids);
		$this->assertNotContains($livePayment, $ids);
	}

	public function testDetachingOnlyTouchesTheDeletedBillsRows(): void {
		$deleted = $this->makeBill('Rent');
		$other = $this->makeBill('Water');
		$paid = $this->makeTransaction($this->accountId, ['bill_id' => $deleted]);
		$kept = $this->makeTransaction($this->accountId, ['bill_id' => $other]);

		$this->assertSame(1, $this->mapper->detachFromBill($deleted));

		$this->assertNull($this->fetchRow('budget_transactions', $paid)['bill_id']);
		$this->assertSame($other, (int)$this->fetchRow('budget_transactions', $kept)['bill_id']);
	}
}
