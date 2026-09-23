<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\Report\ReportCalculator;
use PHPUnit\Framework\TestCase;

class ReportAggregatorTest extends TestCase {
	private ReportAggregator $aggregator;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private TransactionReportQueries $reportQueries;
	private CategoryMapper $categoryMapper;
	private ReportCalculator $calculator;
	private CurrencyConversionService $conversionService;
	private BudgetSnapshotMapper $budgetSnapshotMapper;
	private $carryoverService;
	private $recurringBudgetService;
	private string $currentBudgetMonth;
	private $granularShareService;
	private $categoryMuteMapper;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->reportQueries = $this->createMock(TransactionReportQueries::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->calculator = $this->createMock(ReportCalculator::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);

		$this->budgetSnapshotMapper = $this->createMock(BudgetSnapshotMapper::class);

		$this->recurringBudgetService = $this->createMock(\OCA\Budget\Service\RecurringBudgetService::class);
		$this->recurringBudgetService->method('getMonthlyBudgetsByCategory')->willReturn([]);

		$this->currentBudgetMonth = date('Y-m');
		$this->carryoverService = $this->createMock(\OCA\Budget\Service\BudgetCarryoverService::class);
		$this->carryoverService->method('currentBudgetMonth')
			->willReturnCallback(fn() => $this->currentBudgetMonth);

		// createMock auto-stubs array-returning methods to [], so shared/muted
		// categories default to none; individual tests override as needed
		$this->granularShareService = $this->createMock(\OCA\Budget\Service\GranularShareService::class);
		$this->categoryMuteMapper = $this->createMock(\OCA\Budget\Db\CategoryMuteMapper::class);

		$this->aggregator = new ReportAggregator(
			$this->accountMapper,
			$this->transactionMapper,
			$this->categoryMapper,
			$this->budgetSnapshotMapper,
			$this->calculator,
			$this->conversionService,
			$this->recurringBudgetService,
			$this->carryoverService,
			$this->reportQueries,
			$this->granularShareService,
			$this->categoryMuteMapper
		);
	}

	private function makeAccount(int $id, string $name, string $type, float $balance, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($name);
		$account->setType($type);
		$account->setBalance($balance);
		$account->setCurrency($currency);
		return $account;
	}

	private function setupDefaultMocks(): void {
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('getSpendingSummary')->willReturn([]);
		$this->reportQueries->method('getMonthlyTrendData')->willReturn([]);
	}

	// ===== Single currency (no conversion) =====

