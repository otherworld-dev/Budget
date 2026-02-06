<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Manages bill CRUD operations and summary calculations.
 */
class BillService {
    private BillMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;
    private RecurringBillDetector $recurringDetector;
    private TransactionService $transactionService;

    public function __construct(
        BillMapper $mapper,
        FrequencyCalculator $frequencyCalculator,
        RecurringBillDetector $recurringDetector,
        TransactionService $transactionService
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
        $this->recurringDetector = $recurringDetector;
        $this->transactionService = $transactionService;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Bill {
        return $this->mapper->find($id, $userId);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }

    public function findOverdue(string $userId): array {
        return $this->mapper->findOverdue($userId);
    }

    public function findDueThisMonth(string $userId): array {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        return $this->mapper->findDueInRange($userId, $startDate, $endDate);
    }

    /**
     * Find upcoming bills (including overdue) sorted by due date.
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        $overdue = $this->mapper->findOverdue($userId);
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $upcoming = $this->mapper->findDueInRange($userId, $startDate, $endDate);

        $allBills = array_merge($overdue, $upcoming);

        // Remove duplicates
        $seen = [];
        $uniqueBills = [];
        foreach ($allBills as $bill) {
            $id = $bill->getId();
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $uniqueBills[] = $bill;
            }
        }

        // Sort by next due date
        usort($uniqueBills, function($a, $b) {
            $dateA = $a->getNextDueDate() ?? '9999-12-31';
            $dateB = $b->getNextDueDate() ?? '9999-12-31';
            return strcmp($dateA, $dateB);
        });

        return $uniqueBills;
    }

    public function create(
        string $userId,
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $dueDay = null,
        ?int $dueMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $autoDetectPattern = null,
        ?string $notes = null,
        ?int $reminderDays = null,
        ?string $customRecurrencePattern = null,
        bool $createTransaction = false,
        ?string $transactionDate = null
    ): Bill {
        $bill = new Bill();
        $bill->setUserId($userId);
        $bill->setName($name);
        $bill->setAmount($amount);
        $bill->setFrequency($frequency);
        $bill->setDueDay($dueDay);
        $bill->setDueMonth($dueMonth);
        $bill->setCategoryId($categoryId);
        $bill->setAccountId($accountId);
        $bill->setAutoDetectPattern($autoDetectPattern);
        $bill->setIsActive(true);
        $bill->setNotes($notes);
        $bill->setReminderDays($reminderDays);
        $bill->setCustomRecurrencePattern($customRecurrencePattern);
        $bill->setCreatedAt(date('Y-m-d H:i:s'));

        $nextDue = $this->frequencyCalculator->calculateNextDueDate($frequency, $dueDay, $dueMonth, null, $customRecurrencePattern);
        $bill->setNextDueDate($nextDue);

        $bill = $this->mapper->insert($bill);

        // Create future transaction if requested and bill has account
        if ($createTransaction && $accountId !== null) {
            try {
                $this->transactionService->createFromBill(
                    $userId,
                    $bill,
                    $transactionDate
                );
            } catch (\Exception $e) {
                // Log error but don't fail bill creation
                error_log("Failed to create transaction for bill {$bill->getId()}: {$e->getMessage()}");
            }
        }

        return $bill;
    }

    public function update(int $id, string $userId, array $updates): Bill {
        $bill = $this->find($id, $userId);
        $needsRecalculation = false;
        $dbUpdates = [];

        foreach ($updates as $key => $value) {
            // Track if we need to recalculate next due date
            if (in_array($key, ['frequency', 'dueDay', 'dueMonth', 'lastPaidDate', 'customRecurrencePattern'])) {
                $needsRecalculation = true;
            }

            // Convert camelCase to snake_case for database column names
            $columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
            $dbUpdates[$columnName] = $value;
        }

        // Recalculate next due date if frequency or due day or custom pattern changed
        if ($needsRecalculation && (array_key_exists('frequency', $updates) || array_key_exists('dueDay', $updates) || array_key_exists('dueMonth', $updates) || array_key_exists('customRecurrencePattern', $updates))) {
            // Apply updates to get current state for calculation
            foreach ($updates as $key => $value) {
                $setter = 'set' . ucfirst($key);
                if (method_exists($bill, $setter)) {
                    $bill->$setter($value);
                }
            }

            $nextDue = $this->frequencyCalculator->calculateNextDueDate(
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth(),
                null,
                $bill->getCustomRecurrencePattern()
            );
            $dbUpdates['next_due_date'] = $nextDue;
        }

        // Apply all updates directly to database
        if (!empty($dbUpdates)) {
            $this->mapper->updateFields($id, $userId, $dbUpdates);
        }

        // Reload from database to ensure we return the actual saved state
        return $this->find($id, $userId);
    }

    public function delete(int $id, string $userId): void {
        $bill = $this->find($id, $userId);
        $this->mapper->delete($bill);
    }

    /**
     * Mark a bill as paid and advance to next due date.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @param string|null $paidDate Date bill was paid (defaults to today)
     * @param bool $createNextTransaction Whether to create transaction for next occurrence
     * @return Bill Updated bill
     */
    public function markPaid(int $id, string $userId, ?string $paidDate = null, bool $createNextTransaction = true): Bill {
        $bill = $this->find($id, $userId);

        $paidDate = $paidDate ?? date('Y-m-d');
        $bill->setLastPaidDate($paidDate);

        $nextDue = $this->frequencyCalculator->calculateNextDueDate(
            $bill->getFrequency(),
            $bill->getDueDay(),
            $bill->getDueMonth(),
            $bill->getNextDueDate(),
            $bill->getCustomRecurrencePattern()
        );
        $bill->setNextDueDate($nextDue);

        $bill = $this->mapper->update($bill);

        // Auto-create transaction for next occurrence if bill has account
        if ($createNextTransaction && $bill->getAccountId() !== null) {
            try {
                $this->transactionService->createFromBill($userId, $bill, null);
            } catch (\Exception $e) {
                error_log("Failed to create next transaction for bill {$id}: {$e->getMessage()}");
            }
        }

        return $bill;
    }

    /**
     * Get monthly summary of bills.
     */
    public function getMonthlySummary(string $userId): array {
        $bills = $this->findActive($userId);

        $total = 0.0;
        $dueThisMonth = 0;
        $overdue = 0;
        $paidThisMonth = 0;
        $byCategory = [];
        $byFrequency = [
            'daily' => 0.0,
            'weekly' => 0.0,
            'biweekly' => 0.0,
            'monthly' => 0.0,
            'quarterly' => 0.0,
            'yearly' => 0.0,
        ];

        $today = date('Y-m-d');
        $startOfMonth = date('Y-m-01');
        $endOfMonth = date('Y-m-t');

        foreach ($bills as $bill) {
            $monthlyAmount = $this->frequencyCalculator->getMonthlyEquivalent($bill);
            $total += $monthlyAmount;

            $freq = $bill->getFrequency();
            if (isset($byFrequency[$freq])) {
                $byFrequency[$freq] += $bill->getAmount();
            }

            $catId = $bill->getCategoryId() ?? 0;
            if (!isset($byCategory[$catId])) {
                $byCategory[$catId] = 0.0;
            }
            $byCategory[$catId] += $monthlyAmount;

            // Check if due this month
            $nextDue = $bill->getNextDueDate();
            if ($nextDue && $nextDue >= $startOfMonth && $nextDue <= $endOfMonth) {
                $dueThisMonth++;
            }

            // Check if overdue
            if ($nextDue && $nextDue < $today) {
                $isPaid = $this->checkIfPaidInPeriod($bill, $startOfMonth, $endOfMonth);
                if (!$isPaid) {
                    $overdue++;
                }
            }

            // Check if paid this month
            if ($this->checkIfPaidInPeriod($bill, $startOfMonth, $endOfMonth)) {
                $paidThisMonth++;
            }
        }

        return [
            'totalMonthly' => $total,
            'monthlyTotal' => $total, // Alias for frontend compatibility
            'totalYearly' => $total * 12,
            'billCount' => count($bills),
            'dueThisMonth' => $dueThisMonth,
            'overdue' => $overdue,
            'paidThisMonth' => $paidThisMonth,
            'byCategory' => $byCategory,
            'byFrequency' => $byFrequency,
        ];
    }

    /**
     * Get bill status for current month showing paid/unpaid.
     */
    public function getBillStatusForMonth(string $userId, ?string $month = null): array {
        $month = $month ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $bills = $this->mapper->findDueInRange($userId, $startDate, $endDate);
        $result = [];

        foreach ($bills as $bill) {
            $isPaid = $this->checkIfPaidInPeriod($bill, $startDate, $endDate);

            $result[] = [
                'bill' => $bill,
                'isPaid' => $isPaid,
                'dueDate' => $bill->getNextDueDate(),
                'isOverdue' => !$isPaid && $bill->getNextDueDate() < date('Y-m-d'),
            ];
        }

        return $result;
    }

    /**
     * Auto-detect recurring bills from transaction history.
     */
    public function detectRecurringBills(string $userId, int $months = 6): array {
        return $this->recurringDetector->detectRecurringBills($userId, $months);
    }

    /**
     * Create bills from detected patterns.
     */
    public function createFromDetected(string $userId, array $detected): array {
        $created = [];

        foreach ($detected as $item) {
            $bill = $this->create(
                $userId,
                $item['suggestedName'] ?? $item['description'],
                $item['amount'],
                $item['frequency'],
                $item['dueDay'] ?? null,
                null,
                $item['categoryId'] ?? null,
                $item['accountId'] ?? null,
                $item['autoDetectPattern'] ?? null
            );
            $created[] = $bill;
        }

        return $created;
    }

    /**
     * Check if a transaction matches any bill's auto-detect pattern.
     */
    public function matchTransactionToBill(string $userId, string $description, float $amount): ?Bill {
        $bills = $this->findActive($userId);

        foreach ($bills as $bill) {
            $pattern = $bill->getAutoDetectPattern();
            if (empty($pattern)) {
                continue;
            }

            if (stripos($description, $pattern) !== false) {
                $billAmount = $bill->getAmount();
                if (abs($amount - $billAmount) <= $billAmount * 0.1) {
                    return $bill;
                }
            }
        }

        return null;
    }

    private function checkIfPaidInPeriod(Bill $bill, string $startDate, string $endDate): bool {
        $lastPaid = $bill->getLastPaidDate();
        if (!$lastPaid) {
            return false;
        }
        return $lastPaid >= $startDate && $lastPaid <= $endDate;
    }
}
