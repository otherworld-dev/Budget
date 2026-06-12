<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;

/**
 * Proactive recurring-bill suggestions: runs the detector over recent
 * history and filters down to NEW candidates — not already tracked by a
 * bill, not previously dismissed, confidently recurring. Surfaced as a
 * dismissible card in the Bills view and counted in the digest.
 */
class BillSuggestionService {

    public const MIN_CONFIDENCE = 0.5;
    private const MONTHS = 6;

    public function __construct(
        private RecurringBillDetector $detector,
        private BillMapper $billMapper,
        private DismissedSuggestionMapper $dismissedMapper,
    ) {
    }

    /**
     * Top new recurring candidates plus the total count.
     *
     * @return array{suggestions: array[], total: int}
     */
    public function getSuggestions(string $userId, int $limit = 5): array {
        $detected = $this->detector->detectRecurringBills($userId, self::MONTHS, true);
        if (empty($detected)) {
            return ['suggestions' => [], 'total' => 0];
        }

        $billPatterns = $this->existingBillPatterns($userId);
        $dismissed = array_flip($this->dismissedMapper->findHashes($userId, 'bill'));

        $fresh = [];
        foreach ($detected as $candidate) {
            if ($candidate['confidence'] < self::MIN_CONFIDENCE) {
                continue;
            }
            if (isset($dismissed[$this->hashPatternKey($candidate['patternKey'])])) {
                continue;
            }
            if ($this->matchesExistingBill($candidate['description'], $billPatterns)) {
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

    /**
     * Normalized patterns of the user's existing bills (autoDetectPattern,
     * falling back to the bill name).
     *
     * @return string[]
     */
    private function existingBillPatterns(string $userId): array {
        $patterns = [];
        foreach ($this->billMapper->findAll($userId) as $bill) {
            $raw = $bill->getAutoDetectPattern();
            if ($raw === null || trim($raw) === '') {
                $raw = $bill->getName();
            }
            $normalized = $this->detector->normalizeDescription((string) $raw);
            if ($normalized !== '') {
                $patterns[] = $normalized;
            }
        }
        return $patterns;
    }

    /**
     * Whether a candidate matches one of the existing bills' patterns —
     * substring in either direction, matching how bill auto-detection
     * links imported transactions.
     */
    private function matchesExistingBill(string $description, array $billPatterns): bool {
        $normalized = $this->detector->normalizeDescription($description);
        foreach ($billPatterns as $pattern) {
            if (str_contains($normalized, $pattern) || str_contains($pattern, $normalized)) {
                return true;
            }
        }
        return false;
    }
}