	public function testSingleCurrencyNoConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'Savings', 'savings', 2000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 10],
			2 => ['income' => 100, 'expenses' => 0, 'count' => 2],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertEquals(3000.00, $result['totals']['currentBalance']);
		$this->assertEquals(600, $result['totals']['totalIncome']);
		$this->assertEquals(200, $result['totals']['totalExpenses']);
		$this->assertEquals('GBP', $result['baseCurrency']);
		$this->assertFalse($result['currencyConverted']);
		$this->assertEmpty($result['unconvertedCurrencies']);
	}

	// ===== Multi-currency conversion =====

	public function testMultiCurrencyConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1200.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 5],
			2 => ['income' => 600, 'expenses' => 100, 'count' => 3],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('canConvert')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);

		// Mock conversion for EUR account values
		$this->conversionService->method('convertToBaseFloat')
			->willReturnCallback(function ($amount, $currency, $userId) {
				if ($currency === 'EUR') {
					return (float)$amount * 0.85; // EUR→GBP rate
				}
				return (float)$amount;
			});

		// Transfer deduction uses per-account method
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// GBP: 1000 + EUR→GBP: 1200*0.85=1020 = 2020
		$this->assertEqualsWithDelta(2020.00, $result['totals']['currentBalance'], 0.01);
		// GBP income: 500 + EUR→GBP: 600*0.85=510 = 1010
		$this->assertEqualsWithDelta(1010.00, $result['totals']['totalIncome'], 0.01);
		// GBP expenses: 200 + EUR→GBP: 100*0.85=85 = 285
		$this->assertEqualsWithDelta(285.00, $result['totals']['totalExpenses'], 0.01);
		$this->assertTrue($result['currencyConverted']);
		$this->assertEquals('GBP', $result['baseCurrency']);
	}

	// ===== Transfer deduction with multi-currency =====

	public function testTransferDeductionMultiCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1000.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 1000, 'expenses' => 500, 'count' => 10],
			2 => ['income' => 800, 'expenses' => 300, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('canConvert')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);

		$this->conversionService->method('convertToBaseFloat')
			->willReturnCallback(function ($amount, $currency, $userId) {
				if ($currency === 'EUR') {
					return (float)$amount * 0.85;
				}
				return (float)$amount;
			});

		// Transfer: 200 GBP out, 170 EUR in (cross-currency transfer)
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([
			1 => ['income' => 0, 'expenses' => 200],
			2 => ['income' => 170, 'expenses' => 0],
		]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Income: GBP 1000 + EUR 800*0.85=680 = 1680, minus transfers: 0 + 170*0.85=144.5 = 1535.5
		$expectedIncome = 1000 + (800 * 0.85) - (0 + 170 * 0.85);
		$this->assertEqualsWithDelta($expectedIncome, $result['totals']['totalIncome'], 0.01);
	}

	// ===== Excluded-category deduction (#326) =====

	public function testExcludedCategoryDeductionSingleCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 600, 'expenses' => 200, 'count' => 10],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(7, 'Internal', 'expense', null, true),
		]);

		// All-accounts view: linked transfers already deducted, so skipped here
		$this->transactionMapper->expects($this->once())
			->method('getCategoryTotalsByAccount')
			->with([7], '2026-01-01', '2026-01-31', null, true)
			->willReturn([1 => ['income' => 10, 'expenses' => 50]]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertEqualsWithDelta(590.00, $result['totals']['totalIncome'], 0.01);
		$this->assertEqualsWithDelta(150.00, $result['totals']['totalExpenses'], 0.01);
	}

	public function testExcludedCategoryDeductionMultiCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1000.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 1000, 'expenses' => 500, 'count' => 10],
			2 => ['income' => 800, 'expenses' => 300, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('canConvert')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);
		$this->conversionService->method('convertToBaseFloat')
			->willReturnCallback(function ($amount, $currency, $userId) {
				return $currency === 'EUR' ? (float)$amount * 0.85 : (float)$amount;
			});

		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(7, 'Internal', 'expense', null, true),
		]);

		// Unmatched cross-currency transfer legs booked to an excluded category:
		// each account's amounts must be converted like the totals they offset
		$this->transactionMapper->method('getCategoryTotalsByAccount')->willReturn([
			1 => ['income' => 0, 'expenses' => 100],
			2 => ['income' => 50, 'expenses' => 200],
		]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Income: 1000 + 800*0.85=680 = 1680, minus excluded 0 + 50*0.85=42.5
		$this->assertEqualsWithDelta(1680 - 42.5, $result['totals']['totalIncome'], 0.01);
		// Expenses: 500 + 300*0.85=255 = 755, minus excluded 100 + 200*0.85=170
		$this->assertEqualsWithDelta(755 - 100 - 170, $result['totals']['totalExpenses'], 0.01);
	}

	public function testExcludedCategoryDeductionSkipsUnconvertibleCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'XYZ Account', 'savings', 1000.00, 'XYZ'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 1000, 'expenses' => 500, 'count' => 10],
			2 => ['income' => 800, 'expenses' => 300, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('canConvert')
			->willReturnCallback(fn($currency) => $currency !== 'XYZ');
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'XYZ']);
		$this->conversionService->method('convertToBaseFloat')->willReturnCallback(fn($a) => (float)$a);

		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(7, 'Internal', 'expense', null, true),
		]);
		$this->transactionMapper->method('getCategoryTotalsByAccount')->willReturn([
			1 => ['income' => 0, 'expenses' => 100],
			2 => ['income' => 50, 'expenses' => 200],
		]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Account 2 never entered the totals (unconvertible), so nothing of it
		// may be deducted either — only account 1's excluded expenses apply
		$this->assertEqualsWithDelta(1000.00, $result['totals']['totalIncome'], 0.01);
		$this->assertEqualsWithDelta(400.00, $result['totals']['totalExpenses'], 0.01);
	}

	public function testViewerDeductsOwnerExcludedSharedCategories(): void {
		// A share recipient owns no categories; the owner's exclude-from-reports
		// flag on shared categories must still be deducted from their totals (#326)
		$accounts = [
			$this->makeAccount(1, 'Shared Checking', 'checking', 1000.00, 'GBP'),
		];

		$this->accountMapper->method('findByIds')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 2000, 'expenses' => 1500, 'count' => 10],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		// Viewer owns no categories …
		$this->categoryMapper->method('findAll')->willReturn([]);
		// … but the owner's excluded category is shared with them
		$this->granularShareService->method('getSharedCategoryIds')->willReturn([7]);
		$this->categoryMapper->method('findByIdsUnscoped')->with([7])->willReturn([
			$this->makeCategory(7, 'Internal Transfers', 'expense', null, true),
		]);

		$this->transactionMapper->expects($this->once())
			->method('getCategoryTotalsByAccount')
			->with([7], '2026-01-01', '2026-01-31', null, true)
			->willReturn([1 => ['income' => 1000, 'expenses' => 1200]]);

		$result = $this->aggregator->generateSummary('viewer', null, '2026-01-01', '2026-01-31', [], true, [1]);

		$this->assertEqualsWithDelta(1000.00, $result['totals']['totalIncome'], 0.01);
		$this->assertEqualsWithDelta(300.00, $result['totals']['totalExpenses'], 0.01);
	}

	public function testViewerMutedCategoriesDeductedFromTotals(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 600, 'expenses' => 400, 'count' => 5],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->categoryMuteMapper->method('findMutedCategoryIds')->willReturn([9]);

		$this->transactionMapper->expects($this->once())
			->method('getCategoryTotalsByAccount')
			->with([9], '2026-01-01', '2026-01-31', null, true)
			->willReturn([1 => ['income' => 0, 'expenses' => 100]]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertEqualsWithDelta(600.00, $result['totals']['totalIncome'], 0.01);
		$this->assertEqualsWithDelta(300.00, $result['totals']['totalExpenses'], 0.01);
	}

	public function testExcludedCategoryDeductionScopedToSelectedAccount(): void {
		$account = $this->makeAccount(1, 'Checking', 'checking', 1000.00, 'EUR');

		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 300, 'expenses' => 100, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');

		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(7, 'Internal', 'expense', null, true),
		]);

		// Single-account view: deduction scoped to that account, and linked
		// transfers are NOT skipped (no transfer deduction ran)
		$this->transactionMapper->expects($this->once())
			->method('getCategoryTotalsByAccount')
			->with([7], '2026-01-01', '2026-01-31', 1, false)
			->willReturn([1 => ['income' => 20, 'expenses' => 30]]);

		$result = $this->aggregator->generateSummary('user1', 1, '2026-01-01', '2026-01-31');

		$this->assertEqualsWithDelta(280.00, $result['totals']['totalIncome'], 0.01);
		$this->assertEqualsWithDelta(70.00, $result['totals']['totalExpenses'], 0.01);
	}

	// ===== Per-account data keeps native currency =====

	public function testPerAccountDataKeepsNativeCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1200.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 5],
			2 => ['income' => 600, 'expenses' => 100, 'count' => 3],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);
		$this->conversionService->method('convertToBaseFloat')->willReturnCallback(fn($a) => (float)$a);
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Per-account data should keep original currency and values
		$this->assertEquals('GBP', $result['accounts'][0]['currency']);
		$this->assertEquals(1000.00, $result['accounts'][0]['balance']);
		$this->assertEquals('EUR', $result['accounts'][1]['currency']);
		$this->assertEquals(1200.00, $result['accounts'][1]['balance']);
	}

	// ===== Single account view skips conversion =====

	public function testSingleAccountViewNoConversion(): void {
		$account = $this->makeAccount(1, 'EUR Account', 'checking', 1000.00, 'EUR');

		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 300, 'expenses' => 100, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		// needsConversion should NOT be called for single-account view
		$this->conversionService->expects($this->never())->method('needsConversion');

		$result = $this->aggregator->generateSummary('user1', 1, '2026-01-01', '2026-01-31');

		// Values should be in native EUR, not converted
		$this->assertEquals(1000.00, $result['totals']['currentBalance']);
		$this->assertFalse($result['currencyConverted']);
	}

	// ===== Trend months =====

	public function testTrendDataCarriesYearMonthKeysAlongsideLabels(): void {
		$account = $this->makeAccount(1, 'Current', 'checking', 1000.00, 'GBP');
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([]);
		$this->setupDefaultMocks();
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');

		$result = $this->aggregator->generateSummary('user1', 1, '2025-11-01', '2026-01-31');

		// Y-m for the UI to format in the user's language; the English
		// labels are kept for compatibility.
		$this->assertSame(['2025-11', '2025-12', '2026-01'], $result['trends']['months']);
		$this->assertSame(['Nov 2025', 'Dec 2025', 'Jan 2026'], $result['trends']['labels']);
		$this->assertCount(3, $result['trends']['income']);
	}

	// ===== Metadata in response =====

	public function testResponseIncludesCurrencyMetadata(): void {
		$accounts = [
			$this->makeAccount(1, 'Account', 'checking', 100.00, 'USD'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertArrayHasKey('baseCurrency', $result);
		$this->assertArrayHasKey('currencyConverted', $result);
		$this->assertArrayHasKey('unconvertedCurrencies', $result);
		$this->assertEquals('USD', $result['baseCurrency']);
	}

	// ===== Budget report =====

	public function testBudgetReportUsesExplicitSnapshotMonthForCrossMonthCycle(): void {
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->budgetSnapshotMapper->expects($this->once())
			->method('findEffectiveBatch')
			->with('user1', '2026-09')
			->willReturn([]);

		$this->aggregator->getBudgetReport(
			'user1',
			'2026-08-28',
			'2026-09-27',
			null,
			null,
			'2026-09'
		);
	}

	/**
	 * With a custom start day the dashboard asks for the budget period, not a
	 * calendar month, so the envelope carryover has to recognise it (#386).
	 */
	public function testBudgetReportAppliesCarryoverForTheBudgetPeriod(): void {
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->carryoverService->method('budgetMonthRange')
			->with('user1', '2026-09')
			->willReturn(['2026-08-28', '2026-09-27']);
		$this->carryoverService->expects($this->once())
			->method('getCarryovers')
			->with('user1', '2026-09')
			->willReturn([]);

		$this->aggregator->getBudgetReport('user1', '2026-08-28', '2026-09-27', null, null, '2026-09');
	}

	public function testBudgetReportKeepsCarryoverForACalendarMonth(): void {
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->carryoverService->method('budgetMonthRange')->willReturn(['2026-08-28', '2026-09-27']);
		$this->carryoverService->expects($this->once())
			->method('getCarryovers')
			->with('user1', '2026-09')
			->willReturn([]);

		$this->aggregator->getBudgetReport('user1', '2026-09-01', '2026-09-30');
	}

	public function testBudgetReportSkipsCarryoverForARangeThatIsNoMonth(): void {
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->carryoverService->method('budgetMonthRange')->willReturn(['2026-08-28', '2026-09-27']);
		$this->carryoverService->expects($this->never())->method('getCarryovers');

		$this->aggregator->getBudgetReport('user1', '2026-08-28', '2026-09-15', null, null, '2026-09');
	}

	/**
	 * Recurring budgets reflect today's bills, so they fill in for the current
	 * budget month onwards only. With start day 28 on 29 Sep that is October:
	 * the September period (28 Aug - 27 Sep) is already history.
	 */
	public function testBudgetReportSkipsRecurringBudgetsBeforeTheCurrentBudgetMonth(): void {
		$this->currentBudgetMonth = '2026-10';
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->recurringBudgetService->expects($this->never())->method('getMonthlyBudgetsByCategory');

		$this->aggregator->getBudgetReport('user1', '2026-08-28', '2026-09-27', null, null, '2026-09');
	}

	public function testBudgetReportUsesRecurringBudgetsForTheCurrentBudgetMonth(): void {
		$this->currentBudgetMonth = '2026-10';
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->recurringBudgetService->expects($this->once())->method('getMonthlyBudgetsByCategory');

		$this->aggregator->getBudgetReport('user1', '2026-09-28', '2026-10-27', null, null, '2026-10');
	}

	public function testBudgetReportAddsTheCarryoverToABudgetPeriodBudget(): void {
		$category = new \OCA\Budget\Db\Category();
		$category->setId(5);
		$category->setName('Groceries');
		$category->setType('expense');
		$category->setBudgetAmount(300.0);
		$category->setBudgetPeriod('monthly');
		$this->categoryMapper->method('findAll')->willReturn([$category]);
		$this->budgetSnapshotMapper->method('findEffectiveBatch')->willReturn([]);
		$this->carryoverService->method('budgetMonthRange')->willReturn(['2026-08-28', '2026-09-27']);
		$this->carryoverService->method('getCarryovers')->willReturn([5 => 40.0]);

		$result = $this->aggregator->getBudgetReport('user1', '2026-08-28', '2026-09-27', null, null, '2026-09');

		$this->assertSame(340.0, $result['categories'][0]['budgeted']);
	}

	public function testBudgetReportHoldsSpentToTheAccountsInView(): void {
		// The carryover was scoped to the visible accounts and spent was not,
		// so with shared accounts excluded the report still counted spending
		// in them (#551).
		$category = new \OCA\Budget\Db\Category();
		$category->setId(5);
		$category->setName('Groceries');
		$category->setType('expense');
		$category->setBudgetAmount(300.0);
		$category->setBudgetPeriod('monthly');
		$this->categoryMapper->method('findAll')->willReturn([$category]);
		$this->budgetSnapshotMapper->method('findEffectiveBatch')->willReturn([]);

		$calls = [];
		$this->transactionMapper->method('getCategorySpendingBatch')
			->willReturnCallback(function (...$args) use (&$calls) {
				$calls[] = $args;
				return $args[3] === 'debit' ? [5 => 120.0] : [];
			});

		$result = $this->aggregator->getBudgetReport('user1', '2026-09-01', '2026-09-30', null, [3, 4]);

		$this->assertCount(2, $calls);
		foreach ($calls as $args) {
			$this->assertSame('user1', $args[6] ?? null, 'spent must be scoped to the user');
			$this->assertSame([3, 4], $args[7] ?? null, 'spent must be held to the visible accounts');
		}
		$this->assertSame(120.0, $result['categories'][0]['spent']);
	}

	public function testBudgetReportCountsABudgetsWholeBranch(): void {
		// Housing (budgeted) → Rent (no budget) → Deposit (no budget), and
		// Bills (budgeted of its own) under Housing too: Housing counts Rent
		// and Deposit, Bills counts itself, and nothing is counted twice (#551)
		$housing = $this->makeCategory(1, 'Housing', 'expense');
		$housing->setBudgetAmount(500.0);
		$rent = $this->makeCategory(2, 'Rent', 'expense', 1);
		$deposit = $this->makeCategory(3, 'Deposit', 'expense', 2);
		$bills = $this->makeCategory(4, 'Bills', 'expense', 1);
		$bills->setBudgetAmount(100.0);
		$this->categoryMapper->method('findAll')->willReturn([$housing, $rent, $deposit, $bills]);
		$this->budgetSnapshotMapper->method('findEffectiveBatch')->willReturn([]);

		$asked = [];
		$this->transactionMapper->method('getCategorySpendingBatch')
			->willReturnCallback(function (array $ids, ...$rest) use (&$asked) {
				$asked = array_merge($asked, $ids);
				return array_intersect_key([1 => 5.0, 2 => 300.0, 3 => 20.0, 4 => 60.0], array_flip($ids));
			});

		$result = $this->aggregator->getBudgetReport('user1', '2026-09-01', '2026-09-30');

		$spent = array_column($result['categories'], 'spent', 'categoryId');
		$this->assertEqualsWithDelta(325.0, $spent[1], 0.001);
		$this->assertEqualsWithDelta(60.0, $spent[4], 0.001);
		$this->assertEqualsWithDelta(385.0, $result['totals']['spent'], 0.001);
		sort($asked);
		$this->assertSame([1, 2, 3, 4], $asked);
	}

	// ===== getCategoryMonthlyReport (#288) =====

	private function makeCategory(int $id, string $name, string $type, ?int $parentId = null, bool $excluded = false): \OCA\Budget\Db\Category {
		$c = new \OCA\Budget\Db\Category();
		$c->setId($id);
		$c->setName($name);
		$c->setType($type);
		$c->setParentId($parentId);
		$c->setExcludedFromReports($excluded);
		return $c;
	}

	private function categoryMonthlyFixture(): void {
		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		// Salary (income), Housing (expense) > Rent, Utilities (children)
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(1, 'Salary', 'income'),
			$this->makeCategory(2, 'Housing', 'expense'),
			$this->makeCategory(3, 'Rent', 'expense', 2),
			$this->makeCategory(4, 'Utilities', 'expense', 2),
		]);
		$this->reportQueries->method('getCategoryNetByMonth')->willReturn([
			1 => ['2026-01' => 3000.0, '2026-02' => 3000.0],
			3 => ['2026-01' => -1000.0, '2026-02' => -1000.0],
			4 => ['2026-01' => -100.0, '2026-02' => -150.0],
		]);
	}

	public function testCategoryMonthlyRollupAndAlphabeticalOrder(): void {
		$this->categoryMonthlyFixture();

		$r = $this->aggregator->getCategoryMonthlyReport('user1', '2026-01-01', '2026-02-28');

		$this->assertSame(['2026-01', '2026-02'], $r['period']['months']);

		// Alphabetical roots: Housing before Salary; Housing's children indented under it
		$names = array_map(fn($row) => $row['name'], $r['rows']);
		$this->assertSame(['Housing', 'Rent', 'Utilities', 'Salary'], $names);

		$housing = $r['rows'][0];
		$this->assertSame(0, $housing['depth']);
		$this->assertTrue($housing['isParent']);
		// Parent sums its children: -1000 + -100 = -1100 (Jan), -1000 + -150 = -1150 (Feb)
		$this->assertEqualsWithDelta(-1100.0, $housing['monthly']['2026-01'], 0.001);
		$this->assertEqualsWithDelta(-1150.0, $housing['monthly']['2026-02'], 0.001);
		$this->assertEqualsWithDelta(-2250.0, $housing['total'], 0.001);

		$this->assertSame(1, $r['rows'][1]['depth']); // Rent indented
		$this->assertSame(1, $r['rows'][2]['depth']); // Utilities indented

		$salary = $r['rows'][3];
		$this->assertEqualsWithDelta(6000.0, $salary['total'], 0.001);

		// Grand totals = sum of each category's own net (no double counting)
		$this->assertEqualsWithDelta(1900.0, $r['totals']['monthly']['2026-01'], 0.001); // 3000-1000-100
		$this->assertEqualsWithDelta(1850.0, $r['totals']['monthly']['2026-02'], 0.001); // 3000-1000-150
		$this->assertEqualsWithDelta(3750.0, $r['totals']['total'], 0.001);
	}

	public function testCategoryMonthlySortByTotal(): void {
		$this->categoryMonthlyFixture();

		$r = $this->aggregator->getCategoryMonthlyReport('user1', '2026-01-01', '2026-02-28', null, 'total');

		// By |total| desc at root: Salary (6000) before Housing (2250); children by |total|: Rent (2000) before Utilities (250)
		$names = array_map(fn($row) => $row['name'], $r['rows']);
		$this->assertSame(['Salary', 'Housing', 'Rent', 'Utilities'], $names);
		$this->assertSame('total', $r['sort']);
	}

	public function testCategoryMonthlyTakesItsMoneyFromTheReportScopedQuery(): void {
		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(5, 'Groceries', 'expense'),
		]);
		// Direct and split halves are merged (and scoped) in SQL: the
		// aggregator only shapes rows around what the query returns
		$this->reportQueries->expects($this->once())->method('getCategoryNetByMonth')
			->with('user1', '2026-01-01', '2026-01-31', 7, [7, 8])
			->willReturn([5 => ['2026-01' => -80.0]]);

		$r = $this->aggregator->getCategoryMonthlyReport('user1', '2026-01-01', '2026-01-31', 7, 'alpha', [7, 8]);

		$this->assertEqualsWithDelta(-80.0, $r['rows'][0]['monthly']['2026-01'], 0.001);
		$this->assertEqualsWithDelta(-80.0, $r['totals']['total'], 0.001);
	}

	public function testCategoryMonthlyHonorsExcludedCategories(): void {
		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		$this->conversionService->method('needsConversion')->willReturn(false);
		$this->accountMapper->method('findAll')->willReturn([]);
		// Housing is excluded; its child Rent is NOT — Rent should be promoted to root
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(1, 'Salary', 'income'),
			$this->makeCategory(2, 'Housing', 'expense', null, true),
			$this->makeCategory(3, 'Rent', 'expense', 2),
		]);
		// The query already dropped Housing's money (ReportScope); the
		// aggregator must not show its row either
		$this->reportQueries->method('getCategoryNetByMonth')->willReturn([
			1 => ['2026-01' => 3000.0],
			3 => ['2026-01' => -1000.0],
		]);

		$r = $this->aggregator->getCategoryMonthlyReport('user1', '2026-01-01', '2026-01-31');

		$names = array_map(fn($row) => $row['name'], $r['rows']);
		$this->assertNotContains('Housing', $names);          // excluded row hidden
		$this->assertContains('Rent', $names);                // child kept
		$rent = $r['rows'][array_search('Rent', $names)];
		$this->assertSame(0, $rent['depth']);                 // promoted to root
		$this->assertEqualsWithDelta(2000.0, $r['totals']['total'], 0.001);
	}

	public function testCategoryMonthlyHidesTheViewersMutedCategories(): void {
		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(1, 'Salary', 'income'),
			$this->makeCategory(2, 'Hobbies', 'expense'),
			$this->makeCategory(3, 'Books', 'expense', 2),
		]);
		$this->categoryMuteMapper->method('findMutedCategoryIds')->willReturn([2]);
		$this->reportQueries->method('getCategoryNetByMonth')->willReturn([
			1 => ['2026-01' => 3000.0],
			3 => ['2026-01' => -20.0],
		]);

		$r = $this->aggregator->getCategoryMonthlyReport('user1', '2026-01-01', '2026-01-31');

		$names = array_map(fn($row) => $row['name'], $r['rows']);
		$this->assertSame(['Books', 'Salary'], $names);
		$this->assertSame(0, $r['rows'][0]['depth']);
	}

	// ===== Liability balance sign (#353) =====
	//
	// Uses the exact figures from the issue report, so the arithmetic that was
	// argued about is pinned to a number rather than a description. Before this,
	// the file had no liability account at all and the split at
	// ReportAggregator::generateSummary was dead in the suite.

	private function summaryFor(array $accounts): array {
		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		return $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');
	}

	public function testCorrectlySignedLiabilityIsSubtractedFromNetWorth(): void {
		$result = $this->summaryFor([
			$this->makeAccount(1, 'Checking', 'checking', 14340.88, 'GBP'),
			$this->makeAccount(2, 'Loan', 'loan', -90904.56, 'GBP'),
		]);

		// currentBalance is the Dashboard's Net Worth tile.
		$this->assertEquals(-76563.68, round($result['totals']['currentBalance'], 2));
		$this->assertEquals(14340.88, $result['totals']['totalAssets']);
		$this->assertEquals(90904.56, $result['totals']['totalLiabilities']);
	}

	public function testLiabilityInCreditCountsAsAnAssetNotAPhantomDebt(): void {
		// An overpaid card is money owed TO you. Routing it into totalLiabilities
		// would make abs() report a debt that does not exist, and the Accounts
		// page (totalAssets - totalLiabilities) would understate net worth by
		// twice the credit.
		$result = $this->summaryFor([
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'Overpaid card', 'credit_card', 500.00, 'GBP'),
		]);

		$this->assertEquals(1500.00, $result['totals']['currentBalance']);
		$this->assertEquals(1500.00, $result['totals']['totalAssets']);
		$this->assertEquals(0.0, $result['totals']['totalLiabilities']);
	}

	// ===== Money adds through MoneyCalculator (#274) =====

	/**
	 * The summary totals were float running totals: 0.10 + 0.20 of income
	 * came back as 0.30000000000000004 and the net as a hair off zero.
	 */
	public function testSummaryTotalsAddWithoutFloatDrift(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(1, 'A', 'checking', 0.10, 'GBP'),
			$this->makeAccount(2, 'B', 'checking', 0.20, 'GBP'),
		]);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 0.1, 'expenses' => 0.1, 'count' => 1],
			2 => ['income' => 0.2, 'expenses' => 0.2, 'count' => 1],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0.0, 'expenses' => 0.0]);
		$this->setupDefaultMocks();
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertSame(0.3, $result['totals']['totalIncome']);
		$this->assertSame(0.3, $result['totals']['totalExpenses']);
		$this->assertSame(0.0, $result['totals']['netIncome']);
		$this->assertSame(0.3, $result['totals']['currentBalance']);
	}

	/** Totals round to the currency they are in: a crypto total keeps its 8dp. */
	public function testSummaryTotalsKeepTheCurrencysDecimals(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(1, 'Wallet', 'crypto', 0.5, 'BTC'),
		]);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 0.12345678, 'expenses' => 0.00000001, 'count' => 2],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0.0, 'expenses' => 0.0]);
		$this->setupDefaultMocks();
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertSame(0.12345678, $result['totals']['totalIncome']);
		$this->assertSame(0.00000001, $result['totals']['totalExpenses']);
		$this->assertSame(0.12345677, $result['totals']['netIncome']);
	}

	public function testCashFlowTotalsAddWithoutFloatDrift(): void {
		$this->reportQueries->method('getCashFlowByMonth')->willReturn([
			['month' => '2026-01', 'income' => 0.1, 'expenses' => 0.2, 'net' => -0.1, 'count' => 2],
			['month' => '2026-02', 'income' => 0.2, 'expenses' => 0.1, 'net' => 0.1, 'count' => 2],
		]);

		$result = $this->aggregator->getCashFlowReport('user1', 5, '2026-01-01', '2026-02-28');

		$this->assertSame(0.3, $result['totals']['income']);
		$this->assertSame(0.3, $result['totals']['expenses']);
		$this->assertSame(0.0, $result['totals']['net']);
	}
}
