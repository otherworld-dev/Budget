<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Manages bill CRUD operations and summary calculations.
 */
class BillService {
    private BillMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;
    private RecurringBillDetector $recurringDetector;

    public function __construct(
        BillMapper $mapper,
        FrequencyCalculator $frequencyCalculator,
        RecurringBillDetector $recurringDetector
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
        $this->recurringDetector = $recurringDetector;
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
        ?int $reminderDays = null
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
        $bill->setCreatedAt(date('Y-m-d H:i:s'));

        $nextDue = $this->frequencyCalculator->calculateNextDueDate($frequency, $dueDay, $dueMonth);
        $bill->setNextDueDate($nextDue);

        return $this->mapper->insert($bill);
    }

    public function update(int $id, string $userId, array $updates): Bill {
        $bill = $this->find($id, $userId);
        $needsRecalculation = false;
        $directDbUpdates = [];

        foreach ($updates as $key => $value) {
            // Track if we need to recalculate next due date
            if (in_array($key, ['frequency', 'dueDay', 'dueMonth', 'lastPaidDate'])) {
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
            if (method_exists($bill, $setter)) {
                $bill->$setter($value);
            }
        }

        // Apply direct database updates for null values first
        if (!empty($directDbUpdates)) {
            $this->mapper->updateFields($id, $userId, $directDbUpdates);
            // Reload entity to reflect the changes
            $bill = $this->find($id, $userId);
        }

        // Recalculate next due date if frequency or due day changed
        if ($needsRecalculation && (isset($updates['frequency']) || isset($updates['dueDay']) || isset($updates['dueMonth']))) {
            $nextDue = $this->frequencyCalculator->calculateNextDueDate(
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth()
            );
            $bill->setNextDueDate($nextDue);
        }

        // Save any non-null changes
        $this->mapper->update($bill);

        // Reload from database to ensure we return the actual saved state
        return $this->find($id, $userId);
    }

    public function delete(int $id, string $userId): void {
        $bill = $this->find($id, $userId);
        $this->mapper->delete($bill);
    }

    /**
     * Mark a bill as paid and advance to next due date.
     */
    public function markPaid(int $id, string $userId, ?string $paidDate = null): Bill {
        $bill = $this->find($id, $userId);

        $paidDate = $paidDate ?? date('Y-m-d');
        $bill->setLastPaidDate($paidDate);

        $nextDue = $this->frequencyCalculator->calculateNextDueDate(
            $bill->getFrequency(),
            $bill->getDueDay(),
            $bill->getDueMonth(),
            $bill->getNextDueDate()
        );
        $bill->setNextDueDate($nextDue);

        return $this->mapper->update($bill);
    }

    /**
     * Get monthly summary of bills.
     */
    public function getMonthlySummary(string $userId): array {
        $bills = $this->findActive($userId);

        $total = 0.0;
        $byCategory = [];
        $byFrequency = [
            'daily' => 0.0,
            'weekly' => 0.0,
            'biweekly' => 0.0,
            'monthly' => 0.0,
            'quarterly' => 0.0,
            'yearly' => 0.0,
        ];

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
        }

        return [
            'totalMonthly' => $total,
            'totalYearly' => $total * 12,
            'billCount' => count($bills),
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
