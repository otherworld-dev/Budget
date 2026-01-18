<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class TransactionSplitService {
    private TransactionSplitMapper $splitMapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        TransactionSplitMapper $splitMapper,
        TransactionMapper $transactionMapper
    ) {
        $this->splitMapper = $splitMapper;
        $this->transactionMapper = $transactionMapper;
    }

    /**
     * Get all splits for a transaction.
     *
     * @return TransactionSplit[]
     */
    public function getSplits(int $transactionId, string $userId): array {
        // Verify the transaction belongs to the user
        $this->transactionMapper->find($transactionId, $userId);
        return $this->splitMapper->findByTransaction($transactionId);
    }

    /**
     * Split a transaction into multiple category allocations.
     *
     * @param array $splits Array of ['categoryId' => int|null, 'amount' => float, 'description' => string|null]
     * @return TransactionSplit[]
     * @throws \InvalidArgumentException If splits don't sum to transaction amount
     */
    public function splitTransaction(int $transactionId, string $userId, array $splits): array {
        // Get the transaction and verify ownership
        $transaction = $this->transactionMapper->find($transactionId, $userId);

        // Validate split amounts sum to transaction amount
        $splitTotal = array_reduce($splits, fn($sum, $split) => $sum + ($split['amount'] ?? 0), 0.0);
        $transactionAmount = (float) $transaction->getAmount();

        // Allow small floating point variance
        if (abs($splitTotal - $transactionAmount) > 0.01) {
            throw new \InvalidArgumentException(
                sprintf('Split amounts (%.2f) must equal transaction amount (%.2f)', $splitTotal, $transactionAmount)
            );
        }

        // Must have at least 2 splits
        if (count($splits) < 2) {
            throw new \InvalidArgumentException('A split transaction must have at least 2 parts');
        }

        // Delete existing splits
        $this->splitMapper->deleteByTransaction($transactionId);

        // Create new splits
        $createdSplits = [];
        $now = date('Y-m-d H:i:s');

        foreach ($splits as $splitData) {
            $split = new TransactionSplit();
            $split->setTransactionId($transactionId);
            $split->setCategoryId($splitData['categoryId'] ?? null);
            $split->setAmount((string) ($splitData['amount'] ?? 0));
            $split->setDescription($splitData['description'] ?? null);
            $split->setCreatedAt($now);

            $createdSplits[] = $this->splitMapper->insert($split);
        }

        // Mark transaction as split and clear its category
        $transaction->setIsSplit(true);
        $transaction->setCategoryId(null);
        $transaction->setUpdatedAt($now);
        $this->transactionMapper->update($transaction);

        // Fetch splits with category names
        return $this->splitMapper->findByTransaction($transactionId);
    }

    /**
     * Remove splits from a transaction (unsplit).
     *
     * @param int|null $categoryId Category to assign to the unsplit transaction
     */
    public function unsplitTransaction(int $transactionId, string $userId, ?int $categoryId = null): Transaction {
        // Get the transaction and verify ownership
        $transaction = $this->transactionMapper->find($transactionId, $userId);

        if (!$transaction->getIsSplit()) {
            throw new \InvalidArgumentException('Transaction is not split');
        }

        // Delete all splits
        $this->splitMapper->deleteByTransaction($transactionId);

        // Mark transaction as not split and optionally set category
        $transaction->setIsSplit(false);
        $transaction->setCategoryId($categoryId);
        $transaction->setUpdatedAt(date('Y-m-d H:i:s'));

        return $this->transactionMapper->update($transaction);
    }

    /**
     * Update a single split.
     */
    public function updateSplit(int $splitId, string $userId, array $data): TransactionSplit {
        $split = $this->splitMapper->find($splitId);

        // Verify the transaction belongs to the user
        $transaction = $this->transactionMapper->find($split->getTransactionId(), $userId);

        // Update fields
        if (isset($data['categoryId'])) {
            $split->setCategoryId($data['categoryId'] ?: null);
        }
        if (isset($data['amount'])) {
            // Validate new total
            $splits = $this->splitMapper->findByTransaction($split->getTransactionId());
            $newTotal = array_reduce($splits, function($sum, $s) use ($split, $data) {
                if ($s->getId() === $split->getId()) {
                    return $sum + $data['amount'];
                }
                return $sum + (float) $s->getAmount();
            }, 0.0);

            if (abs($newTotal - (float) $transaction->getAmount()) > 0.01) {
                throw new \InvalidArgumentException(
                    sprintf('Split amounts (%.2f) must equal transaction amount (%.2f)', $newTotal, $transaction->getAmount())
                );
            }

            $split->setAmount((string) $data['amount']);
        }
        if (array_key_exists('description', $data)) {
            $split->setDescription($data['description']);
        }

        return $this->splitMapper->update($split);
    }

    /**
     * Get category totals from splits for a list of transactions.
     *
     * @return array Array of [categoryId => totalAmount]
     */
    public function getCategoryTotalsFromSplits(array $transactionIds): array {
        return $this->splitMapper->getCategoryTotals($transactionIds);
    }
}
