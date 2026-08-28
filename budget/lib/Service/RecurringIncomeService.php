<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Manages recurring income CRUD operations and summary calculations.
 */
/**
 * @extends AbstractCrudService<RecurringIncome>
 */
class RecurringIncomeService extends AbstractCrudService {
    private FrequencyCalculator $frequencyCalculator;
    private RecurringIncomeDetector $recurringDetector;
    private TransactionService $transactionService;
    private LoggerInterface $logger;
    private ?AutoShareService $autoShareService;

    public function __construct(
        RecurringIncomeMapper $mapper,
        FrequencyCalculator $frequencyCalculator,
        RecurringIncomeDetector $recurringDetector,
        TransactionService $transactionService,
        LoggerInterface $logger,
        ?AutoShareService $autoShareService = null
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
        $this->recurringDetector = $recurringDetector;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
        $this->autoShareService = $autoShareService;
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
        ?string $notes = null,
        bool $autoCreateEnabled = false,
        ?string $description = null,
        bool $excludedFromForecast = false,
        ?string $startDate = null
    ): RecurringIncome {
        $income = new RecurringIncome();
        $income->setUserId($userId);
        $income->setName($name);
        $income->setDescription($description);
        $income->setAmount($amount);
        $income->setFrequency($frequency);
        $income->setExpectedDay($expectedDay);
        $income->setExpectedMonth($expectedMonth);
        $income->setCategoryId($categoryId);
        $income->setAccountId($accountId);
        $income->setSource($source);
        $income->setAutoDetectPattern($autoDetectPattern);
        $income->setIsActive(true);
        $income->setAutoCreateEnabled($autoCreateEnabled);
        $income->setNotes($notes);
        $income->setExcludedFromForecast($excludedFromForecast);
        $income->setStartDate($startDate);
        $income->setCreatedAt(date('Y-m-d H:i:s'));

        // startDate anchors weekly/biweekly schedules: occurrences fall on
        // startDate + n*interval, so week parity comes from the user's first
        // payment date, not from the week the entry was created in (#363)
        $nextExpected = $this->frequencyCalculator->calculateNextDueDate($frequency, $expectedDay, $expectedMonth, null, null, false, $startDate);
        $income->setNextExpectedDate($nextExpected);

        $income = $this->mapper->insert($income);
        if ($this->autoShareService !== null) {
            $this->autoShareService->autoShareNewEntity($userId, ShareItem::TYPE_RECURRING_INCOME, $income->getId());
        }
        return $income;
    }

    public function update(int $id, string $userId, array $updates): RecurringIncome {
        $income = $this->find($id, $userId);
        $needsRecalculation = false;
        $directDbUpdates = [];

        foreach ($updates as $key => $value) {
            // Track if we need to recalculate next expected date
            if (in_array($key, ['frequency', 'expectedDay', 'expectedMonth', 'lastReceivedDate', 'startDate'])) {
                $needsRecalculation = true;
            }

            // Special handling for null values - use direct DB update to bypass Entity change detection
            if ($value === null) {
                // Convert camelCase to snake_case for database column names
                $columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
                $directDbUpdates[$columnName] = null;
                if (property_exists($income, $key)) {
                    $setter = 'set' . ucfirst($key);
                    $income->$setter(null);
                }
                continue;
            }

            if (property_exists($income, $key)) {
                $setter = 'set' . ucfirst($key);
                $income->$setter($value);
            }
        }

        // Apply direct database updates for null values first
        if (!empty($directDbUpdates)) {
            $this->mapper->updateFields($id, $userId, $directDbUpdates);
        }

        // Recalculate next expected date if needed. The startDate anchors
        // weekly/biweekly parity (#363).
        if ($needsRecalculation) {
            // The last received date only means "advance strictly past this"
            // while it is today or later: a receipt weeks ago must not push
            // the expectation past an anchor that falls due today — setting
            // First payment date = today has to yield today, not
            // today + interval (#363 review). markReceived keeps its own
            // strictly-after semantics for the date it just recorded.
            $referenceDate = $income->getLastReceivedDate();
            if ($referenceDate !== null && $referenceDate < date('Y-m-d')) {
                $referenceDate = null;
            }

            $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
                $income->getFrequency(),
                $income->getExpectedDay(),
                $income->getExpectedMonth(),
                $referenceDate,
                null,
                false,
                $income->getStartDate()
            );
            $income->setNextExpectedDate($nextExpected);
        }

        // Save any non-null changes
        $this->mapper->update($income);

