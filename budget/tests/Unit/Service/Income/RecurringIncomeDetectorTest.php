<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Income;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use PHPUnit\Framework\TestCase;

class RecurringIncomeDetectorTest extends TestCase {
	private RecurringIncomeDetector $detector;
	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;
	/** @var RecurringIncome[] */
	private array $incomes = [];

	private const MONTHLY = ['2026-04-25', '2026-05-25', '2026-06-25', '2026-07-25', '2026-08-25', '2026-09-25'];

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
		$incomeMapper = $this->createMock(RecurringIncomeMapper::class);
		$incomeMapper->method('findAll')->willReturnCallback(fn () => $this->incomes);

		$this->detector = new RecurringIncomeDetector(
			$this->transactionMapper,
			$this->frequencyCalculator,
			$incomeMapper,
			10.0 // minAmount
		);
	}

	/**
	 * Credits on the given dates.
	 *
	 * @return Transaction[]
	 */
	private function credits(array $dates, array $o = [], int $firstId = 1): array {
		$rows = [];
		foreach (array_values($dates) as $i => $date) {
			$txn = $this->makeTransaction($o['description'] ?? 'SALARY ACME', $o['amount'] ?? 2000.0, $date, $o['type'] ?? 'credit', null, $o['accountId'] ?? 1);
			$txn->setId($firstId + $i);
			$txn->setVendor($o['vendor'] ?? null);
			$txn->setNotes($o['notes'] ?? null);
			$txn->setBillId($o['billId'] ?? null);
			$txn->setStatus($o['status'] ?? 'cleared');
			$txn->setLinkedTransactionId($o['linkedTransactionId'] ?? null);
			$txn->setPensionContribId($o['pensionContribId'] ?? null);
			$rows[] = $txn;
		}
		return $rows;
	}

	private function makeIncome(string $name, ?string $pattern = null): RecurringIncome {
		$income = new RecurringIncome();
		$income->setName($name);
		$income->setAutoDetectPattern($pattern);
		return $income;
	}

	private function makeTransaction(string $description, float $amount, string $date, string $type = 'credit', ?int $categoryId = null, int $accountId = 1): Transaction {
		$txn = new Transaction();
		$txn->setDescription($description);
		$txn->setAmount($amount);
		$txn->setDate($date);
		$txn->setType($type);
		$txn->setCategoryId($categoryId);
		$txn->setAccountId($accountId);
		return $txn;
	}

	// ===== normalizeDescription =====

	public function testNormalizeDescriptionRemovesReferences(): void {
		$result = $this->detector->normalizeDescription('DWP JT055236A UNIVERSAL CREDIT');
		$this->assertStringNotContainsString('JT055236A', $result);
		$this->assertStringContainsString('universal credit', $result);
	}

	public function testNormalizeDescriptionRemovesNumbers(): void {
		$result = $this->detector->normalizeDescription('SALARY 12345 ACME CORP');
		$this->assertStringNotContainsString('12345', $result);
	}

	public function testNormalizeDescriptionLowercases(): void {
		$result = $this->detector->normalizeDescription('SALARY FROM EMPLOYER');
		$this->assertEquals(strtolower($result), $result);
	}

	// ===== generateIncomeName =====

	public function testGenerateIncomeNameCleansSalary(): void {
		$result = $this->detector->generateIncomeName('SALARY FROM ACME LTD');
		// 'SALARY' → 'Salary', 'LTD' → removed
		$this->assertStringContainsString('Salary', $result);
		$this->assertStringNotContainsString('Ltd', $result);
	}

	public function testGenerateIncomeNameRemovesNoise(): void {
		$result = $this->detector->generateIncomeName('DIRECT DEPOSIT COMPANY 123');
		$this->assertStringNotContainsString('DIRECT DEPOSIT', strtoupper($result));
	}

	// ===== generateIncomeSource =====

	public function testGenerateIncomeSourceExtractsCompany(): void {
		$result = $this->detector->generateIncomeSource('SALARY ACME CORP 12345');
		$this->assertStringContainsString('Acme Corp', $result);
	}

	public function testGenerateIncomeSourceReturnsUnknownForEmpty(): void {
		$result = $this->detector->generateIncomeSource('SALARY DEPOSIT PAYMENT');
		$this->assertEquals('Unknown Source', $result);
	}

	// ===== generatePattern =====

	public function testGeneratePatternExtractsCoreWords(): void {
		$result = $this->detector->generatePattern('ACME CORP SALARY PAYMENT 12345');
		$this->assertNotEmpty($result);
		// Should remove numbers and take first 3 meaningful words (>2 chars)
		$words = explode(' ', $result);
		$this->assertLessThanOrEqual(3, count($words));
	}

	// ===== detectRecurringIncome =====

	public function testDetectRecurringIncomeFindsMonthlyPattern(): void {
		$transactions = [
			$this->makeTransaction('SALARY ACME CORP', 3000.0, '2025-10-28'),
			$this->makeTransaction('SALARY ACME CORP', 3000.0, '2025-11-28'),
			$this->makeTransaction('SALARY ACME CORP', 3000.0, '2025-12-28'),
			$this->makeTransaction('SALARY ACME CORP', 3000.0, '2026-01-28'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringIncome('user1', 6);

		$this->assertNotEmpty($result);
		$this->assertEquals('monthly', $result[0]['frequency']);
		$this->assertEquals(3000.0, $result[0]['amount']);
		$this->assertEquals(4, $result[0]['occurrences']);
	}

	public function testDetectRecurringIncomeSkipsDebitTransactions(): void {
		$transactions = [
			$this->makeTransaction('RENT PAYMENT', -1500.0, '2025-10-01', 'debit'),
			$this->makeTransaction('RENT PAYMENT', -1500.0, '2025-11-01', 'debit'),
			$this->makeTransaction('RENT PAYMENT', -1500.0, '2025-12-01', 'debit'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringIncome('user1');
		$this->assertEmpty($result);
	}

	public function testDetectRecurringIncomeSkipsSmallAmounts(): void {
		$transactions = [
			$this->makeTransaction('SMALL CREDIT', 5.0, '2025-10-01'),
			$this->makeTransaction('SMALL CREDIT', 5.0, '2025-11-01'),
			$this->makeTransaction('SMALL CREDIT', 5.0, '2025-12-01'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringIncome('user1');
		$this->assertEmpty($result);
	}

	public function testDetectRecurringIncomeRequiresAtLeastTwoOccurrences(): void {
		$transactions = [
			$this->makeTransaction('BONUS PAYMENT', 5000.0, '2025-12-15'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringIncome('user1');
		$this->assertEmpty($result);
	}

	public function testDetectRecurringIncomeRejectsNoFrequency(): void {
		// Two occurrences but interval doesn't match any frequency
		$transactions = [
			$this->makeTransaction('WEIRD CREDIT', 1000.0, '2025-10-01'),
			$this->makeTransaction('WEIRD CREDIT', 1000.0, '2025-12-20'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn(null);

		$result = $this->detector->detectRecurringIncome('user1');
		$this->assertEmpty($result);
	}

	public function testDetectRecurringIncomeDebugModeIncludesRejected(): void {
		$transactions = [
			$this->makeTransaction('ONE-OFF CREDIT', 500.0, '2025-12-01'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringIncome('user1', 6, true);

		$this->assertArrayHasKey('detected', $result);
		$this->assertArrayHasKey('rejected', $result);
		$this->assertNotEmpty($result['rejected']);
		$this->assertEquals('too_few_occurrences', $result['rejected'][0]['reason']);
	}

	public function testDetectRecurringIncomeConfidenceIncreasesWithOccurrences(): void {
		$transactions = [];
		for ($i = 0; $i < 6; $i++) {
			$date = date('Y-m-d', strtotime("-{$i} months"));
			$transactions[] = $this->makeTransaction('SALARY ACME', 3000.0, $date);
		}

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringIncome('user1');

		$this->assertNotEmpty($result);
		// 6 occurrences → min(1.0, 6/6) = 1.0 base confidence
		$this->assertGreaterThanOrEqual(0.8, $result[0]['confidence']);
	}

	public function testDetectRecurringIncomeSortsByConfidence(): void {
		$transactions = [
			// High confidence: 4 consistent occurrences
			$this->makeTransaction('SALARY ACME', 3000.0, '2025-09-28'),
			$this->makeTransaction('SALARY ACME', 3000.0, '2025-10-28'),
			$this->makeTransaction('SALARY ACME', 3000.0, '2025-11-28'),
			$this->makeTransaction('SALARY ACME', 3000.0, '2025-12-28'),
			// Lower confidence: 2 occurrences
			$this->makeTransaction('FREELANCE JOB', 500.0, '2025-10-15'),
			$this->makeTransaction('FREELANCE JOB', 800.0, '2025-11-15'),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringIncome('user1');

		if (count($result) >= 2) {
			$this->assertGreaterThanOrEqual($result[1]['confidence'], $result[0]['confidence']);
		}
	}

	// ===== the app's own rows and transfers are not income =====

	public function testTransferDepositLegsAreNotIncome(): void {
		// A Transfers-page transfer books its deposit with a blank description
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => '', 'amount' => 500.0, 'billId' => 7, 'accountId' => 2])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testLinkedTransferCreditsAreNotIncome(): void {
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => 'TRANSFER FROM CURRENT ACCOUNT', 'amount' => 300.0, 'linkedTransactionId' => 99, 'accountId' => 2])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testRowsBookedFromAnExistingIncomeAreNotOfferedAgain(): void {
		// Auto-create and Mark received book these; offering them again
		// created a second copy of the income
		$rows = array_merge(
			$this->credits(self::MONTHLY, ['description' => '', 'notes' => 'Auto-generated from income: Salary']),
			$this->credits(self::MONTHLY, ['description' => 'Pension', 'amount' => 800.0, 'notes' => 'Auto-generated from income: State pension'], 20)
		);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testPensionWithdrawalLegsAreNotIncome(): void {
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => 'Pension withdrawal', 'amount' => 400.0, 'pensionContribId' => 3])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testScheduledRowsAreNotReceivedYet(): void {
		$rows = $this->credits(['2026-09-25'], ['description' => 'DIVIDEND XYZ', 'amount' => 120.0]);
		$rows = array_merge($rows, $this->credits(['2026-12-25'], ['description' => 'DIVIDEND XYZ', 'amount' => 120.0, 'status' => 'scheduled'], 5));
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('quarterly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testBlankCreditsFromDifferentPayersNeverShareAGroup(): void {
		// Six blank transfer deposits outnumbered five blank salary credits in
		// one '' group, and the median filter kept the transfer
		$rows = array_merge(
			$this->credits(self::MONTHLY, ['description' => '', 'amount' => 500.0, 'billId' => 7, 'accountId' => 2]),
			$this->credits(array_slice(self::MONTHLY, 1), ['description' => '', 'vendor' => 'ACME LTD', 'amount' => 2000.0], 20)
		);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringIncome('user1');

		$this->assertCount(1, $result);
		$this->assertEquals(2000.0, $result[0]['amount']);
		$this->assertSame(1, $result[0]['accountId']);
		$this->assertSame('Acme', $result[0]['suggestedName']);
	}

	public function testCreditsWithNothingToNameThemAreNotOffered(): void {
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => '', 'amount' => 650.0])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	// ===== already tracked by an income =====

	public function testCreditsMatchingAnExistingIncomePatternAreNotOffered(): void {
		$this->incomes = [$this->makeIncome('Salary', 'ACME LTD SALARY')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => 'ACME LTD SALARY'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testCreditsMatchingAnExistingIncomeNameAreNotOffered(): void {
		$this->incomes = [$this->makeIncome('Acme Salary')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => 'ACME SALARY 0925'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringIncome('user1'));
	}

	public function testAShortIncomeNameHidesOnlyWholeWords(): void {
		$this->incomes = [$this->makeIncome('Pay'), $this->makeIncome('', '')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->credits(self::MONTHLY, ['description' => 'PAYPAL PAYOUT'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertCount(1, $this->detector->detectRecurringIncome('user1'));
	}
}
