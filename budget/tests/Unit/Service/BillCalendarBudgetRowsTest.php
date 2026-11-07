<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\BudgetCalendarEstimates;
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
 * Budgets in the Bills Calendar (#459): a budget the user put there is a
 * row of its own after the bills, its month's share in every month, and
 * counts in the monthly totals; never for a single account (a budget has
 * none), never among inactive bills, and only when asked for.
 */
class BillCalendarBudgetRowsTest extends TestCase {
	private BillMapper $bills;
	private BudgetCalendarEstimates $estimates;
	private BillService $service;
	/** @var string[][] the months each forMonths() call asked for */
	private array $asked = [];

	protected function setUp(): void {
		$this->bills = $this->createMock(BillMapper::class);
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findBillPaymentsInYear')->willReturn([]);
		$transactions->method('findRecordedBillTransactions')->willReturn([]);
		$transactions->method('getBalanceAsOf')->willReturn(0.0);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
		$accounts->method('findByIds')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$currency->method('convertToBaseFloat')->willReturnArgument(0);
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-11-07');

		$this->estimates = $this->createMock(BudgetCalendarEstimates::class);
		$this->estimates->method('forMonths')->willReturnCallback(function (string $userId, array $months): array {
			$this->asked[] = $months;
			$food = new Category();
			$food->setId(9);
			$food->setName('Lebensmittel');
			$coffee = new Category();
			$coffee->setId(10);
			$coffee->setName('Coffee');
			return ['currency' => 'CHF', 'rows' => [
				[
					'category' => $food,
					'amounts' => array_fill_keys($months, '700.00'),
					'budgets' => array_fill_keys($months, [700.0, 'monthly']),
				],
				[
					// A budget that started in July, raised in October
					'category' => $coffee,
					'amounts' => ['2026-07' => '86.67', '2026-08' => '86.67', '2026-09' => '86.67', '2026-10' => '100.00', '2026-11' => '100.00', '2026-12' => '100.00'],
					'budgets' => ['2026-07' => [20.0, 'weekly'], '2026-08' => [20.0, 'weekly'], '2026-09' => [20.0, 'weekly'],
						'2026-10' => [100.0, 'monthly'], '2026-11' => [100.0, 'monthly'], '2026-12' => [100.0, 'monthly']],
				],
			]];
		});

		$this->service = new BillService(
			$this->bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$transactions, $this->createMock(IL10N::class), $accounts, $currency,
			$this->createMock(TransactionSplitService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class), null, $this->createMock(RecurringIncomeMapper::class),
			null, $clock, null, null, $this->estimates,
		);
	}

	private function rent(): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('terje');
		$bill->setName('Miete');
		$bill->setAmount(1500.0);
		$bill->setFrequency('monthly');
		$bill->setDueDay(1);
		$bill->setStartDate('2026-01-01');
		$bill->setNextDueDate('2026-12-01');
		$bill->setLastPaidDate(null);
		$bill->setIsActive(true);
		$bill->setIsTransfer(false);
		$bill->setAccountId(null);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt('2026-01-01 10:00:00');
		return $bill;
	}

	private function rowsNamed(array $overview): array {
		return array_column($overview['bills'], null, 'name');
	}

	public function testBudgetsAreRowsAfterTheBillsAndCountInTheTotals(): void {
		$this->bills->method('findByType')->willReturn([$this->rent()]);

		$overview = $this->service->getAnnualOverview('terje', 2026, false, 'active', null, true);

		$this->assertSame(['Miete', 'Lebensmittel', 'Coffee'], array_column($overview['bills'], 'name'));
		$this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
			'2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'], $this->asked[0]);

		$food = $this->rowsNamed($overview)['Lebensmittel'];
		$this->assertTrue($food['isBudget']);
		$this->assertSame(9, $food['categoryId']);
		$this->assertNull($food['id']);
		$this->assertSame('CHF', $food['currency']);
		$this->assertSame(700.0, $food['amount']);
		$this->assertSame('monthly', $food['frequency']);
		$this->assertFalse($food['mixedAmounts']);
		$this->assertSame(array_fill(1, 12, true), $food['occurrences']);
		$this->assertSame(array_fill(1, 12, 700.0), $food['expectedAmounts']);
		$this->assertSame([], $food['paidAmounts']);
		$this->assertSame([], $food['owedAmounts']);
		$this->assertTrue($food['inTotals']);

		$coffee = $this->rowsNamed($overview)['Coffee'];
		$this->assertFalse($coffee['occurrences'][6]);
		$this->assertTrue($coffee['occurrences'][7]);
		// Changed during the year: the Amount column shows the latest, and the export leaves it blank
		$this->assertSame(100.0, $coffee['amount']);
		$this->assertTrue($coffee['mixedAmounts']);

		// The rent's 1,500 is owed only from its next due date (December);
		// the budgets add theirs to every month they have
		$this->assertEqualsWithDelta(700.0, $overview['monthlyTotals'][1], 0.0001);
		$this->assertEqualsWithDelta(786.67, $overview['monthlyTotals'][7], 0.0001);
		$this->assertEqualsWithDelta(1500.0 + 700.0 + 100.0, $overview['monthlyTotals'][12], 0.0001);
	}

	/** The budgets' shares add up exactly in the totals, with no float drift */
	public function testTheTotalsAddUpWithoutDrift(): void {
		$this->bills->method('findByType')->willReturn([]);

		$overview = $this->service->getAnnualOverview('terje', 2026, false, 'all', null, true);

		$this->assertSame(700.0, $overview['monthlyTotals'][1]);
		$this->assertSame(786.67, $overview['monthlyTotals'][8]);
		$this->assertSame(800.0, $overview['monthlyTotals'][12]);
	}

	public function testNotAskedForThereAreNone(): void {
		$this->bills->method('findByType')->willReturn([$this->rent()]);

		$overview = $this->service->getAnnualOverview('terje', 2026, false, 'active');

		$this->assertSame(['Miete'], array_column($overview['bills'], 'name'));
		$this->assertSame([], $this->asked);
		$this->assertSame(0.0, $overview['monthlyTotals'][1]);
	}

	/** A budget has no account, so a single account's calendar shows none */
	public function testASingleAccountsCalendarHasNoBudgets(): void {
		$this->bills->method('findByType')->willReturn([]);

		$overview = $this->service->getAnnualOverview('terje', 2026, false, 'active', 4, true);

		$this->assertSame([], $overview['bills']);
		$this->assertSame([], $this->asked);
	}

	/** An estimate is never an inactive bill */
	public function testInactiveBillsOnlyHasNoBudgets(): void {
		$this->bills->method('findByType')->willReturn([]);

		$overview = $this->service->getAnnualOverview('terje', 2026, false, 'inactive', null, true);

		$this->assertSame([], $overview['bills']);
		$this->assertSame([], $this->asked);
	}

	public function testAllBillsHasThem(): void {
		$this->bills->method('findByType')->willReturn([]);

		$overview = $this->service->getAnnualOverview('terje', 2026, true, 'all', null, true);

		$this->assertSame(['Lebensmittel', 'Coffee'], array_column($overview['bills'], 'name'));
	}
}
