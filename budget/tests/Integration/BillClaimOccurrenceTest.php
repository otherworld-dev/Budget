<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\BillService;

/**
 * The conditional write that settles one occurrence (#399), on the real
 * database. Two requests that both read a bill as due race for it, and only
 * the first claim matches the row. MySQL has to count the rows the WHERE
 * matched, not the ones it changed, which is how Nextcloud connects to it
 * (CLIENT_FOUND_ROWS); on sqlite and PostgreSQL that is the only count.
 */
class BillClaimOccurrenceTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
	}

	/** A monthly bill on the 15th, paid in September and due in October, pre-booking */
	private function bill(array $overrides = []): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '30.00', 'frequency' => 'monthly', 'due_day' => 15,
			'account_id' => $this->account, 'is_active' => true, 'next_due_date' => '2026-10-15', 'last_paid_date' => '2026-09-15',
			'auto_pay_enabled' => false, 'auto_pay_failed' => false, 'create_transaction' => true, 'created_at' => $this->now(),
		]);
	}

	private function day(?string $value): ?string {
		return $value === null ? null : substr($value, 0, 10);
	}

	public function testOnlyTheFirstClaimOfAnOccurrenceWins(): void {
		$bill = $this->bill();
		$mapper = $this->service(BillMapper::class);
		$fields = ['next_due_date' => '2026-11-15', 'last_paid_date' => '2026-10-15', 'is_active' => true, 'remaining_payments' => null];

		$this->assertTrue($mapper->claimOccurrence($bill, $this->userId, '2026-10-15', $fields));
		$this->assertFalse($mapper->claimOccurrence($bill, $this->userId, '2026-10-15', $fields));

		$row = $this->fetchRow('budget_bills', $bill);
		$this->assertSame('2026-11-15', $this->day($row['next_due_date']));
		$this->assertSame('2026-10-15', $this->day($row['last_paid_date']));
	}

	public function testAClaimThatEndsTheBillIsTakenOnce(): void {
		// A one-time bill: the claim switches it off and clears its date
		$bill = $this->bill(['frequency' => 'one-time', 'start_date' => '2026-10-15']);
		$mapper = $this->service(BillMapper::class);
		$fields = ['next_due_date' => null, 'last_paid_date' => '2026-10-14', 'is_active' => false, 'remaining_payments' => null];

		$this->assertTrue($mapper->claimOccurrence($bill, $this->userId, '2026-10-15', $fields));
		$this->assertFalse($mapper->claimOccurrence($bill, $this->userId, '2026-10-15', $fields));

		$row = $this->fetchRow('budget_bills', $bill);
		$this->assertNull($row['next_due_date']);
		$this->assertFalse((bool)$row['is_active']);
	}

	public function testAPausedBillOrSomeoneElsesIsNotClaimed(): void {
		$paused = $this->bill(['is_active' => false]);
		$mapper = $this->service(BillMapper::class);
		$fields = ['next_due_date' => '2026-11-15'];

		$this->assertFalse($mapper->claimOccurrence($paused, $this->userId, '2026-10-15', $fields));
		$this->assertFalse($mapper->claimOccurrence($this->bill(), 'someone-else', '2026-10-15', $fields));
		$this->assertSame('2026-10-15', $this->day($this->fetchRow('budget_bills', $paused)['next_due_date']));
	}

	public function testConcurrentClaimsOnAPreBookingBillBookOnce(): void {
		// Mark Paid twice, both naming 15 October: one payment, and one row
		// pre-booked for November
		$bill = $this->bill();
		$this->makeTransaction($this->account, [
			'date' => '2026-10-15', 'amount' => '30.00', 'status' => 'scheduled', 'bill_id' => $bill,
			'notes' => 'Auto-generated from bill: Phone', 'description' => '',
		]);
		$service = $this->service(BillService::class);

		$service->markPaid($bill, $this->userId, '2026-10-15', true, null, '2026-10-15');
		try {
			$service->markPaid($bill, $this->userId, '2026-10-15', true, null, '2026-10-15');
			$this->fail('October was paid twice');
		} catch (\InvalidArgumentException $e) {
		}

		$qb = $this->db()->getQueryBuilder();
		$qb->select('date', 'status')->from('budget_transactions')
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill)))
			->orderBy('date', 'ASC');
		$rows = array_map(fn (array $r) => [$this->day($r['date']), $r['status']], $qb->executeQuery()->fetchAll());
		$this->assertSame([['2026-10-15', 'cleared'], ['2026-11-15', 'scheduled']], $rows);
		$this->assertSame('2026-11-15', $this->day($this->fetchRow('budget_bills', $bill)['next_due_date']));
	}

	public function testOnlyTheFirstClaimOfAnIncomePaymentWins(): void {
		$income = $this->insertRow('budget_recurring_income', [
			'user_id' => $this->userId, 'name' => 'Salary', 'amount' => '2500.00', 'frequency' => 'monthly', 'expected_day' => 25,
			'account_id' => $this->account, 'is_active' => true, 'next_expected_date' => '2026-10-25', 'created_at' => $this->now(),
		]);
		$mapper = $this->service(RecurringIncomeMapper::class);
		$fields = ['next_expected_date' => '2026-11-25', 'last_received_date' => '2026-10-24', 'is_active' => true];

		$this->assertTrue($mapper->claimOccurrence($income, $this->userId, '2026-10-25', $fields));
		$this->assertFalse($mapper->claimOccurrence($income, $this->userId, '2026-10-25', $fields));
		$this->assertSame('2026-11-25', $this->day($this->fetchRow('budget_recurring_income', $income)['next_expected_date']));
	}
}
