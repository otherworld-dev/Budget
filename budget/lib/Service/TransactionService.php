<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\DismissedImportMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\QueryFilterBuilder;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Enum\Currency;
use OCP\AppFramework\Db\DoesNotExistException;

class TransactionService {
	private TransactionMapper $mapper;
	private AccountMapper $accountMapper;
	private TransactionTagMapper $transactionTagMapper;
	private TransactionSplitMapper $splitMapper;
	private ExpenseShareMapper $expenseShareMapper;
	private DismissedImportMapper $dismissedImportMapper;
	private AccountBalanceCalculator $balanceCalculator;

	public function __construct(
		TransactionMapper $mapper,
		AccountMapper $accountMapper,
		TransactionTagMapper $transactionTagMapper,
		TransactionSplitMapper $splitMapper,
		ExpenseShareMapper $expenseShareMapper,
		DismissedImportMapper $dismissedImportMapper,
		private \OCA\Budget\Db\AttachmentMapper $attachmentMapper,
		private AuditService $auditService,
		private \OCA\Budget\Db\PensionContributionMapper $pensionContributionMapper,
		private UserClock $userClock,
		private ?CurrencyConversionService $currencyConversion = null,
	) {
		$this->mapper = $mapper;
		$this->accountMapper = $accountMapper;
		$this->transactionTagMapper = $transactionTagMapper;
		$this->splitMapper = $splitMapper;
		$this->expenseShareMapper = $expenseShareMapper;
		$this->dismissedImportMapper = $dismissedImportMapper;
		$this->balanceCalculator = new AccountBalanceCalculator($accountMapper, $mapper);
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

	/**
	 * The account of each of these transactions, for the ones in one of
	 * $visibleAccountIds; the rest are left out.
	 *
	 * @param int[] $ids
	 * @param int[] $visibleAccountIds
	 * @return array<int, int> transactionId => accountId
	 */
	public function findAccountIdsWithin(array $ids, array $visibleAccountIds): array {
		return $this->mapper->findAccountIdsWithin($ids, $visibleAccountIds);
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
		bool $deferBalanceUpdate = false,
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
		// Auto-set status based on date: future transactions are scheduled.
		// "Future" is judged in the USER's timezone — the date came from their
		// calendar, and using the server's would misfile everything they
		// record while their local date runs ahead of it (see UserClock).
		$effectiveStatus = $status ?? ($this->userClock->isFutureDate($date, $userId) ? 'scheduled' : 'cleared');
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
		?string $status = null,
	): Transaction {
		if (!$bill->getAccountId()) {
			throw new \Exception('Bill must have an account to create transaction');
		}

		// The created transaction(s) land in the bill's account, which may be a
		// shared account owned by another user — a bill's accountId is never
		// checked against the acting user's own accounts. Scope creation to the
		// ACCOUNT owner (mirroring TransactionController::create); otherwise
		// create()'s owner-scoped account lookup throws DoesNotExistException and
		// the payment fails to record for the recipient of a shared bill (#334).
		$ownerUserId = $this->accountMapper->findById($bill->getAccountId())->getUserId();

		$date = $transactionDate ?? $bill->getNextDueDate();
		// No explicit date = pre-creating the bill's next occurrence. That is
		// always a scheduled placeholder — no money has moved yet — even when
		// the bill is overdue and its due date lies in the past. Past-dated
		// placeholders used to fall through to 'cleared' and were counted in
		// the account balance immediately, double-booking the payment.
		if ($status === null) {
			$status = $transactionDate === null
				? 'scheduled'
				: ($this->userClock->isFutureDate($date, $ownerUserId) ? 'scheduled' : 'cleared');
		}

		// Handle transfers - create paired transactions
		if ($bill->getIsTransfer()) {
			if (!$bill->getDestinationAccountId()) {
				throw new \Exception('Transfer must have a destination account');
			}

			[$withdrawalAmount, $depositAmount] = $this->transferLegAmounts($bill, $userId, $date);

			// Create withdrawal from source account
			$withdrawal = $this->create(
				userId: $ownerUserId,
				accountId: $bill->getAccountId(),
				date: $date,
				description: $bill->getDescription() ?? '',
				amount: $withdrawalAmount,
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

			// Create deposit to destination account (same category as source
			// for consistency), as that account's owner: it may be another
			// user's, shared with the bill's owner, and booked as the source's
			// owner the deposit failed and only the withdrawal stood
			$destinationOwner = $this->accountMapper->findById($bill->getDestinationAccountId())->getUserId();
			$deposit = $this->create(
				userId: $destinationOwner,
				accountId: $bill->getDestinationAccountId(),
				date: $date,
				description: $bill->getDescription() ?? '',
				amount: $depositAmount,
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

			// Link the two legs. Directly: they were just created as two
			// different owners, which the user-scoped linkTransactions() can't
			// both see. The returned withdrawal carries its partner, or the
			// payment never knew its deposit and undo left it behind.
			$this->mapper->linkTransactions($withdrawal->getId(), $deposit->getId());
			$withdrawal->setLinkedTransactionId($deposit->getId());
			$deposit->setLinkedTransactionId($withdrawal->getId());

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
			userId: $ownerUserId,
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
	 * What each leg of a recurring transfer books: [withdrawal, deposit].
	 *
	 * Between accounts in one currency both are the bill's amount. Across
	 * currencies the same number used to be booked on both legs, so GBP 100
	 * out arrived as EUR 100. The other leg is now converted at the bill
	 * owner's rate for the day: a fixed amount is in the source account's
	 * currency, while a statement or balance amount comes from the card it
	 * pays, in the card's currency (#347).
	 *
	 * @return array{0: float, 1: float}
	 * @throws \Exception when no rate between the two currencies is known,
	 *                    rather than booking the same number in both
	 */
	private function transferLegAmounts(Bill $bill, string $userId, string $date): array {
		$amount = (float)$bill->getAmount();
		$from = strtoupper((string)$this->accountMapper->findById($bill->getAccountId())->getCurrency());
		$to = strtoupper((string)$this->accountMapper->findById($bill->getDestinationAccountId())->getCurrency());
		if ($from === '' || $to === '' || $from === $to || $this->currencyConversion === null) {
			return [$amount, $amount];
		}

		$inDestinationCurrency = ($bill->getAmountType() ?? 'fixed') !== 'fixed';
		[$amountCurrency, $otherCurrency] = $inDestinationCurrency ? [$to, $from] : [$from, $to];
		$converted = $this->currencyConversion->convertBetween($amount, $amountCurrency, $otherCurrency, $userId, $date);
		if ($converted === null) {
			throw new \Exception("No exchange rate between {$amountCurrency} and {$otherCurrency} to book transfer {$bill->getName()}");
		}
		$converted = round((float)$converted, Currency::decimalsFor($otherCurrency));

		return $inDestinationCurrency ? [$converted, $amount] : [$amount, $converted];
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
		?string $status = null,
	): Transaction {
		if (!$income->getAccountId()) {
			throw new \Exception('Income must have an account to create transaction');
		}

		// The row lands in the income's account, which may be another user's
		// shared with the income's owner: book it as the account owner, as
		// createFromBill() does (#334), or the owner-scoped lookup throws
		$ownerUserId = $this->accountMapper->findById($income->getAccountId())->getUserId();

		$date = $transactionDate ?? $income->getNextExpectedDate();
		$status = $status ?? ($this->userClock->isFutureDate($date, $ownerUserId) ? 'scheduled' : 'cleared');

		return $this->create(
			userId: $ownerUserId,
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
	 * All non-scheduled transactions linked to the given bills.
	 * Used to detect bill payments that were never recorded (#274).
	 *
	 * @param int[] $billIds
	 * @return Transaction[]
	 */
	public function findRecordedBillTransactions(array $billIds): array {
		return $this->mapper->findRecordedByBillIds($billIds);
	}

	/**
	 * Recorded payments of the given bills within one calendar year, for the
	 * Bills Calendar (#375). Placeholders are excluded by the mapper.
	 *
	 * @param int[] $billIds
	 * @return Transaction[]
	 */
	public function findBillPaymentsInYear(array $billIds, int $year): array {
		return $this->mapper->findRecordedByBillIdsInYear($billIds, $year);
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
		string $dueDate,
	): array {
		$candidates = $this->mapper->findBillPaymentCandidates($accountId, $dueDate, 7);
		$scored = [];
		$billNameLower = mb_strtolower(trim($billName));

		foreach ($candidates as $tx) {
			$score = 0;
			$reasons = [];

			// Amount match (exact = 40pts, within 5% = 20pts, within 20% = 10pts)
			$txAmount = (float)$tx->getAmount();
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

			// Skip unless the transaction matches on amount, vendor or
			// description. Date proximity alone must never qualify — a payment
			// day fills the register with unrelated same-day debits, and
			// offering those as "matches" steered users into marking bills
			// paid without recording the payment (balance drift, #274).
			$hasNonDateReason = (bool)array_diff($reasons, ['same_day', 'next_day', 'within_3_days', 'within_7_days']);
			if (!$hasNonDateReason) {
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
		usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

		return $scored;
	}

	/**
	 * Find a bill's scheduled transaction and mark it cleared. A transfer
	 * bill's placeholder is a LINKED PAIR — both legs are cleared (deleting
	 * the second leg used to drop the deposit from the destination account).
	 * $amount, when given, overwrites both legs: statement bills resolve
	 * their amount at payment time (#347). It is the card's figure, in the
	 * card's currency, so with $bill given a transfer between currencies
	 * converts the withdrawal as createFromBill() does; the card's figure on
	 * both legs took 500 GBP out to pay a 500 EUR statement.
	 *
	 * @return Transaction|null The cleared withdrawal leg, or null if none found
	 * @throws \Exception when the legs are in two currencies with no rate between them
	 */
	public function clearScheduledBillTransaction(string $userId, int $billId, string $clearedDate, ?float $amount = null, bool $isTransfer = false, ?Bill $bill = null): ?Transaction {
		$allScheduled = $this->mapper->findAllScheduledByBillId($billId);
		$cleared = null;
		$partnerId = null;

		// A pre-booked transfer pair whose link was undone (Unlink transfer
		// allowed it until now) reads as two unrelated rows, and the deposit
		// went as a duplicate below. The other leg of the same occurrence -
		// opposite side, other account, same day - is the partner, so it is
		// linked back and cleared with it.
		$first = $allScheduled[0] ?? null;
		if ($first !== null && $first->getLinkedTransactionId() === null) {
			foreach (array_slice($allScheduled, 1) as $candidate) {
				if ($candidate->getLinkedTransactionId() === null
					&& $candidate->getType() !== $first->getType()
					&& $candidate->getAccountId() !== $first->getAccountId()
					&& $candidate->getDate() === $first->getDate()) {
					$this->mapper->linkTransactions($first->getId(), $candidate->getId());
					$first->setLinkedTransactionId($candidate->getId());
					$candidate->setLinkedTransactionId($first->getId());
					break;
				}
			}
		}

		// A transfer's pre-booked rows only stand for the payment as a pair.
		// With a leg deleted, clearing the other alone moved money out of one
		// account and into none: drop what's left and let the payment book a
		// whole pair instead.
		if ($isTransfer && $allScheduled !== []) {
			$ids = array_map(fn (Transaction $t) => $t->getId(), $allScheduled);
			$paired = array_filter($allScheduled, fn (Transaction $t) => in_array($t->getLinkedTransactionId(), $ids, true));
			if ($paired === []) {
				foreach ($allScheduled as $scheduled) {
					$this->deleteWithChildren($scheduled, $this->ownerOf($scheduled));
				}
				return null;
			}
			// Clear a leg of the pair first, so its partner follows
			usort($allScheduled, fn (Transaction $a, Transaction $b) => (int)in_array($b, $paired, true) <=> (int)in_array($a, $paired, true));
		}

		// Priced before anything is cleared: with no rate, nothing is
		$legAmounts = null;
		if ($amount !== null && $isTransfer && $bill !== null && $allScheduled !== []) {
			$priced = clone $bill;
			$priced->setAmount($amount);
			[$withdrawalAmount, $depositAmount] = $this->transferLegAmounts($priced, $userId, $clearedDate);
			$legAmounts = ['debit' => $withdrawalAmount, 'credit' => $depositAmount];
		}

		foreach ($allScheduled as $scheduled) {
			$isPartner = $partnerId !== null && $scheduled->getId() === $partnerId;
			if ($cleared === null || $isPartner) {
				// Each leg belongs to its account's owner, which may differ
				// from the acting user when the bill points at a shared
				// account — update under the owner (#334).
				$ownerUserId = $this->accountMapper->findById($scheduled->getAccountId())->getUserId();
				$updates = ['status' => 'cleared', 'date' => $clearedDate];
				if ($amount !== null) {
					$updates['amount'] = $legAmounts[$scheduled->getType()] ?? $amount;
				}
				$updated = $this->update($scheduled->getId(), $ownerUserId, $updates);
				if ($cleared === null) {
					$cleared = $updated;
					$partnerId = $scheduled->getLinkedTransactionId();
				}
			} else {
				// Delete any duplicate scheduled transactions. A bill's
				// placeholder can carry splits (applySplitTemplate) and tags,
				// so it goes through the cascade like any other delete.
				$this->deleteWithChildren($scheduled, $this->ownerOf($scheduled));
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
			// A bill with a split template puts real split rows on every
			// placeholder it generates, so deleting the bill has to take them
			// with it.
			$this->deleteWithChildren($transaction, $this->ownerOrNobody($transaction));
		}
	}

	/**
	 * Delete a transaction together with everything that hangs off it.
	 *
	 * There are no foreign keys on any of these tables, so a row deleted
	 * without this leaves its children behind forever: nothing can surface
	 * them, and nothing can reclaim them either, because every cleanup query
	 * (deleteAll included, which factory reset uses) finds its rows by joining
	 * back through budget_transactions. An orphan has no transaction to join
	 * to, so it survives even a factory reset. That is how ~58% of the split
	 * rows on a long-lived test instance came to be dead weight.
	 *
	 * Every path that removes a transaction must come through here rather than
	 * calling the mapper directly.
	 *
	 * Attachment FILES in the user's Nextcloud Files are never touched — only
	 * the rows referencing them.
	 */
	private function deleteWithChildren(Transaction $transaction, string $userId): void {
		$id = $transaction->getId();

		// Deleting a reconciled transaction breaks past statement
		// reconciliations — allowed, but audit-logged (the UI warns first).
		// Here rather than in delete(), so a bill's Skip or delete that takes
		// a reconciled row with it leaves the same trail.
		if ($transaction->getReconciled()) {
			$this->auditService->log($userId, 'reconciled_tx_deleted', 'transaction', $id, [
				'amount' => $transaction->getAmount(),
				'date' => $transaction->getDate(),
				'reconSessionId' => $transaction->getReconSessionId(),
			]);
		}

		// The other half of a transfer keeps its own row, so it has to let go
		// of this one. A bill's placeholder matched to a card credit used to be
		// deleted without this when the bill was paid or skipped, leaving the
		// credit linked to nothing: it could never be matched to the real
		// payment and the totals counted it as income.
		if ($transaction->getLinkedTransactionId() !== null) {
			$this->mapper->unlinkTransaction($id);
		}

		$this->transactionTagMapper->deleteByTransaction($id);
		$this->expenseShareMapper->deleteByTransaction($id, $userId);
		$this->attachmentMapper->deleteByTransaction($id, $userId);
		$this->splitMapper->deleteByTransaction($id);

		$this->mapper->delete($transaction);
	}

	/**
	 * The owner of a transaction's account, which is who its child rows are
	 * scoped to — not necessarily the user doing the deleting, when the
	 * account is shared (#334).
	 */
	private function ownerOf(Transaction $transaction): string {
		return $this->accountMapper->findById($transaction->getAccountId())->getUserId();
	}

	/**
	 * ownerOf(), or no one for a row whose account no longer exists (an
	 * account deleted before accounts took their rows with them). Looking
	 * that owner up stopped a bill's delete, a factory reset and a deleted
	 * user's purge. The row still goes with its tags and splits; expense
	 * shares and attachment links, which belong to a user, are left alone.
	 */
	private function ownerOrNobody(Transaction $transaction): string {
		try {
			return $this->ownerOf($transaction);
		} catch (DoesNotExistException $e) {
			return '';
		}
	}

	/**
	 * Fetch a transaction the way its caller is entitled to see it.
	 *
	 * A null $visibleAccountIds keeps the owner-scoped lookup every internal
	 * caller (import, bank sync, seeding) wants. A controller passes the
	 * acting user's visible accounts instead, because a transfer leg can sit
	 * in a SHARED account: that row belongs to the account's OWNER, so the
	 * owner-scoped find could never see it and linking the two legs failed
	 * with a raw DoesNotExistException the UI showed verbatim (#368).
	 *
	 * @param int[]|null $visibleAccountIds
	 * @throws DoesNotExistException
	 */
	private function findScoped(int $id, string $userId, ?array $visibleAccountIds): Transaction {
		return $visibleAccountIds === null
			? $this->mapper->find($id, $userId)
			: $this->mapper->findForAccounts($id, $visibleAccountIds);
	}

	/**
	 * findScoped()'s counterpart for the account a leg sits in.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @throws DoesNotExistException
	 */
	private function accountFor(int $accountId, string $userId, ?array $visibleAccountIds): Account {
		if ($visibleAccountIds === null) {
			return $this->accountMapper->find($accountId, $userId);
		}
		if (!in_array($accountId, $visibleAccountIds, true)) {
			throw new DoesNotExistException('Account ' . $accountId . ' is not visible to ' . $userId);
		}
		return $this->accountMapper->findById($accountId);
	}

	/**
	 * Delete a transaction under its account owner's identity (#334). Rows a
	 * bill payment creates in a shared account belong to the ACCOUNT owner —
	 * a revert issued by the bill's owner (the sharee) must delete them as
	 * that owner, because the acting-user-scoped find() can never see them.
	 *
	 * With $onlyIfScheduled, a row that is no longer a scheduled placeholder
	 * (it materialised into a real, possibly reconciled ledger row) is left
	 * alone and false is returned. With $onlyForBillId, so is a row that
	 * doesn't carry that bill's id.
	 *
	 * With $onlyForBillId the other side of a transfer goes too when it carries
	 * the same bill: a recurring transfer's deposit, or the other side Convert
	 * to transfer created for a bill's payment. A row of that bill that is
	 * already gone (deleted by hand, or with its other side) is nothing to do.
	 *
	 * Goes through delete() and thus deleteWithChildren() — never the mapper
	 * directly (#359).
	 *
	 * @return bool true when the row was deleted, false when it was
	 *              deliberately left alone
	 * @throws DoesNotExistException when the transaction no longer exists
	 *                               (only without $onlyForBillId)
	 */
	public function deleteAsAccountOwner(int $id, bool $onlyIfScheduled = false, ?int $onlyForBillId = null): bool {
		$transaction = $this->mapper->findById($id);
		if ($transaction === null) {
			if ($onlyForBillId !== null) {
				return false;
			}
			throw new DoesNotExistException("Transaction {$id} does not exist");
		}
		if ($onlyIfScheduled && ($transaction->getStatus() ?? 'cleared') !== 'scheduled') {
			return false;
		}
		// A bill revert deletes only what that bill booked: a row of another
		// bill, or one linked to its placeholder from outside (an imported
		// card credit), is not the bill's to remove
		if ($onlyForBillId !== null && $transaction->getBillId() !== $onlyForBillId) {
			return false;
		}
		$partnerId = $transaction->getLinkedTransactionId();
		$this->delete($id, $this->ownerOf($transaction));

		if ($onlyForBillId !== null && $partnerId !== null) {
			$partner = $this->mapper->findById($partnerId);
			if ($partner !== null && $partner->getBillId() === $onlyForBillId
				&& (!$onlyIfScheduled || ($partner->getStatus() ?? 'cleared') === 'scheduled')) {
				$this->delete($partnerId, $this->ownerOf($partner));
			}
		}
		return true;
	}

	/**
	 * A deleted bill's recorded payments stay as ordinary transactions. They
	 * kept the dead bill's id, so a bill set up again in its place never
	 * offered them in Mark Paid and the payment got recorded a second time.
	 *
	 * @return int how many rows were let go
	 */
	public function detachBillPayments(int $billId): int {
		return $this->mapper->detachFromBill($billId);
	}

	/**
	 * How many of the rows a bill revert would delete are reconciled against
	 * a bank statement: the named rows that carry the bill, and the other
	 * side of each, which deleteAsAccountOwner() takes with it.
	 *
	 * @param int[] $ids
	 */
	public function countReconciledBillRows(array $ids, int $billId): int {
		$seen = [];
		$count = 0;
		foreach ($ids as $id) {
			$row = $this->mapper->findById((int)$id);
			if ($row === null || $row->getBillId() !== $billId) {
				continue;
			}
			$rows = [$row];
			if ($row->getLinkedTransactionId() !== null) {
				$partner = $this->mapper->findById($row->getLinkedTransactionId());
				if ($partner !== null && $partner->getBillId() === $billId) {
					$rows[] = $partner;
				}
			}
			foreach ($rows as $candidate) {
				if (!isset($seen[$candidate->getId()]) && $candidate->getReconciled()) {
					$count++;
				}
				$seen[$candidate->getId()] = true;
			}
		}
		return $count;
	}

	/**
	 * Detach a transaction from its bill under the account owner's identity
	 * (#334). Used when reverting a payment that LINKED a pre-existing
	 * (imported) transaction: the row predates the payment and must survive
	 * the revert — only the bill linkage is undone. A row that no longer
	 * exists is ignored.
	 */
	public function unlinkBillAsAccountOwner(int $id): void {
		$transaction = $this->mapper->findById($id);
		if ($transaction === null) {
			return;
		}
		$billId = $transaction->getBillId();
		$this->update($id, $this->ownerOf($transaction), ['billId' => null]);

		// A transfer paid by linking its withdrawal also marked the arrival
		if ($billId !== null && $transaction->getLinkedTransactionId() !== null) {
			$partner = $this->mapper->findById($transaction->getLinkedTransactionId());
			if ($partner !== null && $partner->getBillId() === $billId) {
				$this->update($partner->getId(), $this->ownerOf($partner), ['billId' => null]);
			}
		}
	}

	/**
	 * Give a recurring transfer paid by linking its withdrawal the other leg.
	 *
	 * Linking only ever touched the withdrawal, so a transfer paid from an
	 * imported bank row left its destination uncredited. Its arrival is, in
	 * order: the row the withdrawal is already paired with; a credit of the
	 * same amount within three days in the destination, the bank's own row
	 * of the arrival; or failing both, a deposit booked as the destination
	 * account's owner on the withdrawal's date.
	 *
	 * @return int|null the id of a deposit booked here, so a revert can
	 *                  remove it; null when an existing row was used
	 */
	public function completeTransferPayment(Transaction $withdrawal, Bill $bill): ?int {
		if ($withdrawal->getLinkedTransactionId() !== null) {
			$partner = $this->mapper->findById($withdrawal->getLinkedTransactionId());
			if ($partner !== null && $partner->getBillId() === null) {
				$this->update($partner->getId(), $this->ownerOf($partner), ['billId' => $bill->getId()]);
			}
			return null;
		}
		$destination = $bill->getDestinationAccountId();
		if ($destination === null) {
			return null;
		}

		$on = new \DateTimeImmutable($withdrawal->getDate());
		$arrivals = $this->mapper->findTransferArrivals(
			$destination,
			(float)$withdrawal->getAmount(),
			$on->modify('-3 days')->format('Y-m-d'),
			$on->modify('+3 days')->format('Y-m-d')
		);
		if ($arrivals !== []) {
			usort($arrivals, fn (Transaction $a, Transaction $b)
				=> abs(strtotime($a->getDate()) - $on->getTimestamp()) <=> abs(strtotime($b->getDate()) - $on->getTimestamp()));
			$arrival = $arrivals[0];
			$this->mapper->linkTransactions($withdrawal->getId(), $arrival->getId());
			$this->update($arrival->getId(), $this->ownerOf($arrival), ['billId' => $bill->getId()]);
			return null;
		}

		$deposit = $this->create(
			userId: $this->accountMapper->findById($destination)->getUserId(),
			accountId: $destination,
			date: $withdrawal->getDate(),
			description: $bill->getDescription() ?? '',
			amount: (float)$withdrawal->getAmount(),
			type: 'credit',
			categoryId: $bill->getCategoryId(),
			vendor: $bill->getName(),
			reference: null,
			notes: "Auto-generated transfer: {$bill->getName()}",
			importId: null,
			billId: $bill->getId(),
			status: 'cleared',
			excludedFromForecast: $bill->getExcludedFromForecast() ?? false
		);
		$this->mapper->linkTransactions($withdrawal->getId(), $deposit->getId());
		$tagIds = $bill->getTagIdsArray();
		if (!empty($tagIds)) {
			$this->applyTagsToTransaction($deposit->getId(), $tagIds);
		}
		return $deposit->getId();
	}

	/**
	 * Credits the app booked for recurring income (Mark Received, auto-
	 * create) in an account between two dates, for matching the bank's own
	 * row of the same payment.
	 *
	 * @return Transaction[]
	 */
	public function findGeneratedIncomeCredits(int $accountId, string $from, string $to): array {
		return $this->mapper->findGeneratedIncomeCredits($accountId, $from, $to);
	}

	/**
	 * A transaction by id with no owner scoping, for callers that check
	 * access themselves. Null when it doesn't exist.
	 */
	public function findTransaction(int $id): ?Transaction {
		return $this->mapper->findById($id);
	}

	/**
	 * Link an existing transaction to a bill as the payment of its current
	 * occurrence, under the account owner's identity (#334): the row may sit
	 * in an account shared with the bill's owner, whose own lookup can't see
	 * it, and the link then failed while the bill moved on anyway.
	 *
	 * The row gets what the bill's own payment would have carried: its
	 * category when the row has none, and its tags. Only the bill id used to
	 * be set, so a payment linked from an import landed in Uncategorised.
	 * A row already paying another bill is refused. $withCategory false
	 * leaves the category off, for a row whose ledger can't use it.
	 *
	 * @throws \InvalidArgumentException
	 * @throws DoesNotExistException
	 */
	public function linkBillAsAccountOwner(int $id, Bill $bill, bool $withCategory = true): Transaction {
		$transaction = $this->mapper->findById($id);
		if ($transaction === null) {
			throw new DoesNotExistException("Transaction {$id} does not exist");
		}
		if ($transaction->getBillId() !== null && $transaction->getBillId() !== $bill->getId()) {
			throw new \InvalidArgumentException('This transaction already pays another bill');
		}

		$updates = ['billId' => $bill->getId()];
		if ($withCategory && $transaction->getCategoryId() === null && !$transaction->getIsSplit() && $bill->getCategoryId() !== null) {
			$updates['categoryId'] = $bill->getCategoryId();
		}
		$linked = $this->update($id, $this->ownerOf($transaction), $updates);

		$tagIds = $bill->getTagIdsArray();
		if (!empty($tagIds)) {
			$existing = array_map(
				fn ($tag) => (int)$tag->getTagId(),
				$this->transactionTagMapper->findByTransaction($id)
			);
			$this->applyTagsToTransaction($id, array_values(array_diff(array_map('intval', $tagIds), $existing)));
		}

		return $linked;
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
			$transactionTag->setTagId((int)$tagId);
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

		// A bill's scheduled row stands for its next unpaid occurrence, and
		// the bill's own actions treat every scheduled row of it that way. A
		// payment set back to scheduled was re-dated and merged by the next
		// Mark Paid, or deleted by Skip and Mark Unpaid, so a settled bill
		// row keeps its status.
		if (($updates['status'] ?? null) === 'scheduled' && $oldStatus !== 'scheduled'
			&& $transaction->getBillId() !== null) {
			unset($updates['status']);
		}

		// Auto-clear scheduled transactions when date is moved to today or past.
		// Not a bill's pre-booked row: it stands for an occurrence the bill
		// hasn't settled, and clearing it here booked the money while the bill
		// stayed unpaid, so Mark Paid then recorded the occurrence twice.
		if (isset($updates['date']) && $oldStatus === 'scheduled' && !isset($updates['status'])
			&& $transaction->getBillId() === null) {
			if (!$this->userClock->isFutureDate($updates['date'], $userId)) {
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

		// A split parent's category is deliberately null — the categories live
		// on its split rows. The blind setter loop below has no idea about
		// that, so bulk edit ("filter by Uncategorized, select all, set a
		// category") wrote a category onto every split parent, which then
		// double-counts against its own splits (#356). Unsplitting sets the
		// category legitimately, so only protect a row that stays split.
		// The flag alone is not proof of a split, though: restores from
		// #351-era archives left rows whose flag claims split with no parts
		// behind it, and unsetting the category here made an assignment on
		// such a row silently vanish while the list kept offering it as
		// uncategorized. Parts are the truth — no parts, keep the category
		// and correct the lying flag on this same write (#360).
		$staysSplit = $transaction->getIsSplit() && ($updates['isSplit'] ?? true);
		if ($staysSplit && array_key_exists('categoryId', $updates)) {
			if ($this->splitMapper->hasParts($id)) {
				unset($updates['categoryId']);
			} else {
				$transaction->setIsSplit(false);
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
		if (array_key_exists('amount', $updates) && $transaction->getIsSplit()) {
			$this->rescaleSplitsAfterAmountChange($transaction, (float)$oldAmount);
		}

		// Recompute affected account balances from the ledger. This replaces the
		// old hand-computed delta branches (account move / status flips / amount
		// or type edits), every one of which was a historical drift source.
		// A balance is a function of amount, type, status and account only (see
		// getNetChangeAll()), so metadata-only updates — category, vendor,
		// reference, notes, reconciled, date — skip the full-ledger SUM; a
		// cross-page bulk reconcile/edit would otherwise run it once per row.
		if (array_intersect(['amount', 'type', 'accountId', 'status'], array_keys($updates)) !== []) {
			$this->recalculateAccountBalance($transaction->getAccountId(), $userId);
			if ($accountChanging) {
				$this->recalculateAccountBalance($oldAccountId, $userId);
			}
		}

		return $transaction;
	}

	/**
	 * Rescale a split transaction's parts once its amount has changed by at
	 * least half of the smallest unit of its account's currency. The fixed
	 * tenth-of-a-penny threshold ignored a change of 0.0005 of a bitcoin,
	 * and the parts went on summing to the old amount.
	 */
	private function rescaleSplitsAfterAmountChange(Transaction $transaction, float $oldAmount): void {
		try {
			$decimals = Currency::decimalsFor($this->accountMapper->findById($transaction->getAccountId())->getCurrency());
		} catch (DoesNotExistException $e) {
			$decimals = 2;
		}
		if (abs($oldAmount - (float)$transaction->getAmount()) * (10 ** $decimals) < 0.5) {
			return;
		}
		$this->rescaleSplits($transaction->getId(), $oldAmount, (float)$transaction->getAmount(), $decimals);
	}

	/**
	 * Proportionally rescale a split transaction's parts to a new total so the
	 * splits keep summing to the transaction amount after an amount edit. The
	 * last split absorbs any rounding remainder so the sum stays exact. When the
	 * old amount was 0 (no proportions to preserve) shares are split evenly.
	 *
	 * @param int $decimals the account currency's decimals, which each part is
	 *                      rounded to: pennies lost all but two of bitcoin's eight
	 *                      places, and split 1001 yen into 500.5 and 500.5
	 */
	private function rescaleSplits(int $transactionId, float $oldAmount, float $newAmount, int $decimals = 2): void {
		$splits = $this->splitMapper->findByTransaction($transactionId);
		$count = count($splits);
		if ($count < 2) {
			return;
		}

		// BCMath throughout (#274): each part is worked out at 10 places and
		// rounded half away from zero, as round() did, then the last part
		// takes the new total less the others, so the sum is exact.
		$scale = 10;
		// bcadd truncates toward zero, so nudging by half a unit away from
		// zero first rounds to the nearest unit of the currency
		$half = $decimals > 0 ? '0.' . str_repeat('0', $decimals) . '5' : '0.5';
		$toUnit = static fn (string $exact): string => MoneyCalculator::add(
			$exact,
			MoneyCalculator::compare($exact, '0', $scale) < 0 ? '-' . $half : $half,
			$decimals
		);
		$proportional = MoneyCalculator::compare($oldAmount, '0', $scale) > 0;
		$running = '0';
		foreach (array_values($splits) as $i => $split) {
			if ($i === $count - 1) {
				$amount = $toUnit(MoneyCalculator::subtract($newAmount, $running, $scale));
			} else {
				$amount = $toUnit($proportional
					? MoneyCalculator::divide(MoneyCalculator::multiply($newAmount, $split->getAmount(), $scale), $oldAmount, $scale)
					: MoneyCalculator::divide($newAmount, (string)$count, $scale));
				$running = MoneyCalculator::add($running, $amount, $scale);
			}
			$split->setAmount($amount);
			$this->splitMapper->update($split);
		}
	}

	/**
	 * @param bool $recalculate Recompute the account balance afterwards. Only
	 *                          set false when the balance doesn't need it row by
	 *                          row: the account itself is being deleted (#336),
	 *                          or a bulk delete recomputes once per affected
	 *                          account at the end instead of once per row.
	 * @return int The deleted transaction's account ID, so bulk callers can
	 *             batch that one-recalculation-per-affected-account
	 */
	public function delete(int $id, string $userId, bool $dismiss = true, bool $recalculate = true): int {
		$transaction = $this->find($id, $userId);

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

		$this->deleteWithChildren($transaction, $userId);

		// Recompute from the ledger now that the row is gone
		if ($recalculate) {
			$this->recalculateAccountBalance($transaction->getAccountId(), $userId);
		}

		return $transaction->getAccountId();
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
	/**
	 * Clear the other account of a transfer when the user cannot see it.
	 * findWithFilters() joins the linked account's name and id without a
	 * scope, and the other half of a transfer on a shared account can sit in
	 * one of the owner's accounts that was never shared, which named that
	 * account to the person it was shared with. The link itself stays, so
	 * the row still reads as a transfer (#767). Every list consumer passes
	 * the user's whole visible set here, not its own query scope: a list of
	 * own accounts only must still name a shared account the user can see.
	 *
	 * @param array<int, array<string, mixed>> $rows findWithFilters() rows
	 * @param array<int|string> $visibleAccountIds
	 * @return array<int, array<string, mixed>>
	 */
	public static function hideUnseenLinkedAccounts(array $rows, array $visibleAccountIds): array {
		$visible = array_flip(array_map('intval', $visibleAccountIds));
		foreach ($rows as &$row) {
			if (!isset($visible[(int)($row['linkedAccountId'] ?? 0)])) {
				$row['linkedAccountId'] = null;
				$row['linkedAccountName'] = null;
			}
		}
		unset($row);
		return $rows;
	}

	public function findWithFilters(string $userId, array $filters, int $limit, int $offset, ?array $visibleAccountIds = null): array {
		$result = $this->mapper->findWithFilters($userId, $filters, $limit, $offset, $visibleAccountIds);

		// Compute running balance when viewing a single account sorted by date
		// with no non-date filters that break chronological contiguity
		$accountId = $filters['accountId'] ?? null;
		$sort = $filters['sort'] ?? 'date';

		$hasContiguityBreakingFilters
			= !empty($filters['category'])
			|| !empty($filters['type'])
			|| !empty($filters['search'])
			|| !empty($filters['amountMin'])
			|| !empty($filters['amountMax'])
			|| !empty($filters['status'])
			|| !empty($filters['tagIds']);

		if ($accountId && $sort === 'date' && !$hasContiguityBreakingFilters
			&& !empty($result['transactions'])) {

			// Use findById (not find) because the account may belong to a different
			// user who shared it. Access is already verified via visibleAccountIds.
			$account = $this->accountMapper->findById((int)$accountId);
			$openingBalance = (string)($account->getOpeningBalance() ?? 0);

			// Compute running balance for each transaction on the current page
			// by iterating over ALL account transactions chronologically.
			// This avoids page-boundary issues entirely.
			// Scheduled rows are not in the balance, so real rows carry the
			// balance money has reached; a scheduled row carries where the
			// balance will be once it and the scheduled rows before it go
			// through (#163), shown as projected. They used to get none.
			$allTx = $this->mapper->getAllTransactionsForBalance($accountId, true);

			$pageIds = [];
			foreach ($result['transactions'] as $tx) {
				$pageIds[$tx['id']] = true;
			}

			// At the account currency's precision, as the stored balance is
			// (#331): in pennies a bitcoin wallet's register read 0.50, 0.50,
			// 0.49 against a balance of 0.49746912
			$scale = Currency::decimalsFor($account->getCurrency());
			$running = $openingBalance;
			$projected = $openingBalance;
			$runningBalances = [];
			foreach ($allTx as $row) {
				$amount = (string)$row['amount'];
				$isScheduled = ($row['status'] ?? null) === 'scheduled';
				if ($row['type'] === 'credit') {
					$projected = MoneyCalculator::add($projected, $amount, $scale);
					$running = $isScheduled ? $running : MoneyCalculator::add($running, $amount, $scale);
				} else {
					$projected = MoneyCalculator::subtract($projected, $amount, $scale);
					$running = $isScheduled ? $running : MoneyCalculator::subtract($running, $amount, $scale);
				}
				if (isset($pageIds[(int)$row['id']])) {
					$runningBalances[(int)$row['id']] = $isScheduled ? $projected : $running;
				}
			}

			$result['runningBalances'] = $runningBalances;
		}

		$result['transactions'] = $this->attachSplitDetails($result['transactions'], $filters);

		return $result;
	}

	/**
	 * Attach each split transaction's parts to it for display, and — when the
	 * caller filtered by category — the share of it that belongs to that
	 * category (#359).
	 *
	 * A split transaction carries no category of its own, so a category filter
	 * matches it through its parts (see QueryFilterBuilder). The row still IS
	 * the whole transaction; matchedSplitAmount is the part of it the filter
	 * asked about, so a list and its total can agree with the spending charts
	 * instead of showing the full amount under every category it touches.
	 *
	 * Parts in the same filtered category are summed, so a receipt with two
	 * grocery lines reports one figure and still occupies one row. The absent
	 * case is null rather than 0.0 — a 0.00 share is a real value.
	 *
	 * isSplit is tri-state coming in (#360): true, false, or NULL for a
	 * pre-#351 import that predates the column. A NULL-flag row is a
	 * candidate here — it only resolves to true once its parts actually come
	 * back, so every row leaves this method with a real boolean, never NULL.
	 *
	 * @param array<int, array<string, mixed>> $transactions
	 * @return array<int, array<string, mixed>>
	 */
	private function attachSplitDetails(array $transactions, array $filters): array {
		$splitTxIds = [];
		foreach ($transactions as $tx) {
			if (($tx['isSplit'] ?? null) !== false && isset($tx['id'])) {
				$splitTxIds[] = (int)$tx['id'];
			}
		}

		if (empty($splitTxIds)) {
			foreach ($transactions as &$tx) {
				$tx['isSplit'] = (bool)($tx['isSplit'] ?? false);
			}
			unset($tx);
			return $transactions;
		}

		$splitDetails = $this->splitMapper->findByTransactionIds($splitTxIds);

		$filterIds = QueryFilterBuilder::parseCategoryIds($filters['category'] ?? null);

		// One pass: resolve the tri-state flag from what actually came back —
		// a candidate with no parts (false-negative NULL flag, or a stray
		// true with the split since deleted) is definitively not a split, so
		// the API never emits NULL — and attach the parts where they exist.
		foreach ($transactions as &$tx) {
			if (($tx['isSplit'] ?? null) === false) {
				continue;
			}
			$parts = $splitDetails[$tx['id']] ?? null;
			$tx['isSplit'] = $parts !== null;
			if ($parts === null) {
				continue;
			}

			if ($filterIds !== []) {
				$matchedTotal = null;
				$matchedNames = [];
				foreach ($parts as $i => $part) {
					$matched = $part['categoryId'] !== null
						&& in_array((int)$part['categoryId'], $filterIds, true);
					$parts[$i]['matched'] = $matched;
					if ($matched) {
						$matchedTotal = ($matchedTotal ?? 0.0) + (float)$part['amount'];
						if (!empty($part['categoryName'])) {
							$matchedNames[] = (string)$part['categoryName'];
						}
					}
				}
				if ($matchedTotal !== null) {
					$tx['matchedSplitAmount'] = $matchedTotal;
					$tx['matchedSplitCategoryName'] = implode(' / ', array_unique($matchedNames));
				}
			}

			$tx['splitCategories'] = $parts;
		}
		unset($tx);

		return $transactions;
	}

	/**
	 * Every transaction matching the filters, yielded a batch at a time for CSV
	 * export (#344).
	 *
	 * Deliberately not findWithFilters(): that computes a running balance for
	 * the page being displayed, which an export neither shows nor needs. Split
	 * detail is always attached — an unfiltered export names each split's
	 * categories in the Category cell (#360), and a category-filtered one
	 * reports the same per-category share the screen does (#359). Paging is
	 * safe here because the sort always carries a secondary sort by id.
	 *
	 * @param int[]|null $visibleAccountIds If provided, scope by account IDs instead of userId
	 * @return \Generator<int, array<int, array<string, mixed>>>
	 */
	public function findAllForExport(
		string $userId,
		array $filters,
		?array $visibleAccountIds = null,
		int $batchSize = 1000,
	): \Generator {
		$offset = 0;

		do {
			$result = $this->mapper->findWithFilters($userId, $filters, $batchSize, $offset, $visibleAccountIds);
			$batch = $result['transactions'] ?? [];

			if (empty($batch)) {
				return;
			}

			// Always attach, not just under a category filter as this did
			// when #359 added it: without a filter there is no matched share,
			// so a split exported with an empty Category cell. One extra
			// query per batch of 1000 rows buys the column back (#360).
			$batch = $this->attachSplitDetails($batch, $filters);

			yield $batch;
			$offset += $batchSize;
		} while ($offset < (int)($result['total'] ?? 0));
	}

	/**
	 * IDs (plus bill-generated count) of all transactions matching the filters,
	 * for cross-page "select all matching" bulk selection.
	 *
	 * @param int[]|null $visibleAccountIds If provided, scope by account IDs instead of userId
	 * @return array{ids: int[], billCount: int}
	 */
	public function findIdsWithFilters(string $userId, array $filters, ?array $visibleAccountIds = null): array {
		return $this->mapper->findIdsWithFilters($userId, $filters, $visibleAccountIds);
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
	 * Bulk delete transactions.
	 *
	 * Balances are recomputed once per affected account after all rows are
	 * gone, not per row — a per-row recompute is a SUM over the whole ledger,
	 * which made "select all matching" deletes of imported accounts crawl.
	 */
	public function bulkDelete(string $userId, array $ids): array {
		$results = ['success' => 0, 'failed' => 0, 'errors' => []];
		$affectedAccountIds = [];

		foreach ($ids as $id) {
			try {
				$accountId = $this->delete($id, $userId, true, false);
				$affectedAccountIds[$accountId] = true;
				$results['success']++;
			} catch (\Exception $e) {
				$results['failed']++;
				$results['errors'][] = [
					'id' => $id,
					'message' => $e->getMessage()
				];
			}
		}

		foreach (array_keys($affectedAccountIds) as $accountId) {
			$this->recalculateAccountBalance($accountId, $userId);
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
	 * $posted carries the bank's final figures: 'amount', 'type',
	 * 'description' and 'vendor', each applied when present. A tip, a fuel or
	 * hotel pre-auth or an FX settlement posts for a different amount than the
	 * hold, often under the merchant's real name, and keeping the hold's
	 * figures left the balance off from the bank's for good. Everything the
	 * user added to the hold (category, notes, tags, splits, receipts, the
	 * bill it paid) stays on the row.
	 *
	 * Pending and cleared both count toward the balance, but the amount or
	 * type may change here, so the caller recomputes the account balance (bank
	 * sync does that once per account).
	 */
	public function reconcilePendingToPosted(\OCA\Budget\Db\Transaction $transaction, ?string $newImportId = null, ?string $newDate = null, array $posted = []): \OCA\Budget\Db\Transaction {
		$oldAmount = (float)$transaction->getAmount();

		$transaction->setStatus('cleared');
		if ($newImportId !== null) {
			$transaction->setImportId($newImportId);
		}
		if ($newDate !== null) {
			$transaction->setDate($newDate);
		}
		if (isset($posted['amount'])) {
			$transaction->setAmount((float)$posted['amount']);
		}
		if (isset($posted['type'])) {
			$transaction->setType($posted['type']);
		}
		if (isset($posted['description']) && trim((string)$posted['description']) !== '') {
			$transaction->setDescription((string)$posted['description']);
		}
		if (isset($posted['vendor']) && trim((string)$posted['vendor']) !== '') {
			$transaction->setVendor((string)$posted['vendor']);
		}
		$transaction->setUpdatedAt(date('Y-m-d H:i:s'));
		$transaction = $this->mapper->update($transaction);

		// Split parts must keep summing to the row, as on an amount edit
		if ($transaction->getIsSplit()) {
			$this->rescaleSplitsAfterAmountChange($transaction, $oldAmount);
		}

		return $transaction;
	}

	/**
	 * Find potential transfer matches for a transaction
	 *
	 * $includeCrossCurrency also returns candidates in accounts with another
	 * currency (any amount, since the exchanged amount never matches) for the
	 * manual match dialog (#326). Auto-link flows must keep it off.
	 *
	 * $visibleAccountIds is the caller's account scope. Both legs of a transfer
	 * into a shared account belong to that account's OWNER, so the own-scoped
	 * lookups find neither the source nor any candidate — pass the scope and
	 * matching spans shared accounts the way linking already does (#378).
	 *
	 * Rows a bill or a recurring income booked are left out unless
	 * $includeGenerated is set, which only the manual match dialog does: there
	 * the user picks the pair, and everywhere else nobody looks before the link
	 * is made. A scheduled row is never matched, see the mapper.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @return Transaction[]
	 */
	public function findPotentialMatches(int $transactionId, string $userId, int $dateWindowDays = 3, bool $includeCrossCurrency = false, ?array $visibleAccountIds = null, bool $includeGenerated = false): array {
		$transaction = $this->findScoped($transactionId, $userId, $visibleAccountIds);

		// Don't find matches if already linked
		if ($transaction->getLinkedTransactionId() !== null) {
			return [];
		}
		if (($transaction->getStatus() ?? 'cleared') === 'scheduled') {
			return [];
		}
		if (!$includeGenerated && self::isScheduleGenerated($transaction)) {
			return [];
		}

		// Get account currency for currency-matched filtering
		$account = $this->accountFor($transaction->getAccountId(), $userId, $visibleAccountIds);

		return $this->mapper->findPotentialMatches(
			$userId,
			$transactionId,
			$transaction->getAccountId(),
			$transaction->getAmount(),
			$transaction->getType(),
			$transaction->getDate(),
			$account->getCurrency(),
			$dateWindowDays,
			$includeCrossCurrency,
			$visibleAccountIds,
			$includeGenerated
		);
	}

	/**
	 * Whether a bill or a recurring income booked this row, rather than the
	 * user or an import. Income rows carry no link back to their income, so
	 * the note they are created with is all there is to go on.
	 */
	private static function isScheduleGenerated(Transaction $transaction): bool {
		if ((int)($transaction->getBillId() ?? 0) !== 0) {
			return true;
		}
		$notes = $transaction->getNotes() ?? '';
		foreach (TransactionMapper::GENERATED_NOTE_PREFIXES as $prefix) {
			if (str_starts_with($notes, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Link two transactions as a transfer pair
	 *
	 * @throws \Exception if transactions cannot be linked
	 */
	public function linkTransactions(int $transactionId, int $targetId, string $userId, ?array $visibleAccountIds = null): array {
		$transaction = $this->findScoped($transactionId, $userId, $visibleAccountIds);
		$target = $this->findScoped($targetId, $userId, $visibleAccountIds);

		// Validation: must be different accounts
		if ($transaction->getAccountId() === $target->getAccountId()) {
			throw new \Exception('Cannot link transactions from the same account');
		}

		// Validation: must be same amount (unless cross-currency transfer)
		$sourceAccount = $this->accountFor($transaction->getAccountId(), $userId, $visibleAccountIds);
		$targetAccount = $this->accountFor($target->getAccountId(), $userId, $visibleAccountIds);
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

		// A bill's pre-booked row is a payment nobody has made yet. Linked to
		// a real row it took that row out of income and spending, and Mark
		// Paid then cleared it into a payment still tied to that row. Only a
		// recurring transfer's own two legs are linked while pre-booked.
		foreach ([[$transaction, $target], [$target, $transaction]] as [$leg, $other]) {
			if (($leg->getStatus() ?? 'cleared') === 'scheduled' && $leg->getBillId() !== null
				&& $other->getBillId() !== $leg->getBillId()) {
				throw new \Exception('This is the upcoming payment of a bill, so it cannot be matched yet. Mark the bill paid first, or make it a recurring transfer.');
			}
		}

		$this->mapper->linkTransactions($transactionId, $targetId);

		// Return updated transactions
		return [
			'transaction' => $this->findScoped($transactionId, $userId, $visibleAccountIds),
			'linkedTransaction' => $this->findScoped($targetId, $userId, $visibleAccountIds)
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
	public function convertToTransfer(int $transactionId, int $targetAccountId, string $userId, ?array $visibleAccountIds = null): array {
		$transaction = $this->findScoped($transactionId, $userId, $visibleAccountIds);

		// Validate before creating the counterpart so a failed conversion
		// never leaves an orphaned transaction behind
		if ($transaction->getLinkedTransactionId() !== null) {
			throw new \Exception('Transaction is already linked to another transaction');
		}
		if ($transaction->getAccountId() === $targetAccountId) {
			throw new \Exception('Cannot transfer within the same account');
		}
		// A bill's pre-booked row is a payment nobody has made yet. Its new
		// other side outlived it whenever the bill was skipped, deleted or
		// matched to an import, and was cleared on the old due date: the card
		// was credited for a payment that never happened.
		if (($transaction->getStatus() ?? 'cleared') === 'scheduled' && $transaction->getBillId() !== null) {
			throw new \Exception('This is the upcoming payment of a bill, so it has no other side yet. Mark the bill paid first, or make it a recurring transfer.');
		}

		// Also verifies both accounts are ones the caller may use
		$sourceAccount = $this->accountFor($transaction->getAccountId(), $userId, $visibleAccountIds);
		$targetAccount = $this->accountFor($targetAccountId, $userId, $visibleAccountIds);
		if ($sourceAccount->getCurrency() !== $targetAccount->getCurrency()) {
			throw new \Exception('Counterpart account must use the same currency');
		}

		$counterpart = $this->create(
			// The counterpart lands in the target account, so it belongs to
			// that account's owner — create() is owner-scoped (#368)
			userId: $targetAccount->getUserId(),
			accountId: $targetAccountId,
			date: $transaction->getDate(),
			description: $transaction->getDescription() ?? '',
			amount: $transaction->getAmount(),
			type: $transaction->getType() === 'debit' ? 'credit' : 'debit',
			vendor: $transaction->getVendor(),
			notes: 'Auto-created transfer counterpart',
			// The other side of a bill's payment is part of that payment, so
			// Mark Unpaid takes it away too rather than leaving the card
			// credited for a bill that is no longer paid
			billId: $transaction->getBillId(),
			status: $transaction->getStatus()
		);

		return $this->linkTransactions($transactionId, $counterpart->getId(), $userId, $visibleAccountIds);
	}

	/**
	 * Unlink a transaction from its transfer partner
	 */
	public function unlinkTransaction(int $transactionId, string $userId, ?array $visibleAccountIds = null): array {
		$transaction = $this->findScoped($transactionId, $userId, $visibleAccountIds);

		if ($transaction->getLinkedTransactionId() === null) {
			throw new \Exception('Transaction is not linked');
		}

		// The two pre-booked legs of a recurring transfer are one payment the
		// transfer hasn't made yet. Unlinked, Mark Paid took the deposit for a
		// duplicate and deleted it: the source paid out and the destination
		// was never credited.
		$partner = $this->mapper->findById($transaction->getLinkedTransactionId());
		if (($transaction->getStatus() ?? 'cleared') === 'scheduled' && $transaction->getBillId() !== null
			&& $partner !== null && $partner->getBillId() === $transaction->getBillId()) {
			throw new \InvalidArgumentException('This is the upcoming payment of a recurring transfer, so its two sides stay linked. Edit or skip the transfer instead.');
		}

		$linkedId = $this->mapper->unlinkTransaction($transactionId);

		return [
			'transaction' => $this->findScoped($transactionId, $userId, $visibleAccountIds),
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
		$newBalance = $this->balanceCalculator->balanceFor(
			$accountId,
			$account->getOpeningBalance(),
			$account->getCurrency()
		);

		$this->accountMapper->updateBalance($accountId, $newBalance, $userId);
	}

	/**
	 * Amount owed on an account as of the end of $boundaryDate: the stored
	 * balance minus everything dated after the boundary (scheduled rows are
	 * in neither). This is the "statement balance" a statement-amount
	 * transfer bill pays (#347) — with a payment made each cycle it equals
	 * the activity between the previous due date and this one, and any
	 * underpaid remainder carries forward. Liability balances are stored
	 * negative when owed; returns 0 when nothing is owed.
	 */
	public function getStatementAmountForAccount(int $accountId, string $boundaryDate): float {
		$owed = $this->getBalanceAsOf($accountId, $boundaryDate);
		return $owed < 0 ? -$owed : 0.0;
	}

	/**
	 * An account's balance at the end of $date: the stored balance with
	 * everything dated after it taken back out (scheduled rows are in
	 * neither). With today's date this is the figure the Accounts page shows.
	 */
	public function getBalanceAsOf(int $accountId, string $date): float {
		$account = $this->accountMapper->findById($accountId);
		$asOf = MoneyCalculator::subtract(
			(string)($account->getBalance() ?? 0),
			$this->mapper->getNetChangeAfterDate($accountId, $date),
			Currency::decimalsFor($account->getCurrency())
		);
		return MoneyCalculator::toFloat($asOf);
	}

	/**
	 * Bulk find and match transactions
	 * Auto-links transactions with exactly one match, returns others for manual review
	 *
	 * $sourceIds limits the rows the search starts from (their counterparts can
	 * be anywhere). The import screen passes the rows it just imported: it used
	 * to sweep the whole ledger, so two unrelated rows of the same amount a
	 * few days apart in different accounts were linked whenever anything at
	 * all was imported.
	 *
	 * @param string $userId
	 * @param int $dateWindowDays
	 * @param int $batchSize
	 * @param int[]|null $sourceIds
	 * @return array Results with autoMatched, needsReview, and stats
	 */
	public function bulkFindAndMatch(string $userId, int $dateWindowDays = 3, int $batchSize = 100, ?array $visibleAccountIds = null, ?array $sourceIds = null): array {
		$autoMatched = [];
		$needsReview = [];
		$processedIds = []; // Track IDs we've already processed to avoid duplicates

		$offset = 0;
		$hasMore = true;
		// Batches of the given ids replace the paging over the whole ledger
		$sourceBatches = $sourceIds === null
			? null
			: array_chunk(array_values(array_unique(array_map('intval', $sourceIds))), max(1, $batchSize));

		while ($hasMore) {
			if ($sourceBatches !== null) {
				if ($sourceBatches === []) {
					break;
				}
				$result = $this->mapper->findUnlinkedWithMatches($userId, $dateWindowDays, $batchSize, 0, $visibleAccountIds, array_shift($sourceBatches));
			} else {
				$result = $this->mapper->findUnlinkedWithMatches($userId, $dateWindowDays, $batchSize, $offset, $visibleAccountIds);
				if (empty($result['transactions'])) {
					$hasMore = false;
					break;
				}
			}

			foreach ($result['transactions'] as $item) {
				$txId = (int)$item['transaction']['id'];

				// Skip if we've already processed this transaction (could be a match for another)
				if (isset($processedIds[$txId])) {
					continue;
				}

				// Filter out matches that have already been processed
				$availableMatches = array_filter($item['matches'], function ($match) use ($processedIds) {
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

			if ($sourceBatches !== null) {
				continue;
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
	public function scanForMatches(string $userId, int $dateWindowDays = 3, int $batchSize = 100, ?array $visibleAccountIds = null): array {
		$candidates = [];
		$processedIds = [];

		$offset = 0;
		$hasMore = true;

		while ($hasMore) {
			$result = $this->mapper->findUnlinkedWithMatches($userId, $dateWindowDays, $batchSize, $offset, $visibleAccountIds);

			if (empty($result['transactions'])) {
				$hasMore = false;
				break;
			}

			foreach ($result['transactions'] as $item) {
				$txId = (int)$item['transaction']['id'];

				if (isset($processedIds[$txId])) {
					continue;
				}

				$availableMatches = array_filter($item['matches'], function ($match) use ($processedIds) {
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
	public function bulkLinkTransactions(string $userId, array $pairs, ?array $visibleAccountIds = null): array {
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
				$this->linkTransactions($sourceId, $targetId, $userId, $visibleAccountIds);
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
