<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Service\TransactionService;

/**
 * Detects duplicate transactions during import.
 */
class DuplicateDetector {
    private TransactionService $transactionService;

    public function __construct(TransactionService $transactionService) {
        $this->transactionService = $transactionService;
    }

    /**
     * Check if a transaction is a duplicate based on import ID.
     *
     * @param int $accountId The account ID
     * @param string $importId The import ID to check
     * @return bool True if duplicate exists
     */
    public function isDuplicateByImportId(int $accountId, string $importId): bool {
        return $this->transactionService->existsByImportId($accountId, $importId);
    }

    /**
     * Check if a transaction is a duplicate based on transaction data.
     *
     * @param int $accountId The account ID
     * @param array $transaction Transaction data with date, amount, description
     * @param string|null $importId Optional import ID for primary check
     * @return bool True if duplicate exists
     */
    public function isDuplicate(int $accountId, array $transaction, ?string $importId = null): bool {
        // Primary check: use import ID if available
        if ($importId !== null) {
            return $this->isDuplicateByImportId($accountId, $importId);
        }

        // Fallback: could implement fuzzy matching based on date, amount, description
        // For now, return false to allow import (caller should use import ID when possible)
        return false;
    }

    /**
     * Filter out duplicates from a list of transactions.
     *
     * @param int $accountId The account ID
     * @param array $transactions List of transactions
     * @param callable $importIdGenerator Function to generate import ID from transaction
     * @return array{unique: array, duplicates: array} Filtered results
     */
    public function filterDuplicates(
        int $accountId,
        array $transactions,
        callable $importIdGenerator
    ): array {
        $unique = [];
        $duplicates = [];

        foreach ($transactions as $transaction) {
            $importId = $importIdGenerator($transaction);

            if ($this->isDuplicateByImportId($accountId, $importId)) {
                $duplicates[] = $transaction;
            } else {
                $unique[] = $transaction;
            }
        }

        return [
            'unique' => $unique,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Check multiple transactions for duplicates in batch.
     *
     * @param int $accountId The account ID
     * @param array $importIds List of import IDs to check
     * @return array<string, bool> Map of import ID to duplicate status
     */
    public function checkBatch(int $accountId, array $importIds): array {
        $results = [];

        foreach ($importIds as $importId) {
            $results[$importId] = $this->isDuplicateByImportId($accountId, $importId);
        }

        return $results;
    }
}
