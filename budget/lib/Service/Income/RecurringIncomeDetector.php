<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Income;

use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;

/**
 * Detects recurring income from transaction patterns.
 */
class RecurringIncomeDetector {
	/** How TransactionService::createFromIncome() marks the rows it books */
	private const INCOME_NOTE_PREFIX = 'Auto-generated from income:';

	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;
	private RecurringIncomeMapper $incomeMapper;
	private float $minAmount;

	public function __construct(
		TransactionMapper $transactionMapper,
		FrequencyCalculator $frequencyCalculator,
		RecurringIncomeMapper $incomeMapper,
		float $minAmount = 10.0,
	) {
		$this->transactionMapper = $transactionMapper;
		$this->frequencyCalculator = $frequencyCalculator;
		$this->incomeMapper = $incomeMapper;
		$this->minAmount = $minAmount;
	}

	/**
	 * Auto-detect recurring income from transaction history: credits that
	 * repeat and that no recurring income tracks yet.
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

			if ($this->isNotIncome($transaction)) {
				continue;
			}

			// A blank description falls back to the payer, and a row with
			// neither isn't grouped at all: blank credits of different
			// sources shared one '' group, and the median filter could keep
			// a transfer and drop the salary
			$text = $this->rowText($transaction);
			$desc = $this->normalizeDescription($text);
			if ($desc === '') {
				continue;
			}
			$amount = abs($transaction->getAmount());

			// For income, group by description only (no amount bucketing)
			// Benefits and freelance income can vary significantly
			$key = $desc;

			if (!isset($grouped[$key])) {
				$grouped[$key] = [
					'description' => $text,
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

		$tracked = $this->trackedPatterns($userId);
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

			// Already an income: offering it again made a second copy that
			// doubled the monthly total and every projection
			if ($this->isTracked($data['description'], $tracked)) {
				if ($debug) {
					$debugRejected[] = [
						'description' => $data['description'],
						'occurrences' => count($data['dates']),
						'avgInterval' => round($avgInterval, 1),
						'reason' => 'already_tracked',
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
			$expectedDays = array_map(fn ($ts) => (int)date('j', $ts), $dates);
			$avgExpectedDay = (int)round(array_sum($expectedDays) / count($expectedDays));

			$detected[] = [
				'description' => $data['description'],
				'suggestedName' => $this->candidateName($data['description']),
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
		usort($detected, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

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
	 * Credits that aren't income, or that an income already books:
	 *  - a transfer's deposit leg, whether a scheduled transfer booked it
	 *    (bill_id) or two imported rows were linked as one
	 *  - a pension withdrawal's bank leg
	 *  - the rows an income's auto-create or Mark received books (there's no
	 *    income id on a transaction, only the note it is written with)
	 *  - anything still scheduled, which hasn't arrived
	 */
	private function isNotIncome(Transaction $transaction): bool {
		return $transaction->getBillId() !== null
			|| $transaction->getLinkedTransactionId() !== null
			|| $transaction->getPensionContribId() !== null
			|| $transaction->getStatus() === 'scheduled'
			|| str_starts_with((string)$transaction->getNotes(), self::INCOME_NOTE_PREFIX);
	}

	/** The row's description, or its payer when the description is blank */
	private function rowText(Transaction $transaction): string {
		$description = trim((string)$transaction->getDescription());
		return $description !== '' ? $description : trim((string)$transaction->getVendor());
	}

	/**
	 * A name for a candidate, never blank: when the cleanup strips the
	 * whole description ("DEPOSIT", "TRANSFER FROM") the description itself
	 * is used. The caller only passes text that normalizes to something.
	 */
	private function candidateName(string $description): string {
		$name = $this->generateIncomeName($description);
		if (preg_match('/\p{L}/u', $name) === 1) {
			return $name;
		}
		return ucwords($this->normalizeDescription($description));
	}

	/**
	 * What the user's existing incomes match on, normalized: the
	 * auto-detect pattern as a substring, the name as whole words.
	 *
	 * @return array{patterns: string[], names: string[]}
	 */
	private function trackedPatterns(string $userId): array {
		$patterns = [];
		$names = [];
		foreach ($this->incomeMapper->findAll($userId) as $income) {
			$pattern = $this->normalizeDescription((string)$income->getAutoDetectPattern());
			if ($pattern !== '') {
				$patterns[] = $pattern;
			}
			$name = $this->normalizeDescription((string)$income->getName());
			if ($name !== '') {
				$names[] = $name;
			}
		}
		return ['patterns' => $patterns, 'names' => $names];
	}

	/**
	 * Whether an existing income already covers credits with this
	 * description. A name only matches as whole words, so an income called
	 * "Pay" doesn't hide "PAYPAL".
	 */
	private function isTracked(string $description, array $tracked): bool {
		$normalized = $this->normalizeDescription($description);
		if ($normalized === '') {
			return false;
		}
		foreach ($tracked['patterns'] as $pattern) {
			if (str_contains($normalized, $pattern) || str_contains($pattern, $normalized)) {
				return true;
			}
		}
		foreach ($tracked['names'] as $name) {
			if (RecurringBillDetector::containsWords($normalized, $name) || RecurringBillDetector::containsWords($name, $normalized)) {
				return true;
			}
		}
		return false;
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
		$words = array_filter($words, fn ($w) => strlen($w) > 2);
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
		$squaredDiffs = array_map(fn ($v) => pow($v - $mean, 2), $values);
		return sqrt(array_sum($squaredDiffs) / $count);
	}
}
