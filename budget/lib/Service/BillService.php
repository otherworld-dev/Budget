<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class BillService {
    private BillMapper $mapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        BillMapper $mapper,
        TransactionMapper $transactionMapper
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
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
     * Find upcoming bills (including overdue) sorted by due date
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        // Get overdue bills first
        $overdue = $this->mapper->findOverdue($userId);

        // Get bills due in the next N days
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $upcoming = $this->mapper->findDueInRange($userId, $startDate, $endDate);

        // Merge and sort by next due date
        $allBills = array_merge($overdue, $upcoming);

        // Remove duplicates (in case overdue bills are also in range)
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
        ?string $notes = null
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
        $bill->setCreatedAt(date('Y-m-d H:i:s'));

        // Calculate next due date
        $nextDue = $this->calculateNextDueDate($frequency, $dueDay, $dueMonth);
        $bill->setNextDueDate($nextDue);

        return $this->mapper->insert($bill);
    }

    public function update(int $id, string $userId, array $updates): Bill {
        $bill = $this->find($id, $userId);

        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($bill, $setter)) {
                $bill->$setter($value);
            }
        }

        // Recalculate next due date if frequency or due day changed
        if (isset($updates['frequency']) || isset($updates['dueDay']) || isset($updates['dueMonth'])) {
            $nextDue = $this->calculateNextDueDate(
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth()
            );
            $bill->setNextDueDate($nextDue);
        }

        return $this->mapper->update($bill);
    }

    public function delete(int $id, string $userId): void {
        $bill = $this->find($id, $userId);
        $this->mapper->delete($bill);
    }

    /**
     * Mark a bill as paid and advance to next due date
     */
    public function markPaid(int $id, string $userId, ?string $paidDate = null): Bill {
        $bill = $this->find($id, $userId);

        $paidDate = $paidDate ?? date('Y-m-d');
        $bill->setLastPaidDate($paidDate);

        // Calculate next due date from current due date
        $nextDue = $this->calculateNextDueDate(
            $bill->getFrequency(),
            $bill->getDueDay(),
            $bill->getDueMonth(),
            $bill->getNextDueDate()
        );
        $bill->setNextDueDate($nextDue);

        return $this->mapper->update($bill);
    }

    /**
     * Get monthly summary of bills
     */
    public function getMonthlySummary(string $userId): array {
        $bills = $this->findActive($userId);

        $total = 0.0;
        $byCategory = [];
        $byFrequency = [
            'weekly' => 0.0,
            'monthly' => 0.0,
            'quarterly' => 0.0,
            'yearly' => 0.0,
        ];

        foreach ($bills as $bill) {
            $monthlyAmount = $this->getMonthlyEquivalent($bill);
            $total += $monthlyAmount;

            // Group by frequency
            $freq = $bill->getFrequency();
            if (isset($byFrequency[$freq])) {
                $byFrequency[$freq] += $bill->getAmount();
            }

            // Group by category
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
     * Get bill status for current month showing paid/unpaid
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
     * Auto-detect recurring bills from transaction history
     */
    public function detectRecurringBills(string $userId, int $months = 6): array {
        $startDate = date('Y-m-d', strtotime("-{$months} months"));
        $endDate = date('Y-m-d');

        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        $grouped = [];

        // Group transactions by description and approximate amount
        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'debit') {
                continue;
            }

            $desc = $this->normalizeDescription($transaction->getDescription());
            $amount = $transaction->getAmount();

            // Create key with rounded amount (to handle slight variations)
            $amountKey = round($amount, 0);
            $key = $desc . '|' . $amountKey;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'description' => $transaction->getDescription(),
                    'amount' => $amount,
                    'amounts' => [],
                    'dates' => [],
                    'categoryId' => $transaction->getCategoryId(),
                    'accountId' => $transaction->getAccountId(),
                ];
            }

            $grouped[$key]['dates'][] = $transaction->getDate();
            $grouped[$key]['amounts'][] = $amount;
        }

        $detected = [];

        foreach ($grouped as $data) {
            if (count($data['dates']) < 3) {
                continue;
            }

            // Sort dates and calculate intervals
            $dates = array_map('strtotime', $data['dates']);
            sort($dates);

            $intervals = [];
            for ($i = 1; $i < count($dates); $i++) {
                $intervalDays = ($dates[$i] - $dates[$i - 1]) / (24 * 60 * 60);
                $intervals[] = $intervalDays;
            }

            $avgInterval = array_sum($intervals) / count($intervals);
            $frequency = $this->detectFrequency($avgInterval);

            if ($frequency === null) {
                continue;
            }

            // Calculate average amount
            $avgAmount = array_sum($data['amounts']) / count($data['amounts']);

            // Calculate confidence based on consistency
            $intervalVariance = $this->calculateVariance($intervals);
            $amountVariance = $this->calculateVariance($data['amounts']);

            $confidence = min(1.0, count($data['dates']) / 6);
            if ($intervalVariance > 5) {
                $confidence *= 0.8;
            }
            if ($amountVariance > $avgAmount * 0.1) {
                $confidence *= 0.9;
            }

            // Detect typical due day
            $dueDays = array_map(function ($ts) {
                return (int)date('j', $ts);
            }, $dates);
            $avgDueDay = (int)round(array_sum($dueDays) / count($dueDays));

            $detected[] = [
                'description' => $data['description'],
                'suggestedName' => $this->generateBillName($data['description']),
                'amount' => round($avgAmount, 2),
                'frequency' => $frequency,
                'dueDay' => $avgDueDay,
                'categoryId' => $data['categoryId'],
                'accountId' => $data['accountId'],
                'occurrences' => count($data['dates']),
                'confidence' => round($confidence, 2),
                'autoDetectPattern' => $this->generatePattern($data['description']),
                'lastSeen' => date('Y-m-d', max($dates)),
            ];
        }

        // Sort by confidence descending
        usort($detected, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $detected;
    }

    /**
     * Create bills from detected patterns
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
     * Check if a transaction matches any bill's auto-detect pattern
     */
    public function matchTransactionToBill(string $userId, string $description, float $amount): ?Bill {
        $bills = $this->findActive($userId);

        foreach ($bills as $bill) {
            $pattern = $bill->getAutoDetectPattern();
            if (empty($pattern)) {
                continue;
            }

            // Check if description matches pattern
            if (stripos($description, $pattern) !== false) {
                // Check if amount is within 10% tolerance
                $billAmount = $bill->getAmount();
                if (abs($amount - $billAmount) <= $billAmount * 0.1) {
                    return $bill;
                }
            }
        }

        return null;
    }

    private function calculateNextDueDate(
        string $frequency,
        ?int $dueDay,
        ?int $dueMonth,
        ?string $fromDate = null
    ): string {
        $baseDate = $fromDate ? new \DateTime($fromDate) : new \DateTime();
        $today = new \DateTime();

        switch ($frequency) {
            case 'weekly':
                $dayOfWeek = $dueDay ?? 1; // Default to Monday
                $next = clone $baseDate;
                $currentDayOfWeek = (int)$next->format('N');
                $daysToAdd = ($dayOfWeek - $currentDayOfWeek + 7) % 7;
                if ($daysToAdd === 0 && $next <= $today) {
                    $daysToAdd = 7;
                }
                $next->modify("+{$daysToAdd} days");
                return $next->format('Y-m-d');

            case 'monthly':
                $day = $dueDay ?? 1;
                $next = clone $baseDate;
                $next->setDate((int)$next->format('Y'), (int)$next->format('m'), min($day, (int)$next->format('t')));
                if ($next <= $today) {
                    $next->modify('+1 month');
                    $next->setDate((int)$next->format('Y'), (int)$next->format('m'), min($day, (int)$next->format('t')));
                }
                return $next->format('Y-m-d');

            case 'quarterly':
                $day = $dueDay ?? 1;
                $next = clone $baseDate;
                $currentMonth = (int)$next->format('n');
                $quarterMonth = ((int)ceil($currentMonth / 3)) * 3 - 2; // First month of quarter
                if ($dueMonth) {
                    $quarterMonth = $dueMonth;
                }
                $next->setDate((int)$next->format('Y'), $quarterMonth, min($day, 28));
                if ($next <= $today) {
                    $next->modify('+3 months');
                }
                return $next->format('Y-m-d');

            case 'yearly':
                $day = $dueDay ?? 1;
                $month = $dueMonth ?? 1;
                $next = clone $baseDate;
                $next->setDate((int)$next->format('Y'), $month, min($day, 28));
                if ($next <= $today) {
                    $next->modify('+1 year');
                }
                return $next->format('Y-m-d');

            default:
                return $baseDate->format('Y-m-d');
        }
    }

    private function getMonthlyEquivalent(Bill $bill): float {
        $amount = $bill->getAmount();

        return match ($bill->getFrequency()) {
            'weekly' => $amount * 52 / 12,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }

    private function checkIfPaidInPeriod(Bill $bill, string $startDate, string $endDate): bool {
        $lastPaid = $bill->getLastPaidDate();
        if (!$lastPaid) {
            return false;
        }
        return $lastPaid >= $startDate && $lastPaid <= $endDate;
    }

    private function normalizeDescription(string $description): string {
        // Remove numbers, dates, reference numbers
        $normalized = preg_replace('/\d+/', '', $description);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return strtolower(trim($normalized));
    }

    private function detectFrequency(float $avgIntervalDays): ?string {
        if ($avgIntervalDays >= 6 && $avgIntervalDays <= 8) {
            return 'weekly';
        }
        if ($avgIntervalDays >= 25 && $avgIntervalDays <= 35) {
            return 'monthly';
        }
        if ($avgIntervalDays >= 85 && $avgIntervalDays <= 100) {
            return 'quarterly';
        }
        if ($avgIntervalDays >= 350 && $avgIntervalDays <= 380) {
            return 'yearly';
        }
        return null;
    }

    private function calculateVariance(array $values): float {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }
        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return sqrt(array_sum($squaredDiffs) / $count);
    }

    private function generateBillName(string $description): string {
        // Common patterns to clean up
        $patterns = [
            '/\bDD\b/i' => '',
            '/\bDIRECT DEBIT\b/i' => '',
            '/\bSTANDING ORDER\b/i' => '',
            '/\bPAYMENT\b/i' => '',
            '/\b(LTD|LIMITED|PLC|INC)\b/i' => '',
            '/\s+/' => ' ',
        ];

        $name = $description;
        foreach ($patterns as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name);
        }

        return trim(ucwords(strtolower($name)));
    }

    private function generatePattern(string $description): string {
        // Extract the core identifier from description
        $pattern = preg_replace('/\d+/', '', $description);
        $pattern = preg_replace('/\s+/', ' ', $pattern);
        $pattern = trim($pattern);

        // Take first few meaningful words
        $words = explode(' ', $pattern);
        $words = array_filter($words, fn($w) => strlen($w) > 2);
        $words = array_slice($words, 0, 3);

        return implode(' ', $words);
    }
}
