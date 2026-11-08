<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\BudgetSnapshot;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\Bill\BudgetCalendarEstimates;
use OCA\Budget\Service\CurrencyConversionService;
use PHPUnit\Framework\TestCase;

/**
 * The budgets a user puts in the Bills Calendar (#459): which categories,
 * and what each month of them is expected to spend.
 */
class BudgetCalendarEstimatesTest extends TestCase {
	private const YEAR = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
		'2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];

	private function category(int $id, string $name, ?float $amount, string $period = 'monthly', array $fields = []): Category {
		$category = new Category();
		$category->setId($id);
		$category->setUserId('terje');
		$category->setName($name);
		$category->setType($fields['type'] ?? 'expense');
		$category->setParentId($fields['parentId'] ?? null);
		$category->setBudgetAmount($amount);
		$category->setBudgetPeriod($period);
		$category->setShowInCalendar(array_key_exists('showInCalendar', $fields) ? $fields['showInCalendar'] : true);
		$category->setExcludedFromBudget($fields['excludedFromBudget'] ?? false);
		$category->setExcludedFromReports($fields['excludedFromReports'] ?? false);
		return $category;
	}

	private function snapshot(int $categoryId, string $from, ?float $amount, string $period = 'monthly'): BudgetSnapshot {
		$snapshot = new BudgetSnapshot();
		$snapshot->setCategoryId($categoryId);
		$snapshot->setUserId('terje');
		$snapshot->setEffectiveFrom($from);
		$snapshot->setAmount($amount);
		$snapshot->setPeriod($period);
		return $snapshot;
	}

