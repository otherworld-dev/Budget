<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Settlement;
use OCA\Budget\Db\SettlementMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserManager;

class SharedExpenseService {
	/** Why setTransactionShares() refused a set of splits, as the exception code */
	public const SPLIT_ERR_DUPLICATE = 1;
	public const SPLIT_ERR_SETTLED = 2;
	public const SPLIT_ERR_AMOUNT = 3;
	public const SPLIT_ERR_OVER_TOTAL = 4;

	private ContactMapper $contactMapper;
	private ExpenseShareMapper $expenseShareMapper;
	private SettlementMapper $settlementMapper;
	private TransactionMapper $transactionMapper;
	private AccountMapper $accountMapper;
	private IUserManager $userManager;
	private IDBConnection $db;

	public function __construct(
		ContactMapper $contactMapper,
		ExpenseShareMapper $expenseShareMapper,
		SettlementMapper $settlementMapper,
		TransactionMapper $transactionMapper,
		AccountMapper $accountMapper,
		IUserManager $userManager,
		IDBConnection $db,
	) {
		$this->contactMapper = $contactMapper;
		$this->expenseShareMapper = $expenseShareMapper;
		$this->settlementMapper = $settlementMapper;
		$this->transactionMapper = $transactionMapper;
		$this->accountMapper = $accountMapper;
		$this->userManager = $userManager;
		$this->db = $db;
	}

	/**
	 * Get expenses that other users have shared with the current user — i.e.
	 * splits assigned to a contact linked to this Nextcloud account (#248).
	 * Read-only from the recipient's side; the owner manages settlement.
	 *
	 * @return array[] Each: {id, ownerUserId, ownerName, transactionId,
	 *                 transactionDescription, transactionDate, transactionAmount,
	 *                 transactionType, amount, isSettled, currency, notes}
	 */
	public function getExpensesSharedWithMe(string $userId): array {
		$rows = $this->expenseShareMapper->findSharedWithNextcloudUser($userId);

		$nameCache = [];
		$result = [];
		foreach ($rows as $row) {
			$ownerId = $row['owner_user_id'];
			if (!array_key_exists($ownerId, $nameCache)) {
				$user = $this->userManager->get($ownerId);
				$nameCache[$ownerId] = $user !== null ? $user->getDisplayName() : $ownerId;
			}

			$result[] = [
				'id' => (int)$row['id'],
				'ownerUserId' => $ownerId,
				'ownerName' => $nameCache[$ownerId],
				'transactionId' => (int)$row['transaction_id'],
				'transactionDescription' => $row['transaction_description'] ?? null,
				'transactionDate' => $row['transaction_date'] ?? null,
				'transactionAmount' => $row['transaction_amount'] !== null ? (float)$row['transaction_amount'] : null,
				'transactionType' => $row['transaction_type'] ?? null,
				'amount' => (float)$row['amount'],
				'isSettled' => (bool)$row['is_settled'],
				'currency' => $row['currency'] ?? null,
				'notes' => $row['notes'] ?? null,
			];
		}

		return $result;
	}

	/**
	 * Get the currency for a transaction by looking up its account.
	 */
	private function getTransactionCurrency(int $transactionId, string $userId): ?string {
		try {
			$transaction = $this->transactionMapper->find($transactionId, $userId);
			$account = $this->accountMapper->find($transaction->getAccountId(), $userId);
			return $account->getCurrency() ?: null;
		} catch (\Exception $e) {
			return null;
		}
	}

	// ==================== Contact Methods ====================

	/**
	 * Get all contacts for a user.
	 *
	 * @return Contact[]
	 */
	public function getContacts(string $userId): array {
		return $this->contactMapper->findAll($userId);
	}

	/**
	 * Get a contact by ID.
	 *
	 * @throws DoesNotExistException
	 */
	public function getContact(int $id, string $userId): Contact {
		return $this->contactMapper->find($id, $userId);
	}

	/**
	 * Create a new contact.
	 */
	public function createContact(string $userId, string $name, ?string $email = null, ?string $nextcloudUserId = null): Contact {
		// Check if a contact already exists for this Nextcloud user
		if ($nextcloudUserId) {
			$existing = $this->contactMapper->findByNextcloudUserId($nextcloudUserId, $userId);
			if ($existing) {
				return $existing;
			}
		}

		$contact = new Contact();
		$contact->setUserId($userId);
		$contact->setName($name);
		$contact->setEmail($email);
		$contact->setNextcloudUserId($nextcloudUserId);
		$contact->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

		return $this->contactMapper->insert($contact);
	}

