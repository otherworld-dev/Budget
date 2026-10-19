<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

use OCA\Budget\Service\BillService;
use OCA\Budget\Service\TransactionService;

/**
 * Link to bill, then Mark Paid, on the real database (#399).
 *
 * Linking a row to a bill files it as the bill's payment without moving the
 * bill on, so the occurrence still shows as due. Mark Paid has to see that
 * payment and offer it (the 409 the page turns into "already has a payment")
 * rather than book a second one, and using it must book nothing.
 */
class LinkedPaymentThenMarkPaidTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
	}

	private function day(?string $value): ?string {
		return $value === null ? null : substr($value, 0, 10);
	}

	/** @return array<int, array{0: ?string, 1: string}> the bill's rows as [date, status], by date */
	private function rowsOf(int $bill): array {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('id', 'date', 'status')->from('budget_transactions')
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill)))
			->orderBy('date', 'ASC')->addOrderBy('id', 'ASC');
		return array_map(fn (array $r) => [$this->day($r['date']), $r['status']], $qb->executeQuery()->fetchAll());
	}

	public function testALinkedPaymentOfAOneTimeBillIsOfferedAndUsingItBooksNothing(): void {
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Corner Card SA', 'amount' => '100.00', 'frequency' => 'one-time',
			'due_day' => 28, 'due_month' => 10, 'start_date' => '2026-10-28', 'account_id' => $this->account,
			'is_active' => true, 'next_due_date' => '2026-10-28', 'auto_pay_enabled' => false, 'auto_pay_failed' => false,
			'create_transaction' => false, 'created_at' => $this->now(),
		]);
		$typed = $this->makeTransaction($this->account, [
			'date' => '2026-10-27', 'amount' => '100.00', 'type' => 'debit', 'status' => 'cleared', 'description' => 'Monatsrechnung',
		]);

		$this->service(TransactionService::class)->attachToBill($typed, $bill, $this->userId);
		$bills = $this->service(BillService::class);

		$offered = $bills->recordedPaymentForPendingOccurrence($bill, $this->userId, '2026-10-28');
		$this->assertNotNull($offered, 'Mark Paid has to see the linked payment');
		$this->assertSame($typed, $offered['id']);
		$this->assertSame('2026-10-27', $offered['date']);

		// "Use that payment": nothing recorded, dated on the payment's day
		$result = $bills->markPaid($bill, $this->userId, '2026-10-27', false, null, '2026-10-28');

		$this->assertTrue($result['paymentTransactionRecorded']);
		$this->assertSame([['2026-10-27', 'cleared']], $this->rowsOf($bill));
		$row = $this->fetchRow('budget_bills', $bill);
		$this->assertFalse((bool)$row['is_active']);
		$this->assertSame('2026-10-27', $this->day($row['last_paid_date']));
		$snapshot = json_decode((string)$row['paid_undo_state'], true);
		$this->assertSame($typed, $snapshot['usedTransactionId'] ?? null);
		$this->assertSame([], array_filter(
			$bills->findUnrecordedPayments($this->userId),
			fn (array $item) => (int)($item['billId'] ?? 0) === $bill
		), 'The bill is not listed as paid without a transaction');
	}

	public function testALinkedPaymentOfAPreBookingBillTakesThePlaceOfItsPlaceholder(): void {
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '30.00', 'frequency' => 'monthly', 'due_day' => 28,
			'account_id' => $this->account, 'is_active' => true, 'next_due_date' => '2026-10-28', 'last_paid_date' => '2026-09-28',
			'auto_pay_enabled' => false, 'auto_pay_failed' => false, 'create_transaction' => true, 'created_at' => $this->now(),
		]);
		$this->makeTransaction($this->account, [
			'date' => '2026-10-28', 'amount' => '30.00', 'type' => 'debit', 'status' => 'scheduled', 'bill_id' => $bill,
			'notes' => 'Auto-generated from bill: Phone', 'description' => '',
		]);
		$typed = $this->makeTransaction($this->account, [
			'date' => '2026-10-26', 'amount' => '30.00', 'type' => 'debit', 'status' => 'cleared', 'description' => 'Phone bill',
		]);

		$this->service(TransactionService::class)->attachToBill($typed, $bill, $this->userId);
		$bills = $this->service(BillService::class);

		$offered = $bills->recordedPaymentForPendingOccurrence($bill, $this->userId, '2026-10-28');
		$this->assertNotNull($offered);
		$this->assertSame($typed, $offered['id']);

		$bills->markPaid($bill, $this->userId, '2026-10-26', false, null, '2026-10-28');

		// October is the linked row; its placeholder is gone and November's is booked
		$this->assertSame([['2026-10-26', 'cleared'], ['2026-11-28', 'scheduled']], $this->rowsOf($bill));
		$this->assertSame('2026-11-28', $this->day($this->fetchRow('budget_bills', $bill)['next_due_date']));
	}
}
