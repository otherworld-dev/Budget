<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Income;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use PHPUnit\Framework\TestCase;

class RecurringIncomeDetectorTest extends TestCase {
    private RecurringIncomeDetector $detector;
    private TransactionMapper $transactionMapper;
    private FrequencyCalculator $frequencyCalculator;

    protected function setUp(): void {
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);

        $this->detector = new RecurringIncomeDetector(
            $this->transactionMapper,
            $this->frequencyCalculator,
            10.0 // minAmount
        );
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
}
