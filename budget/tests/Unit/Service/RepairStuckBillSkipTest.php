<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\UndoSnapshotSkips;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\RepairService;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;

/**
 * Repair Data and a payment snapshot a skip set aside (#399 review).
 *
 * A skip now leaves the payment's snapshot in place, its date listed as
 * skipped. The stuck-bills repair moves a bill on from the occurrence that
 * snapshot says the payment settled, but a skip has settled a later one
 * since: from the payment's occurrence, the skipped one became owed again.
 * A set-aside snapshot says nothing about the occurrence settled last.
 */
class RepairStuckBillSkipTest extends TestCase {
	private const USER = 'terje';

	private function bill(string $snapshot): Bill {
		$bill = new Bill();
		$bill->setId(4);
		$bill->setUserId(self::USER);
		$bill->setName('Insurance');
		$bill->setFrequency('monthly');
		$bill->setDueDay(24);
		$bill->setAmount(50.0);
		$bill->setIsActive(true);
		$bill->setAccountId(1);
		$bill->setLastPaidDate('2026-09-24');
		// Broken by an old forceAdvance: far in the future
		$bill->setNextDueDate('2040-01-24');
		$bill->setPaidUndoState($snapshot);
		return $bill;
	}

	/** @return array<string, mixed> the stuck-bill finding */
	private function finding(Bill $bill): array {
		$bills = $this->createMock(BillMapper::class);
		$bills->method('findActive')->willReturn([$bill]);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
		$service = new RepairService(
			$this->createMock(TransactionMapper::class),
			$bills,
			$accounts,
			new FrequencyCalculator(),
			$this->createMock(AccountService::class),
			$this->createMock(TransactionService::class),
		);
		$stuck = $service->diagnose(self::USER)['stuckBills'];
		$this->assertCount(1, $stuck);
		return $stuck[0];
	}

	private static function snapshot(): string {
		return (string)json_encode([
			'previousState' => ['lastPaidDate' => null, 'nextDueDate' => '2026-09-24', 'isActive' => true],
			'createdTransactionIds' => [5],
			'paidDate' => '2026-09-24',
		]);
	}

	public function testThePaymentsOccurrenceIsUsedWhileItsSnapshotIsInUse(): void {
		$this->assertSame('2026-09-24', $this->finding($this->bill(self::snapshot()))['settledOccurrence']);
	}

	public function testASetAsideSnapshotSaysNothingAboutTheOccurrenceSettled(): void {
		$finding = $this->finding($this->bill(UndoSnapshotSkips::skip(self::snapshot(), '2026-10-24')));

		$this->assertSame('far_future_due_date', $finding['reason']);
		$this->assertNull($finding['settledOccurrence']);
	}
}
