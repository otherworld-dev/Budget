<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\DismissedSuggestionMapper;

/**
 * Proactive recurring-bill suggestions: runs the detector over recent
 * history and filters down to NEW candidates — not already tracked by a
 * bill (the detector leaves those out), not previously dismissed,
 * confidently recurring. Surfaced as a dismissible card in the Bills view
 * and counted in the digest.
 */
class BillSuggestionService {

	public const MIN_CONFIDENCE = 0.5;
	private const MONTHS = 6;

	public function __construct(
		private RecurringBillDetector $detector,
		private DismissedSuggestionMapper $dismissedMapper,
	) {
	}

	/**
	 * Top new recurring candidates plus the total count.
	 *
	 * @return array{suggestions: array[], total: int}
	 */
	public function getSuggestions(string $userId, int $limit = 5): array {
		// Bills only: a linked transfer leg offered here became an expense
		// bill that booked a debit with no deposit
		$detected = $this->detector->detectRecurringBills($userId, self::MONTHS, false);
		if (empty($detected)) {
			return ['suggestions' => [], 'total' => 0];
		}

		$dismissed = array_flip($this->dismissedMapper->findHashes($userId, 'bill'));

		$fresh = [];
		foreach ($detected as $candidate) {
			if ($candidate['confidence'] < self::MIN_CONFIDENCE) {
				continue;
			}
			if (isset($dismissed[$this->hashPatternKey($candidate['patternKey'])])) {
				continue;
			}
			$fresh[] = $candidate;
		}

		return [
			'suggestions' => array_slice($fresh, 0, $limit),
			'total' => count($fresh),
		];
	}

	/**
	 * Number of new suggestions (digest section).
	 */
	public function countSuggestions(string $userId): int {
		return $this->getSuggestions($userId, 1)['total'];
	}

	/**
	 * Remember a dismissal so the pattern never reappears.
	 */
	public function dismiss(string $userId, string $patternKey): void {
		$this->dismissedMapper->dismiss(
			$userId,
			'bill',
			$this->hashPatternKey($patternKey),
			$patternKey
		);
	}

	private function hashPatternKey(string $patternKey): string {
		return sha1($patternKey);
	}
}
