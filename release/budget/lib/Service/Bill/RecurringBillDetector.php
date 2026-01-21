<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\TransactionMapper;

/**
 * Detects recurring bills from transaction patterns.
 */
class RecurringBillDetector {
    private TransactionMapper $transactionMapper;
    private FrequencyCalculator $frequencyCalculator;

    public function __construct(
        TransactionMapper $transactionMapper,
        FrequencyCalculator $frequencyCalculator
    ) {
        $this->transactionMapper = $transactionMapper;
        $this->frequencyCalculator = $frequencyCalculator;
    }

    /**
     * Auto-detect recurring bills from transaction history.
     *
     * @param string $userId User ID
     * @param int $months Number of months to analyze
     * @return array Detected recurring patterns
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
            $frequency = $this->frequencyCalculator->detectFrequency($avgInterval);

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
            $dueDays = array_map(fn($ts) => (int)date('j', $ts), $dates);
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
     * Normalize description for grouping.
     *
     * @param string $description Transaction description
     * @return string Normalized description
     */
    public function normalizeDescription(string $description): string {
        // Remove numbers, dates, reference numbers
        $normalized = preg_replace('/\d+/', '', $description);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return strtolower(trim($normalized));
    }

    /**
     * Generate a clean bill name from description.
     *
     * @param string $description Transaction description
     * @return string Clean bill name
     */
    public function generateBillName(string $description): string {
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

    /**
     * Generate auto-detect pattern from description.
     *
     * @param string $description Transaction description
     * @return string Pattern for matching
     */
    public function generatePattern(string $description): string {
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

    /**
     * Calculate variance of values.
     *
     * @param array $values Numeric values
     * @return float Variance
     */
    private function calculateVariance(array $values): float {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }
        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return sqrt(array_sum($squaredDiffs) / $count);
    }
}
