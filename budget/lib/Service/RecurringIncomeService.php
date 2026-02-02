<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Manages recurring income CRUD operations and summary calculations.
 */
class RecurringIncomeService {
    private RecurringIncomeMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;
    private RecurringIncomeDetector $recurringDetector;

    public function __construct(
        RecurringIncomeMapper $mapper,
        FrequencyCalculator $frequencyCalculator,
        RecurringIncomeDetector $recurringDetector
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
        $this->recurringDetector = $recurringDetector;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): RecurringIncome {
        return $this->mapper->find($id, $userId);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }

    public function findExpectedThisMonth(string $userId): array {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        return $this->mapper->findExpectedInRange($userId, $startDate, $endDate);
    }

    /**
     * Find upcoming income sorted by expected date.
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        return $this->mapper->findUpcoming($userId, $days);
    }

    public function create(
        string $userId,
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $expectedDay = null,
        ?int $expectedMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $source = null,
        ?string $autoDetectPattern = null,
        ?string $notes = null
    ): RecurringIncome {
        $income = new RecurringIncome();
        $income->setUserId($userId);
        $income->setName($name);
        $income->setAmount($amount);
        $income->setFrequency($frequency);
        $income->setExpectedDay($expectedDay);
        $income->setExpectedMonth($expectedMonth);
        $income->setCategoryId($categoryId);
        $income->setAccountId($accountId);
        $income->setSource($source);
        $income->setAutoDetectPattern($autoDetectPattern);
        $income->setIsActive(true);
        $income->setNotes($notes);
        $income->setCreatedAt(date('Y-m-d H:i:s'));

        $nextExpected = $this->frequencyCalculator->calculateNextDueDate($frequency, $expectedDay, $expectedMonth);
        $income->setNextExpectedDate($nextExpected);

        return $this->mapper->insert($income);
    }

    public function update(int $id, string $userId, array $updates): RecurringIncome {
        $income = $this->find($id, $userId);
        $needsRecalculation = false;
        $directDbUpdates = [];

        foreach ($updates as $key => $value) {
            // Track if we need to recalculate next expected date
            if (in_array($key, ['frequency', 'expectedDay', 'expectedMonth', 'lastReceivedDate'])) {
                $needsRecalculation = true;
            }

            // Special handling for null values - use direct DB update to bypass Entity change detection
            if ($value === null) {
                // Convert camelCase to snake_case for database column names
                $columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
                $directDbUpdates[$columnName] = null;
                continue;
            }

            $setter = 'set' . ucfirst($key);
            if (method_exists($income, $setter)) {
                $income->$setter($value);
            }
        }

        // Apply direct database updates for null values first
        if (!empty($directDbUpdates)) {
            $this->mapper->updateFields($id, $userId, $directDbUpdates);
            // Reload entity to reflect the changes
            $income = $this->find($id, $userId);
        }

        // Recalculate next expected date if needed
        if ($needsRecalculation) {
            $referenceDate = $income->getLastReceivedDate();

            $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
                $income->getFrequency(),
                $income->getExpectedDay(),
                $income->getExpectedMonth(),
                $referenceDate
            );
            $income->setNextExpectedDate($nextExpected);
        }

        // Save any non-null changes
        $this->mapper->update($income);

        // Reload from database to ensure we return the actual saved state
        return $this->find($id, $userId);
    }

    public function delete(int $id, string $userId): void {
        $income = $this->find($id, $userId);
        $this->mapper->delete($income);
    }

    /**
     * Mark income as received and advance to next expected date.
     */
    public function markReceived(int $id, string $userId, ?string $receivedDate = null): RecurringIncome {
        $income = $this->find($id, $userId);

        $received = $receivedDate ?? date('Y-m-d');
        $income->setLastReceivedDate($received);

        $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
            $income->getFrequency(),
            $income->getExpectedDay(),
            $income->getExpectedMonth(),
            $received
        );
        $income->setNextExpectedDate($nextExpected);

        return $this->mapper->update($income);
    }

    /**
     * Get monthly summary of recurring income.
     */
    public function getMonthlySummary(string $userId): array {
        $incomes = $this->findActive($userId);
        $totalMonthly = 0.0;
        $byFrequency = [];

        foreach ($incomes as $income) {
            $monthlyEquiv = $this->getMonthlyEquivalent($income);
            $totalMonthly += $monthlyEquiv;

            $freq = $income->getFrequency();
            if (!isset($byFrequency[$freq])) {
                $byFrequency[$freq] = [
                    'count' => 0,
                    'totalMonthly' => 0.0,
                ];
            }
            $byFrequency[$freq]['count']++;
            $byFrequency[$freq]['totalMonthly'] += $monthlyEquiv;
        }

        return [
            'totalCount' => count($incomes),
            'totalMonthly' => round($totalMonthly, 2),
            'totalYearly' => round($totalMonthly * 12, 2),
            'byFrequency' => $byFrequency,
        ];
    }

    /**
     * Get income expected for a specific month.
     */
    public function getIncomeForMonth(string $userId, int $year, int $month): array {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $expected = $this->mapper->findExpectedInRange($userId, $startDate, $endDate);

        $total = 0.0;
        foreach ($expected as $income) {
            $total += $income->getAmount();
        }

        return [
            'incomes' => $expected,
            'total' => round($total, 2),
            'count' => count($expected),
        ];
    }

    /**
     * Convert any income frequency to monthly equivalent.
     */
    private function getMonthlyEquivalent(RecurringIncome $income): float {
        $amount = $income->getAmount();

        return match ($income->getFrequency()) {
            'daily' => $amount * 30,
            'weekly' => $amount * 52 / 12,
            'biweekly' => $amount * 26 / 12,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }

    /**
     * Auto-detect recurring income from transaction history.
     */
    public function detectRecurringIncome(string $userId, int $months = 6, bool $debug = false): array {
        return $this->recurringDetector->detectRecurringIncome($userId, $months, $debug);
    }

    /**
     * Create recurring income entries from detected patterns.
     */
    public function createFromDetected(string $userId, array $detected): array {
        $created = [];

        foreach ($detected as $item) {
            $income = $this->create(
                $userId,
                $item['suggestedName'] ?? $item['description'],
                $item['amount'],
                $item['frequency'],
                $item['expectedDay'] ?? null,
                null, // expectedMonth
                $item['categoryId'] ?? null,
                $item['accountId'] ?? null,
                $item['source'] ?? null,
                $item['autoDetectPattern'] ?? null
            );
            $created[] = $income;
        }

        return $created;
    }

    /**
     * Check if a transaction matches any income's auto-detect pattern.
     */
    public function matchTransactionToIncome(string $userId, string $description, float $amount): ?RecurringIncome {
        $incomes = $this->findActive($userId);

        foreach ($incomes as $income) {
            $pattern = $income->getAutoDetectPattern();
            if (empty($pattern)) {
                continue;
            }

            if (stripos($description, $pattern) !== false) {
                $incomeAmount = $income->getAmount();
                // Allow 20% variance for income (more forgiving than bills)
                if (abs($amount - $incomeAmount) <= $incomeAmount * 0.2) {
                    return $income;
                }
            }
        }

        return null;
    }
}