        // Reload from database to ensure we return the actual saved state
        return $this->find($id, $userId);
    }

    /**
     * Process auto-create for a recurring income entry.
     * Creates a transaction and advances the next expected date.
     *
     * @param int $incomeId Income ID
     * @param string $userId User ID
     * @return array ['success' => bool, 'message' => string, 'income' => ?RecurringIncome]
     */
    public function processAutoCreate(int $incomeId, string $userId): array {
        try {
            $income = $this->find($incomeId, $userId);

            if (!$income->getAutoCreateEnabled()) {
                return ['success' => false, 'message' => 'Auto-create not enabled'];
            }

            if (!$income->getAccountId()) {
                return ['success' => false, 'message' => 'No account set for income'];
            }

            // Capture the current expected date before advancing (used as transaction date)
            $transactionDate = $income->getNextExpectedDate() ?? date('Y-m-d');

            $this->transactionService->createFromIncome($userId, $income, $transactionDate, 'cleared');

            // Advance next expected date (startDate anchors parity, #363)
            $nextDate = $this->frequencyCalculator->calculateNextDueDate(
                $income->getFrequency(),
                $income->getExpectedDay(),
                $income->getExpectedMonth(),
                $transactionDate,
                null,
                false,
                $income->getStartDate()
            );
            $income->setNextExpectedDate($nextDate);
            $income->setLastReceivedDate($transactionDate);
            $this->mapper->update($income);

            return ['success' => true, 'income' => $income];
        } catch (\Exception $e) {
            $this->logger->warning("Auto-create failed for income {$incomeId}: {$e->getMessage()}");
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Mark income as received and advance to next expected date.
     */
    public function markReceived(int $id, string $userId, ?string $receivedDate = null, bool $createTransaction = false): RecurringIncome {
        $income = $this->find($id, $userId);

        // Capture the current expected date before advancing (used as transaction date)
        $transactionDate = $income->getNextExpectedDate() ?? date('Y-m-d');

        $received = $receivedDate ?? date('Y-m-d');
        $income->setLastReceivedDate($received);

        // With a startDate anchor the calculator recomputes from the anchor
        // (first occurrence after the received date), so an early or late
        // receipt cannot shift the week parity (#363); without one the
        // received date remains the base as before.
        $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
            $income->getFrequency(),
            $income->getExpectedDay(),
            $income->getExpectedMonth(),
            $received,
            null,
            false,
            $income->getStartDate()
        );
        $income->setNextExpectedDate($nextExpected);

        $income = $this->mapper->update($income);

        // Create a cleared transaction for the received income if requested
        if ($createTransaction && $income->getAccountId() !== null) {
            try {
                $this->transactionService->createFromIncome($userId, $income, $transactionDate, 'cleared');
            } catch (\Exception $e) {
                $this->logger->warning("Failed to create transaction for income {$id}: {$e->getMessage()}");
            }
        }

        // Auto-deactivate one-time income after receiving
        if ($income->getFrequency() === 'one-time') {
            $income->setIsActive(false);
            $income->setNextExpectedDate(null);
            $income = $this->mapper->update($income);
        }

        return $income;
    }

    /**
     * Get monthly summary of recurring income.
     */
    public function getMonthlySummary(string $userId): array {
        $incomes = $this->findActive($userId);
        $totalMonthly = 0.0;
        $expectedThisMonth = 0;
        $receivedThisMonth = 0;
        $byFrequency = [];

        $startOfMonth = date('Y-m-01');
        $endOfMonth = date('Y-m-t');

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

            $nextExpected = $income->getNextExpectedDate();
            if ($nextExpected && $nextExpected >= $startOfMonth && $nextExpected <= $endOfMonth) {
                $expectedThisMonth++;
            }

            $lastReceived = $income->getLastReceivedDate();
            if ($lastReceived && $lastReceived >= $startOfMonth && $lastReceived <= $endOfMonth) {
                $receivedThisMonth++;
            }
        }

        return [
            'activeCount' => count($incomes),
            'expectedThisMonth' => $expectedThisMonth,
            'receivedThisMonth' => $receivedThisMonth,
            'monthlyTotal' => round($totalMonthly, 2),
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
     * Convert any income frequency to monthly equivalent. Delegates to the
     * shared FrequencyCalculator (this map previously halved semi-monthly
     * income and counted one-time income at full value every month).
     */
    private function getMonthlyEquivalent(RecurringIncome $income): float {
        if ($income->getFrequency() === 'one-time') {
            // Not a recurring monthly commitment — counting it monthly until
            // received inflated the summary (matches RecurringBudgetService)
            return 0.0;
        }
        return $this->frequencyCalculator->getMonthlyEquivalentFromValues(
            $income->getAmount(),
            $income->getFrequency()
        );
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
