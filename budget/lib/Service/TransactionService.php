<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class TransactionService {
    private TransactionMapper $mapper;
    private AccountMapper $accountMapper;

    public function __construct(
        TransactionMapper $mapper,
        AccountMapper $accountMapper
    ) {
        $this->mapper = $mapper;
        $this->accountMapper = $accountMapper;
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
            if (method_exists($transaction, $setter)) {
                $transaction->$setter($value);
            }
        }
        
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
        $transaction = $this->mapper->update($transaction);
        
        // Update account balance if amount or type changed
        if (isset($updates['amount']) || isset($updates['type'])) {
            $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
            // Reverse old transaction
            $this->updateAccountBalance($account, $oldAmount, $oldType === 'credit' ? 'debit' : 'credit', $userId);
            // Apply new transaction
            $this->updateAccountBalance($account, $transaction->getAmount(), $transaction->getType(), $userId);
        }
        
        return $transaction;
    }

    public function delete(int $id, string $userId): void {
        $transaction = $this->find($id, $userId);
        $account = $this->accountMapper->find($transaction->getAccountId(), $userId);
        
        // Reverse transaction effect on balance
        $reverseType = $transaction->getType() === 'credit' ? 'debit' : 'credit';
        $this->updateAccountBalance($account, $transaction->getAmount(), $reverseType, $userId);
        
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
        $currentBalance = $account->getBalance();
        $newBalance = $type === 'credit'
            ? $currentBalance + $amount
            : $currentBalance - $amount;

        $this->accountMapper->updateBalance($account->getId(), $newBalance, $userId);
    }
}