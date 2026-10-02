<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use PHPUnit\Framework\TestCase;

class RecurringBillDetectorTest extends TestCase {
	private RecurringBillDetector $detector;
	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;
	private BillMapper $billMapper;
	/** @var Bill[] */
	private array $bills = [];

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->billMapper->method('findAll')->willReturnCallback(fn () => $this->bills);
		$this->detector = new RecurringBillDetector(
			$this->transactionMapper,
			$this->frequencyCalculator,
			$this->billMapper
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
		$t->setVendor($overrides['vendor'] ?? null);
		$t->setNotes($overrides['notes'] ?? null);
		$t->setBillId($overrides['billId'] ?? null);
		$t->setStatus($overrides['status'] ?? 'cleared');
		$t->setLinkedTransactionId($overrides['linkedTransactionId'] ?? null);
		$t->setPensionContribId($overrides['pensionContribId'] ?? null);
		return $t;
	}

	/**
	 * Three or more debits of one payee on the given dates.
	 *
	 * @return Transaction[]
	 */
	private function series(array $dates, array $overrides = [], int $firstId = 1): array {
		$rows = [];
		foreach (array_values($dates) as $i => $date) {
			$rows[] = $this->makeTransaction(array_merge($overrides, ['id' => $firstId + $i, 'date' => $date]));
		}
		return $rows;
	}

	private function makeBill(string $name, ?string $pattern = null, ?string $transferPattern = null): Bill {
		$bill = new Bill();
		$bill->setName($name);
		$bill->setAutoDetectPattern($pattern);
		$bill->setTransferDescriptionPattern($transferPattern);
		return $bill;
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
				$this->callback(fn ($d) => strtotime($d) !== false),
				$this->callback(fn ($d) => strtotime($d) !== false)
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

	// ── the app's own rows are not new bills ────────────────────────

	public function testDetectSkipsRowsBookedForAnExistingBill(): void {
		// Payments the app booked for a bill or transfer (mark paid, auto-pay,
		// or an imported row matched to it) came back as a nameless copy of it
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05'], ['description' => '', 'billId' => 4])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testDifferentBillsRowsNeverMergeIntoAPhantomBill(): void {
		// Gym 24.99 on the 5th and Streaming 25.40 on the 20th, both blank:
		// they rounded to the same key and became one biweekly 25.20 bill
		$rows = array_merge(
			$this->series(['2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05'], ['description' => '', 'amount' => 24.99, 'billId' => 1]),
			$this->series(['2026-04-20', '2026-05-20', '2026-06-20', '2026-07-20', '2026-08-20', '2026-09-20'], ['description' => '', 'amount' => 25.40, 'billId' => 2], 20)
		);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('biweekly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testDetectSkipsScheduledRows(): void {
		// A pre-booked row dated today is not a payment yet
		$rows = $this->series(['2026-08-01', '2026-09-01'], ['description' => 'COUNCIL TAX']);
		$rows[] = $this->makeTransaction(['id' => 9, 'description' => 'COUNCIL TAX', 'date' => '2026-10-01', 'status' => 'scheduled']);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testDetectSkipsPensionContributionLegs(): void {
		// The pension schedule already books these; a bill would debit twice
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'], ['description' => 'Pension Contribution: Workplace Pension', 'amount' => 200.0, 'pensionContribId' => 3])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testDetectBillsSkipsLinkedTransferLegs(): void {
		// A standing order to savings, linked as a transfer, is not a bill: as
		// one it pre-booked a debit with no deposit
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-05-28', '2026-06-28', '2026-07-28', '2026-08-28', '2026-09-28'], ['description' => 'SO TO SAVINGS', 'amount' => 300.0, 'linkedTransactionId' => 99])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testFindTransfersKeepsLinkedLegsAndSuggestsTheirDestination(): void {
		$dates = ['2026-05-28', '2026-06-28', '2026-07-28', '2026-08-28', '2026-09-28'];
		$rows = [];
		foreach ($dates as $i => $date) {
			$rows[] = $this->makeTransaction(['id' => 10 + $i, 'date' => $date, 'description' => 'SO TO SAVINGS', 'amount' => 300.0, 'accountId' => 1, 'linkedTransactionId' => 20 + $i]);
			$rows[] = $this->makeTransaction(['id' => 20 + $i, 'date' => $date, 'description' => 'FROM CURRENT', 'amount' => 300.0, 'accountId' => 2, 'type' => 'credit', 'linkedTransactionId' => 10 + $i]);
		}
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1', 6, true);

		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]['accountId']);
		$this->assertSame(2, $result[0]['suggestedDestinationAccountId']);
		$this->assertArrayNotHasKey('destinationAccountId', $result[0]);
		$this->assertArrayNotHasKey('isTransfer', $result[0]);
	}

	// ── already tracked by a bill ───────────────────────────────────

	public function testCandidateMatchingAnExistingBillPatternIsNotOffered(): void {
		// Imported rows the bill never got linked to still belong to it
		$this->bills = [$this->makeBill('Streaming', 'NETFLIX 999')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-15', '2026-08-15', '2026-09-15'], ['description' => 'NETFLIX 12345'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testCandidateMatchingAnExistingBillNameIsNotOffered(): void {
		$this->bills = [$this->makeBill('Netflix')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-15', '2026-08-15', '2026-09-15'], ['description' => 'Netflix subscription'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}

	public function testExistingTransferPatternHidesItsStandingOrder(): void {
		$this->bills = [$this->makeBill('Savings', null, 'SAVINGS POT')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-28', '2026-08-28', '2026-09-28'], ['description' => 'STANDING ORDER SAVINGS POT', 'amount' => 300.0])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1', 6, true));
	}

	public function testShortBillNameDoesNotHideAnUnrelatedPayee(): void {
		// A name is matched as whole words: a bill called "Car" is not every
		// "CARD PAYMENT TO ..."
		$this->bills = [$this->makeBill('Car')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-15', '2026-08-15', '2026-09-15'], ['description' => 'CARD PAYMENT TO SPOTIFY'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertCount(1, $this->detector->detectRecurringBills('user1'));
	}

	public function testUnrelatedOrNamelessBillsHideNothing(): void {
		$this->bills = [$this->makeBill('Rent', 'LANDLORD CO'), $this->makeBill('', '')];
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-15', '2026-08-15', '2026-09-15'], ['description' => 'NETFLIX 12345'])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertCount(1, $this->detector->detectRecurringBills('user1'));
	}

	// ── a usable name ───────────────────────────────────────────────

	public function testBlankDescriptionsAreGroupedAndNamedByVendor(): void {
		$rows = array_merge(
			$this->series(['2026-07-05', '2026-08-05', '2026-09-05'], ['description' => '', 'vendor' => 'PureGym', 'amount' => 24.99]),
			$this->series(['2026-07-20', '2026-08-20', '2026-09-20'], ['description' => '', 'vendor' => 'Streamco', 'amount' => 25.40], 10)
		);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($rows);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$result = $this->detector->detectRecurringBills('user1');

		$names = array_column($result, 'suggestedName');
		sort($names);
		$this->assertSame(['Puregym', 'Streamco'], $names);
	}

	public function testRowsWithNothingToNameThemAreNotOffered(): void {
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn(
			$this->series(['2026-07-05', '2026-08-05', '2026-09-05'], ['description' => '', 'amount' => 24.99])
		);
		$this->frequencyCalculator->method('detectFrequency')->willReturn('monthly');

		$this->assertSame([], $this->detector->detectRecurringBills('user1'));
	}
}
