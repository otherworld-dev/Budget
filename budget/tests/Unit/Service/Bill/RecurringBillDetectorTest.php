<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use PHPUnit\Framework\TestCase;

class RecurringBillDetectorTest extends TestCase {
	private RecurringBillDetector $detector;
	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
		$this->detector = new RecurringBillDetector(
			$this->transactionMapper,
			$this->frequencyCalculator
		);
	}

	private function makeTransaction(array $overrides = []): Transaction {
		$t = new Transaction();
		$t->setId($overrides['id'] ?? 1);
		$t->setAccountId($overrides['accountId'] ?? 1);
		$t->setCategoryId($overrides['categoryId'] ?? 5);
		$t->setDate($overrides['date'] ?? '2025-06-15');
		$t->setDescription($overrides['description'] ?? 'NETFLIX.COM');
		$t->setAmount($overrides['amount'] ?? 15.99);
		$t->setType($overrides['type'] ?? 'debit');
		return $t;
	}

	// ── normalizeDescription ────────────────────────────────────────

	public function testNormalizeDescriptionRemovesNumbers(): void {
		$this->assertSame('netflix.com', $this->detector->normalizeDescription('NETFLIX.COM 12345'));
	}

	public function testNormalizeDescriptionCollapsesWhitespace(): void {
		$this->assertSame('netflix subscription', $this->detector->normalizeDescription('NETFLIX   SUBSCRIPTION'));
	}

	public function testNormalizeDescriptionLowercases(): void {
		$this->assertSame('spotify premium', $this->detector->normalizeDescription('Spotify Premium'));
	}

	public function testNormalizeDescriptionRemovesDatesAndRefs(): void {
		$this->assertSame('dd netflix ref', $this->detector->normalizeDescription('DD NETFLIX 20240115 REF 98765'));
	}

	public function testNormalizeDescriptionTrims(): void {
		$this->assertSame('test', $this->detector->normalizeDescription('  TEST  '));
	}

	public function testNormalizeDescriptionAllNumbers(): void {
		$this->assertSame('', $this->detector->normalizeDescription('123456789'));
	}

	public function testNormalizeDescriptionEmpty(): void {
		$this->assertSame('', $this->detector->normalizeDescription(''));
	}

	public function testNormalizeDescriptionMixedContent(): void {
		$this->assertSame('card payment to amazon uk ref', $this->detector->normalizeDescription('CARD PAYMENT TO AMAZON UK REF 4829103'));
	}

	// ── generateBillName ────────────────────────────────────────────

	public function testGenerateBillNameBasic(): void {
		$this->assertSame('Netflix', $this->detector->generateBillName('NETFLIX'));
	}

	public function testGenerateBillNameRemovesDirectDebit(): void {
		$this->assertSame('Netflix', $this->detector->generateBillName('DD NETFLIX DIRECT DEBIT'));
	}

	public function testGenerateBillNameRemovesStandingOrder(): void {
		$this->assertSame('Rent', $this->detector->generateBillName('STANDING ORDER RENT'));
	}

	public function testGenerateBillNameRemovesPayment(): void {
		$this->assertSame('Netflix', $this->detector->generateBillName('NETFLIX PAYMENT'));
	}

	public function testGenerateBillNameRemovesCompanySuffixes(): void {
		$this->assertSame('British Gas', $this->detector->generateBillName('BRITISH GAS LTD'));
		$this->assertSame('British Gas', $this->detector->generateBillName('BRITISH GAS LIMITED'));
		$this->assertSame('British Gas', $this->detector->generateBillName('BRITISH GAS PLC'));
		$this->assertSame('Amazon', $this->detector->generateBillName('AMAZON INC'));
	}

	public function testGenerateBillNameTitleCase(): void {
		$this->assertSame('Virgin Media', $this->detector->generateBillName('VIRGIN MEDIA'));
	}

	public function testGenerateBillNameCollapsesSpaces(): void {
		// After removing DD, DIRECT DEBIT, etc. multiple spaces can remain
		$this->assertSame('Netflix', $this->detector->generateBillName('DD NETFLIX DIRECT DEBIT LTD'));
	}

	public function testGenerateBillNameAlreadyClean(): void {
		$this->assertSame('Spotify', $this->detector->generateBillName('Spotify'));
	}

	// ── generatePattern ─────────────────────────────────────────────

	public function testGeneratePatternBasic(): void {
		$this->assertSame('NETFLIX.COM', $this->detector->generatePattern('NETFLIX.COM'));
	}

	public function testGeneratePatternRemovesNumbers(): void {
		$this->assertSame('NETFLIX REF', $this->detector->generatePattern('NETFLIX 12345 REF 99887'));
	}

	public function testGeneratePatternLimitsToThreeWords(): void {
		$result = $this->detector->generatePattern('CARD PAYMENT TO AMAZON UK MARKETPLACE');
		$words = explode(' ', $result);
		$this->assertLessThanOrEqual(3, count($words));
	}

	public function testGeneratePatternFiltersShortWords(): void {
		// Words <= 2 chars are filtered out
		$result = $this->detector->generatePattern('A TO NETFLIX UK');
		$this->assertStringNotContainsString(' A ', " $result ");
		$this->assertStringNotContainsString(' TO ', " $result ");
		$this->assertStringContainsString('NETFLIX', $result);
	}

	public function testGeneratePatternTrims(): void {
		$result = $this->detector->generatePattern('  NETFLIX  ');
		$this->assertSame('NETFLIX', $result);
	}

	public function testGeneratePatternOnlyNumbers(): void {
		$result = $this->detector->generatePattern('12345 67890');
		$this->assertSame('', $result);
	}

	public function testGeneratePatternOnlyShortWords(): void {
		$result = $this->detector->generatePattern('A TO UK');
		$this->assertSame('', $result);
	}

	// ── detectRecurringBills (integration with mocks) ───────────────

	public function testDetectSkipsNonDebitTransactions(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'type' => 'credit', 'description' => 'SALARY', 'amount' => 3000.0, 'date' => '2025-01-15']),
			$this->makeTransaction(['id' => 2, 'type' => 'credit', 'description' => 'SALARY', 'amount' => 3000.0, 'date' => '2025-02-15']),
			$this->makeTransaction(['id' => 3, 'type' => 'credit', 'description' => 'SALARY', 'amount' => 3000.0, 'date' => '2025-03-15']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringBills('user1');
		$this->assertEmpty($result);
	}

	public function testDetectRequiresAtLeastThreeOccurrences(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'NETFLIX', 'amount' => 15.99, 'date' => '2025-01-15']),
			$this->makeTransaction(['id' => 2, 'description' => 'NETFLIX', 'amount' => 15.99, 'date' => '2025-02-15']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		$result = $this->detector->detectRecurringBills('user1');
		$this->assertEmpty($result);
	}

	public function testDetectSkipsWhenFrequencyNotDetected(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'RANDOM', 'amount' => 10.0, 'date' => '2025-01-05']),
			$this->makeTransaction(['id' => 2, 'description' => 'RANDOM', 'amount' => 10.0, 'date' => '2025-01-20']),
			$this->makeTransaction(['id' => 3, 'description' => 'RANDOM', 'amount' => 10.0, 'date' => '2025-03-10']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn(null);

		$result = $this->detector->detectRecurringBills('user1');
		$this->assertEmpty($result);
	}

	public function testDetectMonthlyBill(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'NETFLIX.COM 12345', 'amount' => 15.99, 'date' => '2025-01-15']),
			$this->makeTransaction(['id' => 2, 'description' => 'NETFLIX.COM 12346', 'amount' => 15.99, 'date' => '2025-02-15']),
			$this->makeTransaction(['id' => 3, 'description' => 'NETFLIX.COM 12347', 'amount' => 15.99, 'date' => '2025-03-15']),
			$this->makeTransaction(['id' => 4, 'description' => 'NETFLIX.COM 12348', 'amount' => 15.99, 'date' => '2025-04-15']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		$bill = $result[0];
		$this->assertSame('monthly', $bill['frequency']);
		$this->assertEqualsWithDelta(15.99, $bill['amount'], 0.01);
		$this->assertSame(4, $bill['occurrences']);
		$this->assertSame(15, $bill['dueDay']);
		$this->assertSame(1, $bill['accountId']);
		$this->assertSame(5, $bill['categoryId']);
		$this->assertSame('2025-04-15', $bill['lastSeen']);
		$this->assertNotEmpty($bill['suggestedName']);
		$this->assertNotEmpty($bill['autoDetectPattern']);
	}

	public function testDetectAveragesVariableAmounts(): void {
		// Amounts vary but all round to 80 → grouped together
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'ELECTRIC BILL', 'amount' => 80.10, 'date' => '2025-01-10']),
			$this->makeTransaction(['id' => 2, 'description' => 'ELECTRIC BILL', 'amount' => 80.40, 'date' => '2025-02-10']),
			$this->makeTransaction(['id' => 3, 'description' => 'ELECTRIC BILL', 'amount' => 80.30, 'date' => '2025-03-10']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(80.27, $result[0]['amount'], 0.01);
	}

	public function testDetectDoesNotGroupDifferentAmountBrackets(): void {
		// Same description but amounts round to different integers → separate groups
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'STORE', 'amount' => 10.0, 'date' => '2025-01-01']),
			$this->makeTransaction(['id' => 2, 'description' => 'STORE', 'amount' => 10.0, 'date' => '2025-02-01']),
			$this->makeTransaction(['id' => 3, 'description' => 'STORE', 'amount' => 10.0, 'date' => '2025-03-01']),
			$this->makeTransaction(['id' => 4, 'description' => 'STORE', 'amount' => 50.0, 'date' => '2025-01-15']),
			$this->makeTransaction(['id' => 5, 'description' => 'STORE', 'amount' => 50.0, 'date' => '2025-02-15']),
			$this->makeTransaction(['id' => 6, 'description' => 'STORE', 'amount' => 50.0, 'date' => '2025-03-15']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(2, $result);
	}

	public function testDetectSortsByConfidenceDescending(): void {
		// 6 occurrences → max confidence, 3 occurrences → lower
		$transactions = [];
		for ($i = 1; $i <= 6; $i++) {
			$transactions[] = $this->makeTransaction([
				'id' => $i,
				'description' => 'NETFLIX',
				'amount' => 15.99,
				'date' => sprintf('2025-%02d-15', $i),
			]);
		}
		for ($i = 1; $i <= 3; $i++) {
			$transactions[] = $this->makeTransaction([
				'id' => 10 + $i,
				'description' => 'SPOTIFY',
				'amount' => 9.99,
				'date' => sprintf('2025-%02d-10', $i),
			]);
		}

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(2, $result);
		// Netflix (6 occurrences) should have higher confidence than Spotify (3)
		$this->assertGreaterThanOrEqual($result[1]['confidence'], $result[0]['confidence']);
	}

	public function testDetectConfidenceReducedByIrregularIntervals(): void {
		// Irregular spacing → intervalVariance > 5 → 0.8x penalty
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'IRREGULAR', 'amount' => 20.0, 'date' => '2025-01-01']),
			$this->makeTransaction(['id' => 2, 'description' => 'IRREGULAR', 'amount' => 20.0, 'date' => '2025-01-20']),
			$this->makeTransaction(['id' => 3, 'description' => 'IRREGULAR', 'amount' => 20.0, 'date' => '2025-03-25']),
			$this->makeTransaction(['id' => 4, 'description' => 'IRREGULAR', 'amount' => 20.0, 'date' => '2025-04-01']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		// Base confidence for 4 occurrences = 4/6 ≈ 0.67, with 0.8x penalty ≈ 0.53
		$this->assertLessThan(0.7, $result[0]['confidence']);
	}

	public function testDetectConsistentBillsGetFullConfidence(): void {
		// 6 consistent monthly occurrences with same amount → max confidence 1.0
		$transactions = [];
		for ($i = 1; $i <= 6; $i++) {
			$transactions[] = $this->makeTransaction([
				'id' => $i,
				'description' => 'CONSISTENT BILL',
				'amount' => 50.0,
				'date' => sprintf('2025-%02d-15', $i),
			]);
		}

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(1.0, $result[0]['confidence'], 0.01);
	}

	public function testDetectMaxConfidenceCap(): void {
		// Even with many occurrences, confidence is capped at 1.0
		$transactions = [];
		for ($i = 1; $i <= 12; $i++) {
			$transactions[] = $this->makeTransaction([
				'id' => $i,
				'description' => 'NETFLIX',
				'amount' => 15.99,
				'date' => sprintf('2025-%02d-15', $i),
			]);
		}

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		$this->assertLessThanOrEqual(1.0, $result[0]['confidence']);
	}

	public function testDetectCalculatesAverageDueDay(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'RENT', 'amount' => 1000.0, 'date' => '2025-01-01']),
			$this->makeTransaction(['id' => 2, 'description' => 'RENT', 'amount' => 1000.0, 'date' => '2025-02-01']),
			$this->makeTransaction(['id' => 3, 'description' => 'RENT', 'amount' => 1000.0, 'date' => '2025-03-01']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertSame(1, $result[0]['dueDay']);
	}

	public function testDetectWithEmptyTransactions(): void {
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

		$result = $this->detector->detectRecurringBills('user1');
		$this->assertEmpty($result);
	}

	public function testDetectUsesFirstTransactionDescriptionAsOriginal(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'NETFLIX.COM 001', 'amount' => 16.0, 'date' => '2025-01-15']),
			$this->makeTransaction(['id' => 2, 'description' => 'NETFLIX.COM 002', 'amount' => 16.0, 'date' => '2025-02-15']),
			$this->makeTransaction(['id' => 3, 'description' => 'NETFLIX.COM 003', 'amount' => 16.0, 'date' => '2025-03-15']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		// description should be the first transaction's original description
		$this->assertSame('NETFLIX.COM 001', $result[0]['description']);
	}

	public function testDetectPassesCorrectDateRange(): void {
		$this->transactionMapper->expects($this->once())
			->method('findAllByUserAndDateRange')
			->with(
				'user1',
				$this->callback(fn($d) => strtotime($d) !== false),
				$this->callback(fn($d) => strtotime($d) !== false)
			)
			->willReturn([]);

		$this->detector->detectRecurringBills('user1', 3);
	}

	public function testDetectGroupingByNormalizedDescriptionAndRoundedAmount(): void {
		// Different reference numbers but same core description, amounts all round to 45
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'WATER CO 11111', 'amount' => 45.10, 'date' => '2025-01-05']),
			$this->makeTransaction(['id' => 2, 'description' => 'WATER CO 22222', 'amount' => 45.30, 'date' => '2025-02-05']),
			$this->makeTransaction(['id' => 3, 'description' => 'WATER CO 33333', 'amount' => 45.40, 'date' => '2025-03-05']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertCount(1, $result);
		$this->assertSame(3, $result[0]['occurrences']);
	}

	public function testDetectLastSeenIsLatestDate(): void {
		$transactions = [
			$this->makeTransaction(['id' => 1, 'description' => 'GYM', 'amount' => 30.0, 'date' => '2025-03-01']),
			$this->makeTransaction(['id' => 2, 'description' => 'GYM', 'amount' => 30.0, 'date' => '2025-01-01']),
			$this->makeTransaction(['id' => 3, 'description' => 'GYM', 'amount' => 30.0, 'date' => '2025-02-01']),
		];

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$this->assertSame('2025-03-01', $result[0]['lastSeen']);
	}
}
