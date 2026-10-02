<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;

/**
 * Detects recurring bills from transaction patterns.
 */
class RecurringBillDetector {
	private TransactionMapper $transactionMapper;
	private FrequencyCalculator $frequencyCalculator;
	private BillMapper $billMapper;

	public function __construct(
		TransactionMapper $transactionMapper,
		FrequencyCalculator $frequencyCalculator,
		BillMapper $billMapper,
	) {
		$this->transactionMapper = $transactionMapper;
		$this->frequencyCalculator = $frequencyCalculator;
		$this->billMapper = $billMapper;
	}

	/**
	 * Auto-detect recurring bills from transaction history: payments that
	 * repeat and that no bill or transfer tracks yet.
	 *
	 * @param string $userId User ID
	 * @param int $months Number of months to analyze
	 * @param bool $includeTransfers Also offer debits already linked to a
	 *                               transfer's other leg (Find Transfers).
	 *                               Detect Bills and the suggestions leave
	 *                               them out: as a bill, one books a debit
	 *                               with no deposit.
	 * @return array Detected recurring patterns
	 */
	public function detectRecurringBills(string $userId, int $months = 6, bool $includeTransfers = false): array {
		$startDate = date('Y-m-d', strtotime("-{$months} months"));
		$endDate = date('Y-m-d');

		$transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

		// Where each row sits, to tell a linked debit where its money went
		$accountOf = [];
		foreach ($transactions as $transaction) {
			$accountOf[$transaction->getId()] = $transaction->getAccountId();
		}

		$grouped = [];

		// Group transactions by description and approximate amount
		foreach ($transactions as $transaction) {
			if ($transaction->getType() !== 'debit') {
				continue;
			}
			if ($this->isAppBooked($transaction)) {
				continue;
			}
			$linkedId = $transaction->getLinkedTransactionId();
			if ($linkedId !== null && !$includeTransfers) {
				continue;
			}

			// A blank description falls back to the payee, and a row with
			// neither isn't grouped at all: blank rows of different payees
			// shared one '' key and merged into a made-up bill
			$text = $this->rowText($transaction);
			$desc = $this->normalizeDescription($text);
			if ($desc === '') {
				continue;
			}
			$amount = $transaction->getAmount();

			// Create key with rounded amount (to handle slight variations)
			$amountKey = round($amount, 0);
			$key = $desc . '|' . $amountKey;

			if (!isset($grouped[$key])) {
				$grouped[$key] = [
					'patternKey' => $key,
					'description' => $text,
					'amount' => $amount,
					'amounts' => [],
					'dates' => [],
					'categoryId' => $transaction->getCategoryId(),
					'accountId' => $transaction->getAccountId(),
					'destinations' => [],
				];
			}

			$grouped[$key]['dates'][] = $transaction->getDate();
			$grouped[$key]['amounts'][] = $amount;
			if ($linkedId !== null && isset($accountOf[$linkedId])) {
				$grouped[$key]['destinations'][] = $accountOf[$linkedId];
			}
		}

		$trackedPatterns = $this->trackedPatterns($userId);
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

			// No bill or transfer form offers a daily schedule, and the same
			// debit every day is spending (coffee, fares) rather than a bill
			if ($frequency === null || $frequency === 'daily') {
				continue;
			}

			if ($this->isTracked($data['description'], $trackedPatterns)) {
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

			// The schedule as the payments show it: a weekday and a real
			// first date for weekly ones, the month for quarterly and yearly
			$schedule = DetectedSchedule::fromDates($frequency, array_map(fn ($ts) => date('Y-m-d', $ts), $dates));

			$candidate = [
				'patternKey' => $data['patternKey'],
				'description' => $data['description'],
				'suggestedName' => $this->candidateName($data['description']),
				'amount' => round($avgAmount, 2),
				'frequency' => $frequency,
				'dueDay' => $schedule['day'],
				'dueMonth' => $schedule['month'],
				'startDate' => $schedule['startDate'],
				'categoryId' => $data['categoryId'],
				'accountId' => $data['accountId'],
				'occurrences' => count($data['dates']),
				'confidence' => round($confidence, 2),
				'autoDetectPattern' => $this->generatePattern($data['description']),
				'lastSeen' => date('Y-m-d', max($dates)),
			];

			// Linked legs show where the money went. Only a suggestion: Find
			// Transfers asks for the destination, and a bill created from
			// this must not turn into a transfer by itself
			$destinations = array_unique($data['destinations']);
			if (count($destinations) === 1 && reset($destinations) !== $data['accountId']) {
				$candidate['suggestedDestinationAccountId'] = reset($destinations);
			}

			$detected[] = $candidate;
		}

		// Sort by confidence descending
		usort($detected, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

		return $detected;
	}

	/**
	 * Rows the app booked itself, or that aren't payments yet. They are
	 * already tracked and fed detection the user's own bills back to them:
	 *  - a bill or transfer's payments and placeholders (bill_id)
	 *  - anything still scheduled (a pre-booked row dated today isn't paid)
	 *  - a pension contribution's bank leg, which its schedule books
	 */
	private function isAppBooked(Transaction $transaction): bool {
		return $transaction->getBillId() !== null
			|| $transaction->getStatus() === 'scheduled'
			|| $transaction->getPensionContribId() !== null;
	}

	/** The row's description, or its payee when the description is blank */
	private function rowText(Transaction $transaction): string {
		$description = trim((string)$transaction->getDescription());
		return $description !== '' ? $description : trim((string)$transaction->getVendor());
	}

	/**
	 * A name for a candidate, never blank: when the cleanup strips the
	 * whole description ("DIRECT DEBIT") the description itself is used.
	 * The caller only passes text that normalizes to something.
	 */
	private function candidateName(string $description): string {
		$name = $this->generateBillName($description);
		if (preg_match('/\p{L}/u', $name) === 1) {
			return $name;
		}
		return ucwords($this->normalizeDescription($description));
	}

	/**
	 * What the user's existing bills and transfers match on, normalized:
	 * the auto-detect and transfer patterns, matched the way bill
	 * auto-detection links imported rows, and the name as whole words.
	 *
	 * @return array{patterns: string[], names: string[]}
	 */
	private function trackedPatterns(string $userId): array {
		$patterns = [];
		$names = [];
		foreach ($this->billMapper->findAll($userId) as $bill) {
			foreach ([$bill->getAutoDetectPattern(), $bill->getTransferDescriptionPattern()] as $raw) {
				$normalized = $this->normalizeDescription((string)$raw);
				if ($normalized !== '') {
					$patterns[] = $normalized;
				}
			}
			$name = $this->normalizeDescription((string)$bill->getName());
			if ($name !== '') {
				$names[] = $name;
			}
		}
		return ['patterns' => $patterns, 'names' => $names];
	}

	/**
	 * Whether an existing bill already covers payments with this
	 * description. A pattern matches as a substring either way round; a
	 * name only as whole words, so a bill called "Car" doesn't hide every
	 * "CARD PAYMENT TO ...".
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
			if (self::containsWords($normalized, $name) || self::containsWords($name, $normalized)) {
				return true;
			}
		}
		return false;
	}

	/** Whether $needle appears in $haystack as whole words */
	public static function containsWords(string $haystack, string $needle): bool {
		return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
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
		$words = array_filter($words, fn ($w) => strlen($w) > 2);
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
		$squaredDiffs = array_map(fn ($v) => pow($v - $mean, 2), $values);
		return sqrt(array_sum($squaredDiffs) / $count);
	}
}
