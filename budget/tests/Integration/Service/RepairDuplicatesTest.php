<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\RepairService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Settings > Repair data, against the real database: the duplicate scan
 * reads a bill's payments through the mappers' own queries, so these check
 * the rows it is given are the ones it should be reasoning about.
 *
 * Repair data used to delete real bill payments: a weekly bill's payments
 * in one month, one of two same-named bills' payments, a payment next to a
 * refund. Only a pair it can prove is now removed without being ticked.
 */
class RepairDuplicatesTest extends IntegrationTestCase {
	private function day(int $offset): string {
		return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
	}

	private function bill(int $accountId, string $name, array $overrides = []): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'name' => $name, 'amount' => '40.00', 'frequency' => 'monthly',
			'account_id' => $accountId, 'is_active' => true, 'due_day' => 1,
			'next_due_date' => $this->day(20), 'created_at' => $this->now(),
		]);
	}

	private function payment(int $accountId, int $billId, string $name, string $date, array $overrides = []): int {
		return $this->makeTransaction($accountId, $overrides + [
			'bill_id' => $billId, 'date' => $date, 'amount' => '40.00', 'vendor' => $name,
			'notes' => "Auto-generated from bill: {$name}",
		]);
	}

	public function testAWeeklyBillsPaymentsAreNotDuplicatesOfEachOther(): void {
		$account = $this->makeAccount()->getId();
		$start = $this->day(-35);
		$bill = $this->bill($account, 'Gym', ['frequency' => 'weekly', 'start_date' => $start, 'next_due_date' => $this->day(0)]);
		foreach ([-28, -21, -14, -7] as $offset) {
			// Written in one second, as one Mark Paid writes a payment and
			// the next occurrence's row
			$this->payment($account, $bill, 'Gym', $this->day($offset), ['created_at' => $start . ' 09:00:00']);
		}

		$found = $this->service(RepairService::class)->diagnose($this->userId)['duplicateTransactions'];

		$this->assertSame([], $found);
	}

	public function testTwoBillsWithOneNameKeepTheirOwnPayments(): void {
		$account = $this->makeAccount()->getId();
		$first = $this->bill($account, 'Council Tax');
		$second = $this->bill($account, 'Council Tax', ['due_day' => 3]);
		$this->payment($account, $first, 'Council Tax', $this->day(-10));
		$this->payment($account, $second, 'Council Tax', $this->day(-9));

		$this->assertSame([], $this->service(RepairService::class)->diagnose($this->userId)['duplicateTransactions']);
	}

	public function testAGeneratedPaymentTheBankRowAlsoRecordsIsRemovedAndTheBankRowKept(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, 'Netflix');
		$generated = $this->payment($account, $bill, 'Netflix', $this->day(-5));
		$imported = $this->makeTransaction($account, [
			'date' => $this->day(-6), 'amount' => '40.00', 'vendor' => 'NETFLIX.COM',
			'description' => 'NETFLIX.COM', 'import_id' => 'csv-it-1',
		]);
		// A refund the same week is not a payment
		$refund = $this->makeTransaction($account, [
			'date' => $this->day(-4), 'amount' => '40.00', 'type' => 'credit',
			'vendor' => 'Netflix refund', 'description' => 'Netflix refund', 'import_id' => 'csv-it-2',
		]);

		$repair = $this->service(RepairService::class);
		$found = $repair->diagnose($this->userId)['duplicateTransactions'];

		$this->assertCount(1, $found);
		$this->assertSame($generated, $found[0]['duplicateId']);
		$this->assertSame($imported, $found[0]['originalId']);
		$this->assertTrue($found[0]['selected']);

		$result = $repair->repair($this->userId, ['duplicateTransactions']);

		$this->assertSame(1, $result['duplicateTransactions']['deleted']);
		$this->assertNull($this->fetchRow('budget_transactions', $generated));
		$this->assertNotNull($this->fetchRow('budget_transactions', $imported));
		$this->assertNotNull($this->fetchRow('budget_transactions', $refund));
	}

	/**
	 * A row the user checked and wants kept stops being listed, and comes
	 * back when they say so (#399: the same old doubles came back on every
	 * scan, with nothing to do about them but delete real history).
	 */
	public function testAnIgnoredDuplicateIsListedApartAndNeverDeleted(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, 'Netflix');
		$generated = $this->payment($account, $bill, 'Netflix', $this->day(-5));
		$this->makeTransaction($account, [
			'date' => $this->day(-6), 'amount' => '40.00', 'vendor' => 'NETFLIX.COM',
			'description' => 'NETFLIX.COM', 'import_id' => 'csv-it-4',
		]);
		$repair = $this->service(RepairService::class);

		$this->assertSame([$generated], $repair->ignoreDuplicates($this->userId, [$generated, 999999999]));

		$found = $repair->diagnose($this->userId);
		$this->assertSame([], $found['duplicateTransactions']);
		$this->assertSame([$generated], array_column($found['ignoredDuplicates'], 'duplicateId'));

		// Neither a repair that names no rows nor a stale page sending it ticked removes it
		$repair->repair($this->userId, ['duplicateTransactions']);
		$repair->repair($this->userId, ['duplicateTransactions'], ['transactionIds' => [$generated]]);
		$this->assertNotNull($this->fetchRow('budget_transactions', $generated));

		$this->assertSame([$generated], $repair->ignoreDuplicates($this->userId, [$generated], false));
		$found = $repair->diagnose($this->userId);
		$this->assertSame([$generated], array_column($found['duplicateTransactions'], 'duplicateId'));
		$this->assertSame([], $found['ignoredDuplicates']);
	}

	public function testAnotherUsersIgnoreChangesNothingHere(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, 'Netflix');
		$generated = $this->payment($account, $bill, 'Netflix', $this->day(-5));
		$this->makeTransaction($account, [
			'date' => $this->day(-6), 'amount' => '40.00', 'vendor' => 'NETFLIX.COM',
			'description' => 'NETFLIX.COM', 'import_id' => 'csv-it-5',
		]);
		$repair = $this->service(RepairService::class);

		// Not a duplicate of theirs, so nothing is stored for them
		$this->assertSame([], $repair->ignoreDuplicates($this->newUserId(), [$generated]));
		$this->assertSame([$generated], array_column($repair->diagnose($this->userId)['duplicateTransactions'], 'duplicateId'));
	}

	public function testAReconciledPaymentIsNeverOffered(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, 'Insurance');
		$generated = $this->payment($account, $bill, 'Insurance', $this->day(-5), ['reconciled' => true]);
		$this->makeTransaction($account, [
			'date' => $this->day(-5), 'amount' => '40.00', 'vendor' => 'INSURANCE',
			'description' => 'INSURANCE', 'import_id' => 'csv-it-3',
		]);

		$repair = $this->service(RepairService::class);

		$this->assertSame([], $repair->diagnose($this->userId)['duplicateTransactions']);
		$repair->repair($this->userId, ['duplicateTransactions'], ['transactionIds' => [$generated]]);
		$this->assertNotNull($this->fetchRow('budget_transactions', $generated));
	}
}
