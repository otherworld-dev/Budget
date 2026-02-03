<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class TransactionService {
    private TransactionMapper $mapper;
    private AccountMapper $accountMapper;
    private TransactionTagMapper $transactionTagMapper;

    public function __construct(
        TransactionMapper $mapper,
        AccountMapper $accountMapper,
        TransactionTagMapper $transactionTagMapper
    ) {
        $this->mapper = $mapper;
        $this->accountMapper = $accountMapper;
        $this->transactionTagMapper = $transactionTagMapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Transaction {
        return $this->mapper->find($id, $userId);
    }

    public function findByAccount(string $userId, int $accountId, int $limit = 100, int $offset = 0): array {
        // Verify account belongs to user
        $this->accountMapper->find($accountId, $userId);
        return $this->mapper->findByAccount($accountId, $limit, $offset);
    }

    public function findByDateRange(string $userId, int $accountId, string $startDate, string $endDate): array {
        // Verify account belongs to user
        $this->accountMapper->find($accountId, $userId);
        return $this->mapper->findByDateRange($accountId, $startDate, $endDate);
    }

    public function findUncategorized(string $userId, int $limit = 100): array {
        return $this->mapper->findUncategorized($userId, $limit);
    }

    public function search(string $userId, string $query, int $limit = 100): array {
        return $this->mapper->search($userId, $query, $limit);
    }

    public function create(
        string $userId,
        int $accountId,
        string $date,
        string $description,
        float $amount,
        string $type,
        ?int $categoryId = null,
        ?string $vendor = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $importId = null
    ): Transaction {
        // Verify account belongs to user
        $account = $this->accountMapper->find($accountId, $userId);
        
        // Check for duplicate import
        if ($importId && $this->mapper->existsByImportId($accountId, $importId)) {
            throw new \Exception('Transaction with this import ID already exists');
        }
        
        $transaction = new Transaction();
        $transaction->setAccountId($accountId);
        $transaction->setDate($date);
        $transaction->setDescription($description);
        $transaction->setAmount($amount);
        $transaction->setType($type);
        $transaction->setCategoryId($categoryId);
        $transaction->setVendor($vendor);
        $transaction->setReference($reference);
        $transaction->setNotes($notes);
        $transaction->setImportId($importId);
        $transaction->setReconciled(false);
        $transaction->setCreatedAt(date('Y-m-d H:i:s'));
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
        
        $transaction = $this->mapper->insert($transaction);
        
        // Update account balance
        $this->updateAccountBalance($account, $amount, $type, $userId);
        
        return $transaction;
    }

    public function update(int $id, string $userId, array $updates): Transaction {
        $transaction = $this->find($id, $userId);
        $oldAmount = $transaction->getAmount();
        $oldType = $transaction->getType();

        // Apply updates
        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            // Use is_callable() instead of method_exists() to support magic methods
            if (is_callable([$transaction, $setter])) {
                $transaction->$setter($value);
            }
        }

        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
        $transaction = $this->mapper->update($transaction);

        // Update account balance only if amount or type actually changed
        $newAmount = $updates['amount'] ?? $oldAmount;
        $newType = $updates['type'] ?? $oldType;

        if ($newAmount != $oldAmount || $newType != $oldType) {
            $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
            $currentBalance = (string) $account->getBalance();

            // Calculate the net balance change using MoneyCalculator for precision
            // Old effect: what was already applied to the balance
            $oldAmountStr = (string) $oldAmount;
            $oldEffect = $oldType === 'credit'
                ? $oldAmountStr
                : MoneyCalculator::multiply($oldAmountStr, '-1');

            // New effect: what should be applied to the balance
            $newAmountStr = (string) $newAmount;
            $newEffect = $newType === 'credit'
                ? $newAmountStr
                : MoneyCalculator::multiply($newAmountStr, '-1');

            // Net change to apply
            $netChange = MoneyCalculator::subtract($newEffect, $oldEffect);
            $newBalance = MoneyCalculator::add($currentBalance, $netChange);

            $this->accountMapper->updateBalance($account->getId(), $newBalance, $userId);
        }

        return $transaction;
    }

    public function delete(int $id, string $userId): void {
        $transaction = $this->find($id, $userId);
        $account = $this->accountMapper->find($transaction->getAccountId(), $userId);

        // Reverse transaction effect on balance
        $reverseType = $transaction->getType() === 'credit' ? 'debit' : 'credit';
        $this->updateAccountBalance($account, $transaction->getAmount(), $reverseType, $userId);

        // Cascade delete: Delete transaction tags first
        $this->transactionTagMapper->deleteByTransaction($id);

        $this->mapper->delete($transaction);
    }

    public function findWithFilters(string $userId, array $filters, int $limit, int $offset): array {
        return $this->mapper->findWithFilters($userId, $filters, $limit, $offset);
    }

    public function bulkCategorize(string $userId, array $updates): array {
        $results = ['success' => 0, 'failed' => 0];

        foreach ($updates as $update) {
            try {
                $this->update($update['id'], $userId, ['categoryId' => $update['categoryId']]);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Bulk delete transactions
     */
    public function bulkDelete(string $userId, array $ids): array {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($ids as $id) {
            try {
                $this->delete($id, $userId);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $id,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk update reconciled status
     */
    public function bulkReconcile(string $userId, array $ids, bool $reconciled): array {
        $results = ['success' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                $this->update($id, $userId, ['reconciled' => $reconciled]);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Bulk edit transaction fields
     */
    public function bulkEdit(string $userId, array $ids, array $updates): array {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($ids as $id) {
            try {
                $this->update($id, $userId, $updates);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $id,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    public function existsByImportId(int $accountId, string $importId): bool {
        return $this->mapper->existsByImportId($accountId, $importId);
    }

    /**
     * Find potential transfer matches for a transaction
     *
     * @return Transaction[]
     */
    public function findPotentialMatches(int $transactionId, string $userId, int $dateWindowDays = 3): array {
        $transaction = $this->find($transactionId, $userId);

        // Don't find matches if already linked
        if ($transaction->getLinkedTransactionId() !== null) {
            return [];
        }

        return $this->mapper->findPotentialMatches(
            $userId,
            $transactionId,
            $transaction->getAccountId(),
            $transaction->getAmount(),
            $transaction->getType(),
            $transaction->getDate(),
            $dateWindowDays
        );
    }

    /**
     * Link two transactions as a transfer pair
     *
     * @throws \Exception if transactions cannot be linked
     */
    public function linkTransactions(int $transactionId, int $targetId, string $userId): array {
        $transaction = $this->find($transactionId, $userId);
        $target = $this->find($targetId, $userId);

        // Validation: must be different accounts
        if ($transaction->getAccountId() === $target->getAccountId()) {
            throw new \Exception('Cannot link transactions from the same account');
        }

        // Validation: must be same amount
        if ($transaction->getAmount() !== $target->getAmount()) {
            throw new \Exception('Cannot link transactions with different amounts');
        }

        // Validation: must be opposite types
        if ($transaction->getType() === $target->getType()) {
            throw new \Exception('Cannot link transactions of the same type');
        }

        // Validation: neither should already be linked
        if ($transaction->getLinkedTransactionId() !== null) {
            throw new \Exception('Transaction is already linked to another transaction');
        }
        if ($target->getLinkedTransactionId() !== null) {
            throw new \Exception('Target transaction is already linked to another transaction');
        }

        $this->mapper->linkTransactions($transactionId, $targetId);

        // Return updated transactions
        return [
            'transaction' => $this->find($transactionId, $userId),
            'linkedTransaction' => $this->find($targetId, $userId)
        ];
    }

    /**
     * Unlink a transaction from its transfer partner
     */
    public function unlinkTransaction(int $transactionId, string $userId): array {
        $transaction = $this->find($transactionId, $userId);

        if ($transaction->getLinkedTransactionId() === null) {
            throw new \Exception('Transaction is not linked');
        }

        $linkedId = $this->mapper->unlinkTransaction($transactionId);

        return [
            'transaction' => $this->find($transactionId, $userId),
            'unlinkedTransactionId' => $linkedId
        ];
    }

    private function updateAccountBalance($account, float $amount, string $type, string $userId): void {
        $currentBalance = (string) $account->getBalance();
        $amountStr = (string) $amount;

        $newBalance = $type === 'credit'
            ? MoneyCalculator::add($currentBalance, $amountStr)
            : MoneyCalculator::subtract($currentBalance, $amountStr);

        $this->accountMapper->updateBalance($account->getId(), $newBalance, $userId);
    }

    /**
     * Bulk find and match transactions
     * Auto-links transactions with exactly one match, returns others for manual review
     *
     * @param string $userId
     * @param int $dateWindowDays
     * @param int $batchSize
     * @return array Results with autoMatched, needsReview, and stats
     */
    public function bulkFindAndMatch(string $userId, int $dateWindowDays = 3, int $batchSize = 100): array {
        $autoMatched = [];
        $needsReview = [];
        $processedIds = []; // Track IDs we've already processed to avoid duplicates

        $offset = 0;
        $hasMore = true;

        while ($hasMore) {
            $result = $this->mapper->findUnlinkedWithMatches($userId, $dateWindowDays, $batchSize, $offset);

            if (empty($result['transactions'])) {
                $hasMore = false;
                break;
            }

            foreach ($result['transactions'] as $item) {
                $txId = (int)$item['transaction']['id'];

                // Skip if we've already processed this transaction (could be a match for another)
                if (isset($processedIds[$txId])) {
                    continue;
                }

                // Filter out matches that have already been processed
                $availableMatches = array_filter($item['matches'], function($match) use ($processedIds) {
                    return !isset($processedIds[$match['id']]);
                });

                if (empty($availableMatches)) {
                    continue;
                }

                $availableMatches = array_values($availableMatches); // Re-index

                if (count($availableMatches) === 1) {
                    // Auto-match: exactly one available match
                    $matchId = $availableMatches[0]['id'];
                    try {
                        $this->mapper->linkTransactions($txId, $matchId);

                        // Mark both as processed
                        $processedIds[$txId] = true;
                        $processedIds[$matchId] = true;

                        $autoMatched[] = [
                            'transaction' => $item['transaction'],
                            'linkedTo' => $availableMatches[0]
                        ];
                    } catch (\Exception $e) {
                        // If linking fails, skip this pair
                        continue;
                    }
                } else {
                    // Multiple matches - needs manual review
                    $needsReview[] = [
                        'transaction' => $item['transaction'],
                        'matches' => $availableMatches,
                        'matchCount' => count($availableMatches)
                    ];
                    // Mark source AND all its matches as processed/reserved
                    // This prevents matches from being auto-linked to other transactions
                    $processedIds[$txId] = true;
                    foreach ($availableMatches as $match) {
                        $processedIds[$match['id']] = true;
                    }
                }
            }

            // Move to next batch
            $offset += $batchSize;

            // Stop if we've processed all transactions
            if ($offset >= $result['total']) {
                $hasMore = false;
            }
        }

        return [
            'autoMatched' => $autoMatched,
            'needsReview' => $needsReview,
            'stats' => [
                'autoMatchedCount' => count($autoMatched),
                'needsReviewCount' => count($needsReview)
            ]
        ];
    }
}