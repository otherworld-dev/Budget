<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCP\IDBConnection;
use OCP\IL10N;

class TransactionSplitService {
	private TransactionSplitMapper $splitMapper;
	private TransactionMapper $transactionMapper;
	private GranularShareService $granularShareService;

	public function __construct(
		TransactionSplitMapper $splitMapper,
		TransactionMapper $transactionMapper,
		GranularShareService $granularShareService,
		private ?IDBConnection $db = null,
		private ?IL10N $l = null,
	) {
		$this->splitMapper = $splitMapper;
		$this->transactionMapper = $transactionMapper;
		$this->granularShareService = $granularShareService;
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

		// Every part is checked before anything is written. The old parts are
		// deleted below, so a part refused after that (the database rejected
		// a zero amount) left the transaction with some of its parts, or none.
		// A part may be negative (a coupon line); zero is not an allocation.
		$amounts = [];
		foreach ($splits as $i => $splitData) {
			$amount = $splitData['amount'] ?? null;
			if (is_string($amount)) {
				$amount = trim($amount);
			}
			if (!is_numeric($amount)) {
				throw new \InvalidArgumentException($this->t('Split %1$s: amount is required', [$i]));
			}
			$amount = MoneyCalculator::plain($amount);
			// At the column's scale: a part stored as 0.00000000 is empty
			if (MoneyCalculator::compare($amount, '0', 8) === 0) {
				throw new \InvalidArgumentException($this->t('Split %1$s: amount cannot be zero', [$i]));
			}
			$amounts[$i] = $amount;
		}

		// Validate split amounts sum to transaction amount, allowing a cent of
		// rounding between the parts and the total
		$splitTotal = MoneyCalculator::sum($amounts, 8);
		$transactionAmount = (string)$transaction->getAmount();
		if (!MoneyCalculator::equals($splitTotal, $transactionAmount, '0.01')) {
			throw new \InvalidArgumentException(
				sprintf('Split amounts (%.2f) must equal transaction amount (%.2f)', (float)$splitTotal, (float)$transactionAmount)
			);
		}

		// Must have at least 2 splits
		if (count($splits) < 2) {
			throw new \InvalidArgumentException('A split transaction must have at least 2 parts');
		}

		// Every part's category must be one the ledger owner can see ($userId
		// is the owner: the transaction was found under it above)
		foreach ($splits as $splitData) {
			$this->granularShareService->requireUsableCategory($userId, self::categoryIdOf($splitData['categoryId'] ?? null));
		}

		// The old parts go and the new ones arrive together, or not at all
		$now = date('Y-m-d H:i:s');
		$this->db?->beginTransaction();
		try {
			// Delete existing splits
			$this->splitMapper->deleteByTransaction($transactionId);

			// Create new splits
			foreach ($splits as $i => $splitData) {
				$split = new TransactionSplit();
				$split->setTransactionId($transactionId);
				$split->setCategoryId(self::categoryIdOf($splitData['categoryId'] ?? null));
				$split->setAmount($amounts[$i]);
				$split->setDescription($splitData['description'] ?? null);
				$split->setCreatedAt($now);

				$this->splitMapper->insert($split);
			}

			// Mark transaction as split and clear its category
			$transaction->setIsSplit(true);
			$transaction->setCategoryId(null);
			$transaction->setUpdatedAt($now);
			$this->transactionMapper->update($transaction);

			$this->db?->commit();
		} catch (\Throwable $e) {
			$this->db?->rollBack();
			throw $e;
		}

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
		$this->granularShareService->requireUsableCategory($userId, $categoryId);

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
			$categoryId = self::categoryIdOf($data['categoryId']);
			$this->granularShareService->requireUsableCategory($userId, $categoryId);
			$split->setCategoryId($categoryId);
		}
		if (isset($data['amount'])) {
			// Validate new total
			$splits = $this->splitMapper->findByTransaction($split->getTransactionId());
			$newTotal = array_reduce($splits, function ($sum, $s) use ($split, $data) {
				if ($s->getId() === $split->getId()) {
					return $sum + $data['amount'];
				}
				return $sum + (float)$s->getAmount();
			}, 0.0);

			if (abs($newTotal - (float)$transaction->getAmount()) > 0.01) {
				throw new \InvalidArgumentException(
					sprintf('Split amounts (%.2f) must equal transaction amount (%.2f)', $newTotal, $transaction->getAmount())
				);
			}

			$split->setAmount((string)$data['amount']);
		}
		if (array_key_exists('description', $data)) {
			$split->setDescription($data['description']);
		}

		return $this->splitMapper->update($split);
	}

	/** Translated when a translator was injected; tests build the service without one. */
	private function t(string $text, array $parameters = []): string {
		return $this->l !== null ? $this->l->t($text, $parameters) : vsprintf($text, $parameters);
	}

	/** A split's category from client input: empty/0 means uncategorised. */
	private static function categoryIdOf(mixed $raw): ?int {
		if ($raw === null || $raw === '' || $raw === false) {
			return null;
		}
		$id = (int)$raw;
		return $id > 0 ? $id : null;
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