	/**
	 * Update a contact.
	 *
	 * @throws DoesNotExistException
	 */
	public function updateContact(int $id, string $userId, string $name, ?string $email = null): Contact {
		$contact = $this->contactMapper->find($id, $userId);
		$contact->setName($name);
		$contact->setEmail($email);

		return $this->contactMapper->update($contact);
	}

	/**
	 * Delete a contact.
	 *
	 * @throws DoesNotExistException
	 */
	public function deleteContact(int $id, string $userId): Contact {
		$contact = $this->contactMapper->find($id, $userId);

		// Their splits and settlements go too. Nothing can reach a split whose
		// contact is gone, so left behind it dropped out of every balance and
		// kept a Shared badge on its transaction that nothing could clear.
		$this->db->beginTransaction();
		try {
			$this->expenseShareMapper->deleteByContact($id, $userId);
			$this->settlementMapper->deleteByContact($id, $userId);
			$deleted = $this->contactMapper->delete($contact);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $deleted;
	}

	// ==================== Expense Share Methods ====================

	/**
	 * Share an expense with a contact.
	 *
	 * @param int $transactionId The transaction to share
	 * @param int $contactId The contact who owes/is owed
	 * @param float $amount Positive = they owe you, negative = you owe them
	 * @param string|null $notes Optional notes
	 */
	public function shareExpense(
		string $userId,
		int $transactionId,
		int $contactId,
		float $amount,
		?string $notes = null,
		?array $visibleAccountIds = null,
	): ExpenseShare {
		// Verify the transaction exists and is accessible to user
		try {
			$this->transactionMapper->find($transactionId, $userId);
		} catch (DoesNotExistException $e) {
			if (!empty($visibleAccountIds)) {
				$this->transactionMapper->findForAccounts($transactionId, $visibleAccountIds);
			} else {
				throw $e;
			}
		}
		// Verify the contact exists and belongs to user
		$this->contactMapper->find($contactId, $userId);

		// Check for existing share with this contact on this transaction
		$existingShares = $this->expenseShareMapper->findByTransaction($transactionId, $userId);
		foreach ($existingShares as $existing) {
			if ($existing->getContactId() === $contactId) {
				throw new \InvalidArgumentException('This transaction is already shared with this contact');
			}
		}

		$currency = $this->getTransactionCurrency($transactionId, $userId);

		$share = new ExpenseShare();
		$share->setUserId($userId);
		$share->setTransactionId($transactionId);
		$share->setContactId($contactId);
		$share->setAmount($amount);
		$share->setCurrency($currency);
		$share->setIsSettled(false);
		$share->setNotes($notes);
		$share->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

		return $this->expenseShareMapper->insert($share);
	}

	/**
	 * Split a transaction 50/50 with a contact.
	 */
	public function splitFiftyFifty(
		string $userId,
		int $transactionId,
		int $contactId,
		?string $notes = null,
		?array $visibleAccountIds = null,
	): ExpenseShare {
		// Try own accounts first, fall back to shared accounts
		try {
			$transaction = $this->transactionMapper->find($transactionId, $userId);
		} catch (DoesNotExistException $e) {
			if (!empty($visibleAccountIds)) {
				$transaction = $this->transactionMapper->findForAccounts($transactionId, $visibleAccountIds);
			} else {
				throw $e;
			}
		}

		$amount = abs((float)$transaction->getAmount()) / 2;

		// Debit = you paid (expense), so they owe you half (positive share)
		// Credit = you received (income), so you owe them half (negative share)
		if ($transaction->getType() === 'debit') {
			return $this->shareExpense($userId, $transactionId, $contactId, $amount, $notes, $visibleAccountIds);
		} else {
			return $this->shareExpense($userId, $transactionId, $contactId, -$amount, $notes, $visibleAccountIds);
		}
	}

	/**
	 * Set everyone a transaction is split with in one go (#391).
	 *
	 * $splits is the complete list of open splits the transaction should end
	 * up with, one per contact: positive when they owe you, negative when you
	 * owe them. An open split whose contact is missing from the list is
	 * removed. Settled splits belong to a recorded settlement, so they are
	 * never changed and their contacts cannot be listed, but they still count
	 * towards the transaction's amount, which the splits together may not
	 * exceed.
	 *
	 * @param array<array{contactId: int, amount: float|int|string}> $splits
	 * @param int[]|null $visibleAccountIds also reach a transaction in an account shared with the user
	 * @return ExpenseShare[] every split on the transaction afterwards, settled ones included
	 * @throws \InvalidArgumentException coded with a SPLIT_ERR_* constant
	 * @throws DoesNotExistException when the transaction or a contact is not the user's
	 */
	public function setTransactionShares(
		string $userId,
		int $transactionId,
		array $splits,
		?string $notes = null,
		?array $visibleAccountIds = null,
	): array {
		$transaction = $this->findShareableTransaction($transactionId, $userId, $visibleAccountIds);

		$open = [];
		$settled = [];
		foreach ($this->expenseShareMapper->findByTransaction($transactionId, $userId) as $share) {
			if ($share->getIsSettled()) {
				$settled[$share->getContactId()] = $share;
			} else {
				$open[$share->getContactId()] = $share;
			}
		}

		$amounts = [];
		$total = '0';
		foreach ($splits as $split) {
			$split = is_array($split) ? $split : [];
			$contactId = (int)($split['contactId'] ?? 0);
			$amount = $split['amount'] ?? null;
			if (!is_numeric($amount) || MoneyCalculator::compare((float)$amount, '0') === 0) {
				throw new \InvalidArgumentException('Every split needs an amount', self::SPLIT_ERR_AMOUNT);
			}
			if (isset($amounts[$contactId])) {
				throw new \InvalidArgumentException('A contact can only be in a split once', self::SPLIT_ERR_DUPLICATE);
			}
			if (isset($settled[$contactId])) {
				throw new \InvalidArgumentException('A settled split cannot be changed', self::SPLIT_ERR_SETTLED);
			}
			$this->contactMapper->find($contactId, $userId);

			$amounts[$contactId] = MoneyCalculator::add((float)$amount, '0');
			$total = MoneyCalculator::add($total, MoneyCalculator::abs($amounts[$contactId]));
		}
		foreach ($settled as $share) {
			$total = MoneyCalculator::add($total, MoneyCalculator::abs((float)$share->getAmount()));
		}
		if (MoneyCalculator::compare($total, MoneyCalculator::abs((float)$transaction->getAmount())) > 0) {
			throw new \InvalidArgumentException('The splits add up to more than the transaction', self::SPLIT_ERR_OVER_TOTAL);
		}

		$currency = $this->accountCurrency($transaction);
		$result = array_values($settled);

		$this->db->beginTransaction();
		try {
			foreach ($open as $contactId => $share) {
				if (!isset($amounts[$contactId])) {
					$this->expenseShareMapper->delete($share);
				}
			}
			foreach ($amounts as $contactId => $amount) {
				$share = $open[$contactId] ?? null;
				if ($share !== null) {
					$share->setAmount(MoneyCalculator::toFloat($amount));
					$share->setNotes($notes);
					$result[] = $this->expenseShareMapper->update($share);
					continue;
				}

				$share = new ExpenseShare();
				$share->setUserId($userId);
				$share->setTransactionId($transactionId);
				$share->setContactId($contactId);
				$share->setAmount(MoneyCalculator::toFloat($amount));
				$share->setCurrency($currency);
				$share->setIsSettled(false);
				$share->setNotes($notes);
				$share->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));
				$result[] = $this->expenseShareMapper->insert($share);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $result;
	}

	/**
	 * The user's own transaction, or failing that one in an account shared
	 * with them.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @throws DoesNotExistException
	 */
	private function findShareableTransaction(int $transactionId, string $userId, ?array $visibleAccountIds): Transaction {
		try {
			return $this->transactionMapper->find($transactionId, $userId);
		} catch (DoesNotExistException $e) {
			if (empty($visibleAccountIds)) {
				throw $e;
			}
			return $this->transactionMapper->findForAccounts($transactionId, $visibleAccountIds);
		}
	}

	/**
	 * The currency of the account a transaction is in, looked up by id so a
	 * transaction in a shared account gets its owner's currency too.
	 */
	private function accountCurrency(Transaction $transaction): ?string {
		try {
			return $this->accountMapper->findById($transaction->getAccountId())->getCurrency() ?: null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get all expense shares for a user.
	 *
	 * @return ExpenseShare[]
	 */
	public function getExpenseShares(string $userId): array {
		return $this->expenseShareMapper->findAll($userId);
	}

	/**
	 * Get shares for a specific transaction.
	 *
	 * @return ExpenseShare[]
	 */
	public function getSharesByTransaction(int $transactionId, string $userId): array {
		return $this->expenseShareMapper->findByTransaction($transactionId, $userId);
	}

	/**
	 * Get all unsettled shares.
	 *
	 * @return ExpenseShare[]
	 */
	public function getUnsettledShares(string $userId): array {
		return $this->expenseShareMapper->findUnsettled($userId);
	}

	/**
	 * Update an expense share.
	 *
	 * @throws DoesNotExistException
	 */
	public function updateExpenseShare(
		int $id,
		string $userId,
		float $amount,
		?string $notes = null,
	): ExpenseShare {
		$share = $this->expenseShareMapper->find($id, $userId);
		$share->setAmount($amount);
		$share->setNotes($notes);

		return $this->expenseShareMapper->update($share);
	}

	/**
	 * Mark a share as settled.
	 *
	 * @throws DoesNotExistException
	 */
	public function markShareSettled(int $id, string $userId): ExpenseShare {
		$share = $this->expenseShareMapper->find($id, $userId);
		$share->setIsSettled(true);

		return $this->expenseShareMapper->update($share);
	}

	/**
	 * Delete an expense share.
	 *
	 * @throws DoesNotExistException
	 */
	public function deleteExpenseShare(int $id, string $userId): ExpenseShare {
		$share = $this->expenseShareMapper->find($id, $userId);
		return $this->expenseShareMapper->delete($share);
	}

	/**
	 * Remove all shares for a transaction.
	 */
	public function removeTransactionShares(int $transactionId, string $userId): void {
		$this->expenseShareMapper->deleteByTransaction($transactionId, $userId);
	}

	// ==================== Settlement Methods ====================

	/**
	 * Record a settlement payment.
	 *
	 * @param float $amount Positive = they paid you, negative = you paid them
	 */
	public function recordSettlement(
		string $userId,
		int $contactId,
		float $amount,
		string $date,
		?string $notes = null,
		?string $currency = null,
	): Settlement {
		// Verify contact exists
		$this->contactMapper->find($contactId, $userId);

		$settlement = new Settlement();
		$settlement->setUserId($userId);
		$settlement->setContactId($contactId);
		$settlement->setAmount($amount);
		$settlement->setCurrency($currency);
		$settlement->setDate($date);
		$settlement->setNotes($notes);
		$settlement->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

		return $this->settlementMapper->insert($settlement);
	}

	/**
	 * Settle selected shares by ID, creating per-currency settlements.
	 *
	 * @param int[] $shareIds
	 * @return Settlement[] One settlement per currency
	 */
	public function settleSelectedShares(
		string $userId,
		array $shareIds,
		string $date,
		?string $notes = null,
	): array {
		$contactId = null;
		$byCurrency = [];

		foreach ($shareIds as $shareId) {
			$share = $this->expenseShareMapper->find($shareId, $userId);
			if ($contactId === null) {
				$contactId = $share->getContactId();
			}
			$currency = $share->getCurrency() ?? 'USD';
			$byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $share->getAmount();
			$share->setIsSettled(true);
			$this->expenseShareMapper->update($share);
		}

		$settlements = [];
		foreach ($byCurrency as $currency => $total) {
			$settlements[] = $this->recordSettlement($userId, $contactId, $total, $date, $notes, $currency);
		}

		return $settlements;
	}

	/**
	 * Settle all unsettled shares with a contact, creating per-currency settlements.
	 *
	 * @return Settlement[] One settlement per currency
	 */
	public function settleWithContact(
		string $userId,
		int $contactId,
		string $date,
		?string $notes = null,
	): array {
		$shares = $this->expenseShareMapper->findUnsettledByContact($contactId, $userId);

		$byCurrency = [];
		foreach ($shares as $share) {
			$currency = $share->getCurrency() ?? 'USD';
			$byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $share->getAmount();
			$share->setIsSettled(true);
			$this->expenseShareMapper->update($share);
		}

		$settlements = [];
		foreach ($byCurrency as $currency => $total) {
			$settlements[] = $this->recordSettlement($userId, $contactId, $total, $date, $notes, $currency);
		}

		return $settlements;
	}

	/**
	 * Get all settlements for a user.
	 *
	 * @return Settlement[]
	 */
	public function getSettlements(string $userId): array {
		return $this->settlementMapper->findAll($userId);
	}

	/**
	 * Get settlements for a specific contact.
	 *
	 * @return Settlement[]
	 */
	public function getSettlementsByContact(int $contactId, string $userId): array {
		return $this->settlementMapper->findByContact($contactId, $userId);
	}

	/**
	 * Delete a settlement.
	 *
	 * @throws DoesNotExistException
	 */
	public function deleteSettlement(int $id, string $userId): Settlement {
		$settlement = $this->settlementMapper->find($id, $userId);
		return $this->settlementMapper->delete($settlement);
	}

	/**
	 * Get shared transaction statuses.
	 *
	 * @return array<int, string> transaction_id => 'shared' or 'settled'
	 */
	public function getSharedTransactionStatuses(string $userId): array {
		return $this->expenseShareMapper->getSharedTransactionStatuses($userId);
	}

	// ==================== Balance Methods ====================

	/**
	 * Get balance summary for all contacts, grouped by currency.
	 */
	public function getBalanceSummary(string $userId): array {
		$contacts = $this->contactMapper->findAll($userId);
		$balances = $this->expenseShareMapper->getBalancesByContact($userId);
		$incoming = $this->expenseShareMapper->getIncomingBalancesByOwner($userId);

		$contactBalances = [];
		$totalsByCurrency = []; // currency => {owed, owing}

		foreach ($contacts as $contact) {
			$currencyBalances = $balances[$contact->getId()] ?? [];

			// What the linked user split with me is owed the other way round (#390)
			$sharer = $this->linkedSharer($contact, $userId);
			foreach ($sharer !== null ? ($incoming[$sharer] ?? []) : [] as $currency => $amount) {
				$currencyBalances[$currency] = MoneyCalculator::toFloat(
					MoneyCalculator::subtract($currencyBalances[$currency] ?? 0.0, $amount)
				);
			}

			// Build per-currency balance lines
			$balanceLines = [];
			$hasBalance = false;
			foreach ($currencyBalances as $currency => $amount) {
				if (abs($amount) < 0.005) {
					continue;
				}
				$hasBalance = true;
				$balanceLines[] = [
					'currency' => $currency,
					'amount' => $amount,
					'direction' => $amount > 0 ? 'owed' : 'owing',
				];

				if (!isset($totalsByCurrency[$currency])) {
					$totalsByCurrency[$currency] = ['owed' => 0.0, 'owing' => 0.0];
				}
				if ($amount > 0) {
					$totalsByCurrency[$currency]['owed'] += $amount;
				} else {
					$totalsByCurrency[$currency]['owing'] += abs($amount);
				}
			}

			$contactBalances[] = [
				'contact' => $contact->jsonSerialize(),
				'balances' => $balanceLines,
				// Legacy single-currency field for backward compat (sum of all currencies)
				'balance' => array_sum($currencyBalances),
				'direction' => !$hasBalance ? 'settled' : (array_sum($currencyBalances) > 0 ? 'owed' : 'owing'),
			];
		}

		return [
			'contacts' => $contactBalances,
			'totalsByCurrency' => $totalsByCurrency,
			// Legacy single-currency totals for backward compat
			'totalOwed' => array_sum(array_column($totalsByCurrency, 'owed')),
			'totalOwing' => array_sum(array_column($totalsByCurrency, 'owing')),
			'netBalance' => array_sum(array_column($totalsByCurrency, 'owed')) - array_sum(array_column($totalsByCurrency, 'owing')),
		];
	}

	/**
	 * Get detailed balance for a specific contact including transaction history.
	 */
	public function getContactDetails(int $contactId, string $userId): array {
		$contact = $this->contactMapper->find($contactId, $userId);
		$shares = $this->expenseShareMapper->findByContact($contactId, $userId);
		$settlements = $this->settlementMapper->findByContact($contactId, $userId);

		// Enrich shares with transaction data
		$enrichedShares = [];
		foreach ($shares as $share) {
			try {
				$transaction = $this->transactionMapper->find($share->getTransactionId(), $userId);
				$enrichedShares[] = [
					'share' => $share->jsonSerialize(),
					'transaction' => [
						'id' => $transaction->getId(),
						'date' => $transaction->getDate(),
						'description' => $transaction->getDescription(),
						'amount' => $transaction->getAmount(),
					],
					'incoming' => false,
				];
			} catch (DoesNotExistException $e) {
				// Transaction was deleted, skip this share
				continue;
			}
		}

		// Calculate per-currency balances from unsettled shares
		$balancesByCurrency = [];
		foreach ($shares as $share) {
			if (!$share->getIsSettled()) {
				$currency = $share->getCurrency() ?? 'USD';
				$balancesByCurrency[$currency] = ($balancesByCurrency[$currency] ?? 0.0) + $share->getAmount();
			}
		}

		$settlementRows = array_map(fn ($s) => $s->jsonSerialize() + ['incoming' => false], $settlements);

		// A contact linked to a Nextcloud user also carries what that user split
		// with me, flipped to my side of the ledger. Read-only here: the user who
		// split it settles it (#248, #390).
		$sharer = $this->linkedSharer($contact, $userId);
		if ($sharer !== null) {
			foreach ($this->expenseShareMapper->findSharedWithNextcloudUser($userId, $sharer) as $row) {
				$amount = -(float)$row['amount'];
				$isSettled = (bool)$row['is_settled'];
				if (!$isSettled) {
					$currency = $row['currency'] ?? 'USD';
					$balancesByCurrency[$currency] = MoneyCalculator::toFloat(
						MoneyCalculator::add($balancesByCurrency[$currency] ?? 0.0, $amount)
					);
				}
				if ($row['transaction_date'] === null) {
					// Transaction was deleted, skip this share
					continue;
				}
				$enrichedShares[] = [
					'share' => [
						'id' => (int)$row['id'],
						'userId' => $sharer,
						'transactionId' => (int)$row['transaction_id'],
						'contactId' => $contactId,
						'amount' => $amount,
						'isSettled' => $isSettled,
						'notes' => $row['notes'] ?? null,
						'createdAt' => $row['created_at'],
						'currency' => $row['currency'] ?? null,
					],
					'transaction' => [
						'id' => (int)$row['transaction_id'],
						'date' => $row['transaction_date'],
						'description' => $row['transaction_description'],
						'amount' => (float)$row['transaction_amount'],
					],
					'incoming' => true,
				];
			}

			foreach ($this->settlementMapper->findSharedWithNextcloudUser($userId, $sharer) as $settlement) {
				$row = $settlement->jsonSerialize();
				$row['contactId'] = $contactId;
				$row['amount'] = -(float)$row['amount'];
				$row['incoming'] = true;
				$settlementRows[] = $row;
			}

			// Newest first across both sides, as each list is on its own
			usort($enrichedShares, fn ($a, $b) => strcmp((string)$b['share']['createdAt'], (string)$a['share']['createdAt']));
			usort($settlementRows, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		}

		$totalBalance = array_sum($balancesByCurrency);

		return [
			'contact' => $contact->jsonSerialize(),
			'shares' => $enrichedShares,
			'settlements' => $settlementRows,
			'balances' => $balancesByCurrency,
			// Legacy
			'balance' => $totalBalance,
			'direction' => abs($totalBalance) < 0.005 ? 'settled' : ($totalBalance > 0 ? 'owed' : 'owing'),
		];
	}

	/**
	 * The Nextcloud user whose splits with $userId belong on this contact's
	 * card: the linked user, or null for a manual contact (#390).
	 */
	private function linkedSharer(Contact $contact, string $userId): ?string {
		$linked = $contact->getNextcloudUserId();
		return ($linked !== null && $linked !== '' && $linked !== $userId) ? $linked : null;
	}
}
