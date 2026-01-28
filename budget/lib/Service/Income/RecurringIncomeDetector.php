<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Income;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;

/**
 * Detects recurring income from transaction patterns.
 */
class RecurringIncomeDetector {
	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;
	private float $minAmount;

	public function __construct(
		TransactionMapper $transactionMapper,
		FrequencyCalculator $frequencyCalculator,
		float $minAmount = 10.0
	) {
		$this->transactionMapper = $transactionMapper;
		$this->frequencyCalculator = $frequencyCalculator;
		$this->minAmount = $minAmount;
	}

	/**
	 * Auto-detect recurring income from transaction history.
	 *
	 * @param string $userId User ID
	 * @param int $months Number of months to analyze
	 * @param bool $debug Include debug information about rejected patterns
	 * @return array Detected recurring patterns
	 */
	public function detectRecurringIncome(string $userId, int $months = 6, bool $debug = false): array {
		$startDate = date('Y-m-d', strtotime("-{$months} months"));
		$endDate = date('Y-m-d');

		$transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

		$grouped = [];

		// Group transactions by description and approximate amount
		foreach ($transactions as $transaction) {
			// Only analyze credit transactions (income)
			if ($transaction->getType() !== 'credit') {
				continue;
			}

			// Filter out small deposits (ATM deposits, misc small credits)
			if (abs($transaction->getAmount()) < $this->minAmount) {
				continue;
			}

			$desc = $this->normalizeDescription($transaction->getDescription());
			$amount = abs($transaction->getAmount());

			// For income, group by description only (no amount bucketing)
			// Benefits and freelance income can vary significantly
			$key = $desc;

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
		$debugRejected = [];

		foreach ($grouped as $data) {
			// Filter out outliers: amounts that are more than 50% different from median
			// This handles cases like £10 transaction mixed with £300+ benefit payments
			$amounts = $data['amounts'];
			sort($amounts);
			$medianAmount = $amounts[intdiv(count($amounts), 2)];

			// Filter transactions: keep only those within 50% of median
			$filteredData = [];
			foreach (array_keys($data['dates']) as $i) {
				$amount = $data['amounts'][$i];
				$percentDiff = abs($amount - $medianAmount) / $medianAmount;

				if ($percentDiff <= 0.5) {
					$filteredData['dates'][] = $data['dates'][$i];
					$filteredData['amounts'][] = $amount;
				}
			}

			// Use filtered data if we still have enough transactions
			if (count($filteredData['dates'] ?? []) >= 2) {
				$data['dates'] = $filteredData['dates'];
				$data['amounts'] = $filteredData['amounts'];
			}

			// Sort dates and calculate intervals first (for debug info)
			$dates = array_map('strtotime', $data['dates']);
			sort($dates);

			$intervals = [];
			for ($i = 1; $i < count($dates); $i++) {
				$intervalDays = ($dates[$i] - $dates[$i - 1]) / (24 * 60 * 60);
				$intervals[] = $intervalDays;
			}

			$avgInterval = count($intervals) > 0 ? array_sum($intervals) / count($intervals) : 0;

			// Track rejection reasons for debug
			$rejectionReason = null;

			// Require at least 2 occurrences (reduced from 3 for better detection)
			if (count($data['dates']) < 2) {
				$rejectionReason = 'too_few_occurrences';
				if ($debug) {
					$debugRejected[] = [
						'description' => $data['description'],
						'occurrences' => count($data['dates']),
						'avgInterval' => round($avgInterval, 1),
						'reason' => $rejectionReason,
					];
				}
				continue;
			}

			$frequency = $this->frequencyCalculator->detectFrequency($avgInterval);

			if ($frequency === null) {
				$rejectionReason = 'no_matching_frequency';
				if ($debug) {
					$debugRejected[] = [
						'description' => $data['description'],
						'occurrences' => count($data['dates']),
						'avgInterval' => round($avgInterval, 1),
						'reason' => $rejectionReason,
					];
				}
				continue;
			}

			// Calculate average amount
			$avgAmount = array_sum($data['amounts']) / count($data['amounts']);

			// Calculate confidence based on consistency
			// More forgiving for income since amounts can vary (freelance, hourly, etc.)
			$intervalVariance = $this->calculateVariance($intervals);
			$amountVariance = $this->calculateVariance($data['amounts']);

			$confidence = min(1.0, count($data['dates']) / 6);

			// Interval variance tolerance: ±7 days is acceptable for income
			if ($intervalVariance > 7) {
				$confidence *= 0.8;
			}

			// Amount variance tolerance: 20% variation is acceptable for income
			if ($amountVariance > $avgAmount * 0.2) {
				$confidence *= 0.85;
			}

			// Detect typical expected day
			$expectedDays = array_map(fn($ts) => (int)date('j', $ts), $dates);
			$avgExpectedDay = (int)round(array_sum($expectedDays) / count($expectedDays));

			$detected[] = [
				'description' => $data['description'],
				'suggestedName' => $this->generateIncomeName($data['description']),
				'source' => $this->generateIncomeSource($data['description']),
				'amount' => round($avgAmount, 2),
				'frequency' => $frequency,
				'expectedDay' => $avgExpectedDay,
				'categoryId' => $data['categoryId'],
				'accountId' => $data['accountId'],
				'occurrences' => count($data['dates']),
				'confidence' => round($confidence, 2),
				'autoDetectPattern' => $this->generatePattern($data['description']),
				'lastSeen' => date('Y-m-d', max($dates)),
				'amountVariance' => round($amountVariance, 2),
				'avgInterval' => round($avgInterval, 1),
			];
		}

		// Sort by confidence descending
		usort($detected, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

		// If debug mode, add rejected patterns info
		if ($debug && count($debugRejected) > 0) {
			return [
				'detected' => $detected,
				'rejected' => $debugRejected,
			];
		}

		return $detected;
	}

	/**
	 * Normalize description for grouping.
	 *
	 * @param string $description Transaction description
	 * @return string Normalized description
	 */
	public function normalizeDescription(string $description): string {
		// Remove common UK government payment reference prefixes (e.g., JT055236A, RN12345B)
		$normalized = preg_replace('/\b[A-Z]{1,3}\d+[A-Z]?\b/i', '', $description);
		// Remove remaining standalone numbers
		$normalized = preg_replace('/\b\d+\b/', '', $normalized);
		// Remove single letters that are likely part of reference codes
		$normalized = preg_replace('/\b[A-Z]\b/i', '', $normalized);
		// Collapse multiple spaces
		$normalized = preg_replace('/\s+/', ' ', $normalized);
		return strtolower(trim($normalized));
	}

	/**
	 * Generate a clean income name from description.
	 *
	 * @param string $description Transaction description
	 * @return string Clean income name
	 */
	public function generateIncomeName(string $description): string {
		// Common patterns to clean up for income
		$patterns = [
			'/\bSALARY\b/i' => 'Salary',
			'/\bPAYROLL\b/i' => 'Payroll',
			'/\bDEPOSIT\b/i' => '',
			'/\bDIRECT DEPOSIT\b/i' => '',
			'/\bTRANSFER FROM\b/i' => '',
			'/\bPAYMENT FROM\b/i' => '',
			'/\bCREDIT\b/i' => '',
			'/\b(LTD|LIMITED|PLC|INC|LLC|CORP)\b/i' => '',
			'/\s+/' => ' ',
		];

		$name = $description;
		foreach ($patterns as $pattern => $replacement) {
			$name = preg_replace($pattern, $replacement, $name);
		}

		return trim(ucwords(strtolower($name)));
	}

	/**
	 * Generate income source from description.
	 * This extracts the likely employer or client name.
	 *
	 * @param string $description Transaction description
	 * @return string Income source
	 */
	public function generateIncomeSource(string $description): string {
		// Remove common noise words
		$source = preg_replace('/\b(SALARY|PAYROLL|DEPOSIT|DIRECT|TRANSFER|FROM|PAYMENT|CREDIT)\b/i', '', $description);
		$source = preg_replace('/\d+/', '', $source);
		$source = preg_replace('/\s+/', ' ', $source);
		$source = trim($source);

		if (empty($source)) {
			return 'Unknown Source';
		}

		return ucwords(strtolower($source));
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
	 * @return float Variance (standard deviation)
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
