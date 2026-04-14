<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\RecurringIncome;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class TransactionService {
    private TransactionMapper $mapper;
    private AccountMapper $accountMapper;
    private TransactionTagMapper $transactionTagMapper;
    private ExpenseShareMapper $expenseShareMapper;

    public function __construct(
        TransactionMapper $mapper,
        AccountMapper $accountMapper,
        TransactionTagMapper $transactionTagMapper,
        ExpenseShareMapper $expenseShareMapper
    ) {
        $this->mapper = $mapper;
        $this->accountMapper = $accountMapper;
        $this->transactionTagMapper = $transactionTagMapper;
        $this->expenseShareMapper = $expenseShareMapper;
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
        ?string $importId = null,
        ?int $billId = null,
        ?string $status = null
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
        $transaction->setBillId($billId);
        $transaction->setStatus($status ?? 'cleared');
        $transaction->setReconciled(false);
        $transaction->setCreatedAt(date('Y-m-d H:i:s'));
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));

        $transaction = $this->mapper->insert($transaction);

        // Update account balance (scheduled transactions don't affect balance until cleared)
        if (($status ?? 'cleared') !== 'scheduled') {
            $this->updateAccountBalance($account, $amount, $type, $userId);
        }

        return $transaction;
    }

    /**
     * Create a transaction from a bill
     *
     * @param string $userId User ID
     * @param Bill $bill The bill to create transaction from
     * @param string|null $transactionDate Optional date override (uses bill's nextDueDate if not provided)
     * @return Transaction The created transaction (for transfers, returns the withdrawal transaction)
     * @throws \Exception if bill has no account or if transfer has no destination account
     */
    public function createFromBill(
        string $userId,
        Bill $bill,
        ?string $transactionDate = null,
        ?string $status = null
    ): Transaction {
        if (!$bill->getAccountId()) {
            throw new \Exception('Bill must have an account to create transaction');
        }

        $date = $transactionDate ?? $bill->getNextDueDate();
        $status = $status ?? (($date > date('Y-m-d')) ? 'scheduled' : 'cleared');

        // Handle transfers - create paired transactions
        if ($bill->getIsTransfer()) {
            if (!$bill->getDestinationAccountId()) {
                throw new \Exception('Transfer must have a destination account');
            }

            // Create withdrawal from source account
            $withdrawal = $this->create(
                userId: $userId,
                accountId: $bill->getAccountId(),
                date: $date,
                description: 'Bill payment',
                amount: $bill->getAmount(),
                type: 'debit',
                categoryId: $bill->getCategoryId(),
                vendor: $bill->getName(),
                reference: null,
                notes: "Auto-generated transfer: {$bill->getName()}",
                importId: null,
                billId: $bill->getId(),
                status: $status
            );

            // Create deposit to destination account
            $deposit = $this->create(
                userId: $userId,
                accountId: $bill->getDestinationAccountId(),
                date: $date,
                description: 'Bill payment',
                amount: $bill->getAmount(),
                type: 'credit',
                categoryId: $bill->getCategoryId(),
                vendor: $bill->getName(),
                reference: null,
                notes: "Auto-generated transfer: {$bill->getName()}",
                importId: null,
                billId: $bill->getId(),
                status: $status
            );

            // Link the two transactions
            $this->linkTransactions($withdrawal->getId(), $deposit->getId(), $userId);

            // Apply bill's tags to both transactions
            $tagIds = $bill->getTagIdsArray();
            if (!empty($tagIds)) {
                $this->applyTagsToTransaction($withdrawal->getId(), $tagIds);
                $this->applyTagsToTransaction($deposit->getId(), $tagIds);
            }

            // Return the withdrawal transaction
            return $withdrawal;
        }

        // Handle regular bills - create single transaction
        $transaction = $this->create(
            userId: $userId,
            accountId: $bill->getAccountId(),
            date: $date,
            description: 'Bill payment',
            amount: $bill->getAmount(),
            type: 'debit',
            categoryId: $bill->getCategoryId(),
            vendor: $bill->getName(),
            reference: null,
            notes: "Auto-generated from bill: {$bill->getName()}",
            importId: null,
            billId: $bill->getId(),
            status: $status
        );

        // Apply bill's tags to the transaction
        $tagIds = $bill->getTagIdsArray();
        if (!empty($tagIds)) {
            $this->applyTagsToTransaction($transaction->getId(), $tagIds);
        }

        return $transaction;
    }

    /**
     * Create a transaction from a recurring income entry.
     *
     * @param string $userId User ID
     * @param RecurringIncome $income The recurring income to create transaction from
     * @param string|null $transactionDate Optional date override (uses income's nextExpectedDate if not provided)
     * @param string|null $status Optional status override (auto-determined from date if not provided)
     * @return Transaction The created transaction
     * @throws \Exception if income has no account
     */
    public function createFromIncome(
        string $userId,
        RecurringIncome $income,
        ?string $transactionDate = null,
        ?string $status = null
    ): Transaction {
        if (!$income->getAccountId()) {
            throw new \Exception('Income must have an account to create transaction');
        }

        $date = $transactionDate ?? $income->getNextExpectedDate();
        $status = $status ?? (($date > date('Y-m-d')) ? 'scheduled' : 'cleared');

        return $this->create(
            userId: $userId,
            accountId: $income->getAccountId(),
            date: $date,
            description: 'Income payment',
            amount: $income->getAmount(),
            type: 'credit',
            categoryId: $income->getCategoryId(),
            vendor: $income->getName(),
            notes: "Auto-generated from income: {$income->getName()}",
            status: $status
        );
    }

    /**
     * Find a scheduled transaction for a bill and mark it as cleared.
     *
     * @return Transaction|null The cleared transaction, or null if none found
     */
    public function clearScheduledBillTransaction(string $userId, int $billId, string $clearedDate): ?Transaction {
        $allScheduled = $this->mapper->findAllScheduledByBillId($billId);
        $cleared = null;

        foreach ($allScheduled as $i => $scheduled) {
            if ($i === 0) {
                // Clear the earliest scheduled transaction
                $cleared = $this->update($scheduled->getId(), $userId, [
                    'status' => 'cleared',
                    'date' => $clearedDate,
                ]);
            } else {
                // Delete any duplicate scheduled transactions
                $this->mapper->delete($scheduled);
            }
        }

        return $cleared;
    }

    /**
     * Delete all scheduled transactions for a bill (used when deleting a bill).
     */
    public function deleteScheduledBillTransactions(int $billId): void {
        $scheduled = $this->mapper->findAllScheduledByBillId($billId);
        foreach ($scheduled as $transaction) {
            $this->mapper->delete($transaction);
        }
    }

    /**
     * Apply tag IDs to a transaction (used when creating transactions from bills).
     * @param int $transactionId
     * @param int[] $tagIds
     */
    private function applyTagsToTransaction(int $transactionId, array $tagIds): void {
        $now = date('Y-m-d H:i:s');
        foreach ($tagIds as $tagId) {
            $transactionTag = new TransactionTag();
            $transactionTag->setTransactionId($transactionId);
            $transactionTag->setTagId((int) $tagId);
            $transactionTag->setCreatedAt($now);
            $this->transactionTagMapper->insert($transactionTag);
        }
    }

    public function update(int $id, string $userId, array $updates): Transaction {
        $transaction = $this->find($id, $userId);
        $oldAmount = $transaction->getAmount();
        $oldType = $transaction->getType();
        $oldAccountId = $transaction->getAccountId();
        $oldStatus = $transaction->getStatus() ?? 'cleared';

        // If changing account, verify new account belongs to user
        $accountChanging = isset($updates['accountId']) && $updates['accountId'] !== $oldAccountId;
        if ($accountChanging) {
            $this->accountMapper->find($updates['accountId'], $userId);
        }

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

        $newAmount = $updates['amount'] ?? $oldAmount;
        $newType = $updates['type'] ?? $oldType;
        $newStatus = $updates['status'] ?? $oldStatus;

        // Scheduled transactions don't affect balance — only apply balance changes
        // when the transaction is or becomes cleared
        $wasAffectingBalance = ($oldStatus !== 'scheduled');
        $nowAffectsBalance = ($newStatus !== 'scheduled');

        if ($accountChanging) {
            // Moving to a different account: reverse on old (if it was affecting balance), apply on new (if it now affects balance)
            if ($wasAffectingBalance) {
                $oldAccount = $this->accountMapper->find($oldAccountId, $userId);
                $reverseType = $oldType === 'credit' ? 'debit' : 'credit';
                $this->updateAccountBalance($oldAccount, $oldAmount, $reverseType, $userId);
            }
            if ($nowAffectsBalance) {
                $newAccount = $this->accountMapper->find($updates['accountId'], $userId);
                $this->updateAccountBalance($newAccount, $newAmount, $newType, $userId);
            }
        } elseif (!$wasAffectingBalance && $nowAffectsBalance) {
            // Status changed from scheduled → cleared: apply full balance effect
            $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
            $this->updateAccountBalance($account, $newAmount, $newType, $userId);
        } elseif ($wasAffectingBalance && !$nowAffectsBalance) {
            // Status changed from cleared → scheduled: reverse the balance effect
            $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
            $reverseType = $oldType === 'credit' ? 'debit' : 'credit';
            $this->updateAccountBalance($account, $oldAmount, $reverseType, $userId);
        } elseif ($wasAffectingBalance && $nowAffectsBalance && ($newAmount != $oldAmount || $newType != $oldType)) {
            // Both cleared, but amount or type changed — adjust the difference
            $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
            $currentBalance = (string) $account->getBalance();

            $oldAmountStr = (string) $oldAmount;
            $oldEffect = $oldType === 'credit'
                ? $oldAmountStr
                : MoneyCalculator::multiply($oldAmountStr, '-1');

            $newAmountStr = (string) $newAmount;
            $newEffect = $newType === 'credit'
                ? $newAmountStr
                : MoneyCalculator::multiply($newAmountStr, '-1');

            $netChange = MoneyCalculator::subtract($newEffect, $oldEffect);
            $newBalance = MoneyCalculator::add($currentBalance, $netChange);

            $this->accountMapper->updateBalance($account->getId(), $newBalance, $userId);
        }
        // If both scheduled (wasAffectingBalance=false, nowAffectsBalance=false): no balance changes

        return $transaction;
    }

    public function delete(int $id, string $userId): void {
        $transaction = $this->find($id, $userId);
        $account = $this->accountMapper->find($transaction->getAccountId(), $userId);

        // Reverse transaction effect on balance (scheduled transactions never affected balance)
        $status = $transaction->getStatus() ?? 'cleared';
        if ($status !== 'scheduled') {
            $reverseType = $transaction->getType() === 'credit' ? 'debit' : 'credit';
            $this->updateAccountBalance($account, $transaction->getAmount(), $reverseType, $userId);
        }

        // Cascade delete: Delete transaction tags and expense shares
        $this->transactionTagMapper->deleteByTransaction($id);
        $this->expenseShareMapper->deleteByTransaction($id, $userId);

        $this->mapper->delete($transaction);
    }

    public function findWithFilters(string $userId, array $filters, int $limit, int $offset): array {
        $result = $this->mapper->findWithFilters($userId, $filters, $limit, $offset);

        // Compute running balance when viewing a single account sorted by date
        // with no non-date filters that break chronological contiguity
        $accountId = $filters['accountId'] ?? null;
        $sort = $filters['sort'] ?? 'date';

        $hasContiguityBreakingFilters =
            !empty($filters['category']) ||
            !empty($filters['type']) ||
            !empty($filters['search']) ||
            !empty($filters['amountMin']) ||
            !empty($filters['amountMax']) ||
            !empty($filters['status']) ||
            !empty($filters['tagIds']);

        if ($accountId && $sort === 'date' && !$hasContiguityBreakingFilters
            && !empty($result['transactions'])) {

            $account = $this->accountMapper->find($accountId, $userId);
            $openingBalance = (string)($account->getOpeningBalance() ?? 0);

            // Find the chronologically earliest transaction on this page (date ASC, id ASC)
            $earliest = $result['transactions'][0];
            foreach ($result['transactions'] as $tx) {
                if ($tx['date'] < $earliest['date']
                    || ($tx['date'] === $earliest['date'] && $tx['id'] < $earliest['id'])) {
                    $earliest = $tx;
                }
            }

            $netBefore = $this->mapper->getNetChangeBefore(
                $accountId,
                $earliest['date'],
                $earliest['id']
            );

            $result['balanceBeforePage'] = MoneyCalculator::add($openingBalance, $netBefore);
        }

        return $result;
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

        // Get account currency for currency-matched filtering
        $account = $this->accountMapper->find($transaction->getAccountId(), $userId);

        return $this->mapper->findPotentialMatches(
            $userId,
            $transactionId,
            $transaction->getAccountId(),
            $transaction->getAmount(),
            $transaction->getType(),
            $transaction->getDate(),
            $account->getCurrency(),
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

    /**
     * Scan for potential transfer matches (read-only, no linking)
     *
     * @return array{candidates: array, stats: array{singleMatchCount: int, multiMatchCount: int, totalCandidates: int}}
     */
    public function scanForMatches(string $userId, int $dateWindowDays = 3, int $batchSize = 100): array {
        $candidates = [];
        $processedIds = [];

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

                if (isset($processedIds[$txId])) {
                    continue;
                }

                $availableMatches = array_filter($item['matches'], function($match) use ($processedIds) {
                    return !isset($processedIds[$match['id']]);
                });

                if (empty($availableMatches)) {
                    continue;
                }

                $availableMatches = array_values($availableMatches);

                $candidates[] = [
                    'transaction' => $item['transaction'],
                    'matches' => $availableMatches,
                    'matchCount' => count($availableMatches)
                ];

                // Mark source and all its matches as processed to avoid mirror pairs
                $processedIds[$txId] = true;
                foreach ($availableMatches as $match) {
                    $processedIds[$match['id']] = true;
                }
            }

            $offset += $batchSize;

            if ($offset >= $result['total']) {
                $hasMore = false;
            }
        }

        $singleMatchCount = 0;
        $multiMatchCount = 0;
        foreach ($candidates as $c) {
            if ($c['matchCount'] === 1) {
                $singleMatchCount++;
            } else {
                $multiMatchCount++;
            }
        }

        return [
            'candidates' => $candidates,
            'stats' => [
                'singleMatchCount' => $singleMatchCount,
                'multiMatchCount' => $multiMatchCount,
                'totalCandidates' => count($candidates)
            ]
        ];
    }

    /**
     * Bulk link multiple transaction pairs
     *
     * @param array<array{sourceId: int, targetId: int}> $pairs
     * @return array{linked: array, failed: array, stats: array{linkedCount: int, failedCount: int}}
     */
    public function bulkLinkTransactions(string $userId, array $pairs): array {
        $linked = [];
        $failed = [];

        foreach ($pairs as $pair) {
            $sourceId = (int)($pair['sourceId'] ?? 0);
            $targetId = (int)($pair['targetId'] ?? 0);

            if ($sourceId === 0 || $targetId === 0) {
                $failed[] = [
                    'sourceId' => $sourceId,
                    'targetId' => $targetId,
                    'error' => 'Invalid source or target ID'
                ];
                continue;
            }

            try {
                $this->linkTransactions($sourceId, $targetId, $userId);
                $linked[] = [
                    'sourceId' => $sourceId,
                    'targetId' => $targetId
                ];
            } catch (\Exception $e) {
                $failed[] = [
                    'sourceId' => $sourceId,
                    'targetId' => $targetId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'linked' => $linked,
            'failed' => $failed,
            'stats' => [
                'linkedCount' => count($linked),
                'failedCount' => count($failed)
            ]
        ];
    }

    /**
     * Find groups of suspected duplicate transactions.
     *
     * @param string $userId User ID
     * @param int $dateWindowDays Date window for matching (default 3 days)
     * @return array[] Groups of suspected duplicates
     */
    public function findDuplicates(string $userId, int $dateWindowDays = 3): array {
        return $this->mapper->findDuplicates($userId, $dateWindowDays);
    }
}