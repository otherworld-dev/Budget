<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Exception\ReconciledPaymentException;
use OCA\Budget\Service\Bill\BalanceProjector;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Manages bill CRUD operations and summary calculations.
 */
class BillService {
	private BillMapper $mapper;
	private FrequencyCalculator $frequencyCalculator;
	private RecurringBillDetector $recurringDetector;
	private TransactionService $transactionService;
	private IL10N $l;
	private AccountMapper $accountMapper;
	private CurrencyConversionService $currencyConversion;
	private TransactionSplitService $splitService;
	private LoggerInterface $logger;
	private DismissedSuggestionMapper $dismissedMapper;
	private ?AutoShareService $autoShareService;
	private ?RecurringIncomeMapper $incomeMapper;
	private ?GranularShareService $granularShareService;
	private ?UserClock $userClock;
	private ?INotificationManager $notificationManager;

	public function __construct(
		BillMapper $mapper,
		FrequencyCalculator $frequencyCalculator,
		RecurringBillDetector $recurringDetector,
		TransactionService $transactionService,
		IL10N $l,
		AccountMapper $accountMapper,
		CurrencyConversionService $currencyConversion,
		TransactionSplitService $splitService,
		LoggerInterface $logger,
		DismissedSuggestionMapper $dismissedMapper,
		?AutoShareService $autoShareService = null,
		?RecurringIncomeMapper $incomeMapper = null,
		?GranularShareService $granularShareService = null,
		?UserClock $userClock = null,
		?INotificationManager $notificationManager = null,
		private ?PensionRecurringService $pensionRecurringService = null,
	) {
		$this->mapper = $mapper;
		$this->frequencyCalculator = $frequencyCalculator;
		$this->recurringDetector = $recurringDetector;
		$this->transactionService = $transactionService;
		$this->l = $l;
		$this->accountMapper = $accountMapper;
		$this->currencyConversion = $currencyConversion;
		$this->splitService = $splitService;
		$this->logger = $logger;
		$this->dismissedMapper = $dismissedMapper;
		$this->autoShareService = $autoShareService;
		$this->incomeMapper = $incomeMapper;
		$this->granularShareService = $granularShareService;
		$this->userClock = $userClock;
		$this->notificationManager = $notificationManager;
	}