	/** @return array<string, array<string, string>> name => month => amount */
	private function byName(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$out[$row['category']->getName()] = $row['amounts'];
		}
		return $out;
	}

	public function testTheReportersMonthlyBudgetFillsEveryMonth(): void {
		$rows = BudgetCalendarEstimates::estimates([$this->category(1, 'Lebensmittel', 700.0)], [], self::YEAR, 2);

		$amounts = $this->byName($rows)['Lebensmittel'];
		$this->assertCount(12, $amounts);
		$this->assertSame('700.00', $amounts['2026-01']);
		$this->assertSame('700.00', $amounts['2026-12']);
		$this->assertSame([700.0, 'monthly'], $rows[0]['budgets']['2026-06']);
	}

	/** Each period is turned into a month's share as the Budget page does (formatters.prorateBudget) */
	public function testOtherPeriodsAreSpreadOverTheMonths(): void {
		$rows = $this->byName(BudgetCalendarEstimates::estimates([
			$this->category(1, 'Coffee', 20.0, 'weekly'),
			$this->category(2, 'Car service', 300.0, 'quarterly'),
			$this->category(3, 'Gifts', 1200.0, 'yearly'),
			$this->category(4, 'Odd', 100.0, 'yearly'),
		], [], self::YEAR, 2));

		$this->assertSame('86.67', $rows['Coffee']['2026-03']);   // 20 x 52 / 12
		$this->assertSame('100.00', $rows['Car service']['2026-07']);
		$this->assertSame('100.00', $rows['Gifts']['2026-11']);
		$this->assertSame('8.33', $rows['Odd']['2026-01']);       // 100 / 12, rounded half up
	}

	public function testAZeroDecimalCurrencyIsRoundedToWholeUnits(): void {
		$rows = $this->byName(BudgetCalendarEstimates::estimates([$this->category(1, 'Food', 20000.0, 'weekly')], [], self::YEAR, 0));

		$this->assertSame('86667', $rows['Food']['2026-01']);
	}

	public function testOnlyFlaggedBudgetedExpenseCategoriesAreIn(): void {
		$rows = $this->byName(BudgetCalendarEstimates::estimates([
			$this->category(1, 'Not flagged', 100.0, 'monthly', ['showInCalendar' => false]),
			$this->category(2, 'Unflagged default', 100.0, 'monthly', ['showInCalendar' => null]),
			$this->category(3, 'Salary', 3000.0, 'monthly', ['type' => 'income']),
			$this->category(4, 'No budget', null),
			$this->category(5, 'Zero budget', 0.0),
			$this->category(6, 'Out of budgeting', 100.0, 'monthly', ['excludedFromBudget' => true]),
			$this->category(7, 'Out of reports', 100.0, 'monthly', ['excludedFromReports' => true]),
			$this->category(8, 'Groceries', 50.0),
		], [], self::YEAR, 2));

		$this->assertSame(['Groceries'], array_keys($rows));
	}

	/** The flags cascade to a subcategory, as BudgetScope has them (an excluded parent takes its branch out) */
	public function testASubcategoryOfAnExcludedParentIsOut(): void {
		$rows = $this->byName(BudgetCalendarEstimates::estimates([
			$this->category(1, 'Hobbies', null, 'monthly', ['showInCalendar' => false, 'excludedFromBudget' => true]),
			$this->category(2, 'Climbing', 40.0, 'monthly', ['parentId' => 1]),
			$this->category(3, 'House', null, 'monthly', ['showInCalendar' => false, 'excludedFromReports' => true]),
			$this->category(4, 'Garden', 30.0, 'monthly', ['parentId' => 3]),
			$this->category(5, 'Food', null, 'monthly', ['showInCalendar' => false]),
			$this->category(6, 'Bakery', 25.0, 'monthly', ['parentId' => 5]),
		], [], self::YEAR, 2));

		$this->assertSame(['Bakery'], array_keys($rows));
	}

	/**
	 * A month takes the budget in effect then, a snapshot's from the month
	 * it took effect, as the Budget page shows it
	 */
	public function testSnapshotsApplyFromTheMonthTheyTakeEffect(): void {
		$rows = BudgetCalendarEstimates::estimates(
			[$this->category(1, 'Food', 700.0)],
			[
				// newest first, as BudgetSnapshotMapper::findAll() returns them
				$this->snapshot(1, '2026-09', 1200.0, 'quarterly'),
				$this->snapshot(1, '2026-04', 650.0),
			],
			self::YEAR,
			2
		);

		$amounts = $rows[0]['amounts'];
		$this->assertSame('700.00', $amounts['2026-03']);
		$this->assertSame('650.00', $amounts['2026-04']);
		$this->assertSame('650.00', $amounts['2026-08']);
		$this->assertSame('400.00', $amounts['2026-09']);
		$this->assertSame([1200.0, 'quarterly'], $rows[0]['budgets']['2026-12']);
	}

	/** A snapshot that sets the budget to nothing leaves its months empty */
	public function testASnapshotWithNoBudgetLeavesItsMonthsOut(): void {
		$rows = BudgetCalendarEstimates::estimates(
			[$this->category(1, 'Food', 700.0)],
			[$this->snapshot(1, '2026-07', 0.0)],
			self::YEAR,
			2
		);

		$this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], array_keys($rows[0]['amounts']));
	}

	public function testForMonthsReadsTheUsersDataInTheBaseCurrency(): void {
		$categories = $this->createMock(CategoryMapper::class);
		$categories->expects($this->once())->method('findAll')->with('terje')
			->willReturn([$this->category(1, 'Lebensmittel', 700.0)]);
		$snapshots = $this->createMock(BudgetSnapshotMapper::class);
		$snapshots->expects($this->once())->method('findAll')->with('terje')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');

		$result = (new BudgetCalendarEstimates($categories, $snapshots, $currency))->forMonths('terje', ['2026-11', '2026-12']);

		$this->assertSame('CHF', $result['currency']);
		$this->assertSame(['2026-11' => '700.00', '2026-12' => '700.00'], $result['rows'][0]['amounts']);
	}

	/** Nobody has flagged anything: the snapshots aren't read */
	public function testNothingFlaggedReadsNoSnapshots(): void {
		$categories = $this->createMock(CategoryMapper::class);
		$categories->method('findAll')->willReturn([$this->category(1, 'Food', 700.0, 'monthly', ['showInCalendar' => false])]);
		$snapshots = $this->createMock(BudgetSnapshotMapper::class);
		$snapshots->expects($this->never())->method('findAll');
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('EUR');

		$result = (new BudgetCalendarEstimates($categories, $snapshots, $currency))->forMonths('terje', self::YEAR);

		$this->assertSame(['currency' => 'EUR', 'rows' => []], $result);
	}
}
