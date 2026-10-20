<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One-time bills sharing a name fold into one Bills Calendar row, which
 * kept the first invoice's amount: the CSV printed the reporter's four
 * Corner Card SA invoices (0.20, 50.00, 1,013.10 and 500.00) as one bill of
 * 1013.1 (#399). The row says when its invoices' amounts differ, so the
 * export can leave the amount blank rather than print one of them.
 */
class BillCalendarMergedAmountTest extends TestCase {
	private BillMapper $bills;
	private TransactionService $transactions;
	private BillService $service;

	protected function setUp(): void {
		$this->bills = $this->createMock(BillMapper::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('findBillPaymentsInYear')->willReturn([]);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
		$accounts->method('findByIds')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-20');

		$this->service = new BillService(
			$this->bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$this->transactions, $this->createMock(IL10N::class), $accounts, $currency,
			$this->createMock(TransactionSplitService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class), null, $this->createMock(RecurringIncomeMapper::class),
			null, $clock,
		);
	}

	private function invoice(int $id, string $name, float $amount, int $month, string $frequency = 'one-time'): Bill {
		$date = sprintf('2026-%02d-28', $month);
		$bill = new Bill();
		$bill->setId($id);
		$bill->setUserId('t399');
		$bill->setName($name);
		$bill->setAmount($amount);
		$bill->setFrequency($frequency);
		$bill->setDueDay(28);
		$bill->setDueMonth($frequency === 'one-time' ? $month : null);
		$bill->setStartDate($date);
		$bill->setEndDate(null);
		$bill->setNextDueDate($date);
		$bill->setLastPaidDate(null);
		$bill->setIsActive(true);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt('2026-01-01 10:00:00');
		return $bill;
	}

	private function row(array $bills, string $name): array {
		$this->bills->method('findByType')->willReturn($bills);
		foreach ($this->service->getAnnualOverview('t399', 2026)['bills'] as $row) {
			if ($row['name'] === $name) {
				return $row;
			}
		}
		$this->fail("no row for $name");
	}

	public function testInvoicesOfDifferentAmountsSayTheirAmountsDiffer(): void {
		$row = $this->row([
			$this->invoice(62, 'Corner Card SA', 0.2, 11),
			$this->invoice(63, 'Corner Card SA', 50.0, 11),
			$this->invoice(73, 'Corner Card SA', 1013.1, 12),
		], 'Corner Card SA');

		$this->assertSame([62, 63, 73], $row['billIds']);
		$this->assertTrue($row['mixedAmounts']);
	}

	public function testInvoicesOfOneAmountKeepIt(): void {
		$row = $this->row([
			$this->invoice(51, 'EKZ Ratenrechnung', 166.7, 11),
			$this->invoice(52, 'EKZ Ratenrechnung', 166.70, 12),
		], 'EKZ Ratenrechnung');

		$this->assertSame([51, 52], $row['billIds']);
		$this->assertFalse($row['mixedAmounts'] ?? false);
		$this->assertEqualsWithDelta(166.7, (float)$row['amount'], 0.001);
	}

	public function testABillOnItsOwnSaysNothing(): void {
		$row = $this->row([
			$this->invoice(5, 'Pfaff Steuerberatung', 220.0, 11),
			$this->invoice(6, 'Rent', 1200.0, 11, 'monthly'),
		], 'Pfaff Steuerberatung');

		$this->assertFalse($row['mixedAmounts'] ?? false);
	}
}
