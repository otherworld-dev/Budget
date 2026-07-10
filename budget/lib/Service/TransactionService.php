<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\DismissedImportMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\RecurringIncome;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class TransactionService {
    private TransactionMapper $mapper;
    private AccountMapper $accountMapper;
    private TransactionTagMapper $transactionTagMapper;
    private TransactionSplitMapper $splitMapper;
    private ExpenseShareMapper $expenseShareMapper;
    private DismissedImportMapper $dismissedImportMapper;

    public function __construct(
        TransactionMapper $mapper,
        AccountMapper $accountMapper,
        TransactionTagMapper $transactionTagMapper,
        TransactionSplitMapper $splitMapper,
        ExpenseShareMapper $expenseShareMapper,
        DismissedImportMapper $dismissedImportMapper,
        private \OCA\Budget\Db\AttachmentMapper $attachmentMapper,
        private AuditService $auditService,
        private \OCA\Budget\Db\PensionContributionMapper $pensionContributionMapper
    ) {
        $this->mapper = $mapper;
        $this->accountMapper = $accountMapper;
        $this->transactionTagMapper = $transactionTagMapper;
        $this->splitMapper = $splitMapper;
        $this->expenseShareMapper = $expenseShareMapper;
        $this->dismissedImportMapper = $dismissedImportMapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Transaction {
        return $this->mapper->find($id, $userId);
    }

    /**
     * Find a transaction by ID scoped to visible account IDs (for shared access).
     *
     * @param int[] $visibleAccountIds
     * @throws DoesNotExistException
     */
    public function findForAccounts(int $id, array $visibleAccountIds): Transaction {
        return $this->mapper->findForAccounts($id, $visibleAccountIds);
    }

    /**
     * Find an account by ID without user scoping (for shared account resolution).
     *
     * @throws DoesNotExistException
     */
    public function findAccountById(int $accountId): \OCA\Budget\Db\Account {
        return $this->accountMapper->findById($accountId);
    }

    public function findByAccount(string $userId, int $accountId, int $limit = 100, int $offset = 0): array {
        return $this->mapper->findByAccount($accountId, $userId, $limit, $offset);
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
        ?string $status = null,
        bool $excludedFromForecast = false,
        bool $deferBalanceUpdate = false
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
        // Auto-set status based on date: future transactions are scheduled
        $effectiveStatus = $status ?? (($date > date('Y-m-d')) ? 'scheduled' : 'cleared');
        $transaction->setStatus($effectiveStatus);
        $transaction->setExcludedFromForecast($excludedFromForecast);
        $transaction->setReconciled(false);
        $transaction->setCreatedAt(date('Y-m-d H:i:s'));
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));

        $transaction = $this->mapper->insert($transaction);

        // Recompute the account balance from the ledger (scheduled transactions
        // are excluded by the recompute itself). Bulk callers (imports, bank
        // sync) defer this and recalculate once per account after their loop.
        if (!$deferBalanceUpdate) {
            $this->recalculateAccountBalance($accountId, $userId);
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
                description: $bill->getDescription() ?? '',
                amount: $bill->getAmount(),
                type: 'debit',
                categoryId: $bill->getCategoryId(),
                vendor: $bill->getName(),
                reference: null,
                notes: "Auto-generated transfer: {$bill->getName()}",
                importId: null,
                billId: $bill->getId(),
                status: $status,
                excludedFromForecast: $bill->getExcludedFromForecast() ?? false
            );

            // Create deposit to destination account (same category as source for consistency)
            $deposit = $this->create(
                userId: $userId,
                accountId: $bill->getDestinationAccountId(),
                date: $date,
                description: $bill->getDescription() ?? '',
                amount: $bill->getAmount(),
                type: 'credit',
                categoryId: $bill->getCategoryId(),
                vendor: $bill->getName(),
                reference: null,
                notes: "Auto-generated transfer: {$bill->getName()}",
                importId: null,
                billId: $bill->getId(),
                status: $status,
                excludedFromForecast: $bill->getExcludedFromForecast() ?? false
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
            description: $bill->getDescription() ?? '',
            amount: $bill->getAmount(),
            type: 'debit',
            categoryId: $bill->getCategoryId(),
            vendor: $bill->getName(),
            reference: null,
            notes: "Auto-generated from bill: {$bill->getName()}",
            importId: null,
            billId: $bill->getId(),
            status: $status,
            excludedFromForecast: $bill->getExcludedFromForecast() ?? false
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
            description: $income->getDescription() ?? '',
            amount: $income->getAmount(),
            type: 'credit',
            categoryId: $income->getCategoryId(),
            vendor: $income->getName(),
            notes: "Auto-generated from income: {$income->getName()}",
            status: $status,
            excludedFromForecast: $income->getExcludedFromForecast() ?? false
        );
    }

    /**
     * Find candidate transactions that might match a bill payment.
     * Scores each candidate based on amount, vendor, description, and date proximity.
     *
     * @param int $accountId Account to search in
     * @param string $billName Bill name to match against vendor/description
     * @param float $billAmount Bill amount to compare
     * @param string $dueDate Bill's due date (center of search window)
     * @return array Scored candidates sorted by relevance [{transaction, score, matchReasons}]
     */
    public function findBillPaymentCandidates(
        int $accountId,
        string $billName,
        float $billAmount,
        string $dueDate
    ): array {
        $candidates = $this->mapper->findBillPaymentCandidates($accountId, $dueDate, 7);
        $scored = [];
        $billNameLower = mb_strtolower(trim($billName));

        foreach ($candidates as $tx) {
            $score = 0;
            $reasons = [];

            // Amount match (exact = 40pts, within 5% = 20pts, within 20% = 10pts)
            $txAmount = (float) $tx->getAmount();
            if (abs($txAmount - $billAmount) < 0.01) {
                $score += 40;
                $reasons[] = 'exact_amount';
            } elseif ($billAmount > 0 && abs($txAmount - $billAmount) / $billAmount <= 0.05) {
                $score += 20;
                $reasons[] = 'similar_amount';
            } elseif ($billAmount > 0 && abs($txAmount - $billAmount) / $billAmount <= 0.20) {
                $score += 10;
                $reasons[] = 'approximate_amount';
            }

            // Vendor match (exact = 30pts, contains = 20pts)
            $vendor = mb_strtolower(trim($tx->getVendor() ?? ''));
            if ($vendor !== '' && $vendor === $billNameLower) {
                $score += 30;
                $reasons[] = 'exact_vendor';
            } elseif ($vendor !== '' && (str_contains($vendor, $billNameLower) || str_contains($billNameLower, $vendor))) {
                $score += 20;
                $reasons[] = 'partial_vendor';
            }

            // Description match (exact = 20pts, contains = 10pts)
            $desc = mb_strtolower(trim($tx->getDescription() ?? ''));
            if ($desc !== '' && $desc === $billNameLower) {
                $score += 20;
                $reasons[] = 'exact_description';
            } elseif ($desc !== '' && (str_contains($desc, $billNameLower) || str_contains($billNameLower, $desc))) {
                $score += 10;
                $reasons[] = 'partial_description';
            }

            // Date proximity (same day = 10pts, ±1 day = 8pts, ±3 days = 5pts, ±7 days = 2pts)
            $daysDiff = abs((strtotime($tx->getDate()) - strtotime($dueDate)) / 86400);
            if ($daysDiff < 1) {
                $score += 10;
                $reasons[] = 'same_day';
            } elseif ($daysDiff <= 1) {
                $score += 8;
                $reasons[] = 'next_day';
            } elseif ($daysDiff <= 3) {
                $score += 5;
                $reasons[] = 'within_3_days';
            } else {
                $score += 2;
                $reasons[] = 'within_7_days';
            }

            // Skip if no meaningful match (must match on at least amount or name)
            if ($score < 10) {
                continue;
            }

            // Skip auto-generated transactions (these are FROM bills, not manual entries)
            $notes = $tx->getNotes() ?? '';
            if (str_starts_with($notes, 'Auto-generated from bill:') || str_starts_with($notes, 'Auto-generated transfer:')) {
                continue;
            }

            $scored[] = [
                'transaction' => $tx->jsonSerialize(),
                'score' => $score,
                'matchReasons' => $reasons,
            ];
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
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

        // Auto-clear scheduled transactions when date is moved to today or past
        if (isset($updates['date']) && $oldStatus === 'scheduled' && !isset($updates['status'])) {
            if ($updates['date'] <= date('Y-m-d')) {
                $updates['status'] = 'cleared';
            }
        }

        // Editing a reconciled transaction in a balance-affecting way breaks
        // past statement reconciliations — allowed (bank-sync corrections are
        // legitimate) but leaves an audit trail. The UI also warns first.
        if ($transaction->getReconciled()) {
            $balanceKeys = ['amount', 'type', 'accountId', 'date', 'status'];
            $changedKeys = [];
            foreach ($balanceKeys as $key) {
                $getter = 'get' . ucfirst($key);
                if (array_key_exists($key, $updates) && $updates[$key] != $transaction->$getter()) {
                    $changedKeys[] = $key;
                }
            }
            if (!empty($changedKeys)) {
                $this->auditService->log($userId, 'reconciled_tx_modified', 'transaction', $id, [
                    'changedFields' => $changedKeys,
                    'reconSessionId' => $transaction->getReconSessionId(),
                ]);
            }
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

        // If a split transaction's amount changed (e.g. inline-edited in the
        // list), rescale its splits proportionally so they keep summing to the
        // new amount instead of reflecting the old total (#297 follow-up).
        if (array_key_exists('amount', $updates)
            && $transaction->getIsSplit()
            && abs((float) $oldAmount - (float) $transaction->getAmount()) > 0.001) {
            $this->rescaleSplits($id, (float) $oldAmount, (float) $transaction->getAmount());
        }

        // Recompute affected account balances from the ledger. This replaces the
        // old hand-computed delta branches (account move / status flips / amount
        // or type edits), every one of which was a historical drift source.
        $this->recalculateAccountBalance($transaction->getAccountId(), $userId);
        if ($accountChanging) {
            $this->recalculateAccountBalance($oldAccountId, $userId);
        }

        return $transaction;
    }

    /**
     * Proportionally rescale a split transaction's parts to a new total so the
     * splits keep summing to the transaction amount after an amount edit. The
     * last split absorbs any rounding remainder so the sum stays exact. When the
     * old amount was 0 (no proportions to preserve) shares are split evenly.
     */
    private function rescaleSplits(int $transactionId, float $oldAmount, float $newAmount): void {
        $splits = $this->splitMapper->findByTransaction($transactionId);
        $count = count($splits);
        if ($count < 2) {
            return;
        }

        $running = 0.0;
        foreach (array_values($splits) as $i => $split) {
            if ($i === $count - 1) {
                $amount = round($newAmount - $running, 2);
            } else {
                $share = $oldAmount > 0
                    ? ((float) $split->getAmount() / $oldAmount)
                    : (1.0 / $count);
                $amount = round($newAmount * $share, 2);
                $running += $amount;
            }
            $split->setAmount((string) $amount);
            $this->splitMapper->update($split);
        }
    }

    public function delete(int $id, string $userId, bool $dismiss = true): void {
        $transaction = $this->find($id, $userId);

        // Deleting a reconciled transaction breaks past statement
        // reconciliations — allowed, but audit-logged (the UI warns first)
        if ($transaction->getReconciled()) {
            $this->auditService->log($userId, 'reconciled_tx_deleted', 'transaction', $id, [
                'amount' => $transaction->getAmount(),
                'date' => $transaction->getDate(),
                'reconSessionId' => $transaction->getReconSessionId(),
            ]);
        }

        // Unlink counterpart transfer before deleting — prevents dangling
        // linked_transaction_id references that break dashboard/tag queries
        if ($transaction->getLinkedTransactionId() !== null) {
            $this->mapper->unlinkTransaction($id);
        }

        // If this bank leg funded a pension contribution/withdrawal (#304),
        // detach it so the pension record survives as a plain manual entry
        // rather than pointing at a deleted transaction.
        if ($transaction->getPensionContribId() !== null) {
            $this->pensionContributionMapper->unlinkByTransaction($id);
        }

        // Record dismissed import ID for bank-synced transactions so they
        // don't get re-imported on the next sync. Only applies to provider-
        // prefixed IDs (e.g. "simplefin:xxx", "gocardless:xxx"), not CSV imports.
        $importId = $transaction->getImportId();
        if ($dismiss && $importId !== null && $importId !== '' && str_contains($importId, ':')) {
            try {
                $this->dismissedImportMapper->dismiss($transaction->getAccountId(), $importId);
            } catch (\Exception $e) {
                // Ignore duplicates — already dismissed
            }
        }

        // Cascade delete: tags, expense shares and attachment references
        // (attachment files in the user's Files are never touched)
        $this->transactionTagMapper->deleteByTransaction($id);
        $this->expenseShareMapper->deleteByTransaction($id, $userId);
        $this->attachmentMapper->deleteByTransaction($id, $userId);

        $this->mapper->delete($transaction);

        // Recompute from the ledger now that the row is gone
        $this->recalculateAccountBalance($transaction->getAccountId(), $userId);
    }

    /**
     * Clear the pension_contrib_id marker from any bank legs that pointed at the
     * given (about-to-be-deleted) pension contributions (#304). The transactions
     * survive as ordinary debits/credits.
     *
     * @param int[] $contributionIds
     */
    public function clearPensionContribMarkers(array $contributionIds): void {
        $this->mapper->clearPensionContribByIds($contributionIds);
    }

    /**
     * Mark a bank transaction as the funding leg of a pension contribution (#304)
     * so it is excluded from spending/income aggregates. Does not affect the
     * account balance, so no recompute is needed.
     *
     * @throws DoesNotExistException
     */
    public function markPensionContribLink(int $txId, string $userId, ?int $contribId): void {
        $tx = $this->mapper->find($txId, $userId);
        $tx->setPensionContribId($contribId);
        $tx->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->mapper->update($tx);
    }

    /**
     * @param int[]|null $visibleAccountIds If provided, scope by account IDs instead of userId
     */
    public function findWithFilters(string $userId, array $filters, int $limit, int $offset, ?array $visibleAccountIds = null): array {
        $result = $this->mapper->findWithFilters($userId, $filters, $limit, $offset, $visibleAccountIds);

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

            // Use findById (not find) because the account may belong to a different
            // user who shared it. Access is already verified via visibleAccountIds.
            $account = $this->accountMapper->findById((int)$accountId);
            $openingBalance = (string)($account->getOpeningBalance() ?? 0);

            // Compute running balance for each transaction on the current page
            // by iterating over ALL account transactions chronologically.
            // This avoids page-boundary issues entirely.
            $allTx = $this->mapper->getAllTransactionsForBalance($accountId);

            $pageIds = [];
            foreach ($result['transactions'] as $tx) {
                $pageIds[$tx['id']] = true;
            }

            $running = $openingBalance;
            $runningBalances = [];
            foreach ($allTx as $row) {
                $amount = (string)$row['amount'];
                if ($row['type'] === 'credit') {
                    $running = MoneyCalculator::add($running, $amount);
                } else {
                    $running = MoneyCalculator::subtract($running, $amount);
                }
                if (isset($pageIds[(int)$row['id']])) {
                    $runningBalances[(int)$row['id']] = $running;
                }
            }

            $result['runningBalances'] = $runningBalances;
        }

        // Attach split category details for split transactions
        $splitTxIds = array_filter(
            array_map(fn($tx) => ($tx['isSplit'] ?? false) ? ($tx['id'] ?? null) : null, $result['transactions']),
            fn($id) => $id !== null
        );
        if (!empty($splitTxIds)) {
            $splitDetails = $this->splitMapper->findByTransactionIds(array_values($splitTxIds));
            foreach ($result['transactions'] as &$tx) {
                if (($tx['isSplit'] ?? false) && isset($splitDetails[$tx['id']])) {
                    $tx['splitCategories'] = $splitDetails[$tx['id']];
                }
            }
            unset($tx);
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
     * Find a transaction by account + import ID (or null).
     */
    public function findByImportId(int $accountId, string $importId): ?\OCA\Budget\Db\Transaction {
        return $this->mapper->findByImportId($accountId, $importId);
    }

    /**
     * Find pending transactions on an account imported by a given provider.
     *
     * @return \OCA\Budget\Db\Transaction[]
     */
    public function findPendingImported(int $accountId, string $importPrefix): array {
        return $this->mapper->findPendingImported($accountId, $importPrefix);
    }

    /**
     * Mark an existing pending bank-sync transaction as posted (cleared),
     * optionally re-pointing it at the posted version's import ID and date.
     *
     * Balance is unaffected: pending and cleared both count toward the balance,
     * and the amount/type are unchanged — so we update fields directly.
     */
    public function reconcilePendingToPosted(\OCA\Budget\Db\Transaction $transaction, ?string $newImportId = null, ?string $newDate = null): \OCA\Budget\Db\Transaction {
        $transaction->setStatus('cleared');
        if ($newImportId !== null) {
            $transaction->setImportId($newImportId);
        }
        if ($newDate !== null) {
            $transaction->setDate($newDate);
        }
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->mapper->update($transaction);
    }

    /**
     * Find potential transfer matches for a transaction
     *
     * $includeCrossCurrency also returns candidates in accounts with another
     * currency (any amount, since the exchanged amount never matches) for the
     * manual match dialog (#326). Auto-link flows must keep it off.
     *
     * @return Transaction[]
     */
    public function findPotentialMatches(int $transactionId, string $userId, int $dateWindowDays = 3, bool $includeCrossCurrency = false): array {
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
            $dateWindowDays,
            $includeCrossCurrency
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

        // Validation: must be same amount (unless cross-currency transfer)
        $sourceAccount = $this->accountMapper->find($transaction->getAccountId(), $userId);
        $targetAccount = $this->accountMapper->find($target->getAccountId(), $userId);
        if ($sourceAccount->getCurrency() === $targetAccount->getCurrency()
            && $transaction->getAmount() !== $target->getAmount()) {
            throw new \Exception('Cannot link same-currency transactions with different amounts');
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
     * Convert a transaction into a transfer by creating the missing opposite
     * leg in another account and linking the pair (#313). For accounts with
     * no importable feed (e.g. a loan account) there is never an existing
     * counterpart to match against, so one is created on demand.
     *
     * @throws \Exception if the transaction cannot be converted
     */
    public function convertToTransfer(int $transactionId, int $targetAccountId, string $userId): array {
        $transaction = $this->find($transactionId, $userId);

        // Validate before creating the counterpart so a failed conversion
        // never leaves an orphaned transaction behind
        if ($transaction->getLinkedTransactionId() !== null) {
            throw new \Exception('Transaction is already linked to another transaction');
        }
        if ($transaction->getAccountId() === $targetAccountId) {
            throw new \Exception('Cannot transfer within the same account');
        }

        // Also verifies both accounts belong to the user
        $sourceAccount = $this->accountMapper->find($transaction->getAccountId(), $userId);
        $targetAccount = $this->accountMapper->find($targetAccountId, $userId);
        if ($sourceAccount->getCurrency() !== $targetAccount->getCurrency()) {
            throw new \Exception('Counterpart account must use the same currency');
        }

        $counterpart = $this->create(
            userId: $userId,
            accountId: $targetAccountId,
            date: $transaction->getDate(),
            description: $transaction->getDescription() ?? '',
            amount: $transaction->getAmount(),
            type: $transaction->getType() === 'debit' ? 'credit' : 'debit',
            vendor: $transaction->getVendor(),
            notes: 'Auto-created transfer counterpart',
            status: $transaction->getStatus()
        );

        return $this->linkTransactions($transactionId, $counterpart->getId(), $userId);
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

    /**
     * Recompute an account's stored balance from the ledger:
     * balance = opening_balance + net of all non-scheduled transactions.
     *
     * This is the single source of truth for account balances. Historically the
     * balance was a running total adjusted by hand-computed deltas on every
     * create/update/delete path; any missed or mis-signed delta corrupted the
     * stored balance permanently (#3, #89, #124, #163, #187, #194, #274).
     * Recomputing from the ledger after each mutation makes drift impossible
     * and self-heals any past inconsistency on the next write.
     */
    public function recalculateAccountBalance(int $accountId, string $userId): void {
        $account = $this->accountMapper->find($accountId, $userId);
        $openingBalance = (string) ($account->getOpeningBalance() ?? 0);
        // Pass the float through: MoneyCalculator normalizes it without
        // scientific notation (a string cast would bypass that)
        $newBalance = MoneyCalculator::add($openingBalance, $this->mapper->getNetChangeAll($accountId));

        $this->accountMapper->updateBalance($accountId, $newBalance, $userId);
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
                        $this->linkTransactions($txId, $matchId, $userId);

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