	/**
	 * Take down the bill's reminder and overdue notices: they stayed up
	 * after it was paid, skipped or deleted.
	 */
	private function withdrawNotifications(Bill $bill): void {
		if ($this->notificationManager === null) {
			return;
		}
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('budget')
				->setUser($bill->getUserId())
				->setObject('bill', (string)$bill->getId());
			$this->notificationManager->markProcessed($notification);
		} catch (\Throwable $e) {
			// Only a nicety: never a reason for the action itself to fail
		}
	}

	/** Amount types whose figure is resolved from the destination card at payment time (#347) */
	private const DYNAMIC_AMOUNT_TYPES = ['statement', 'current_balance', 'minimum_payment'];

	/** suggestion_type under which dismissed unrecorded payments are stored (#394) */
	private const UNRECORDED_DISMISS_TYPE = 'unrecorded';
	/** Most occurrences one auto-pay run pays for a bill that fell behind */
	private const MAX_AUTO_PAY_CATCH_UP = 60;

	/** replaceBookedPayment(): the bill booked no payment near the bank row */
	private const BOOKED_NONE = 0;
	/** replaceBookedPayment(): the bank row took the booked payment's place */
	private const BOOKED_REPLACED = 1;
	/** replaceBookedPayment(): the bank row is the copy of a booked payment that stays */
	private const BOOKED_KEPT = 2;

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Bill {
		return $this->mapper->find($id, $userId);
	}

	/**
	 * Refuse a payment action on a bill whose owner can no longer write to
	 * the account(s) it posts into.
	 *
	 * A bill can name another user's account, shared to the bill's owner.
	 * Revoking, leaving or cutting that share to read doesn't touch the bill,
	 * and its actions only check access to the bill itself, so it went on
	 * booking and deleting rows in the other user's ledger, auto-pay included.
	 * Every action that writes to the account checks here first, before the
	 * bill is changed.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireWritableAccounts(Bill $bill): void {
		if (!$this->accountsWritable($bill)) {
			throw new \InvalidArgumentException($this->l->t('This bill uses an account you can no longer change. Edit the bill and choose another account.'));
		}
	}

	/**
	 * Remove the pending rows a user's bills booked into accounts the user can
	 * no longer write to. Called when a share ends or is cut back: the rows
	 * sit in the other user's ledger, where that user can neither see the
	 * bill behind them nor stop them.
	 */
	public function dropUnwritablePlaceholders(string $userId): void {
		foreach ($this->mapper->findAll($userId) as $bill) {
			if ($bill->getAccountId() !== null && !$this->accountsWritable($bill)) {
				$this->transactionService->deleteScheduledBillTransactions($bill->getId());
			}
		}
	}

	/**
	 * Whether the bill's owner can still write to every account the bill
	 * posts into. A transfer that has lost its destination can't: a share of
	 * that account ended, its owner reset or was deleted, or it was closed,
	 * and each lets go of it (CrossUserLinks, AccountClosureService). It
	 * still said "transfer", so Mark Paid "paid" it with nothing recorded and
	 * moved it on; now it is refused, saying why, until one is chosen.
	 */
	private function accountsWritable(Bill $bill): bool {
		if (($bill->getIsTransfer() ?? false) && $bill->getDestinationAccountId() === null) {
			return false;
		}
		if ($this->granularShareService === null) {
			return true;
		}
		$accountIds = [$bill->getAccountId()];
		if ($bill->getIsTransfer() ?? false) {
			$accountIds[] = $bill->getDestinationAccountId();
		}
		foreach ($accountIds as $accountId) {
			if ($accountId !== null && !$this->granularShareService->canWrite($bill->getUserId(), ShareItem::TYPE_ACCOUNT, (int)$accountId)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A dynamic amount type needs a card-like transfer destination to
	 * resolve against.
	 */
	private function validateAmountType(string $amountType, bool $isTransfer, ?int $destinationAccountId): void {
		if ($amountType !== 'fixed' && !in_array($amountType, self::DYNAMIC_AMOUNT_TYPES, true)) {
			throw new \InvalidArgumentException($this->l->t('Invalid amount type'));
		}
		if ($amountType === 'fixed') {
			return;
		}
		if (!$isTransfer || $destinationAccountId === null) {
			throw new \InvalidArgumentException($this->l->t('This amount type requires a transfer with a destination account'));
		}
		$destination = $this->accountMapper->findById($destinationAccountId);
		if (!in_array($destination->getType(), ['credit_card', 'line_of_credit'], true)) {
			throw new \InvalidArgumentException($this->l->t('This amount type is only available for transfers to a credit card'));
		}
		if ($amountType === 'minimum_payment') {
			$this->cardMinimum($destination);
		}
	}

	/**
	 * The card's stored minimum payment. With none it resolved to
	 * min(0, owed) = 0.00: a 0.00 pair booked every month, the transfer
	 * shown paid, and the card never paid down (#399 review, F60).
	 */
	private function cardMinimum(Account $card): float {
		$minimum = (float)($card->getMinimumPayment() ?? 0);
		if ($minimum <= 0) {
			throw new \InvalidArgumentException($this->l->t('The card has no minimum payment set. Set one on the card, or choose another amount.'));
		}
		return $minimum;
	}

	/**
	 * Resolve the payable amount for a dynamic-amount bill (#347).
	 * $dueDate is the statement cut-off (null = use $paidDate); $paidDate is
	 * when the money moves. Returns null for fixed-amount bills.
	 */
	private function resolveDynamicAmount(string $amountType, ?int $destinationAccountId, ?string $dueDate, string $paidDate): ?float {
		if (!in_array($amountType, self::DYNAMIC_AMOUNT_TYPES, true) || $destinationAccountId === null) {
			return null;
		}
		if ($amountType === 'statement') {
			// What was owed at the due date; later charges roll to the next statement
			return $this->transactionService->getStatementAmountForAccount($destinationAccountId, $dueDate ?? $paidDate);
		}
		$owedNow = $this->transactionService->getStatementAmountForAccount($destinationAccountId, $paidDate);
		if ($amountType === 'current_balance') {
			return $owedNow;
		}
		// minimum_payment: the card's stored minimum, never more than is owed
		return min($this->cardMinimum($this->accountMapper->findById($destinationAccountId)), $owedNow);
	}

	public function findAll(string $userId): array {
		return $this->mapper->findAll($userId);
	}

	public function findActive(string $userId): array {
		return $this->mapper->findActive($userId);
	}

	/**
	 * Find existing transactions that might match a bill payment.
	 * Used to avoid creating duplicate transactions when marking a bill paid.
	 *
	 * @return array Scored candidates [{transaction, score, matchReasons}]
	 */
	public function findMatchingTransactions(int $billId, string $userId, ?string $actingUserId = null): array {
		$bill = $this->find($billId, $userId);

		if (!$bill->getAccountId()) {
			return [];
		}

		// The candidates are full rows from the bill's account, offered for
		// linking, which changes them. A user who can't write to that account
		// (a share since revoked or cut to read, or a bill shared without its
		// account) gets none of them: Mark Paid would refuse the link.
		if ($this->granularShareService !== null
			&& !$this->granularShareService->canWrite($actingUserId ?? $userId, ShareItem::TYPE_ACCOUNT, (int)$bill->getAccountId())) {
			return [];
		}

		$dueDate = $bill->getNextDueDate() ?? $this->today($userId);

		return $this->transactionService->findBillPaymentCandidates(
			$bill->getAccountId(),
			$bill->getName(),
			(float)$bill->getAmount(),
			$dueDate
		);
	}

	/**
	 * Bills whose most recent payment has no recorded transaction (#274).
	 *
	 * Marking a bill paid without creating or linking a transaction leaves
	 * the account balance out of step with the bank. This returns recent
	 * occurrences (last $sinceDays days) so the Bills page can surface them.
	 * Deliberate skips (Skip button) never set the last-paid date and are
	 * not flagged, and neither is a payment dismissed from the card (#394).
	 */
	public function findUnrecordedPayments(string $userId, int $sinceDays = 60): array {
		$cutoff = (new \DateTimeImmutable($this->today($userId)))->modify("-{$sinceDays} days")->format('Y-m-d');
		$candidates = [];
		foreach ($this->mapper->findAll($userId) as $bill) {
			$lastPaid = $bill->getLastPaidDate();
			if ($lastPaid !== null && $lastPaid >= $cutoff) {
				$candidates[] = $bill;
			}
		}
		if (empty($candidates)) {
			return [];
		}

		$billIds = array_map(static fn (Bill $b) => $b->getId(), $candidates);
		$rowsByBill = [];
		foreach ($this->transactionService->findRecordedBillTransactions($billIds) as $tx) {
			$rowsByBill[$tx->getBillId()][] = $tx;
		}

		// Payments the user has dismissed as deliberately unrecorded (#394)
		$dismissed = array_flip($this->dismissedMapper->findHashes($userId, self::UNRECORDED_DISMISS_TYPE));

		$currencyMap = $this->withAccountsOf(
			$this->buildCurrencyMap($userId),
			array_map(static fn (Bill $b) => $b->getAccountId(), $candidates)
		);
		$unrecorded = [];
		foreach ($candidates as $bill) {
			if ($this->hasRecordedPayment($bill, $rowsByBill[$bill->getId()] ?? [])) {
				continue;
			}
			if (isset($dismissed[sha1($this->unrecordedPaymentKey($bill->getId(), $bill->getLastPaidDate()))])) {
				continue;
			}
			$unrecorded[] = [
				'billId' => $bill->getId(),
				'name' => $bill->getName(),
				'amount' => (float)$bill->getAmount(),
				'lastPaidDate' => $bill->getLastPaidDate(),
				'accountId' => $bill->getAccountId(),
				'currency' => $bill->getAccountId() !== null
					? ($currencyMap[$bill->getAccountId()] ?? null)
					: null,
				// Lets the card offer Mark Unpaid alongside Dismiss (#394)
				'canMarkUnpaid' => $bill->canMarkUnpaid(),
			];
		}

		usort($unrecorded, static fn ($a, $b) => strcmp($b['lastPaidDate'], $a['lastPaidDate']));
		return $unrecorded;
	}

	/**
	 * Record the missing transaction for a payment that was marked paid
	 * without one (#274). Creates a cleared transaction (both legs for
	 * transfers) dated the bill's last paid date and linked to the bill.
	 */
	public function recordMissedPayment(int $id, string $userId): array {
		$bill = $this->find($id, $userId);

		$lastPaid = $bill->getLastPaidDate();
		if ($lastPaid === null) {
			throw new \InvalidArgumentException($this->l->t('This bill has never been marked as paid'));
		}
		if ($bill->getAccountId() === null) {
			throw new \InvalidArgumentException($this->l->t('Assign an account to the bill first'));
		}
		$this->requireWritableAccounts($bill);

		// The bill may reference an account that has since been deleted — give an
		// actionable message instead of a raw lookup failure surfacing as the
		// generic "Failed to record the payment" (#333, #334).
		try {
			$this->accountMapper->findById($bill->getAccountId());
		} catch (DoesNotExistException $e) {
			throw new \InvalidArgumentException($this->l->t('The account linked to this bill no longer exists. Edit the bill and choose an account.'));
		}

		// Idempotence guard: refuse when a payment transaction already exists
		if ($this->hasRecordedPayment($bill, $this->transactionService->findRecordedBillTransactions([$id]))) {
			throw new \InvalidArgumentException($this->l->t('A transaction for this payment already exists'));
		}

		$transaction = $this->transactionService->createFromBill($userId, $bill, $lastPaid, 'cleared');
		$this->applySplitTemplate($bill, $transaction, $userId);

		// The payment's snapshot now names this row, so the card sees the
		// payment as recorded and Mark Unpaid takes the row back with it
		$snapshot = $this->paymentSnapshot($bill);
		if ($snapshot !== null) {
			$ids = is_array($snapshot['createdTransactionIds'] ?? null) ? $snapshot['createdTransactionIds'] : [];
			$ids[] = $transaction->getId();
			if ($transaction->getLinkedTransactionId()) {
				$ids[] = $transaction->getLinkedTransactionId();
			}
			$snapshot['createdTransactionIds'] = array_values(array_unique(array_map('intval', $ids)));
			$bill->setPaidUndoState(json_encode($snapshot));
			try {
				$this->mapper->update($bill);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to note the recorded payment on bill {$id}: {$e->getMessage()}");
			}
		}

		return ['transaction' => $transaction];
	}

	/**
	 * Acknowledge that a payment has no transaction on purpose, so the
	 * unrecorded-payments card stops listing it (#394). The dismissal is
	 * keyed on the paid date, so the bill's next payment without a
	 * transaction is flagged afresh.
	 */
	public function dismissUnrecordedPayment(int $id, string $userId): void {
		$bill = $this->find($id, $userId);

		$lastPaid = $bill->getLastPaidDate();
		if ($lastPaid === null) {
			throw new \InvalidArgumentException($this->l->t('This bill has never been marked as paid'));
		}

		$key = $this->unrecordedPaymentKey($bill->getId(), $lastPaid);
		$this->dismissedMapper->dismiss($userId, self::UNRECORDED_DISMISS_TYPE, sha1($key), $key);
	}

	private function unrecordedPaymentKey(int $billId, string $paidDate): string {
		return $billId . ':' . $paidDate;
	}

	/**
	 * Whether the bill's last payment has a transaction behind it (#274).
	 *
	 * The snapshot markPaid() leaves on the bill names the rows that payment
	 * recorded or linked, so when it describes this payment the answer is
	 * those rows, found by id. Moving one to the bank's date, or linking a
	 * bank row from weeks before the click, can't hide it, and a row of an
	 * earlier payment can't stand in for it, a few days off or the same day.
	 *
	 * Payments from before the snapshot existed are matched by date: a row of
	 * the bill near the paid date, within half the bill's interval (14 days
	 * at most), so a weekly bill's previous payment isn't taken for this one.
	 *
	 * So are payments whose snapshot names no row of them: 2.54.0's Record
	 * transaction booked the row without adding it to the snapshot, and the
	 * card listed the payment again and Record booked it twice. The row must
	 * then sit nearer this payment than the one before it, so an earlier
	 * payment's row (two overdue occurrences paid the same day) still can't
	 * stand in for this one.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $rows the bill's non-scheduled transactions
	 */
	private function hasRecordedPayment(Bill $bill, array $rows): bool {
		$paidDate = $bill->getLastPaidDate();
		if ($paidDate === null) {
			return false;
		}

		$previousPaid = null;
		$snapshot = $this->paymentSnapshot($bill);
		// Early snapshots didn't record a linked row at all
		if ($snapshot !== null && array_key_exists('linkedTransactionId', $snapshot)) {
			$ids = is_array($snapshot['createdTransactionIds'] ?? null) ? $snapshot['createdTransactionIds'] : [];
			if ($snapshot['linkedTransactionId'] !== null) {
				$ids[] = $snapshot['linkedTransactionId'];
			}
			$ids = array_map('intval', array_filter($ids, 'is_numeric'));
			foreach ($rows as $tx) {
				if (in_array($tx->getId(), $ids, true)) {
					return true;
				}
			}
			$previous = $snapshot['previousState']['lastPaidDate'] ?? null;
			$previousPaid = is_string($previous) && $previous !== '' ? $previous : null;
		}

		$window = match ($bill->getFrequency()) {
			'daily' => 0,
			'weekly' => 3,
			'biweekly', 'semi-monthly' => 6,
			default => 14,
		};
		foreach ($rows as $tx) {
			$distance = abs(strtotime($tx->getDate()) - strtotime($paidDate));
			if ($distance > $window * 86400) {
				continue;
			}
			if ($previousPaid !== null && $distance >= abs(strtotime($tx->getDate()) - strtotime($previousPaid))) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * The undo snapshot markPaid() left on the bill, when it describes the
	 * bill's last payment.
	 */
	private function paymentSnapshot(Bill $bill): ?array {
		$raw = $bill->getPaidUndoState();
		$decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		if (!is_array($decoded) || ($decoded['paidDate'] ?? null) !== $bill->getLastPaidDate()) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Build a map of accountId => currency for the user's accounts.
	 */
	private function buildCurrencyMap(string $userId): array {
		return $this->currencyMapFor($this->accountMapper->findAll($userId));
	}

	/**
	 * @param Account[] $accounts
	 * @return array<int, string|null> account id => currency code
	 */
	private function currencyMapFor(array $accounts): array {
		$map = [];
		foreach ($accounts as $account) {
			$map[$account->getId()] = $account->getCurrency() ?: null;
		}
		return $map;
	}

	/**
	 * Add to a currency map the accounts it doesn't hold yet among the ones
	 * named, whoever owns them. A bill can be paid from an account another
	 * user shared with its owner, which the owner's own account list never
	 * holds, so such a bill was shown in the base currency and totalled
	 * unconverted.
	 *
	 * @param array<int, string|null> $map account id => currency code
	 * @param array<int|null> $accountIds
	 * @return array<int, string|null>
	 */
	private function withAccountsOf(array $map, array $accountIds): array {
		$missing = [];
		foreach ($accountIds as $id) {
			if ($id !== null && !array_key_exists((int)$id, $map)) {
				$missing[(int)$id] = true;
			}
		}
		if ($missing === []) {
			return $map;
		}
		return $map + $this->currencyMapFor($this->accountMapper->findByIds(array_keys($missing)));
	}

	/**
	 * Set the non-persisted currency property on each bill from its linked account.
	 *
	 * @param Bill[] $bills
	 * @return Bill[]
	 */
	public function enrichBillsWithCurrency(array $bills, string $userId): array {
		$currencyMap = $this->withAccountsOf(
			$this->buildCurrencyMap($userId),
			array_map(static fn (Bill $b) => $b->getAccountId(), $bills)
		);
		$baseCurrency = $this->currencyConversion->getBaseCurrency($userId);
		foreach ($bills as $bill) {
			$accountId = $bill->getAccountId();
			$bill->setCurrency($accountId !== null && isset($currencyMap[$accountId])
				? $currencyMap[$accountId]
				: $baseCurrency);
		}
		return $bills;
	}

	/**
	 * enrichBillsWithCurrency() for bills shared with someone else: the rows
	 * GranularShareService::getSharedBills() returns, each priced from its
	 * OWNER's account, or the owner's base currency, as the owner sees it.
	 *
	 * @param array[] $bills serialized bills carrying their owner's userId
	 * @return array[]
	 */
	public function enrichSharedBillsWithCurrency(array $bills): array {
		$maps = [];
		$bases = [];
		foreach ($bills as &$bill) {
			$owner = (string)($bill['userId'] ?? '');
			$maps[$owner] ??= $this->buildCurrencyMap($owner);
			$bases[$owner] ??= $this->currencyConversion->getBaseCurrency($owner);
			$accountId = $bill['accountId'] ?? null;
			$maps[$owner] = $this->withAccountsOf($maps[$owner], [$accountId]);
			$bill['currency'] = $accountId !== null && isset($maps[$owner][$accountId])
				? $maps[$owner][$accountId]
				: $bases[$owner];
		}
		unset($bill);
		return $bills;
	}

	public function findByType(string $userId, ?bool $isTransfer = null, ?bool $isActive = null, bool $activeOrRevertible = false): array {
		return $this->mapper->findByType($userId, $isTransfer, $isActive, $activeOrRevertible);
	}

	public function findOverdue(string $userId): array {
		return $this->mapper->findOverdue($userId, $this->today($userId));
	}

	public function findDueThisMonth(string $userId): array {
		$today = new \DateTimeImmutable($this->today($userId));
		return $this->mapper->findDueInRange($userId, $today->format('Y-m-01'), $today->format('Y-m-t'));
	}

	/**
	 * Find upcoming bills (including overdue) sorted by due date.
	 */
	public function findUpcoming(string $userId, int $days = 30): array {
		// The user's today: on the server's UTC date a bill due today was
		// "overdue" every evening west of UTC
		$startDate = $this->today($userId);
		$overdue = $this->mapper->findOverdue($userId, $startDate);
		$endDate = (new \DateTimeImmutable($startDate))->modify("+{$days} days")->format('Y-m-d');
		$upcoming = $this->mapper->findDueInRange($userId, $startDate, $endDate);

		$allBills = array_merge($overdue, $upcoming);

		// Remove duplicates
		$seen = [];
		$uniqueBills = [];
		foreach ($allBills as $bill) {
			$id = $bill->getId();
			if (!isset($seen[$id])) {
				$seen[$id] = true;
				$uniqueBills[] = $bill;
			}
		}

		// Sort by next due date
		usort($uniqueBills, function ($a, $b) {
			$dateA = $a->getNextDueDate() ?? '9999-12-31';
			$dateB = $b->getNextDueDate() ?? '9999-12-31';
			return strcmp($dateA, $dateB);
		});

		return $uniqueBills;
	}

	public function create(
		string $userId,
		string $name,
		float $amount,
		string $frequency = 'monthly',
		?int $dueDay = null,
		?int $dueMonth = null,
		?int $categoryId = null,
		?int $accountId = null,
		?string $autoDetectPattern = null,
		?string $description = null,
		?string $notes = null,
		?int $reminderDays = null,
		?string $customRecurrencePattern = null,
		bool $createTransaction = true,
		?string $transactionDate = null,
		bool $autoPayEnabled = false,
		bool $isTransfer = false,
		?int $destinationAccountId = null,
		?string $transferDescriptionPattern = null,
		array $tagIds = [],
		?string $endDate = null,
		?int $remainingPayments = null,
		?array $splitTemplate = null,
		?string $startDate = null,
		bool $excludedFromForecast = false,
		string $amountType = 'fixed',
	): Bill {
		// Validate auto-pay requires account
		if ($autoPayEnabled && $accountId === null) {
			throw new \InvalidArgumentException($this->l->t('Auto-pay requires an account to be set'));
		}

		// Validate transfer requires destination account
		if ($isTransfer && $destinationAccountId === null) {
			throw new \InvalidArgumentException($this->l->t('Transfer requires a destination account'));
		}

		// Validate transfer cannot have same source and destination
		if ($isTransfer && $accountId !== null && $accountId === $destinationAccountId) {
			throw new \InvalidArgumentException($this->l->t('Cannot transfer to the same account'));
		}

		$this->validateAmountType($amountType, $isTransfer, $destinationAccountId);
		if ($amountType !== 'fixed') {
			// Initial estimate: resolved against the card right now. Refined to
			// the actual amount on every markPaid().
			$amount = $this->resolveDynamicAmount($amountType, $destinationAccountId, null, $this->today($userId)) ?? $amount;
		}

		$bill = new Bill();
		$bill->setUserId($userId);
		$bill->setName($name);
		$bill->setAmount($amount);
		$bill->setAmountType($amountType);
		$bill->setFrequency($frequency);
		$bill->setDueDay($dueDay);
		$bill->setDueMonth($dueMonth);
		$bill->setCategoryId($categoryId);
		$bill->setAccountId($accountId);
		$bill->setAutoDetectPattern($autoDetectPattern);
		$bill->setDescription($description);
		$bill->setIsActive(true);
		$bill->setNotes($notes);
		$bill->setReminderDays($reminderDays);
		$bill->setCustomRecurrencePattern($customRecurrencePattern);
		$bill->setAutoPayEnabled($autoPayEnabled);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer($isTransfer);
		$bill->setDestinationAccountId($destinationAccountId);
		$bill->setTransferDescriptionPattern($transferDescriptionPattern);
		$bill->setTagIdsArray($tagIds);
		$bill->setStartDate($startDate);
		$bill->setEndDate($endDate);
		$bill->setRemainingPayments($remainingPayments);
		// A one-time bill's date is the whole schedule: its day and month
		// follow the date so the Bills Calendar puts it in the right month
		// (#375). The calculator returns this date verbatim, past or not.
		if ($frequency === 'one-time' && $startDate !== null && $startDate !== '') {
			$dueDay = (int)(new \DateTime($startDate))->format('j');
			$dueMonth = (int)(new \DateTime($startDate))->format('n');
			$bill->setDueDay($dueDay);
			$bill->setDueMonth($dueMonth);
		}
		$bill->setExcludedFromForecast($excludedFromForecast);
		$bill->setCreateTransaction($createTransaction);
		if ($splitTemplate !== null) {
			$bill->setSplitTemplateArray($splitTemplate);
			// When splits define the categories, clear the bill-level category
			$bill->setCategoryId(null);
		}
		$bill->setCreatedAt(date('Y-m-d H:i:s'));

		if ($frequency === 'one-time' && ($startDate === null || $startDate === '')) {
			// Without it the date was made up from a day and month, and a
			// bill with no month landed on 1 January next year (#399)
			throw new \InvalidArgumentException($this->l->t('A one-time bill needs its due date'));
		}

		// The first occurrence from today, never before the start date. The
		// start date doubles as the anchor for weekly/biweekly: occurrences
		// fall on startDate + n*interval, so the week parity is fixed by the
		// user's chosen first payment date, not by when the bill happened to
		// be created (#364)
		$nextDue = $this->firstDue($bill, $this->today($userId));
		if ($nextDue === null || ($endDate !== null && $endDate !== '' && $nextDue > $endDate)) {
			// Ends before it is ever due: nothing to pay, nothing to pre-book
			$bill->setIsActive(false);
			$nextDue = null;
		}
		$bill->setNextDueDate($nextDue);

		$bill = $this->mapper->insert($bill);

		// Pre-create the next occurrence if requested and the bill has an
		// account. Always a scheduled placeholder: creating a bill records no
		// payment, whatever date the row carries. Found live with #375 - a
		// one-time bill dated in the past got a CLEARED row on creation,
		// GBP 321.60 booked as spent for an invoice nobody had paid, because
		// createFromBill() reads an explicit date in the past as a payment.
		if ($createTransaction && $accountId !== null && $bill->getIsActive()) {
			try {
				$transaction = $this->transactionService->createFromBill(
					$userId,
					$bill,
					$transactionDate,
					'scheduled'
				);
				$this->applySplitTemplate($bill, $transaction, $userId);
			} catch (\Exception $e) {
				// Log error but don't fail bill creation
				$this->logger->warning("Failed to create transaction for bill {$bill->getId()}: {$e->getMessage()}");
			}
		}

		if ($this->autoShareService !== null) {
			$this->autoShareService->autoShareNewEntity($userId, ShareItem::TYPE_BILL, $bill->getId());
		}

		return $bill;
	}

	public function update(int $id, string $userId, array $updates): Bill {
		$bill = $this->find($id, $userId);
		$dbUpdates = [];

		// The controller and older clients say 'active'; the column is is_active
		if (array_key_exists('active', $updates)) {
			$updates['isActive'] = (bool)$updates['active'];
			unset($updates['active']);
		}

		// Validate auto-pay requires account when enabling
		if (isset($updates['autoPayEnabled']) && $updates['autoPayEnabled'] === true) {
			$currentAccountId = $updates['accountId'] ?? $bill->getAccountId();
			if ($currentAccountId === null) {
				throw new \InvalidArgumentException($this->l->t('Auto-pay requires an account to be set'));
			}
		}

		// Auto-disable auto-pay if account is being removed
		if (array_key_exists('accountId', $updates) && $updates['accountId'] === null) {
			$updates['autoPayEnabled'] = false;
			$updates['autoPayFailed'] = false;
		}

		// Dynamic amount types: validate and refresh the estimate (#347)
		if (array_key_exists('isTransfer', $updates) && !$updates['isTransfer']
			&& ($updates['amountType'] ?? $bill->getAmountType() ?? 'fixed') !== 'fixed') {
			// No longer a transfer — nothing to resolve the amount against
			$updates['amountType'] = 'fixed';
		}
		if (isset($updates['amountType']) && $updates['amountType'] !== 'fixed') {
			$isTransfer = $updates['isTransfer'] ?? ($bill->getIsTransfer() ?? false);
			$destinationId = $updates['destinationAccountId'] ?? $bill->getDestinationAccountId();
			$this->validateAmountType($updates['amountType'], (bool)$isTransfer, $destinationId);
			$updates['amount'] = $this->resolveDynamicAmount($updates['amountType'], $destinationId, null, $this->today($userId));
		} elseif (isset($updates['amountType'])) {
			$this->validateAmountType($updates['amountType'], true, null);
		}

		// The bill as it will be, for the schedule decisions below
		$edited = clone $bill;
		foreach ($updates as $key => $value) {
			if (property_exists($edited, $key)) {
				$edited->{'set' . ucfirst($key)}($value);
			}
		}
		// Legacy rows hold NULL where the form sends the default
		$defaults = ['createTransaction' => true, 'isTransfer' => false, 'amountType' => 'fixed', 'excludedFromForecast' => false];
		$changed = fn (string $key): bool => array_key_exists($key, $updates)
			&& $this->differs($bill->{'get' . ucfirst($key)}() ?? ($defaults[$key] ?? null), $updates[$key]);

		// The schedule changed only if one of its fields did, not because the
		// form sent them back unchanged: recalculating on every edit put a
		// paid-ahead bill back on the occurrence already paid and undid skips
		$scheduleChanged = false;
		foreach (['frequency', 'dueDay', 'dueMonth', 'customRecurrencePattern', 'startDate'] as $key) {
			$scheduleChanged = $scheduleChanged || $changed($key);
		}

		if ($edited->getFrequency() === 'one-time') {
			if ($edited->getStartDate() === null || $edited->getStartDate() === '') {
				throw new \InvalidArgumentException($this->l->t('A one-time bill needs its due date'));
			}
			// A one-time bill's date is its schedule (#375): day and month follow it
			$date = new \DateTimeImmutable($edited->getStartDate());
			foreach (['dueDay' => (int)$date->format('j'), 'dueMonth' => (int)$date->format('n')] as $key => $value) {
				if ($value !== $edited->{'get' . ucfirst($key)}()) {
					$edited->{'set' . ucfirst($key)}($value);
					$updates[$key] = $value;
				}
			}
		}

		// Pausing and resuming, and what an edit does to whether there is
		// anything left to pay
		$wasActive = (bool)$bill->getIsActive();
		$resuming = array_key_exists('isActive', $updates) && $updates['isActive'] && !$wasActive;
		$extended = !$wasActive && $bill->getNextDueDate() === null && $edited->getFrequency() !== 'one-time'
			&& ($changed('endDate') || $changed('remainingPayments') || $changed('frequency'));
		$today = $this->today($userId);

		if ($edited->getIsActive() || $resuming || $extended) {
			$next = $bill->getNextDueDate();
			if ($scheduleChanged || $next === null || $edited->getFrequency() === 'one-time') {
				$next = $this->rescheduledDue($bill, $edited, $today);
			}
			$ended = $next === null
				|| ($edited->getEndDate() !== null && $edited->getEndDate() !== '' && $next > $edited->getEndDate())
				|| ($edited->getRemainingPayments() !== null && $edited->getRemainingPayments() <= 0);
			if ($ended) {
				$edited->setIsActive(false);
				$edited->setNextDueDate(null);
			} elseif ($resuming || $extended || ($wasActive && $edited->getIsActive())) {
				$edited->setIsActive(true);
				$edited->setNextDueDate($next);
			}
			if ($edited->getIsActive() !== $bill->getIsActive()) {
				$updates['isActive'] = $edited->getIsActive();
			}
			if ($edited->getNextDueDate() !== $bill->getNextDueDate()) {
				$dbUpdates['next_due_date'] = $edited->getNextDueDate();
			}
		}

		foreach ($updates as $key => $value) {
			// Convert camelCase to snake_case for database column names
			$columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
			$dbUpdates[$columnName] = $value;
		}

		// A stored mark-unpaid snapshot captures the bill's amount and
		// schedule state; restoring it after a deliberate change to any of
		// those would silently revert the edit (or restore a next-due-date
		// computed under the old schedule). A material edit therefore spends
		// the snapshot; name/notes/reminder-style edits keep it (#365 review).
		$materialKeys = ['amount', 'amountType', 'frequency', 'dueDay', 'dueMonth',
			'customRecurrencePattern', 'startDate', 'accountId', 'destinationAccountId', 'isTransfer'];
		if ($bill->getPaidUndoState() !== null && $bill->getPaidUndoState() !== '') {
			foreach ($materialKeys as $key) {
				if (!array_key_exists($key, $updates)) {
					continue;
				}
				$current = $bill->{'get' . ucfirst($key)}();
				// Legacy rows hold NULL where the form sends the default
				if ($key === 'amountType') {
					$current = $current ?? 'fixed';
				} elseif ($key === 'isTransfer') {
					$current = $current ?? false;
				}
				if ($this->differs($current, $updates[$key])) {
					$dbUpdates['paid_undo_state'] = null;
					break;
				}
			}
		}

		// The pre-booked row mirrors the bill: an edit to anything it carries
		// rebuilds it, so it never keeps the old amount, account, date or
		// category for Mark Paid to record (and turning pre-booking off
		// removes it, on adds it). Decided before the write, against the
		// bill as it was.
		$rowKeys = ['name', 'description', 'amount', 'amountType', 'categoryId', 'accountId', 'destinationAccountId',
			'isTransfer', 'splitTemplate', 'tagIds', 'excludedFromForecast', 'createTransaction', 'isActive'];
		$rowChanged = array_key_exists('next_due_date', $dbUpdates);
		foreach ($rowKeys as $key) {
			$rowChanged = $rowChanged || $changed($key);
		}

		// Apply all updates directly to database
		if (!empty($dbUpdates)) {
			$this->mapper->updateFields($id, $userId, $dbUpdates);
		}

		if ($rowChanged) {
			$this->syncPlaceholder($this->find($id, $userId), $userId);
		}

		// Reload from database to ensure we return the actual saved state
		return $this->find($id, $userId);
	}

	/**
	 * Replace the bill's pre-booked row with one for its current next due
	 * date, or none when the bill doesn't pre-book, isn't active, has no
	 * account or posts into an account its owner can no longer write to.
	 */
	private function syncPlaceholder(Bill $bill, string $userId): void {
		$this->transactionService->deleteScheduledBillTransactions($bill->getId());
		if (($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null
			&& $bill->getNextDueDate() !== null && $this->accountsWritable($bill)) {
			try {
				$row = $this->transactionService->createFromBill($userId, $bill, null);
				$this->applySplitTemplate($bill, $row, $userId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to pre-book the next occurrence of bill {$bill->getId()}: {$e->getMessage()}");
			}
		}
	}

	/**
	 * Two field values differ, reading "5" and 5, true and 1, or "" and null
	 * as the same, but null and false (or 0) as different
	 */
	private function differs(mixed $a, mixed $b): bool {
		$norm = static fn ($v) => ($v === '' ? null : (is_bool($v) ? (int)$v : $v));
		[$a, $b] = [$norm($a), $norm($b)];
		if ($a === null || $b === null) {
			return $a !== $b;
		}
		return $a != $b;
	}

	/**
	 * The first occurrence a new bill owes: on or after today and never
	 * before its start date. A one-time bill is its date, past or not (#375).
	 */
	private function firstDue(Bill $bill, string $today): ?string {
		if ($bill->getFrequency() === 'one-time') {
			return $bill->getStartDate();
		}
		return $this->frequencyCalculator->occurrenceOnOrAfter(
			$bill->getFrequency(), $bill->getDueDay(), $bill->getDueMonth(),
			$today, $bill->getCustomRecurrencePattern(), $bill->getStartDate()
		);
	}

	/**
	 * The occurrence a bill owes after its schedule changes: the same month's
	 * (or week's) occurrence under the new schedule. Moving the day from the
	 * 15th to the 25th makes November's payment the 25th, rather than
	 * bringing back an occurrence already paid or skipping one still owed.
	 * A bill that owed nothing (ended, paused) starts again from today.
	 */
	private function rescheduledDue(Bill $before, Bill $after, string $today): ?string {
		if ($after->getFrequency() === 'one-time') {
			return $after->getStartDate();
		}
		$pending = $before->getNextDueDate();
		if ($pending === null || $before->getFrequency() === 'one-time') {
			return $this->firstDue($after, $today);
		}
		$schedule = fn (Bill $b): array => [
			'frequency' => $b->getFrequency(), 'dueDay' => $b->getDueDay(), 'dueMonth' => $b->getDueMonth(),
			'pattern' => $b->getCustomRecurrencePattern(), 'anchor' => $b->getStartDate() ?: null,
		];
		return $this->frequencyCalculator->reschedule($schedule($before), $pending, $schedule($after))
			?? $this->firstDue($after, $today);
	}

	/** Today's date as the user's calendar shows it */
	private function today(string $userId): string {
		return $this->userClock !== null ? $this->userClock->today($userId) : date('Y-m-d');
	}

	public function delete(int $id, string $userId): void {
		$bill = $this->find($id, $userId);
		// Remove scheduled transactions before deleting the bill
		$this->transactionService->deleteScheduledBillTransactions($id);
		// Its recorded payments stay, free for a bill set up in its place
		$this->transactionService->detachBillPayments($id);
		$this->mapper->delete($bill);
		$this->withdrawNotifications($bill);
	}

	/**
	 * Mark a bill as paid and advance to next due date.
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @param string|null $paidDate Date bill was paid (defaults to the bill's current due date)
	 * @param bool $recordPayment Whether to record the payment itself as a transaction.
	 *                            This decides the payment leg ONLY. Whether the ledger carries a
	 *                            pre-created row for the NEXT occurrence is the bill's own
	 *                            create_transaction setting, exactly as skipPayment() and update()
	 *                            read it. One flag used to answer both, so declining to record a
	 *                            payment (or linking one that already existed) also left the bill
	 *                            with no upcoming row - while the placeholder for the occurrence
	 *                            just paid stayed behind, still scheduled, still looking due (#376).
	 * @param int|null $existingTransactionId Link an existing transaction instead of creating a new one
	 * @param string|null $actingUserId The user paying, when it is a person (the bill may be shared with them)
	 * @return array Updated bill with undo data
	 */
	public function markPaid(int $id, string $userId, ?string $paidDate = null, bool $recordPayment = true, ?int $existingTransactionId = null, ?string $expectedDueDate = null, ?string $actingUserId = null): array {
		$bill = $this->find($id, $userId);

		// A paid one-time bill, an ended or paused one: a second click
		// recorded another payment and overwrote the undo snapshot
		if (!$bill->getIsActive() || $bill->getNextDueDate() === null) {
			throw new \InvalidArgumentException($this->l->t('This bill has nothing left to pay'));
		}
		// The page names the occurrence it showed. A stale tab or a double
		// submit names one already settled, and is refused rather than paid
		// a second time.
		if ($expectedDueDate !== null && $expectedDueDate !== '' && $expectedDueDate !== $bill->getNextDueDate()) {
			throw new \InvalidArgumentException($this->l->t('This payment was already recorded. Reload the page to see the next one.'));
		}
		$this->requireWritableAccounts($bill);

		// Capture previous state for undo support
		$previousState = [
			'lastPaidDate' => $bill->getLastPaidDate(),
			'nextDueDate' => $bill->getNextDueDate(),
			'remainingPayments' => $bill->getRemainingPayments(),
			'isActive' => $bill->getIsActive(),
			'autoPayFailed' => $bill->getAutoPayFailed(),
			'amount' => $bill->getAmount(),
			// Paying an undated one-time bill keeps its due date here
			'startDate' => $bill->getStartDate(),
		];
		$createdTransactionIds = [];
		// The next-occurrence placeholder is tracked separately from the
		// payment legs: by the time the snapshot is used it may have
		// materialised into a real (possibly reconciled) ledger row, and the
		// revert must then leave it alone (#365 review).
		$scheduledTransactionIds = [];
		// A pre-existing transaction LINKED to record the payment (dialog
		// choice or import auto-match) is unlinked on revert, never deleted.
		$linkedTransactionId = null;
		$hadScheduledTransaction = false;
		$linkedExistingTransaction = false;
		$paymentTransactionRecorded = false;
		// A transfer's deposit this payment booked
		$bookedDepositId = null;

		// Reset auto-pay failed flag on successful manual payment
		if ($bill->getAutoPayFailed()) {
			$bill->setAutoPayFailed(false);
		}

		// A payment made by linking a transaction that already exists (the
		// Mark Paid dialog or an import match). The row must sit in the bill's
		// account, or for a bill with no account in one its owner can write
		// to: the id comes from the browser and could name anyone's row.
		$existingRow = null;
		$linkCategory = true;
		if ($existingTransactionId !== null) {
			$existingRow = $this->transactionService->findTransaction($existingTransactionId);
			$rowAccount = $existingRow?->getAccountId();
			$allowed = $existingRow !== null && ($bill->getAccountId() !== null
				? $rowAccount === $bill->getAccountId()
				: ($this->granularShareService === null
					|| $this->granularShareService->canWrite($bill->getUserId(), ShareItem::TYPE_ACCOUNT, (int)$rowAccount)));
			// ...and in one the user paying can write to, as the Mark Paid
			// dialog lists them: linking gives the row a bill, a category and
			// tags, and a share of the bill alone let them do that to a row of
			// an account hidden from them or shared with them read-only
			if ($allowed && $actingUserId !== null && $this->granularShareService !== null
				&& !$this->granularShareService->canWrite($actingUserId, ShareItem::TYPE_ACCOUNT, (int)$rowAccount)) {
				$allowed = false;
			}
			if (!$allowed) {
				throw new \InvalidArgumentException($this->l->t('That transaction can\'t pay this bill'));
			}
			// A bill with no account pays from other people's accounts too:
			// the row takes its category only when the row's ledger can use it
			$linkCategory = $this->categoryUsableIn($bill->getCategoryId(), (int)$rowAccount);
		}

		// The payment's date: a linked row's own, else today so the payment
		// appears in the account balance at once, regardless of when the bill
		// was due. The user's today, not the server's: a payment marked in the
		// evening west of UTC was filed under tomorrow. Dated the day of the
		// click, a linked row read as a different payment, and the card
		// listed it as unrecorded.
		$paidDate = $paidDate ?? ($existingRow?->getDate() ?: $this->today($userId));
		$bill->setLastPaidDate($paidDate);

		// Dynamic-amount bills resolve their amount now: what the card calls
		// for at this payment. Persisting it into the bill keeps lists,
		// summaries and the next placeholder showing a real number, and the
		// next placeholder inherits it as the estimate (#347).
		$statementAmount = null;
		if ($bill->getIsTransfer() ?? false) {
			$statementAmount = $this->resolveDynamicAmount(
				$bill->getAmountType() ?? 'fixed',
				$bill->getDestinationAccountId(),
				$bill->getNextDueDate(),
				$paidDate
			);
			if ($statementAmount !== null) {
				$bill->setAmount($statementAmount);
			}
		}

		// Handle transaction: either link existing or create new
		if ($existingRow !== null) {
			// A transfer's arrival is priced before anything changes: with no
			// rate between its two currencies it can't be booked, and the
			// withdrawal linked first left the bill half paid
			$arrivalAmount = null;
			if (($bill->getIsTransfer() ?? false) && $bill->getDestinationAccountId() !== null
				&& $existingRow->getLinkedTransactionId() === null) {
				$arrivalAmount = $this->transactionService->transferArrivalAmount($existingRow, $bill);
			}
			// Link first, and only then drop the pre-booked row: a failed
			// link used to be logged and ignored, with the row already gone
			// and the bill moved on. The link runs as the account's owner, so
			// a row in an account shared with the bill's owner can be linked,
			// and gives the row the bill's category, tags and splits.
			try {
				$linked = $this->transactionService->linkBillAsAccountOwner($existingRow->getId(), $bill, $linkCategory);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException($this->l->t('That transaction already pays another bill'));
			}
			if (!$linked->getIsSplit()) {
				$this->applySplitTemplate($bill, $linked, $userId);
			}
			$this->transactionService->deleteScheduledBillTransactions($bill->getId());
			// A transfer's withdrawal needs its arrival: the bank's own credit
			// in the destination, or a deposit booked for it (which a revert
			// removes). Linking used to leave the destination uncredited.
			if ($bill->getIsTransfer() ?? false) {
				$deposit = $this->transactionService->completeTransferPayment($linked, $bill, $arrivalAmount);
				if ($deposit !== null) {
					$createdTransactionIds[] = $deposit;
					// The destination's own credit may be in already (below)
					$bookedDepositId = $deposit;
				}
			}
			$linkedExistingTransaction = true;
			$linkedTransactionId = $existingRow->getId();
		} elseif ($recordPayment && $bill->getAccountId() !== null) {
			try {
				// Clear pre-existing scheduled transaction(s), or create new cleared one
				$transaction = $this->transactionService->clearScheduledBillTransaction($userId, $bill->getId(), $paidDate, $statementAmount, (bool)($bill->getIsTransfer() ?? false), $bill);
				if ($transaction) {
					$hadScheduledTransaction = true;
				} else {
					$transaction = $this->transactionService->createFromBill($userId, $bill, $paidDate, 'cleared');
				}
				$createdTransactionIds[] = $transaction->getId();
				$paymentTransactionRecorded = true;
				// For transfers, also track the linked deposit transaction
				if ($transaction->getLinkedTransactionId()) {
					$createdTransactionIds[] = $transaction->getLinkedTransactionId();
					if ($bill->getIsTransfer() ?? false) {
						$bookedDepositId = $transaction->getLinkedTransactionId();
					}
				}

				// Apply split template if defined
				$this->applySplitTemplate($bill, $transaction, $userId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to create transaction for bill {$id}: {$e->getMessage()}");
			}
		} else {
			// Nothing recorded this occurrence, so the placeholder standing in
			// for it has to go: the payment happened, and a scheduled row for
			// a paid occurrence goes on showing the bill as still due. The
			// other two branches already clear it, one by clearing it into the
			// payment and one by deleting it outright (#376).
			$this->transactionService->deleteScheduledBillTransactions($bill->getId());
		}

		// Auto-deactivate one-time bills after payment
		if ($bill->getFrequency() === 'one-time') {
			// The date it was due is all a paid invoice has left to show, and
			// clearing next_due_date threw it away: a bill created before the
			// date field existed then read "No due date" in the list and
			// opened with an empty Due Date (#333). It is kept as the start
			// date, which is where a one-time bill's date lives (#375).
			if (($bill->getStartDate() === null || $bill->getStartDate() === '') && $bill->getNextDueDate()) {
				$bill->setStartDate($bill->getNextDueDate());
			}
			$bill->setIsActive(false);
			$bill->setNextDueDate(null);
		} else {
			$nextDue = $this->frequencyCalculator->calculateNextDueDate(
				$bill->getFrequency(),
				$bill->getDueDay(),
				$bill->getDueMonth(),
				$bill->getNextDueDate(),
				$bill->getCustomRecurrencePattern(),
				true, // Always force advance — the bill was just paid
				// The start date supplies the day and month the form left
				// out, as at creation and in the calendar: without it a
				// yearly bill due 14 March moved to 1 January
				$bill->getStartDate()
			);
			$bill->setNextDueDate($nextDue);

			// Decrement remaining payments if set
			$remaining = $bill->getRemainingPayments();
			if ($remaining !== null) {
				$remaining--;
				$bill->setRemainingPayments($remaining);
				if ($remaining <= 0) {
					$bill->setIsActive(false);
					$bill->setNextDueDate(null);
				}
			}

			// Deactivate if next due date exceeds end date
			$endDate = $bill->getEndDate();
			if ($endDate !== null && $bill->getNextDueDate() !== null && $bill->getNextDueDate() > $endDate) {
				$bill->setIsActive(false);
				$bill->setNextDueDate(null);
			}
		}

		$bill = $this->mapper->update($bill);
		$this->withdrawNotifications($bill);

		// Auto-create transaction for next occurrence if bill has account
		// Skip for deactivated bills (one-time, end date reached, remaining payments exhausted)
		// and bills that opted out of pre-created transactions (null = legacy rows, treated as opted in)
		if (($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null) {
			try {
				$nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
				$scheduledTransactionIds[] = $nextTransaction->getId();
				// For transfers, also track the linked deposit transaction
				if ($nextTransaction->getLinkedTransactionId()) {
					$scheduledTransactionIds[] = $nextTransaction->getLinkedTransactionId();
				}
				$this->applySplitTemplate($bill, $nextTransaction, $userId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to create next transaction for bill {$id}: {$e->getMessage()}");
			}
		}

		// Persist the undo payload so "mark as unpaid" survives page reloads
		// and covers auto-pay / import-match payments too (#365). Overwrites
		// any previous snapshot: only the latest payment is revertible.
		// The payment itself is already committed — a DB error here may only
		// cost the durable undo, never fail the payment.
		$bill->setPaidUndoState(json_encode([
			'previousState' => $previousState,
			'createdTransactionIds' => $createdTransactionIds,
			'scheduledTransactionIds' => $scheduledTransactionIds,
			'linkedTransactionId' => $linkedTransactionId,
			'hadScheduledTransaction' => $hadScheduledTransaction,
			'paidDate' => $paidDate,
		]));
		try {
			$bill = $this->mapper->update($bill);
		} catch (\Exception $e) {
			// A snapshot that failed to persist must not be advertised as a
			// working Mark Unpaid; the toast-based undo still has the ids below.
			$bill->setPaidUndoState(null);
			$this->logger->warning("Failed to persist the undo snapshot for bill {$id}: {$e->getMessage()}");
		}

		// The destination's statement may be in already: its own credit then
		// takes the place of the deposit just booked, as it does when it
		// comes in afterwards, rather than the money arriving twice. The
		// same for a payment that booked the deposit and one that linked the
		// bank's withdrawal.
		if ($bookedDepositId !== null) {
			try {
				if ($this->adoptArrival($bill, $bookedDepositId) !== null) {
					$bill = $this->find($id, $userId);
				}
			} catch (\Exception $e) {
				$this->logger->warning("Failed to match bill {$id}'s deposit to the destination's own credit: {$e->getMessage()}");
			}
		}

		return [
			'bill' => $bill,
			'previousState' => $previousState,
			// The toast-based undo path deletes everything it is given right
			// away, so it keeps receiving payment legs and placeholder alike.
			'createdTransactionIds' => array_merge($createdTransactionIds, $scheduledTransactionIds),
			'hadScheduledTransaction' => $hadScheduledTransaction,
			'linkedExistingTransaction' => $linkedExistingTransaction,
			// False = the bill was marked paid but NO money movement was
			// recorded (no account on the bill, creation failed, or the user
			// neither created nor linked a transaction). The UI must surface
			// this — silently recording nothing made app balances drift from
			// real bank balances (#89, #274).
			'paymentTransactionRecorded' => $paymentTransactionRecorded || $linkedExistingTransaction,
			// Non-null only for statement bills: the amount resolved for
			// this payment (#347)
			'statementAmount' => $statementAmount,
		];
	}

	/**
	 * Undo a mark-paid action, restoring the bill to its previous state
	 * and deleting any transactions that were created.
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @param array $previousState Previous bill field values to restore
	 * @param int[] $createdTransactionIds Transaction IDs created by markPaid to delete
	 * @param bool $hadScheduledTransaction Whether a scheduled transaction existed before markPaid
	 * @param int[] $scheduledTransactionIds Next-occurrence placeholder IDs — deleted only while still 'scheduled'
	 * @param int|null $linkedTransactionId Pre-existing transaction linked by markPaid — unlinked, never deleted
	 * @return Bill Restored bill
	 */
	public function undoPaid(int $id, string $userId, array $previousState, array $createdTransactionIds, bool $hadScheduledTransaction = false, array $scheduledTransactionIds = [], ?int $linkedTransactionId = null): Bill {
		$bill = $this->find($id, $userId);
		$this->requireWritableAccounts($bill);

		// Delete the transactions markPaid recorded. They were created under
		// the ACCOUNT owner (#334) — for a bill on a shared account that is
		// not the acting user, and deleting under the acting user missed
		// every row: the revert left the payment in the ledger while the
		// bill claimed unpaid.
		foreach ($createdTransactionIds as $transactionId) {
			try {
				$this->transactionService->deleteAsAccountOwner((int)$transactionId, false, $id);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to delete transaction {$transactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
			}
		}

		// The next-occurrence placeholder may have materialised into a real
		// (possibly reconciled) ledger row since the payment — remove it only
		// while it is still a scheduled placeholder (#365 review).
		foreach ($scheduledTransactionIds as $transactionId) {
			try {
				$this->transactionService->deleteAsAccountOwner((int)$transactionId, true, $id);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to delete scheduled transaction {$transactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
			}
		}

		// A linked pre-existing transaction was never created by markPaid:
		// it predates the payment and survives the revert — only the bill
		// linkage is undone.
		if ($linkedTransactionId !== null) {
			try {
				$this->transactionService->unlinkBillAsAccountOwner($linkedTransactionId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to unlink transaction {$linkedTransactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
			}
		}

		// Restore previous bill state
		$bill->setLastPaidDate($previousState['lastPaidDate'] ?? null);
		$bill->setNextDueDate($previousState['nextDueDate'] ?? null);
		$bill->setIsActive($previousState['isActive'] ?? true);
		// Left behind, a date the server once made up for an undated bill
		// came back as if it had been entered (#399 review)
		if (array_key_exists('startDate', $previousState)) {
			$bill->setStartDate($previousState['startDate']);
		}
		if (array_key_exists('remainingPayments', $previousState)) {
			$bill->setRemainingPayments($previousState['remainingPayments']);
		}
		if (array_key_exists('autoPayFailed', $previousState)) {
			$bill->setAutoPayFailed($previousState['autoPayFailed'] ?? false);
		}
		// Statement bills overwrite the amount on markPaid — restore it.
		// Older clients round-trip undo data without this key.
		if (array_key_exists('amount', $previousState) && $previousState['amount'] !== null) {
			$bill->setAmount((float)$previousState['amount']);
		}

		// The payment was reverted — its persisted undo snapshot is spent (#365)
		$bill->setPaidUndoState(null);

		$bill = $this->mapper->update($bill);

		// The restored occurrence gets its pre-booked row back whenever the
		// bill pre-books: after a payment that linked a bank row or recorded
		// nothing the row was never put back, and one was put back on a bill
		// that had since stopped pre-booking
		$this->syncPlaceholder($bill, $userId);
		$bill = $this->find($id, $userId);

		return $bill;
	}

	/**
	 * Durable "mark as unpaid": revert the last markPaid from the snapshot
	 * persisted on the bill, so the revert works long after the undo toast is
	 * gone — across reloads, and for auto-paid or import-matched bills that
	 * never showed a toast at all (#365).
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @param bool $confirmReconciled The user has been told the payment was
	 *                                reconciled and wants it reverted anyway
	 * @return Bill Restored bill
	 * @throws \InvalidArgumentException when no payment snapshot is stored
	 * @throws ReconciledPaymentException when the revert would delete a
	 *                                    reconciled row and isn't confirmed
	 */
	public function markUnpaid(int $id, string $userId, bool $confirmReconciled = false): Bill {
		$bill = $this->find($id, $userId);

		$raw = $bill->getPaidUndoState();
		$decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		if (!is_array($decoded) || !is_array($decoded['previousState'] ?? null)) {
			throw new \InvalidArgumentException($this->l->t('This bill has no recorded payment to undo'));
		}

		// A corrupt blob must fail exactly like a missing snapshot — the
		// controller's catches do not cover a TypeError from array_map (#365
		// review). Older snapshots carry the placeholder inside
		// createdTransactionIds and no linked id; both stay valid.
		$createdIds = $decoded['createdTransactionIds'] ?? [];
		$scheduledIds = $decoded['scheduledTransactionIds'] ?? [];
		$linkedId = $decoded['linkedTransactionId'] ?? null;
		$allNumeric = static fn (array $ids): bool => array_filter($ids, static fn ($v) => !is_numeric($v)) === [];
		if (!is_array($createdIds) || !is_array($scheduledIds)
			|| !$allNumeric($createdIds) || !$allNumeric($scheduledIds)
			|| ($linkedId !== null && !is_numeric($linkedId))) {
			throw new \InvalidArgumentException($this->l->t('This bill has no recorded payment to undo'));
		}

		// Reverting deletes the payment, and one reconciled against a bank
		// statement used to go with no warning: the account then stopped
		// matching the statement. The user is asked first, as the
		// Transactions page asks before deleting a reconciled row.
		if (!$confirmReconciled
			&& $this->transactionService->countReconciledBillRows(array_map('intval', $createdIds), $id) > 0) {
			throw new ReconciledPaymentException($this->l->t('This payment has been reconciled against a bank statement. Marking the bill unpaid deletes it, so the account will no longer match that statement.'));
		}

		// undoPaid deletes the created transactions tolerantly, restores the
		// snapshot fields (including a statement amount that cannot be
		// re-derived, #347) and clears the spent snapshot.
		return $this->undoPaid(
			$id,
			$userId,
			$decoded['previousState'],
			array_map('intval', $createdIds),
			(bool)($decoded['hadScheduledTransaction'] ?? false),
			array_map('intval', $scheduledIds),
			$linkedId !== null ? (int)$linkedId : null
		);
	}

	/**
	 * Skip a bill payment, advancing to the next due date without creating a transaction.
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @return Bill Updated bill
	 */
	public function skipPayment(int $id, string $userId): array {
		$bill = $this->find($id, $userId);
		$this->requireWritableAccounts($bill);

		if ($bill->getFrequency() === 'one-time') {
			throw new \InvalidArgumentException($this->l->t('Cannot skip a one-time bill'));
		}

		if (!$bill->getIsActive()) {
			throw new \InvalidArgumentException($this->l->t('Cannot skip an inactive bill'));
		}

		$previousNextDueDate = $bill->getNextDueDate();

		// Delete any pre-existing scheduled transaction for the skipped occurrence
		$this->transactionService->deleteScheduledBillTransactions($id);

		$nextDue = $this->frequencyCalculator->calculateNextDueDate(
			$bill->getFrequency(),
			$bill->getDueDay(),
			$bill->getDueMonth(),
			$bill->getNextDueDate(),
			$bill->getCustomRecurrencePattern(),
			true, // forceAdvance: always advance one cycle, even if not yet overdue
			$bill->getStartDate() // the same anchor creation and the calendar use
		);
		$bill->setNextDueDate($nextDue);

		// Deactivate if next due date exceeds end date
		$endDate = $bill->getEndDate();
		if ($endDate !== null && $bill->getNextDueDate() !== null && $bill->getNextDueDate() > $endDate) {
			$bill->setIsActive(false);
			$bill->setNextDueDate(null);
		}

		// The skip moved next_due_date — a mark-unpaid snapshot restored
		// after it would bring back a pre-skip date, so it is spent (#365 review)
		$bill->setPaidUndoState(null);

		$bill = $this->mapper->update($bill);
		$this->withdrawNotifications($bill);

		// Create scheduled transaction for the new next occurrence,
		// unless the bill opted out of pre-created transactions
		if (($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null) {
			try {
				$nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
				$this->applySplitTemplate($bill, $nextTransaction, $userId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to create next transaction after skipping bill {$id}: {$e->getMessage()}");
			}
		}

		return [
			'bill' => $bill,
			'previousNextDueDate' => $previousNextDueDate,
		];
	}

	/**
	 * Undo a skipped bill payment, reverting to the previous due date.
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @param string $previousNextDueDate The due date to restore
	 * @return Bill Updated bill
	 */
	public function undoSkip(int $id, string $userId, string $previousNextDueDate): Bill {
		$bill = $this->find($id, $userId);
		$this->requireWritableAccounts($bill);

		// Delete scheduled transaction for the advanced (wrong) date
		$this->transactionService->deleteScheduledBillTransactions($id);

		// Restore previous due date and reactivate if needed
		$bill->setNextDueDate($previousNextDueDate);
		if (!$bill->getIsActive() && $bill->getFrequency() !== 'one-time') {
			$bill->setIsActive(true);
		}

		$bill = $this->mapper->update($bill);

		// Recreate scheduled transaction for the restored date, unless the
		// bill opted out of pre-created transactions - skipPayment() honours
		// that, and undoing it must not put back what it never had (#396)
		if (($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null) {
			try {
				$nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
				$this->applySplitTemplate($bill, $nextTransaction, $userId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to recreate transaction after undoing skip for bill {$id}: {$e->getMessage()}");
			}
		}

		return $bill;
	}

	/**
	 * Get monthly summary of bills.
	 *
	 * @param Bill[] $sharedBills bills other people shared with $userId. The
	 *                            Bills page lists them, so its cards count the
	 *                            active ones too. Each is priced from its
	 *                            owner's account (or the owner's base
	 *                            currency) and converted to $userId's.
	 * @param bool|null $isTransfer which page the cards are for: false for
	 *                              the Bills page (the default), true for the
	 *                              Transfers page, null for both. Each page
	 *                              lists one kind only, and the Bills cards
	 *                              counted every transfer as well.
	 */
	public function getMonthlySummary(string $userId, array $sharedBills = [], ?bool $isTransfer = false): array {
		$bills = array_values(array_filter(
			array_merge(
				$this->findActive($userId),
				array_filter($sharedBills, static fn (Bill $bill) => (bool)$bill->getIsActive())
			),
			static fn (Bill $bill) => $isTransfer === null || (bool)$bill->getIsTransfer() === $isTransfer
		));
		$baseCurrency = $this->currencyConversion->getBaseCurrency($userId);
		$currencyMaps = [$userId => $this->buildCurrencyMap($userId)];
		$ownerBases = [$userId => $baseCurrency];

		$total = 0.0;
		$dueThisMonth = 0;
		$overdue = 0;
		$paidThisMonth = 0;
		$byCategory = [];
		$byFrequency = [
			'daily' => 0.0,
			'weekly' => 0.0,
			'biweekly' => 0.0,
			'semi-monthly' => 0.0,
			'monthly' => 0.0,
			'quarterly' => 0.0,
			'semi-annually' => 0.0,
			'yearly' => 0.0,
			'one-time' => 0.0,
			'custom' => 0.0,
		];

		// The user's month, not the server's
		$today = $this->today($userId);
		$startOfMonth = substr($today, 0, 8) . '01';
		$endOfMonth = (new \DateTimeImmutable($startOfMonth))->format('Y-m-t');

		foreach ($bills as $bill) {
			// A one-time bill is not a monthly commitment: a twelfth of an
			// unpaid invoice sat in Monthly Total until it was paid, where
			// recurring income and budgets count one-off items as nothing.
			// It still counts as due, overdue or paid below.
			$monthlyAmount = $bill->getFrequency() === 'one-time'
				? 0.0
				: $this->frequencyCalculator->getMonthlyEquivalent($bill);

			// Convert to base currency if the bill's account uses a different
			// currency. A shared bill's account is its owner's, or one shared
			// with its owner.
			$owner = (string)($bill->getUserId() ?? $userId);
			$currencyMaps[$owner] ??= $this->buildCurrencyMap($owner);
			$currencyMaps[$owner] = $this->withAccountsOf($currencyMaps[$owner], [$bill->getAccountId()]);
			$ownerBases[$owner] ??= $this->currencyConversion->getBaseCurrency($owner);
			$billCurrency = ($bill->getAccountId() !== null && isset($currencyMaps[$owner][$bill->getAccountId()]))
				? $currencyMaps[$owner][$bill->getAccountId()]
				: $ownerBases[$owner];
			$convertedMonthly = $this->convertToBase($monthlyAmount, $billCurrency, $baseCurrency, $userId);
			$convertedAmount = $this->convertToBase($bill->getAmount(), $billCurrency, $baseCurrency, $userId);

			$total += $convertedMonthly;

			$freq = $bill->getFrequency();
			if (isset($byFrequency[$freq])) {
				$byFrequency[$freq] += $convertedAmount;
			}

			$catId = $bill->getCategoryId() ?? 0;
			if (!isset($byCategory[$catId])) {
				$byCategory[$catId] = 0.0;
			}
			$byCategory[$catId] += $convertedMonthly;

			// Check if due this month
			$nextDue = $bill->getNextDueDate();
			if ($nextDue && $nextDue >= $startOfMonth && $nextDue <= $endOfMonth) {
				$dueThisMonth++;
			}

			// Overdue: the next due date is the first occurrence not yet paid,
			// so a date gone by is owed. A payment this month for an earlier
			// occurrence used to hide a later one still owed.
			if ($nextDue && $nextDue < $today) {
				$overdue++;
			}
		}

		// Paid this month, over every bill: paying a one-time bill switches
		// it off, which took it out of the count the moment it was paid
		$allBills = array_merge(
			$this->mapper->findByType($userId, $isTransfer, null),
			array_filter($sharedBills, static fn (Bill $bill) => $isTransfer === null || (bool)$bill->getIsTransfer() === $isTransfer)
		);
		foreach ($allBills as $bill) {
			if ($this->checkIfPaidInPeriod($bill, $startOfMonth, $endOfMonth)) {
				$paidThisMonth++;
			}
		}

		return [
			'totalMonthly' => $total,
			'monthlyTotal' => $total, // Alias for frontend compatibility
			'totalYearly' => $total * 12,
			'billCount' => count($bills),
			'dueThisMonth' => $dueThisMonth,
			'overdue' => $overdue,
			'paidThisMonth' => $paidThisMonth,
			'byCategory' => $byCategory,
			'byFrequency' => $byFrequency,
			'baseCurrency' => $baseCurrency,
		];
	}

	/**
	 * Apply a bill's split template to a newly created transaction.
	 */
	private function applySplitTemplate(Bill $bill, $transaction, string $userId): void {
		$splits = $bill->getSplitTemplateArray();
		if (empty($splits) || $transaction === null) {
			return;
		}

		try {
			$splitData = array_map(function ($split) {
				return [
					'categoryId' => isset($split['categoryId']) ? (int)$split['categoryId'] : null,
					'amount' => (float)$split['amount'],
					'description' => $split['description'] ?? null,
				];
			}, $splits);

			// Splits are scoped to the transaction's account owner, which may
			// differ from the acting user when the bill points at a shared
			// account (#334).
			$ownerUserId = $this->accountMapper->findById($transaction->getAccountId())->getUserId();
			$this->splitService->splitTransaction($transaction->getId(), $ownerUserId, $splitData);
		} catch (\Exception $e) {
			$this->logger->warning("Failed to apply split template to transaction {$transaction->getId()}: {$e->getMessage()}");
		}
	}

	/**
	 * Whether a row in this account may be filed under the category: the
	 * account owner's ledger has to be able to use it, as for any row they
	 * keep. The split template is held to the same rule by the split itself.
	 */
	private function categoryUsableIn(?int $categoryId, int $accountId): bool {
		if ($categoryId === null || $this->granularShareService === null) {
			return true;
		}
		try {
			$this->granularShareService->requireUsableCategory($this->accountMapper->findById($accountId)->getUserId(), $categoryId);
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Convert an amount to base currency if needed. Returns unchanged if already base.
	 */
	private function convertToBase(float $amount, ?string $fromCurrency, string $baseCurrency, string $userId): float {
		if ($fromCurrency === null || $fromCurrency === $baseCurrency) {
			return $amount;
		}
		return $this->currencyConversion->convertToBaseFloat($amount, $fromCurrency, $userId);
	}

	/**
	 * Get bill status for current month showing paid/unpaid.
	 */
	public function getBillStatusForMonth(string $userId, ?string $month = null): array {
		$today = $this->today($userId);
		$month = $month ?? substr($today, 0, 7);
		$startDate = $month . '-01';
		$endDate = date('Y-m-t', strtotime($startDate));

		$bills = $this->mapper->findDueInRange($userId, $startDate, $endDate);
		$result = [];

		foreach ($bills as $bill) {
			// The listed date is the bill's next due date: the first
			// occurrence not yet paid. "Paid sometime this month" read it as
			// paid when the payment was for an earlier occurrence.
			$result[] = [
				'bill' => $bill,
				'isPaid' => false,
				'dueDate' => $bill->getNextDueDate(),
				'isOverdue' => $bill->getNextDueDate() < $today,
			];
		}

		return $result;
	}

	/**
	 * Auto-detect recurring bills from transaction history.
	 *
	 * @param bool $includeTransfers Find Transfers: also offer debits linked to a transfer's other leg
	 */
	public function detectRecurringBills(string $userId, int $months = 6, bool $includeTransfers = false): array {
		return $this->recurringDetector->detectRecurringBills($userId, $months, $includeTransfers);
	}

	/**
	 * Create bills from detected patterns.
	 *
	 * @throws \InvalidArgumentException when an item has no name
	 */
	public function createFromDetected(string $userId, array $detected): array {
		// Every item needs a name before any is created. `suggestedName ??
		// description` kept a blank suggested name and created nameless bills
		$names = [];
		foreach ($detected as $i => $item) {
			$names[$i] = '';
			foreach (['name', 'suggestedName', 'description'] as $field) {
				if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
					$names[$i] = trim($item[$field]);
					break;
				}
			}
			if ($names[$i] === '') {
				throw new \InvalidArgumentException($this->l->t('%1$s is required', [$this->l->t('Name')]));
			}
		}

		$created = [];

		foreach ($detected as $i => $item) {
			$isTransfer = !empty($item['isTransfer']);
			$destinationAccountId = isset($item['destinationAccountId']) ? (int)$item['destinationAccountId'] : null;

			// Named arguments — create() has 25 parameters and positional
			// calls here previously drifted (a missing value pushed `false`
			// into the ?string customRecurrencePattern slot, 500-ing every
			// detect-and-add). Names keep this aligned.
			$bill = $this->create(
				userId: $userId,
				name: $names[$i],
				amount: (float)$item['amount'],
				frequency: $item['frequency'] ?? 'monthly',
				dueDay: isset($item['dueDay']) ? (int)$item['dueDay'] : null,
				// The schedule the payments showed: the month a quarterly or
				// yearly bill falls in, and the payment a weekly one counts from
				dueMonth: isset($item['dueMonth']) ? (int)$item['dueMonth'] : null,
				categoryId: $isTransfer ? null : (isset($item['categoryId']) ? (int)$item['categoryId'] : null),
				accountId: isset($item['accountId']) ? (int)$item['accountId'] : null,
				autoDetectPattern: $item['autoDetectPattern'] ?? null,
				isTransfer: $isTransfer,
				destinationAccountId: $destinationAccountId,
				startDate: isset($item['startDate']) && $item['startDate'] !== '' ? (string)$item['startDate'] : null,
			);
			$created[] = $bill;
		}

		return $created;
	}

	/**
	 * Check if a transaction matches any bill's auto-detect pattern.
	 */
	public function matchTransactionToBill(string $userId, string $description, float $amount): ?Bill {
		$bills = $this->findActive($userId);

		foreach ($bills as $bill) {
			$pattern = $bill->getAutoDetectPattern();
			if (empty($pattern)) {
				continue;
			}

			if (stripos($description, $pattern) !== false) {
				$billAmount = $bill->getAmount();
				if (abs($amount - $billAmount) <= $billAmount * 0.1) {
					return $bill;
				}
			}
		}

		return null;
	}

	/**
	 * Auto-mark bills paid from freshly imported transactions (#274).
	 *
	 * For each imported debit that matches an active bill's auto-detect
	 * pattern (amount within 10%, account matching when the bill has one,
	 * transaction date within the bill's current due window), the bill is
	 * marked paid with the transaction LINKED — no duplicate money movement
	 * is ever recorded. Marking paid advances the due date, so a second
	 * same-period transaction in the batch falls outside the new window and
	 * cannot double-advance the bill.
	 *
	 * A credit in a recurring transfer's destination is the bank's own row
	 * of a deposit the app may already have booked for the transfer, and
	 * takes that deposit's place (replaceBookedDeposit()).
	 *
	 * @param \OCA\Budget\Db\Transaction[] $transactions freshly created rows
	 * @return int number of bills marked paid
	 */
	public function autoMatchPaidFromImport(string $userId, array $transactions): int {
		if (empty($transactions)) {
			return 0;
		}

		// Bills and recurring transfers alike: a transfer matches its
		// withdrawal from the source account by its pattern (the transfer
		// form's description pattern, which nothing used to read)
		$active = $this->findActive($userId);
		$bills = array_filter(
			$active,
			fn (Bill $bill) => $this->matchPattern($bill) !== ''
				&& $bill->getNextDueDate() !== null
		);
		$transfersInto = [];
		foreach ($active as $bill) {
			if (($bill->getIsTransfer() ?? false) && $bill->getDestinationAccountId() !== null) {
				$transfersInto[$bill->getDestinationAccountId()][] = $bill;
			}
		}
		if (empty($bills) && $transfersInto === []) {
			return 0;
		}

		// Oldest first, whatever order the statement lists them in: a bank
		// row of a payment already booked takes its place before a later row
		// pays the next occurrence. Newest-first files paid the next one
		// first and left the bank's row beside the booked payment.
		usort($transactions, fn ($a, $b) => [(string)$a->getDate(), (int)$a->getId()] <=> [(string)$b->getDate(), (int)$b->getId()]);

		$marked = 0;
		foreach ($transactions as $transaction) {
			if (($transaction->getStatus() ?? 'cleared') === 'scheduled') {
				continue;
			}
			if ($transaction->getType() === 'credit') {
				foreach ($transfersInto[$transaction->getAccountId()] ?? [] as $transfer) {
					if ($this->replaceBookedDeposit($transfer, $transaction)) {
						break;
					}
				}
				continue;
			}
			if ($transaction->getType() !== 'debit') {
				continue;
			}

			foreach ($bills as $key => $bill) {
				// The bank's copy of a payment already booked by Mark Paid or
				// auto-pay takes that payment's place rather than sitting
				// beside it, which booked the money twice
				$booked = $this->replaceBookedPayment($bill, $transaction);
				if ($booked === self::BOOKED_REPLACED) {
					$marked++;
					$bills[$key] = $this->find($bill->getId(), $userId);
					break;
				}
				// The copy of a payment that has to stay (reconciled by hand)
				// is not the next one's either: paying that with it paid twice
				if ($booked === self::BOOKED_KEPT) {
					break;
				}
				if (!$this->importedTransactionMatchesBill($bill, $transaction)) {
					continue;
				}

				try {
					$this->markPaid($bill->getId(), $userId, $transaction->getDate(), false, $transaction->getId());
					$marked++;
					// Reload: the due date advanced (or the bill deactivated),
					// which is what keeps this loop from double-advancing
					$bills[$key] = $this->find($bill->getId(), $userId);
					if (!$bills[$key]->getIsActive() || $bills[$key]->getNextDueDate() === null) {
						unset($bills[$key]);
					}
				} catch (\Exception $e) {
					$this->logger->warning(
						"Auto-match failed marking bill {$bill->getId()} paid from imported transaction {$transaction->getId()}: {$e->getMessage()}"
					);
				}
				break; // one bill per transaction
			}
		}

		return $marked;
	}

	/**
	 * Undo the payment a bank-sync hold made on a bill, when the bank drops
	 * the hold without it posting (a cancelled authorisation). The hold is
	 * about to be deleted, and leaving the bill paid kept it moved on to its
	 * next due date for money that never left the account, with the
	 * unrecorded-payments card inviting the user to record it.
	 *
	 * Only the bill's latest payment can be undone, since that is all the
	 * snapshot covers: a hold that paid an earlier occurrence returns false
	 * and the bill is left alone. The revert runs as the bill's owner, who
	 * isn't necessarily the user whose bank account is syncing.
	 *
	 * @return bool true when the bill was reverted
	 */
	public function revertCancelledPayment(int $billId, int $transactionId): bool {
		$bill = $this->mapper->findByIds([$billId])[0] ?? null;
		if ($bill === null) {
			return false;
		}
		$raw = $bill->getPaidUndoState();
		$snapshot = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		if (!is_array($snapshot) || !is_numeric($snapshot['linkedTransactionId'] ?? null)
			|| (int)$snapshot['linkedTransactionId'] !== $transactionId) {
			return false;
		}

		// The revert also puts back the pre-booked row of the restored
		// occurrence, which linking the hold had removed
		$this->markUnpaid($billId, $bill->getUserId());

		return true;
	}

	/**
	 * Match an imported transaction against a bill: pattern in description
	 * or vendor, amount within 10%, account agreement, and the transaction
	 * date inside the bill's current due window (so historical re-imports
	 * can't advance bills through future periods).
	 */
	private function importedTransactionMatchesBill(Bill $bill, \OCA\Budget\Db\Transaction $transaction): bool {
		return $this->importedTransactionLooksLikeBill($bill, $transaction)
			&& $this->withinDueWindow($bill, $transaction->getDate(), (string)$bill->getNextDueDate());
	}

	/** What an imported row has to contain to be this bill's (or transfer's) payment */
	private function matchPattern(Bill $bill): string {
		$pattern = trim((string)$bill->getAutoDetectPattern());
		if ($pattern === '' && ($bill->getIsTransfer() ?? false)) {
			$pattern = trim((string)$bill->getTransferDescriptionPattern());
		}
		return $pattern;
	}

	/** Pattern, amount within 10% and the bill's account: everything but the date */
	private function importedTransactionLooksLikeBill(Bill $bill, \OCA\Budget\Db\Transaction $transaction): bool {
		$pattern = $this->matchPattern($bill);
		$haystack = $transaction->getDescription() . ' ' . ($transaction->getVendor() ?? '');
		if ($pattern === '' || stripos($haystack, $pattern) === false) {
			return false;
		}

		$billAmount = (float)$bill->getAmount();
		if ($billAmount <= 0 || abs((float)$transaction->getAmount() - $billAmount) > $billAmount * 0.1) {
			return false;
		}

		return $bill->getAccountId() === null || $bill->getAccountId() === $transaction->getAccountId();
	}

	private function withinDueWindow(Bill $bill, string $date, string $around): bool {
		$daysOff = abs((strtotime($date) - strtotime($around)) / 86400);
		return $daysOff <= $this->dueDateToleranceDays($bill->getFrequency());
	}

	/**
	 * Put an imported bank row in place of a payment Mark Paid or auto-pay
	 * already booked for the bill.
	 *
	 * The booked payment is the one nearest the bank row's own date within
	 * the bill's due window (bookedPaymentNear()), whichever occurrence it
	 * paid. Only the last one, the one the undo snapshot names, used to be
	 * replaceable, so a statement bringing four weeks of a weekly bill left
	 * three booked payments beside the bank's, and a newest-first statement
	 * left the older one. Only a row the app generated is replaced: not a
	 * bank row, not reconciled. The generated row goes, the bank row is
	 * linked in its place with the bill's category, splits and tags, and
	 * when it was the last payment the snapshot then names the bank row, so
	 * Mark Unpaid unlinks it rather than deleting the bank's own record. The
	 * bill stays where it is. What the user added to the generated row (a
	 * split with a contact, a receipt, tags, notes) moves to the bank row;
	 * it was deleted with it.
	 *
	 * A recurring transfer's booked pair goes as a whole, and the bank row
	 * gets its arrival as Mark Paid's link does: the bank's own credit in the
	 * destination, or a deposit booked for it. Transfers used to skip this,
	 * so the bank's copy of a payment marked late fell inside the next
	 * occurrence's window and paid that one too.
	 *
	 * A reconciled row was matched to a statement by hand and stays: a
	 * reconciled payment keeps its place, and the bank row then pays nothing
	 * else (BOOKED_KEPT); a reconciled deposit stays as the transfer's
	 * arrival, paired with the bank row, where the swap used to delete it.
	 *
	 * @return int BOOKED_REPLACED, BOOKED_KEPT, or BOOKED_NONE when the bill
	 *             booked no payment near the row
	 */
	private function replaceBookedPayment(Bill $bill, \OCA\Budget\Db\Transaction $imported): int {
		if (!$this->importedTransactionLooksLikeBill($bill, $imported)) {
			return self::BOOKED_NONE;
		}
		$isTransfer = (bool)($bill->getIsTransfer() ?? false);
		$generatedNote = $isTransfer ? 'Auto-generated transfer:' : 'Auto-generated from bill:';
		$booked = $this->bookedPaymentNear($bill, $imported, $generatedNote);
		if ($booked === null) {
			return self::BOOKED_NONE;
		}
		if ($booked->getReconciled()) {
			return self::BOOKED_KEPT;
		}

		// The deposit the app booked with a transfer's payment goes with it,
		// and the bank row gets its own arrival. Any other row on the other
		// side (the destination's own credit, or a deposit reconciled by
		// hand) stays, paired with the bank row.
		$partner = $booked->getLinkedTransactionId() !== null ? $this->transactionService->findTransaction($booked->getLinkedTransactionId()) : null;
		$bookedDeposit = ($isTransfer && $partner !== null && $partner->getBillId() === $bill->getId() && !$partner->getReconciled()
			&& ($partner->getImportId() ?? '') === '' && str_starts_with((string)$partner->getNotes(), $generatedNote))
			? $partner : null;
		$removed = [$booked->getId()];
		if ($isTransfer && $booked->getLinkedTransactionId() !== null) {
			$removed[] = $booked->getLinkedTransactionId();
		}
		// The bank row's arrival is priced before the booked pair goes: with
		// no rate between the two currencies it can't take the pair's place
		$arrivalAmount = null;
		$current = $this->transactionService->findTransaction($imported->getId()) ?? $imported;
		if ($isTransfer && $bill->getDestinationAccountId() !== null && $current->getLinkedTransactionId() === null
			&& ($partner === null || $bookedDeposit !== null)) {
			try {
				$arrivalAmount = $this->transactionService->transferArrivalAmount($current, $bill);
			} catch (\Exception $e) {
				$this->logger->warning("Imported transaction {$imported->getId()} can't take the place of bill {$bill->getId()}'s payment: {$e->getMessage()}");
				return self::BOOKED_KEPT;
			}
		}
		$deposit = null;
		$generated = $generatedNote . ' ' . $bill->getName();
		try {
			$linked = $this->transactionService->linkBillAsAccountOwner($imported->getId(), $bill);
			if (!$linked->getIsSplit()) {
				$this->applySplitTemplate($bill, $linked, $bill->getUserId());
			}
			// What the user added to the booked payment (shares, receipts,
			// tags, notes) moves to the bank row rather than going with it
			$this->transactionService->replaceBookedRow($booked, $linked, $generated, $bookedDeposit === null);
			if ($isTransfer) {
				$withdrawal = $this->transactionService->findTransaction($imported->getId()) ?? $linked;
				$deposit = $this->transactionService->completeTransferPayment($withdrawal, $bill, $arrivalAmount);
				if ($bookedDeposit !== null) {
					// and what was added to the booked deposit, to the arrival
					$arrivalId = $this->transactionService->findTransaction($imported->getId())?->getLinkedTransactionId();
					$arrival = $arrivalId !== null ? $this->transactionService->findTransaction($arrivalId) : null;
					if ($arrival !== null && $arrival->getId() !== $bookedDeposit->getId()) {
						$this->transactionService->replaceBookedRow($bookedDeposit, $arrival, $generated);
					} else {
						$this->transactionService->deleteAsAccountOwner($bookedDeposit->getId(), false, $bill->getId());
					}
				}
				// The destination's own credit, already in, is the arrival
				if ($deposit !== null && $this->adoptArrival($bill, $deposit) !== null) {
					$deposit = null;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning("Failed to put imported transaction {$imported->getId()} in place of bill {$bill->getId()}'s payment: {$e->getMessage()}");
			return self::BOOKED_KEPT;
		}

		// The last payment's snapshot names the bank row from now on; an
		// earlier payment has no snapshot left to change
		$raw = $bill->getPaidUndoState();
		$snapshot = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		$ids = is_array($snapshot) && is_array($snapshot['createdTransactionIds'] ?? null) ? $snapshot['createdTransactionIds'] : [];
		if (in_array($booked->getId(), array_map('intval', $ids), true)) {
			$kept = [];
			foreach ($ids as $id) {
				if (!in_array((int)$id, $removed, true)) {
					$kept[] = $id;
				}
			}
			if ($deposit !== null) {
				$kept[] = $deposit;
			}
			$snapshot['createdTransactionIds'] = $kept;
			$snapshot['linkedTransactionId'] = $imported->getId();
			$bill->setPaidUndoState(json_encode($snapshot));
			$this->mapper->update($bill);
		}
		return self::BOOKED_REPLACED;
	}

	/**
	 * The payment the app booked for the bill nearest a bank row's date:
	 * within the bill's due window of it, in the row's account and within a
	 * tenth of the bill's amount of it.
	 */
	private function bookedPaymentNear(Bill $bill, \OCA\Budget\Db\Transaction $imported, string $generatedNote): ?\OCA\Budget\Db\Transaction {
		$days = $this->dueDateToleranceDays($bill->getFrequency());
		$on = new \DateTimeImmutable($imported->getDate());
		$nearest = null;
		$rank = null;
		$candidates = $this->transactionService->findBookedBillRows(
			$bill->getId(),
			'debit',
			$generatedNote,
			$on->modify("-{$days} days")->format('Y-m-d'),
			$on->modify("+{$days} days")->format('Y-m-d')
		);
		foreach ($candidates as $row) {
			$amountOff = abs((float)$row->getAmount() - (float)$imported->getAmount());
			if ($row->getId() === $imported->getId() || $row->getAccountId() !== $imported->getAccountId()
				|| $amountOff > (float)$bill->getAmount() * 0.1) {
				continue;
			}
			$thisRank = [abs(strtotime($row->getDate()) - $on->getTimestamp()), $amountOff];
			if ($rank === null || $thisRank < $rank) {
				[$nearest, $rank] = [$row, $thisRank];
			}
		}
		return $nearest;
	}

	/**
	 * A credit just imported or synced into a transfer's destination: when it
	 * is near a deposit the app booked for one of the transfer's payments,
	 * that deposit's arrival is chosen (adoptArrival()), and the statement no
	 * longer brings the same money in a second time.
	 *
	 * @return bool whether this credit became the arrival
	 */
	private function replaceBookedDeposit(Bill $bill, \OCA\Budget\Db\Transaction $credit): bool {
		// Income patterns are checked once a deposit is near (bestArrivalFor())
		if ($credit->getAccountId() !== $bill->getDestinationAccountId() || !$this->couldBeArrival($credit, [])) {
			return false;
		}

		$days = $this->dueDateToleranceDays($bill->getFrequency());
		$on = new \DateTimeImmutable($credit->getDate());
		$margin = $this->arrivalMargin($bill, (float)$credit->getAmount());
		$deposit = null;
		$rank = null;
		$candidates = $this->transactionService->findBookedBillRows(
			$bill->getId(),
			'credit',
			'Auto-generated transfer:',
			$on->modify("-{$days} days")->format('Y-m-d'),
			$on->modify("+{$days} days")->format('Y-m-d')
		);
		foreach ($candidates as $row) {
			$amountOff = abs((float)$row->getAmount() - (float)$credit->getAmount());
			if ($row->getAccountId() !== $credit->getAccountId() || $row->getLinkedTransactionId() === null || $amountOff > $margin) {
				continue;
			}
			$thisRank = [abs(strtotime($row->getDate()) - $on->getTimestamp()), $amountOff];
			if ($rank === null || $thisRank < $rank) {
				[$deposit, $rank] = [$row, $thisRank];
			}
		}
		// The deposit's arrival is whichever credit fits it best, which may
		// be a later row of the same statement
		return $deposit !== null && $this->adoptArrival($bill, $deposit->getId()) === $credit->getId();
	}

	/**
	 * Let the destination's own credit of a transfer's payment take the
	 * place of the deposit the app booked for it, so the money arrives once.
	 *
	 * Every payment path comes here with the deposit it booked: Mark Paid,
	 * linking the bank's withdrawal (the dialog, an import, auto-pay), and an
	 * import of the destination's statement. The credit has to be in the
	 * bill's due window around the deposit's date and of its amount: exactly
	 * within one currency, within a tenth between two (the deposit is only
	 * the app's estimate of the bank's conversion). A credit that is a
	 * recurring income's (its pattern is in the text), or one a bill, a
	 * transfer or a pension already has, never is: a salary of the same
	 * amount was taken, and auto-create then booked the salary again. Of the
	 * rest, one naming the transfer wins, then the one nearest the deposit.
	 * A reconciled deposit stays.
	 *
	 * @return int|null the credit that took the deposit's place
	 */
	private function adoptArrival(Bill $bill, int $depositId): ?int {
		$deposit = $this->transactionService->findTransaction($depositId);
		if ($deposit === null || $deposit->getType() !== 'credit' || $deposit->getReconciled()
			|| $deposit->getBillId() !== $bill->getId() || ($deposit->getImportId() ?? '') !== '') {
			return null;
		}
		$arrival = $this->bestArrivalFor($bill, $deposit);
		if ($arrival === null) {
			return null;
		}

		try {
			$linked = $this->transactionService->linkBillAsAccountOwner($arrival->getId(), $bill);
			$this->transactionService->replaceBookedRow($deposit, $linked, 'Auto-generated transfer: ' . $bill->getName());
		} catch (\Exception $e) {
			$this->logger->warning("Failed to put transaction {$arrival->getId()} in place of transfer {$bill->getId()}'s deposit: {$e->getMessage()}");
			return null;
		}

		// Mark Unpaid deletes what the payment booked; the bank's credit
		// isn't that, so the snapshot stops naming the deposit
		$fresh = $this->find($bill->getId(), $bill->getUserId());
		$raw = $fresh->getPaidUndoState();
		$snapshot = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		if (is_array($snapshot) && is_array($snapshot['createdTransactionIds'] ?? null)) {
			$kept = array_values(array_filter($snapshot['createdTransactionIds'], fn ($id) => (int)$id !== $deposit->getId()));
			if (count($kept) !== count($snapshot['createdTransactionIds'])) {
				$snapshot['createdTransactionIds'] = $kept;
				$fresh->setPaidUndoState(json_encode($snapshot));
				$this->mapper->update($fresh);
			}
		}
		return $arrival->getId();
	}

	/** The destination's credit that best fits a booked deposit, by adoptArrival()'s rules */
	private function bestArrivalFor(Bill $bill, \OCA\Budget\Db\Transaction $deposit): ?\OCA\Budget\Db\Transaction {
		$days = $this->dueDateToleranceDays($bill->getFrequency());
		$on = new \DateTimeImmutable($deposit->getDate());
		$amount = abs((float)$deposit->getAmount());
		$credits = $this->transactionService->findTransferArrivals(
			$deposit->getAccountId(),
			$amount,
			$on->modify("-{$days} days")->format('Y-m-d'),
			$on->modify("+{$days} days")->format('Y-m-d'),
			$this->arrivalMargin($bill, $amount)
		);
		$incomePatterns = $this->incomePatternsInto($deposit->getAccountId(), $bill->getUserId());
		$names = $this->transferNames($bill);
		$best = null;
		$rank = null;
		foreach ($credits as $credit) {
			if ($credit->getId() === $deposit->getId() || !$this->couldBeArrival($credit, $incomePatterns)) {
				continue;
			}
			$text = mb_strtolower($credit->getDescription() . ' ' . ($credit->getVendor() ?? ''));
			$named = array_filter($names, fn (string $name) => str_contains($text, $name)) !== [];
			$thisRank = [$named ? 0 : 1, abs(strtotime($credit->getDate()) - $on->getTimestamp()), abs((float)$credit->getAmount() - $amount)];
			if ($rank === null || $thisRank < $rank) {
				[$best, $rank] = [$credit, $thisRank];
			}
		}
		if ($best === null) {
			return null;
		}
		// Read again: a payment earlier in the same import may have taken it
		$current = $this->transactionService->findTransaction($best->getId());
		return ($current !== null && $this->couldBeArrival($current, $incomePatterns)) ? $current : null;
	}

	/**
	 * How far the destination's credit may be from the deposit: none within
	 * one currency, a tenth between two, where the bank converts at its own
	 * rate and charges.
	 */
	private function arrivalMargin(Bill $bill, float $amount): float {
		return $this->transactionService->transferBetweenCurrencies($bill) ? abs($amount) * 0.1 : 0.005;
	}

	/**
	 * Whether a credit is free to be a transfer's arrival: settled (a bank
	 * sync hold waits until it posts, as one dropped later would take the
	 * arrival with it), not the app's own row, not already a bill's,
	 * transfer's or pension's, and not a recurring income's.
	 *
	 * @param string[] $incomePatterns lower-case patterns of the incomes paid into its account
	 */
	private function couldBeArrival(\OCA\Budget\Db\Transaction $credit, array $incomePatterns): bool {
		if ($credit->getType() !== 'credit' || in_array($credit->getStatus() ?? 'cleared', ['scheduled', 'pending'], true)
			|| (int)($credit->getBillId() ?? 0) !== 0 || $credit->getLinkedTransactionId() !== null
			|| $credit->getPensionContribId() !== null) {
			return false;
		}
		foreach (\OCA\Budget\Db\TransactionMapper::GENERATED_NOTE_PREFIXES as $prefix) {
			if (str_starts_with((string)$credit->getNotes(), $prefix)) {
				return false;
			}
		}
		$text = mb_strtolower($credit->getDescription() . ' ' . ($credit->getVendor() ?? ''));
		foreach ($incomePatterns as $pattern) {
			if (str_contains($text, $pattern)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lower-case auto-detect patterns of the active recurring income paid
	 * into an account (whoever owns it), and of the user's own income with
	 * no account set, as the income match reads them
	 *
	 * @return string[]
	 */
	private function incomePatternsInto(int $accountId, string $userId): array {
		if ($this->incomeMapper === null) {
			return [];
		}
		$incomes = $this->incomeMapper->findActiveByAccount($accountId);
		foreach ($this->incomeMapper->findActive($userId) as $income) {
			if ($income->getAccountId() === null) {
				$incomes[] = $income;
			}
		}
		$patterns = [];
		foreach ($incomes as $income) {
			$pattern = mb_strtolower(trim((string)$income->getAutoDetectPattern()));
			if ($pattern !== '') {
				$patterns[$pattern] = $pattern;
			}
		}
		return array_values($patterns);
	}

	/**
	 * Lower-case words the destination's credit of a transfer may carry: its
	 * description pattern, its name and the source account's name
	 *
	 * @return string[]
	 */
	private function transferNames(Bill $bill): array {
		$names = [$this->matchPattern($bill), (string)$bill->getName()];
		if ($bill->getAccountId() !== null) {
			try {
				$names[] = (string)$this->accountMapper->findById($bill->getAccountId())->getName();
			} catch (DoesNotExistException $e) {
				// a source that is gone names nothing
			}
		}
		$names = array_map(fn (string $name) => mb_strtolower(trim($name)), $names);
		return array_values(array_unique(array_filter($names, fn (string $name) => mb_strlen($name) >= 3)));
	}

	/**
	 * How far an imported payment may sit from the due date and still count
	 * as paying THIS occurrence — roughly half the recurrence interval,
	 * capped so monthly+ bills accept payments up to two weeks early/late.
	 */
	private function dueDateToleranceDays(?string $frequency): int {
		return match ($frequency) {
			'daily' => 1,
			'weekly' => 3,
			'biweekly' => 6,
			'semi-monthly' => 7,
			default => 15, // monthly, quarterly, semi-annually, yearly, custom, one-time
		};
	}

	/**
	 * Attempt to auto-pay a bill and handle success/failure.
	 *
	 * Pays every occurrence due by the owner's today (at most
	 * MAX_AUTO_PAY_CATCH_UP), each dated on its own due date. An occurrence
	 * whose bank row is already in the account (unclaimedPaymentFor()) is
	 * paid by linking that row, as an import would have. Payments made
	 * before a failure stay paid; auto-pay then switches itself off.
	 *
	 * @param int $id Bill ID
	 * @param string $userId User ID
	 * @return array ['success' => bool, 'message' => string, 'bill' => ?Bill, 'count' => int occurrences paid (on success),
	 *               'disabled' => bool whether a failure switched auto-pay off; false when there was nothing to pay]
	 */
	public function processAutoPay(int $id, string $userId): array {
		try {
			$bill = $this->find($id, $userId);

			// Validate auto-pay is enabled and account exists
			if (!$bill->getAutoPayEnabled()) {
				return [
					'success' => false,
					'message' => $this->l->t('Auto-pay is not enabled for this bill'),
					'bill' => null,
					'disabled' => false,
				];
			}

			if ($bill->getAccountId() === null) {
				// Disable auto-pay and mark as failed
				$this->mapper->updateFields($id, $userId, [
					'auto_pay_enabled' => false,
					'auto_pay_failed' => true,
				]);
				return [
					'success' => false,
					'message' => $this->l->t('Bill has no account associated'),
					'bill' => $this->find($id, $userId),
					'disabled' => true,
				];
			}

			// Pay every occurrence owed by the owner's today, each on its own
			// due date, as income auto-create and pension auto-post do. Paying
			// one per run, dated the day of the run, left a weekly bill weeks
			// behind and then caught it up in a burst of rows all dated today.
			// The caller asks for a bill that was due when it looked. Another
			// run, or the owner's own Mark Paid, may have paid it since: that
			// is nothing to pay, not a failure. A second run at once used to
			// pay the next occurrence early, or be refused as "already
			// recorded" and switch auto-pay off.
			$today = $this->today($userId);
			$paid = 0;
			$result = null;
			while ($paid < self::MAX_AUTO_PAY_CATCH_UP
				&& $bill->getIsActive()
				&& $bill->getNextDueDate() !== null
				&& $bill->getNextDueDate() <= $today) {
				$due = (string)$bill->getNextDueDate();
				try {
					// The bank's own row of the occurrence, already imported, is
					// its payment: catching up booked another beside each one
					$existing = $this->unclaimedPaymentFor($bill, $due);
					$result = $existing !== null
						? $this->markPaid($id, $userId, $existing->getDate(), false, $existing->getId(), $due)
						: $this->markPaid($id, $userId, min($due, $today), true, null, $due);
				} catch (\InvalidArgumentException $e) {
					if ($this->find($id, $userId)->getNextDueDate() !== $due) {
						break;
					}
					throw $e;
				}

				// Paid with nothing booked (the account is gone, the row failed):
				// put the bill back and fail, rather than report success and move
				// the bill on while the money never shows
				if (!($result['paymentTransactionRecorded'] ?? false)) {
					try {
						$this->markUnpaid($id, $userId);
					} catch (\Exception $e) {
						$this->logger->warning("Failed to revert auto-pay of bill {$id}: {$e->getMessage()}");
					}
					throw new \RuntimeException($this->l->t('The payment could not be recorded'));
				}
				$paid++;
				$bill = $result['bill'];
			}

			if ($result === null) {
				return [
					'success' => false,
					'message' => 'Nothing due',
					'bill' => $bill,
					'disabled' => false,
				];
			}

			return [
				'success' => true,
				'message' => $this->l->t('Bill auto-paid successfully'),
				'bill' => $result['bill'],
				'count' => $paid,
			];

		} catch (\Exception $e) {
			// Mark auto-pay as failed and disable it
			try {
				$this->mapper->updateFields($id, $userId, [
					'auto_pay_enabled' => false,
					'auto_pay_failed' => true,
				]);
				$bill = $this->find($id, $userId);
			} catch (\Exception $e2) {
				$bill = null;
			}

			return [
				'success' => false,
				'message' => 'Auto-pay failed: ' . $e->getMessage(),
				'bill' => $bill,
				'disabled' => true,
			];
		}
	}

	/**
	 * A row already in the bill's account that is the payment of the
	 * occurrence due on $due, as an import would have matched it: it carries
	 * the bill's pattern, is within a tenth of its amount and in its due
	 * window, and no bill pays it. The nearest the due date wins.
	 */
	private function unclaimedPaymentFor(Bill $bill, string $due): ?\OCA\Budget\Db\Transaction {
		if ($bill->getAccountId() === null || $this->matchPattern($bill) === '') {
			return null;
		}
		$nearest = null;
		$rank = null;
		foreach ($this->transactionService->findUnclaimedDebits($bill->getAccountId(), $due, $this->dueDateToleranceDays($bill->getFrequency())) as $row) {
			// A deleted bill's row is offered too, but linking it would fail
			if ((int)($row->getBillId() ?? 0) !== 0 || $row->getPensionContribId() !== null
				|| !$this->importedTransactionLooksLikeBill($bill, $row) || !$this->withinDueWindow($bill, $row->getDate(), $due)) {
				continue;
			}
			$thisRank = [abs(strtotime($row->getDate()) - strtotime($due)), abs((float)$row->getAmount() - (float)$bill->getAmount())];
			if ($rank === null || $thisRank < $rank) {
				[$nearest, $rank] = [$row, $thisRank];
			}
		}
		return $nearest;
	}

	private function checkIfPaidInPeriod(Bill $bill, string $startDate, string $endDate): bool {
		$lastPaid = $bill->getLastPaidDate();
		if (!$lastPaid) {
			return false;
		}
		return $lastPaid >= $startDate && $lastPaid <= $endDate;
	}

	/**
	 * Get annual overview of bills showing which months each bill occurs
	 *
	 * @param string $userId User ID
	 * @param int $year Year to generate overview for
	 * @param bool $includeTransfers Include transfer bills
	 * @param string $billStatus 'active', 'inactive', or 'all'
	 * @param int|null $accountId Filter by account ID (source or destination)
	 * @return array Bills with monthly occurrences and totals
	 */
	public function getAnnualOverview(string $userId, int $year, bool $includeTransfers = false, string $billStatus = 'active', ?int $accountId = null): array {
		// Build currency map for conversion. The same list finds the picked
		// account below, so an id the user cannot see finds nothing.
		$accounts = $this->accountMapper->findAll($userId);
		$currencyMap = $this->currencyMapFor($accounts);
		$baseCurrency = $this->currencyConversion->getBaseCurrency($userId);

		[$billsData, $monthlyTotals] = $this->calendarRows($userId, $year, $includeTransfers, $billStatus, $accountId, $currencyMap, $baseCurrency);

		// With an account picked: its balance carried through the rest of
		// this year, from today's (#393). Another year has no today to start
		// from, so it gets no projection.
		$accountSummary = null;
		$projection = null;
		$account = $accountId === null ? null : $this->accountAmong($accounts, $accountId);
		if ($account !== null) {
			// The user's today: a purchase dated it is already in the balance
			$balance = $this->transactionService->getBalanceAsOf($account->getId(), $this->today($userId));
			$accountSummary = [
				'id' => $account->getId(),
				'name' => $account->getName(),
				'currency' => $account->getCurrency() ?: $baseCurrency,
				'balance' => $balance,
			];
			if ($year === (int)substr($this->today($userId), 0, 4)) {
				// The money moves whatever the table is set to show, so a
				// view without transfers, or of inactive bills only, is
				// projected from every bill that is still live
				$projectionRows = ($includeTransfers && $billStatus !== 'inactive')
					? $billsData
					: $this->calendarRows($userId, $year, true, 'active', $accountId, $currencyMap, $baseCurrency)[0];
				$projection = $this->projectBalance($userId, $projectionRows, $account, $balance);
			}
		}

		return [
			'year' => $year,
			'bills' => $this->groupOneTimeBillsByName($billsData),
			'monthlyTotals' => $monthlyTotals,
			'baseCurrency' => $baseCurrency,
			'account' => $accountSummary,
			'projectedBalance' => $projection['balance'] ?? null,
			'projectedFlows' => $projection['flows'] ?? null,
		];
	}

	/**
	 * The calendar's rows, one per bill with anything in the year, and what
	 * each month comes to in the base currency.
	 *
	 * @param array<int, string|null> $currencyMap account id => currency code
	 * @return array{0: array[], 1: array<int, float>} ungrouped rows, monthly totals
	 */
	private function calendarRows(string $userId, int $year, bool $includeTransfers, string $billStatus, ?int $accountId, array $currencyMap, string $baseCurrency): array {
		// Determine which bills to fetch based on status
		$bills = [];
		if ($billStatus === 'active') {
			$isActive = true;
		} elseif ($billStatus === 'inactive') {
			$isActive = false;
		} else {
			$isActive = null; // All bills
		}

		// Fetch bills with type filter. "Active" fetches everything and is
		// narrowed below, because a bill that is inactive now but was paid
		// this year - a one-time bill deactivates itself on payment - still
		// belongs in the year's picture (#375).
		$fetchActive = $billStatus === 'active' ? null : $isActive;
		if ($includeTransfers) {
			$bills = $this->mapper->findByType($userId, null, $fetchActive);
		} else {
			$bills = $this->mapper->findByType($userId, false, $fetchActive);
		}

		// Filter by account if specified (match source or destination account)
		if ($accountId !== null) {
			$bills = array_filter($bills, function ($bill) use ($accountId) {
				return $bill->getAccountId() === $accountId
					|| $bill->getDestinationAccountId() === $accountId;
			});
		}

		// What was actually paid this year, per bill. The cells used to be
		// guessed from last_paid_date - every month up to it counted as paid,
		// so a bill first paid in September showed January to August paid
		// too - and a one-time bill fell out of the calendar the moment it
		// was paid (#375). Only the debit leg counts: a transfer bill's
		// credit leg is the same payment arriving, not a second one.
		$paymentsByBill = [];
		$billIds = array_map(fn (Bill $bill) => $bill->getId(), $bills);
		foreach ($this->transactionService->findBillPaymentsInYear($billIds, $year) as $payment) {
			if (($payment->getType() ?? 'debit') !== 'debit') {
				continue;
			}
			$paymentsByBill[$payment->getBillId()][] = [
				'date' => $payment->getDate(),
				'amount' => (float)$payment->getAmount(),
			];
		}

		if ($billStatus === 'active') {
			$bills = array_filter($bills, fn (Bill $bill) => $bill->getIsActive() || isset($paymentsByBill[$bill->getId()]));
		}
		$currencyMap = $this->withAccountsOf($currencyMap, array_map(fn (Bill $bill) => $bill->getAccountId(), $bills));

		// Calculate monthly occurrences for each bill
		$billsData = [];
		$monthlyTotals = array_fill(1, 12, 0.0);

		foreach ($bills as $bill) {
			$datesByMonth = $this->occurrencesInYear($bill, $year);
			$occurrences = array_fill(1, 12, false);
			foreach (array_keys($datesByMonth) as $month) {
				$occurrences[$month] = true;
			}
			[$occurrences, $paidMonths, $paidAmounts, $unrecordedMonths] = $this->attributePayments(
				$occurrences,
				$bill,
				$paymentsByBill[$bill->getId()] ?? [],
				$year
			);

			// A bill with nothing in this year - created after its only date
			// came round, or started next year - has no cell to draw, and an
			// empty row says nothing (#333)
			if (array_filter($occurrences) === []) {
				continue;
			}

			$billCurrency = ($bill->getAccountId() !== null && isset($currencyMap[$bill->getAccountId()]))
				? $currencyMap[$bill->getAccountId()]
				: $baseCurrency;

			// What each month is expected to cost, one amount per date the
			// bill falls on (a weekly bill pays four or five times a month),
			// and what of it is still owed: the dates from the bill's next
			// due date on, which the projection counts
			$amount = (float)$bill->getAmount();
			$expectedAmounts = [];
			foreach (array_keys(array_filter($occurrences)) as $month) {
				$expectedAmounts[$month] = $amount * max(1, count($datesByMonth[$month] ?? []));
			}
			$owedAmounts = [];
			$nextDue = $bill->getNextDueDate();
			if ($bill->getIsActive() && $nextDue !== null && $nextDue !== '') {
				foreach ($datesByMonth as $month => $dates) {
					$owed = count(array_filter($dates, fn (string $d): bool => $d >= $nextDue));
					if ($owed > 0) {
						$owedAmounts[$month] = $amount * $owed;
					}
				}
			}

			$billData = [
				'id' => $bill->getId(),
				'name' => $bill->getName(),
				'amount' => $bill->getAmount(),
				'currency' => $billCurrency,
				'frequency' => $bill->getFrequency(),
				'categoryId' => $bill->getCategoryId(),
				'accountId' => $bill->getAccountId(),
				'isActive' => $bill->getIsActive(),
				'isTransfer' => $bill->getIsTransfer() ?? false,
				'destinationAccountId' => $bill->getDestinationAccountId(),
				'lastPaidDate' => $bill->getLastPaidDate(),
				'nextDueDate' => $bill->getNextDueDate(),
				'occurrences' => $occurrences, // Array with month numbers as keys
				// Months whose occurrence has a recorded payment, and what was
				// actually paid in each - the expected amount stands in for
				// the months still to come (#375)
				'paidMonths' => $paidMonths,
				'paidAmounts' => $paidAmounts,
				// Occurrences the bill has moved past without a recorded
				// payment - marked paid with no transaction, or skipped. Not
				// paid, but not owed either, so they must not read as due (#333)
				'unrecordedMonths' => $unrecordedMonths,
				// What each occurring month is expected to cost. One per month
				// so that one-time bills sharing a name can be shown as one
				// row without losing each invoice's own amount (#375)
				'expectedAmounts' => $expectedAmounts,
				'owedAmounts' => $owedAmounts,
			];

			$billsData[] = $billData;

			// A transfer into the picked account is money arriving, not a
			// bill it pays: it stays in the table, but adding it to the
			// monthly totals gave a savings account a bill the size of every
			// transfer it receives. The projected balance counts it as money in.
			if ($accountId !== null && ($bill->getIsTransfer() ?? false)
				&& $bill->getDestinationAccountId() === $accountId && $bill->getAccountId() !== $accountId) {
				continue;
			}

			// Monthly totals: what was paid plus what is still owed, or the
			// expected amount for a month with neither
			foreach ($occurrences as $month => $occurs) {
				if (!$occurs) {
					continue;
				}
				$total = isset($paidAmounts[$month]) || isset($owedAmounts[$month])
					? ($paidAmounts[$month] ?? 0.0) + ($owedAmounts[$month] ?? 0.0)
					: ($expectedAmounts[$month] ?? $amount);
				$monthlyTotals[$month] += $this->convertToBase($total, $billCurrency, $baseCurrency, $userId);
			}
		}

		return [$billsData, $monthlyTotals];
	}

	/** @param Account[] $accounts */
	private function accountAmong(array $accounts, int $accountId): ?Account {
		foreach ($accounts as $account) {
			if ($account->getId() === $accountId) {
				return $account;
			}
		}
		return null;
	}

	/**
	 * The account's running balance through the rest of this year: bills
	 * out, transfers and recurring income in, carried month to month (#393).
	 *
	 * @param array[] $billsData ungrouped calendar rows
	 * @return array{balance: array<int, float|null>, flows: array<int, array{bills: float, transfersIn: float, income: float}|null>}
	 */
	private function projectBalance(string $userId, array $billsData, Account $account, float $balance): array {
		$scale = Currency::decimalsFor($account->getCurrency());
		$today = $this->today($userId);
		$projector = new BalanceProjector($this->frequencyCalculator);
		$incomes = $this->incomeMapper?->findActive($userId) ?? [];
		// Scheduled pension contributions paid from the account come out of it
		// like a bill nobody has paid yet
		$pensionDebits = $this->pensionRecurringService?->upcomingDebitsByMonth($account->getId(), $today) ?? [];
		if ($pensionDebits !== []) {
			$billsData[] = [
				'accountId' => $account->getId(),
				'isTransfer' => false,
				'destinationAccountId' => null,
				'paidMonths' => [],
				'unrecordedMonths' => [],
				'expectedAmounts' => $pensionDebits,
			];
		}
		return $projector->project(
			$billsData,
			$account->getId(),
			$balance,
			$projector->incomeByMonth($incomes, $account->getId(), $today, $scale),
			(int)substr($today, 5, 2),
			$scale
		);
	}

	/**
	 * Fold one-time bills that share a name into a single calendar row.
	 *
	 * Every invoice from the same vendor is its own one-time bill, and once
	 * paid ones stay in the year's calendar (#375) a garage that sent three
	 * invoices showed as three rows with the same name, one cell each. One
	 * row with a cell per invoice reads the way the recurring rows do. Each
	 * month keeps its own expected and paid amount; two invoices in one
	 * month add up. Recurring bills are left alone.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function groupOneTimeBillsByName(array $rows): array {
		$grouped = [];
		$byName = [];
		foreach ($rows as $row) {
			if (($row['frequency'] ?? '') !== 'one-time') {
				$grouped[] = $row;
				continue;
			}
			$key = mb_strtolower(trim((string)$row['name'])) . '|' . ($row['currency'] ?? '');
			$row['unrecordedMonths'] = $row['unrecordedMonths'] ?? [];
			if (!isset($byName[$key])) {
				$row['billIds'] = [$row['id']];
				$byName[$key] = count($grouped);
				$grouped[] = $row;
				continue;
			}
			$index = $byName[$key];
			$grouped[$index]['billIds'][] = $row['id'];
			$grouped[$index]['isActive'] = $grouped[$index]['isActive'] || $row['isActive'];
			foreach ($row['occurrences'] as $month => $occurs) {
				if ($occurs) {
					$grouped[$index]['occurrences'][$month] = true;
				}
			}
			$unrecorded = array_unique(array_merge($grouped[$index]['unrecordedMonths'], $row['unrecordedMonths']));
			sort($unrecorded);
			$grouped[$index]['unrecordedMonths'] = array_values($unrecorded);
			foreach ($row['expectedAmounts'] as $month => $amount) {
				$grouped[$index]['expectedAmounts'][$month] = ($grouped[$index]['expectedAmounts'][$month] ?? 0.0) + $amount;
			}
			foreach ($row['owedAmounts'] ?? [] as $month => $amount) {
				$grouped[$index]['owedAmounts'][$month] = ($grouped[$index]['owedAmounts'][$month] ?? 0.0) + $amount;
			}
			foreach ($row['paidAmounts'] as $month => $amount) {
				$grouped[$index]['paidAmounts'][$month] = ($grouped[$index]['paidAmounts'][$month] ?? 0.0) + $amount;
			}
			ksort($grouped[$index]['paidAmounts']);
			ksort($grouped[$index]['expectedAmounts']);
			$grouped[$index]['paidMonths'] = array_keys($grouped[$index]['paidAmounts']);
		}

		return $grouped;
	}

	/** How far a payment may sit from an occurrence's due date and still be that occurrence's. */
	private const PAYMENT_MATCH_WINDOW_DAYS = 45;

	/**
	 * Match a year's payments to the schedule's occurrences, by date - and
	 * never ahead of the bill itself.
	 *
	 * The bill already knows which occurrences are done: marking one paid
	 * advances next_due_date by a cycle, so every occurrence due before that
	 * date is closed and everything from it on is still owed. Payments are
	 * only ever placed on closed occurrences. Without that boundary a user
	 * who pays each cycle a few days before the next one is due - or pays
	 * one twice - had the row shift a month forward, and the calendar showed
	 * September paid while the Bills page showed the same occurrence as
	 * upcoming (#375).
	 *
	 * Within the closed occurrences a payment belongs to the nearest unpaid
	 * one within the window; when all of those are taken it adds to the
	 * nearest closed one (a double payment shows as the larger amount rather
	 * than as a month that was never paid); a payment with no closed
	 * occurrence anywhere near it becomes an extra paid month, provided that
	 * month is itself before the next due date. A bill with a single
	 * occurrence takes any payment, whenever it was made.
	 *
	 * A closed occurrence that ends up with no payment is reported too. The
	 * bill was marked paid with "Don't create any transaction", or the
	 * occurrence was skipped: either way the bill has moved past it, so it is
	 * neither paid nor owed, and drawing it as due - which is what a cell
	 * without a payment used to mean - had a yearly premium paid in
	 * February still showing as outstanding in September (#333).
	 *
	 * @param array<int, bool> $occurrences month => occurs, 1..12
	 * @param array<int, array{date: string, amount: float}> $payments in date order
	 * @return array{0: array<int, bool>, 1: int[], 2: array<int, float>, 3: int[]}
	 *                                                                              occurrences (with extras), paid months, paid amount per month,
	 *                                                                              months the bill has moved past with no payment recorded
	 */
	private function attributePayments(array $occurrences, Bill $bill, array $payments, int $year): array {
		$paidAmounts = [];
		$slots = array_keys(array_filter($occurrences));
		$slotDates = [];
		foreach ($slots as $slot) {
			$slotDates[$slot] = new \DateTimeImmutable($this->occurrenceDate($bill, $year, $slot));
		}

		// Occurrences the bill itself still counts as owed are never paid
		// targets. An inactive bill (ended, or a one-time bill after its
		// payment) has nothing owed, so all of its occurrences are closed.
		$nextDue = $bill->getNextDueDate();
		$boundary = ($bill->getIsActive() && $nextDue !== null && $nextDue !== '' && $bill->getFrequency() !== 'one-time')
			? new \DateTimeImmutable($nextDue)
			: null;
		$closedSlots = array_values(array_filter(
			$slots,
			fn (int $slot): bool => $boundary === null || $slotDates[$slot] < $boundary
		));

		// What the bill has definitely moved past. An inactive bill owes
		// nothing; an active one-time bill owes its only occurrence, and an
		// active recurring bill owes everything from next_due_date on. Kept
		// apart from $closedSlots, which is deliberately wider for an active
		// one-time bill so that its payment can land whenever it was made.
		if (!$bill->getIsActive()) {
			$settledSlots = $slots;
		} elseif ($boundary === null) {
			$settledSlots = [];
		} else {
			$settledSlots = $closedSlots;
		}

		$nearest = function (\DateTimeImmutable $paidOn, array $candidates, ?int $window) use (&$slotDates): ?int {
			$best = null;
			foreach ($candidates as $slot) {
				$days = $paidOn->diff($slotDates[$slot])->days;
				if ($window !== null && $days > $window) {
					continue;
				}
				// Nearest wins; on a tie the earlier month (a late payment)
				if ($best === null || $days < $best[0] || ($days === $best[0] && $slot < $best[1])) {
					$best = [$days, $slot];
				}
			}
			return $best[1] ?? null;
		};

		foreach ($payments as $payment) {
			$paidOn = new \DateTimeImmutable($payment['date']);
			$target = null;

			if (count($slots) === 1) {
				$target = $slots[0];
			} else {
				$unpaid = array_values(array_filter($closedSlots, fn (int $s): bool => !isset($paidAmounts[$s])));
				$target = $nearest($paidOn, $unpaid, self::PAYMENT_MATCH_WINDOW_DAYS)
					?? $nearest($paidOn, $closedSlots, self::PAYMENT_MATCH_WINDOW_DAYS);

				if ($target === null) {
					// Real money with no occurrence near it: its own month, as
					// long as the bill does not still count that month as owed
					$month = (int)$paidOn->format('n');
					$monthDate = new \DateTimeImmutable($this->occurrenceDate($bill, $year, $month));
					if ($boundary === null || $monthDate < $boundary) {
						$target = $month;
						$occurrences[$month] = true;
						$slotDates[$month] = $monthDate;
					} else {
						$target = $nearest($paidOn, $closedSlots, null);
					}
				}
			}

			if ($target === null) {
				continue; // nothing the bill counts as paid to put it on
			}
			$paidAmounts[$target] = ($paidAmounts[$target] ?? 0.0) + $payment['amount'];
		}

		ksort($paidAmounts);
		$unrecorded = array_values(array_filter($settledSlots, fn (int $slot): bool => !isset($paidAmounts[$slot])));

		return [$occurrences, array_keys($paidAmounts), $paidAmounts, $unrecorded];
	}

	/**
	 * The date an occurrence in a given month falls due: the schedule's own
	 * date in that month. A month the schedule doesn't fall in (a payment
	 * with no occurrence near it) gets the due day, or mid-month for the
	 * frequencies that fall several times a month.
	 */
	private function occurrenceDate(Bill $bill, int $year, int $month): string {
		$dates = $this->occurrencesInYear($bill, $year)[$month] ?? [];
		if ($dates !== []) {
			return $dates[0];
		}

		$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
		$day = in_array($bill->getFrequency(), ['daily', 'weekly', 'biweekly'], true)
			? 15
			: min(max((int)($bill->getDueDay() ?? 1), 1), $daysInMonth);

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	/**
	 * Every date a bill falls on in a year, by month, from the same schedule
	 * the Bills page pays against. The calendar used to keep its own rules:
	 * semi-monthly bills never appeared, a weekly bill counted once a month,
	 * a quarterly bill from November showed only in November, and a one-time
	 * bill came back in the same month of every later year.
	 *
	 *  - Nothing occurs before the start date or, without one, before the
	 *    bill existed (#333); nothing after the end date.
	 *  - Remaining payments cap what is still to come from the next due date.
	 *  - A quarterly, half-yearly or yearly bill with no month stored takes
	 *    it from its next due date; a weekly one with no start date takes its
	 *    week from it too.
	 *
	 * @return array<int, string[]> month => Y-m-d dates in order
	 */
	private function occurrencesInYear(Bill $bill, int $year): array {
		$from = sprintf('%04d-01-01', $year);
		$to = sprintf('%04d-12-31', $year);
		$frequency = $bill->getFrequency();
		$nextDue = $bill->getNextDueDate() ?: null;
		$anchor = $bill->getStartDate() ?: null;

		if ($frequency === 'one-time' && $anchor === null) {
			// A one-time bill from before the date field: its due date is it,
			// or once paid, its day and month in the year it was paid
			$anchor = $nextDue;
			$lastPaid = $bill->getLastPaidDate() ?: null;
			if ($anchor === null && $lastPaid !== null && $bill->getDueMonth() !== null) {
				$paidYear = (int)substr($lastPaid, 0, 4);
				$anchor = $this->frequencyCalculator->occurrenceOnOrAfter(
					'monthly', $bill->getDueDay() ?? 1, null, sprintf('%04d-%02d-01', $paidYear, $bill->getDueMonth())
				);
			}
			if ($anchor === null) {
				return [];
			}
		}

		$dueMonth = $bill->getDueMonth();
		if ($dueMonth === null && $nextDue !== null && in_array($frequency, ['quarterly', 'semi-annually', 'yearly'], true)) {
			$dueMonth = (int)substr($nextDue, 5, 2);
		}

		if ($anchor === null && $bill->getCreatedAt()) {
			$from = max($from, substr((string)$bill->getCreatedAt(), 0, 10));
		}
		$endDate = $bill->getEndDate() ?: null;
		if ($endDate !== null) {
			$to = min($to, $endDate);
		}
		if ($from > $to) {
			return [];
		}

		if ($anchor === null && $nextDue !== null && in_array($frequency, ['weekly', 'biweekly'], true)) {
			// A step back far enough that the week comes from the due date
			$interval = $frequency === 'biweekly' ? 14 : 7;
			$back = (new \DateTimeImmutable($nextDue))->diff(new \DateTimeImmutable($from))->days;
			$anchor = (new \DateTimeImmutable($nextDue))
				->modify('-' . ((intdiv($back, $interval) + 1) * $interval) . ' days')->format('Y-m-d');
		}

		$dates = $this->frequencyCalculator->occurrencesBetween(
			$frequency, $bill->getDueDay(), $dueMonth, $from, $to, $bill->getCustomRecurrencePattern(), $anchor
		);

		$remaining = $bill->getRemainingPayments();
		if ($remaining !== null && $nextDue !== null) {
			if ($nextDue < $from) {
				// Payments still to come before this year starts use some up
				$remaining -= count($this->frequencyCalculator->occurrencesBetween(
					$frequency, $bill->getDueDay(), $dueMonth, $nextDue,
					(new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'),
					$bill->getCustomRecurrencePattern(), $anchor
				));
			}
			$kept = [];
			foreach ($dates as $date) {
				if ($date < $nextDue) {
					$kept[] = $date;
				} elseif ($remaining > 0) {
					$kept[] = $date;
					$remaining--;
				}
			}
			$dates = $kept;
		}

		$byMonth = [];
		foreach ($dates as $date) {
			$byMonth[(int)substr($date, 5, 2)][] = $date;
		}
		return $byMonth;
	}

	/**
	 * Calculate which months a bill occurs in for a given year
	 *
	 * @param Bill $bill The bill entity
	 * @param int $year The year to calculate for
	 * @return array Array with month numbers (1-12) as keys and boolean values
	 */
	private function calculateMonthlyOccurrences(Bill $bill, int $year): array {
		$occurrences = array_fill(1, 12, false);
		foreach (array_keys($this->occurrencesInYear($bill, $year)) as $month) {
			$occurrences[$month] = true;
		}
		return $occurrences;
	}
}
