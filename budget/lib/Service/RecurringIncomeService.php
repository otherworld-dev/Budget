<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Manages recurring income CRUD operations and summary calculations.
 */
/**
 * @extends AbstractCrudService<RecurringIncome>
 */
class RecurringIncomeService extends AbstractCrudService {
	private FrequencyCalculator $frequencyCalculator;
	private RecurringIncomeDetector $recurringDetector;
	private TransactionService $transactionService;
	private LoggerInterface $logger;
	private IL10N $l;
	private ?AutoShareService $autoShareService;

	/** Most occurrences one auto-create run books, so a years-old date can't flood the ledger */
	private const MAX_AUTO_CREATE_CATCH_UP = 60;

	public function __construct(
		RecurringIncomeMapper $mapper,
		FrequencyCalculator $frequencyCalculator,
		RecurringIncomeDetector $recurringDetector,
		TransactionService $transactionService,
		LoggerInterface $logger,
		IL10N $l,
		?AutoShareService $autoShareService = null,
		private ?UserClock $userClock = null,
		private ?GranularShareService $granularShareService = null,
		private ?AccountMapper $accountMapper = null,
		private ?CurrencyConversionService $currencyConversion = null,
	) {
		$this->mapper = $mapper;
		$this->frequencyCalculator = $frequencyCalculator;
		$this->recurringDetector = $recurringDetector;
		$this->transactionService = $transactionService;
		$this->logger = $logger;
		$this->l = $l;
		$this->autoShareService = $autoShareService;
	}

	public function findActive(string $userId): array {
		return $this->mapper->findActive($userId);
	}

	public function findExpectedThisMonth(string $userId): array {
		$today = new \DateTimeImmutable($this->today($userId));
		return $this->mapper->findExpectedInRange($userId, $today->format('Y-m-01'), $today->format('Y-m-t'));
	}

	/**
	 * Find upcoming income sorted by expected date.
	 */
	public function findUpcoming(string $userId, int $days = 30): array {
		return $this->mapper->findUpcoming($userId, $days, $this->today($userId));
	}

	public function create(
		string $userId,
		string $name,
		float $amount,
		string $frequency = 'monthly',
		?int $expectedDay = null,
		?int $expectedMonth = null,
		?int $categoryId = null,
		?int $accountId = null,
		?string $source = null,
		?string $autoDetectPattern = null,
		?string $notes = null,
		bool $autoCreateEnabled = false,
		?string $description = null,
		bool $excludedFromForecast = false,
		?string $startDate = null,
	): RecurringIncome {
		$startDate = ($startDate === null || $startDate === '') ? null : $startDate;
		$this->validateSchedule($frequency, $startDate);

		$income = new RecurringIncome();
		$income->setUserId($userId);
		$income->setName($name);
		$income->setDescription($description);
		$income->setAmount($amount);
		$income->setFrequency($frequency);
		$income->setExpectedDay($expectedDay);
		$income->setExpectedMonth($expectedMonth);
		$income->setCategoryId($categoryId);
		$income->setAccountId($accountId);
		$income->setSource($source);
		$income->setAutoDetectPattern($autoDetectPattern);
		$income->setIsActive(true);
		$income->setAutoCreateEnabled($autoCreateEnabled);
		$income->setNotes($notes);
		$income->setExcludedFromForecast($excludedFromForecast);
		$income->setStartDate($startDate);
		$income->setCreatedAt(date('Y-m-d H:i:s'));

		// The first occurrence from today, never before the start date. A
		// one-time income is its date, past or not. startDate anchors
		// weekly/biweekly schedules: occurrences fall on startDate +
		// n*interval, so week parity comes from the user's first payment
		// date, not from the week the entry was created in (#363)
		$income->setNextExpectedDate($this->firstOccurrence($income, $this->today($userId)));

		$income = $this->mapper->insert($income);
		if ($this->autoShareService !== null) {
			$this->autoShareService->autoShareNewEntity($userId, ShareItem::TYPE_RECURRING_INCOME, $income->getId());
		}
		return $income;
	}

	public function update(int $id, string $userId, array $updates): RecurringIncome {
		$income = $this->find($id, $userId);
		$before = clone $income;
		$directDbUpdates = [];

		// The schedule changed only if one of its fields did, not because the
		// form sent them back unchanged: recalculating on every edit dropped
		// an overdue payment and undid a skip
		$scheduleChanged = false;
		foreach (['frequency', 'expectedDay', 'expectedMonth', 'startDate'] as $key) {
			if (array_key_exists($key, $updates) && $updates[$key] != $income->{'get' . ucfirst($key)}()) {
				$scheduleChanged = true;
			}
		}

		foreach ($updates as $key => $value) {
			// Special handling for null values - use direct DB update to bypass Entity change detection
			if ($value === null) {
				// Convert camelCase to snake_case for database column names
				$columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
				$directDbUpdates[$columnName] = null;
				if (property_exists($income, $key)) {
					$setter = 'set' . ucfirst($key);
					$income->$setter(null);
				}
				continue;
			}

			if (property_exists($income, $key)) {
				$setter = 'set' . ucfirst($key);
				$income->$setter($value);
			}
		}

		if ($scheduleChanged) {
			$this->validateSchedule($income->getFrequency(), $income->getStartDate());
			// A settled income stays settled; an active one moves its pending
			// occurrence within its own month (or week) under the new schedule
			if ($income->getIsActive()) {
				$income->setNextExpectedDate($this->rescheduled($before, $income, $this->today($userId)));
				// Undoing an earlier receipt would restore a date of the old schedule
				if ($income->canUndoReceived()) {
					$income->setReceivedUndoState(null);
					$directDbUpdates['received_undo_state'] = null;
				}
			}
		}

		// Apply direct database updates for null values first
		if (!empty($directDbUpdates)) {
			$this->mapper->updateFields($id, $userId, $directDbUpdates);
		}

		// Save any non-null changes
		$this->mapper->update($income);

		// Reload from database to ensure we return the actual saved state
		return $this->find($id, $userId);
	}

	/**
	 * Book a recurring income that is due, without anyone marking it.
	 *
	 * Every occurrence due by today is booked once, on its expected date, so
	 * a run after a gap catches up rather than booking the oldest and
	 * jumping past the rest. A one-time income is completed after it. When it
	 * can't book (no account, or one its owner can no longer write to) auto-
	 * create switches itself off, so the job doesn't fail and notify every
	 * six hours forever.
	 *
	 * @return array ['success' => bool, 'message' => string, 'income' => ?RecurringIncome,
	 *               'disabled' => bool whether a failure switched auto-create off; false when nothing was due]
	 */
	public function processAutoCreate(int $incomeId, string $userId): array {
		try {
			$income = $this->find($incomeId, $userId);
		} catch (\Exception $e) {
			$this->logger->warning("Auto-create failed for income {$incomeId}: {$e->getMessage()}");
			return ['success' => false, 'message' => $e->getMessage(), 'disabled' => false];
		}
		if (!$income->getAutoCreateEnabled() || !$income->getIsActive()) {
			return ['success' => false, 'message' => 'Auto-create not enabled', 'disabled' => false];
		}

		$today = $this->today($userId);
		$booked = 0;
		try {
			if (!$income->getAccountId()) {
				throw new \InvalidArgumentException($this->l->t('No account set for income'));
			}
			$this->requireWritableAccount($income);

			while ($booked < self::MAX_AUTO_CREATE_CATCH_UP
				&& $income->getIsActive()
				&& $income->getNextExpectedDate() !== null
				&& $income->getNextExpectedDate() <= $today) {
				$date = $income->getNextExpectedDate();
				$this->transactionService->createFromIncome($userId, $income, $date, 'cleared');
				$this->settle($income, $date);
				$income->setLastReceivedDate($date);
				$income->setReceivedUndoState(null);
				$income = $this->mapper->update($income);
				$booked++;
			}
		} catch (\Exception $e) {
			$this->logger->warning("Auto-create failed for income {$incomeId}: {$e->getMessage()}");
			// Whatever booked before the failure stays booked and settled
			$income->setAutoCreateEnabled(false);
			$this->mapper->update($income);
			return ['success' => false, 'message' => $e->getMessage(), 'income' => $income, 'disabled' => true];
		}

		if ($booked === 0) {
			// Another run booked it first: nothing to report
			return ['success' => false, 'message' => 'Nothing due', 'income' => $income, 'disabled' => false];
		}
		return ['success' => true, 'income' => $income, 'count' => $booked];
	}

	/**
	 * Mark the income's next expected occurrence as received.
	 *
	 * Settles exactly that one occurrence and moves on to the next. The money
	 * is dated the day it arrived: dated on the stored expected date, a stale
	 * date filed September's payment under August, and an early receipt was
	 * booked in the future while the date stayed put (#399).
	 *
	 * @param string|null $expectedDate The occurrence the page showed. A
	 *                                  stale tab or a second click names one already settled and is
	 *                                  refused, instead of booking the money twice.
	 * @throws \InvalidArgumentException
	 */
	public function markReceived(int $id, string $userId, ?string $receivedDate = null, bool $createTransaction = false, ?string $expectedDate = null): RecurringIncome {
		$income = $this->find($id, $userId);

		if (!$income->getIsActive() || $income->getNextExpectedDate() === null) {
			throw new \InvalidArgumentException($this->l->t('This income has no payment to receive'));
		}
		if ($expectedDate !== null && $expectedDate !== '' && $expectedDate !== $income->getNextExpectedDate()) {
			throw new \InvalidArgumentException($this->l->t('This payment was already recorded. Reload the page to see the next one.'));
		}
		$willBook = $createTransaction && $income->getAccountId() !== null;
		if ($willBook) {
			$this->requireWritableAccount($income);
		}

		$received = $receivedDate ?? $this->today($userId);
		$snapshot = [
			'nextExpectedDate' => $income->getNextExpectedDate(),
			'lastReceivedDate' => $income->getLastReceivedDate(),
			'isActive' => $income->getIsActive(),
			'startDate' => $income->getStartDate(),
			'transactionIds' => [],
		];

		$this->settle($income, $income->getNextExpectedDate());
		$income->setLastReceivedDate($received);
		$income = $this->mapper->update($income);

		if ($willBook) {
			try {
				$transaction = $this->transactionService->createFromIncome($userId, $income, $received, 'cleared');
				$snapshot['transactionIds'][] = $transaction->getId();
			} catch (\Exception $e) {
				$this->logger->warning("Failed to create transaction for income {$id}: {$e->getMessage()}");
			}
		}

		$income->setReceivedUndoState(json_encode($snapshot));
		return $this->mapper->update($income);
	}

	/**
	 * Revert the last Mark Received: the dates and active state go back and
	 * the credit it booked is removed.
	 *
	 * @throws \InvalidArgumentException when there is nothing to revert
	 */
	public function markUnreceived(int $id, string $userId): RecurringIncome {
		$income = $this->find($id, $userId);
		$raw = $income->getReceivedUndoState();
		$snapshot = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		if (!is_array($snapshot) || !array_key_exists('nextExpectedDate', $snapshot)) {
			throw new \InvalidArgumentException($this->l->t('This income has no recorded receipt to undo'));
		}

		$ids = is_array($snapshot['transactionIds'] ?? null) ? $snapshot['transactionIds'] : [];
		if ($ids !== []) {
			$this->requireWritableAccount($income);
		}
		foreach ($ids as $transactionId) {
			// Only this income's own credit: the snapshot holds ids, and
			// after a backup restore they can name anyone's rows
			$row = $this->transactionService->findTransaction((int)$transactionId);
			if ($row === null || $row->getAccountId() !== $income->getAccountId() || $row->getType() !== 'credit'
				|| !str_starts_with((string)$row->getNotes(), 'Auto-generated from income:')) {
				continue;
			}
			try {
				$this->transactionService->deleteAsAccountOwner((int)$transactionId);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to delete transaction {$transactionId} while undoing a receipt of income {$id}: {$e->getMessage()}");
			}
		}

		$income->setNextExpectedDate($snapshot['nextExpectedDate']);
		$income->setLastReceivedDate($snapshot['lastReceivedDate'] ?? null);
		$income->setIsActive((bool)($snapshot['isActive'] ?? true));
		if (array_key_exists('startDate', $snapshot)) {
			$income->setStartDate($snapshot['startDate']);
		}
		$income->setReceivedUndoState(null);
		// Restored values may be null, which the entity's change tracking skips
		$this->writeFields($id, $userId, [
			'next_expected_date' => $income->getNextExpectedDate(),
			'last_received_date' => $income->getLastReceivedDate(),
			'start_date' => $income->getStartDate(),
			'received_undo_state' => null,
		]);
		return $this->mapper->update($income);
	}

	/**
	 * Close the occurrence on $occurrence and move to the one after it, or
	 * complete a one-time income (keeping its date in startDate, where a
	 * one-time income's date lives).
	 */
	private function settle(RecurringIncome $income, string $occurrence): void {
		if ($income->getFrequency() === 'one-time') {
			if ($income->getStartDate() === null || $income->getStartDate() === '') {
				$income->setStartDate($occurrence);
			}
			$income->setIsActive(false);
			$income->setNextExpectedDate(null);
			return;
		}
		$income->setNextExpectedDate($this->frequencyCalculator->calculateNextDueDate(
			$income->getFrequency(),
			$income->getExpectedDay(),
			$income->getExpectedMonth(),
			$occurrence,
			null,
			true,
			$income->getStartDate()
		));
	}

	/** The first occurrence on or after $today, never before the start date */
	private function firstOccurrence(RecurringIncome $income, string $today): ?string {
		if ($income->getFrequency() === 'one-time') {
			return $income->getStartDate();
		}
		return $this->frequencyCalculator->occurrenceOnOrAfter(
			$income->getFrequency(), $income->getExpectedDay(), $income->getExpectedMonth(),
			$today, null, $income->getStartDate()
		);
	}

	/**
	 * The pending occurrence after a schedule change: the same month's (or
	 * week's) occurrence under the new schedule, so moving pay day from the
	 * 3rd to the 25th makes October's payment the 25th rather than reviving
	 * September's or skipping October's.
	 */
	private function rescheduled(RecurringIncome $before, RecurringIncome $after, string $today): ?string {
		if ($after->getFrequency() === 'one-time') {
			return $after->getStartDate();
		}
		$pending = $before->getNextExpectedDate();
		if ($pending === null || $before->getFrequency() === 'one-time') {
			return $this->firstOccurrence($after, $today);
		}
		$schedule = fn (RecurringIncome $i): array => [
			'frequency' => $i->getFrequency(), 'dueDay' => $i->getExpectedDay(), 'dueMonth' => $i->getExpectedMonth(),
			'pattern' => null, 'anchor' => $i->getStartDate() ?: null,
		];
		return $this->frequencyCalculator->reschedule($schedule($before), $pending, $schedule($after))
			?? $this->firstOccurrence($after, $today);
	}

	/**
	 * One-time income needs its date, and income has no pattern to run a
	 * custom schedule on.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateSchedule(string $frequency, ?string $startDate): void {
		if ($frequency === 'custom') {
			throw new \InvalidArgumentException($this->l->t('Recurring income cannot use a custom schedule'));
		}
		if ($frequency === 'one-time' && ($startDate === null || $startDate === '')) {
			throw new \InvalidArgumentException($this->l->t('A one-time income needs the date it is expected'));
		}
	}

	/**
	 * Refuse to book into an account the income's owner can no longer write
	 * to: shared with them once, since revoked, left or cut to read.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireWritableAccount(RecurringIncome $income): void {
		if ($this->granularShareService !== null && $income->getAccountId() !== null
			&& !$this->granularShareService->canWrite($income->getUserId(), ShareItem::TYPE_ACCOUNT, (int)$income->getAccountId())) {
			throw new \InvalidArgumentException($this->l->t('This income uses an account you can no longer change. Edit it and choose another account.'));
		}
	}

	/** Columns written straight to the row, nulls included (see RecurringIncomeMapper::updateFields()) */
	private function writeFields(int $id, string $userId, array $fields): void {
		/** @var RecurringIncomeMapper $mapper */
		$mapper = $this->mapper;
		$mapper->updateFields($id, $userId, $fields);
	}

	private function today(string $userId): string {
		return $this->userClock !== null ? $this->userClock->today($userId) : date('Y-m-d');
	}

	/**
	 * Skip the next expected payment, advancing one cycle without recording a
	 * receipt or creating a transaction (#396). The last received date is left
	 * alone so the entry doesn't read as received.
	 *
	 * @return array{income: RecurringIncome, previousNextExpectedDate: string}
	 */
	public function skipPayment(int $id, string $userId): array {
		$income = $this->find($id, $userId);

		if ($income->getFrequency() === 'one-time') {
			throw new \InvalidArgumentException($this->l->t('Cannot skip a one-time income'));
		}
		if (!$income->getIsActive()) {
			throw new \InvalidArgumentException($this->l->t('Cannot skip an inactive income'));
		}

		$previousNextExpectedDate = $income->getNextExpectedDate();
		if ($previousNextExpectedDate === null) {
			throw new \InvalidArgumentException($this->l->t('This income has no expected date to skip'));
		}

		$nextExpected = $this->frequencyCalculator->calculateNextDueDate(
			$income->getFrequency(),
			$income->getExpectedDay(),
			$income->getExpectedMonth(),
			$previousNextExpectedDate,
			null,
			true, // forceAdvance: always one cycle on, even if not yet due
			$income->getStartDate()
		);
		$income->setNextExpectedDate($nextExpected);
		// Undoing an earlier receipt now would bring back a pre-skip date
		$income->setReceivedUndoState(null);
		$income = $this->mapper->update($income);

		return [
			'income' => $income,
			'previousNextExpectedDate' => $previousNextExpectedDate,
		];
	}

	/**
	 * Undo a skip by putting back the expected date it moved past (#396).
	 */
	public function undoSkip(int $id, string $userId, string $previousNextExpectedDate): RecurringIncome {
		$income = $this->find($id, $userId);
		$income->setNextExpectedDate($previousNextExpectedDate);
		return $this->mapper->update($income);
	}

	/**
	 * Get monthly summary of recurring income.
	 */
	public function getMonthlySummary(string $userId, ?int $accountId = null): array {
		// Held to one account for a dashboard tile set to it
		$inAccount = static fn (RecurringIncome $i) => $accountId === null || $i->getAccountId() === $accountId;
		$incomes = array_values(array_filter($this->findActive($userId), $inAccount));
		// Each income is in its account's currency; the totals are in the
		// user's base one, as the Bills page's are. Adding them as they
		// were put euros and dollars into a pound total.
		$baseCurrency = $this->baseCurrency($userId);
		$currencies = $this->accountCurrencies(array_map(fn (RecurringIncome $i) => $i->getAccountId(), $incomes));
		$totalMonthly = 0.0;
		$expectedThisMonth = 0;
		$receivedThisMonth = 0;
		$byFrequency = [];

		// The user's month: on the server's UTC date the cards still counted
		// last month for the first hours of a month east of UTC
		$thisMonth = new \DateTimeImmutable($this->today($userId));
		$startOfMonth = $thisMonth->format('Y-m-01');
		$endOfMonth = $thisMonth->format('Y-m-t');

		foreach ($incomes as $income) {
			$monthlyEquiv = $this->toBase(
				$this->getMonthlyEquivalent($income),
				$currencies[$income->getAccountId() ?? 0] ?? $baseCurrency,
				$baseCurrency,
				$userId
			);
			$totalMonthly += $monthlyEquiv;

			$freq = $income->getFrequency();
			if (!isset($byFrequency[$freq])) {
				$byFrequency[$freq] = [
					'count' => 0,
					'totalMonthly' => 0.0,
				];
			}
			$byFrequency[$freq]['count']++;
			$byFrequency[$freq]['totalMonthly'] += $monthlyEquiv;

			$nextExpected = $income->getNextExpectedDate();
			if ($nextExpected && $nextExpected >= $startOfMonth && $nextExpected <= $endOfMonth) {
				$expectedThisMonth++;
			}
		}

		// Every income, not just active ones: receiving a one-time income
		// completes it, which left it out of the count the moment it arrived
		foreach (array_filter($this->findAll($userId), $inAccount) as $income) {
			$lastReceived = $income->getLastReceivedDate();
			if ($lastReceived && $lastReceived >= $startOfMonth && $lastReceived <= $endOfMonth) {
				$receivedThisMonth++;
			}
		}

		return [
			'activeCount' => count($incomes),
			'expectedThisMonth' => $expectedThisMonth,
			'receivedThisMonth' => $receivedThisMonth,
			'monthlyTotal' => round($totalMonthly, 2),
			'totalCount' => count($incomes),
			'totalMonthly' => round($totalMonthly, 2),
			'totalYearly' => round($totalMonthly * 12, 2),
			'byFrequency' => $byFrequency,
			'baseCurrency' => $baseCurrency,
		];
	}

	/**
	 * Set each income's currency: its account's, whoever owns the account,
	 * or the user's base currency when it has none.
	 *
	 * @param RecurringIncome[] $incomes
	 * @return RecurringIncome[]
	 */
	public function enrichWithCurrency(array $incomes, string $userId): array {
		$baseCurrency = $this->baseCurrency($userId);
		$currencies = $this->accountCurrencies(array_map(fn (RecurringIncome $i) => $i->getAccountId(), $incomes));
		foreach ($incomes as $income) {
			$income->setCurrency($currencies[$income->getAccountId() ?? 0] ?? $baseCurrency);
		}
		return $incomes;
	}

	/**
	 * enrichWithCurrency() for income other people shared: serialized rows
	 * carrying their owner's userId, priced as the owner sees them.
	 *
	 * @param array[] $rows
	 * @return array[]
	 */
	public function enrichSharedWithCurrency(array $rows): array {
		$currencies = $this->accountCurrencies(array_column($rows, 'accountId'));
		$bases = [];
		foreach ($rows as &$row) {
			$owner = (string)($row['userId'] ?? '');
			$bases[$owner] ??= $this->baseCurrency($owner);
			$row['currency'] = $currencies[(int)($row['accountId'] ?? 0)] ?? $bases[$owner];
		}
		unset($row);
		return $rows;
	}

	/**
	 * Account id => currency for the accounts named, whoever owns them: an
	 * income can be paid into an account another user shared.
	 *
	 * @param array<int|null> $accountIds
	 * @return array<int, string>
	 */
	private function accountCurrencies(array $accountIds): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', array_filter($accountIds, fn ($id) => $id !== null)))));
		if ($ids === [] || $this->accountMapper === null) {
			return [];
		}
		$map = [];
		foreach ($this->accountMapper->findByIds($ids) as $account) {
			if ($account->getCurrency()) {
				$map[$account->getId()] = $account->getCurrency();
			}
		}
		return $map;
	}

	private function baseCurrency(string $userId): ?string {
		return $this->currencyConversion?->getBaseCurrency($userId);
	}

	private function toBase(float $amount, ?string $from, ?string $base, string $userId): float {
		if ($this->currencyConversion === null || $from === null || $base === null || $from === $base) {
			return $amount;
		}
		return $this->currencyConversion->convertToBaseFloat($amount, $from, $userId);
	}

	/**
	 * Get income expected for a specific month.
	 */
	public function getIncomeForMonth(string $userId, int $year, int $month): array {
		$startDate = sprintf('%04d-%02d-01', $year, $month);
		$endDate = date('Y-m-t', strtotime($startDate));

		$expected = $this->mapper->findExpectedInRange($userId, $startDate, $endDate);

		$total = 0.0;
		foreach ($expected as $income) {
			$total += $income->getAmount();
		}

		return [
			'incomes' => $expected,
			'total' => round($total, 2),
			'count' => count($expected),
		];
	}

	/**
	 * Convert any income frequency to monthly equivalent. Delegates to the
	 * shared FrequencyCalculator (this map previously halved semi-monthly
	 * income and counted one-time income at full value every month).
	 */
	private function getMonthlyEquivalent(RecurringIncome $income): float {
		if ($income->getFrequency() === 'one-time') {
			// Not a recurring monthly commitment — counting it monthly until
			// received inflated the summary (matches RecurringBudgetService)
			return 0.0;
		}
		return $this->frequencyCalculator->getMonthlyEquivalentFromValues(
			$income->getAmount(),
			$income->getFrequency()
		);
	}

	/**
	 * Auto-detect recurring income from transaction history.
	 */
	public function detectRecurringIncome(string $userId, int $months = 6, bool $debug = false): array {
		return $this->recurringDetector->detectRecurringIncome($userId, $months, $debug);
	}

	/**
	 * Create recurring income entries from detected patterns.
	 *
	 * @throws \InvalidArgumentException when an item has no name
	 */
	public function createFromDetected(string $userId, array $detected): array {
		// Every item needs a name before any is created. `suggestedName ??
		// description` kept a blank suggested name and created nameless income
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
			$income = $this->create(
				$userId,
				$names[$i],
				$item['amount'],
				$item['frequency'],
				$item['expectedDay'] ?? null,
				// The schedule the payments showed: the month a quarterly or
				// yearly income arrives in, and the payment weekly pay counts from
				isset($item['expectedMonth']) ? (int)$item['expectedMonth'] : null,
				$item['categoryId'] ?? null,
				$item['accountId'] ?? null,
				$item['source'] ?? null,
				$item['autoDetectPattern'] ?? null,
				startDate: isset($item['startDate']) && $item['startDate'] !== '' ? (string)$item['startDate'] : null,
			);
			$created[] = $income;
		}

		return $created;
	}

	/**
	 * Mark recurring income received from credits an import or bank sync
	 * just brought in.
	 *
	 * Nothing matched imported income: it stayed expected, and Mark Received
	 * or auto-create then booked a second credit beside the bank's. A credit
	 * now pays the income whose auto-detect pattern it carries, when it is
	 * within 20% of the amount, in the income's account and near its next
	 * expected date. That payment is settled, dated the row's own date, with
	 * nothing more booked. When auto-create already booked the payment, the
	 * bank's row takes the place of that credit instead.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $transactions
	 * @return int how many payments were matched
	 */
	public function autoMatchReceivedFromImport(string $userId, array $transactions): int {
		$incomes = array_filter(
			$this->findActive($userId),
			fn (RecurringIncome $income) => !empty($income->getAutoDetectPattern())
				&& $income->getNextExpectedDate() !== null
		);
		if ($incomes === []) {
			return 0;
		}

		// Oldest first, whatever order the statement lists them in: the
		// bank's row of a payment already booked replaces it before a later
		// row is taken for the next payment (newest-first files left it)
		usort($transactions, fn ($a, $b) => [(string)$a->getDate(), (int)$a->getId()] <=> [(string)$b->getDate(), (int)$b->getId()]);

		$matched = 0;
		foreach ($transactions as $transaction) {
			if ($transaction->getType() !== 'credit' || ($transaction->getStatus() ?? 'cleared') === 'scheduled'
				|| $transaction->getBillId() !== null || $transaction->getLinkedTransactionId() !== null) {
				continue;
			}
			foreach ($incomes as $key => $income) {
				if (!$this->creditLooksLikeIncome($income, $transaction)) {
					continue;
				}
				// The bill match runs first and may have taken it as a
				// transfer's arrival since it was imported
				$current = $this->transactionService->findTransaction($transaction->getId());
				if ($current === null || $current->getBillId() !== null || $current->getLinkedTransactionId() !== null
					|| $current->getPensionContribId() !== null) {
					break;
				}
				try {
					if ($this->replaceGeneratedCredit($income, $transaction)) {
						$matched++;
						break;
					}
					if (!$this->withinExpectedWindow($income, $transaction->getDate(), (string)$income->getNextExpectedDate())) {
						continue;
					}
					$incomes[$key] = $this->markReceived(
						$income->getId(), $income->getUserId(), $transaction->getDate(), false, $income->getNextExpectedDate()
					);
					if (!$incomes[$key]->getIsActive()) {
						unset($incomes[$key]);
					}
					$matched++;
				} catch (\Exception $e) {
					$this->logger->warning("Matching imported transaction {$transaction->getId()} to income {$income->getId()} failed: {$e->getMessage()}");
				}
				break; // one income per transaction
			}
		}
		return $matched;
	}

	/** Pattern, amount within 20% and the income's account: everything but the date */
	private function creditLooksLikeIncome(RecurringIncome $income, \OCA\Budget\Db\Transaction $transaction): bool {
		$pattern = (string)$income->getAutoDetectPattern();
		$haystack = ($transaction->getDescription() ?? '') . ' ' . ($transaction->getVendor() ?? '');
		if ($pattern === '' || stripos($haystack, $pattern) === false) {
			return false;
		}
		$amount = (float)$income->getAmount();
		if ($amount <= 0 || abs((float)$transaction->getAmount() - $amount) > $amount * 0.2) {
			return false;
		}
		return $income->getAccountId() === null || $income->getAccountId() === $transaction->getAccountId();
	}

	/** Roughly half an interval either side of an expected date, at most two weeks */
	private function withinExpectedWindow(RecurringIncome $income, string $date, string $expected): bool {
		return abs((strtotime($date) - strtotime($expected)) / 86400) <= self::expectedWindowDays($income->getFrequency());
	}

	private static function expectedWindowDays(?string $frequency): int {
		return match ($frequency) {
			'daily' => 1,
			'weekly' => 3,
			'biweekly' => 6,
			'semi-monthly' => 7,
			default => 15,
		};
	}

	/**
	 * Put the bank's row in place of a credit auto-create (or Mark Received)
	 * already booked for one of the income's payments: the app's credit goes,
	 * the bank's stays, and the income isn't received a second time. What
	 * the user added to the app's credit (a split with a contact, a receipt,
	 * tags), and its category, move to the bank's row.
	 *
	 * The credit is the app's one nearest the bank row's own date, within
	 * the income's window of it. Only the last payment's credit used to be
	 * looked for, so a statement bringing several weeks of auto-created
	 * wages, or listing the newest first, left the others beside the bank's.
	 */
	private function replaceGeneratedCredit(RecurringIncome $income, \OCA\Budget\Db\Transaction $imported): bool {
		if ($income->getAccountId() === null) {
			return false;
		}
		$days = self::expectedWindowDays($income->getFrequency());
		$on = new \DateTimeImmutable($imported->getDate());
		$prefix = 'Auto-generated from income: ' . $income->getName();
		$nearest = null;
		$rank = null;
		$candidates = $this->transactionService->findGeneratedIncomeCredits(
			(int)$income->getAccountId(),
			$on->modify("-{$days} days")->format('Y-m-d'),
			$on->modify("+{$days} days")->format('Y-m-d')
		);
		foreach ($candidates as $generated) {
			$amountOff = abs((float)$generated->getAmount() - (float)$imported->getAmount());
			if ($generated->getId() === $imported->getId() || $generated->getReconciled()
				|| (string)$generated->getNotes() !== $prefix
				|| $amountOff > (float)$income->getAmount() * 0.2) {
				continue;
			}
			$thisRank = [abs(strtotime($generated->getDate()) - $on->getTimestamp()), $amountOff];
			if ($rank === null || $thisRank < $rank) {
				[$nearest, $rank] = [$generated, $thisRank];
			}
		}
		if ($nearest === null) {
			return false;
		}

		$this->transactionService->replaceBookedRow($nearest, $imported, $prefix);
		// Mark Unreceived can't take back a receipt whose credit is gone
		$raw = $income->getReceivedUndoState();
		$snapshot = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		$ids = is_array($snapshot) && is_array($snapshot['transactionIds'] ?? null) ? array_map('intval', $snapshot['transactionIds']) : [];
		if (in_array($nearest->getId(), $ids, true)) {
			$income->setReceivedUndoState(null);
			$this->writeFields($income->getId(), $income->getUserId(), ['received_undo_state' => null]);
		}
		return true;
	}

	/**
	 * Check if a transaction matches any income's auto-detect pattern.
	 */
	public function matchTransactionToIncome(string $userId, string $description, float $amount): ?RecurringIncome {
		$incomes = $this->findActive($userId);

		foreach ($incomes as $income) {
			$pattern = $income->getAutoDetectPattern();
			if (empty($pattern)) {
				continue;
			}

			if (stripos($description, $pattern) !== false) {
				$incomeAmount = $income->getAmount();
				// Allow 20% variance for income (more forgiving than bills)
				if (abs($amount - $incomeAmount) <= $incomeAmount * 0.2) {
					return $income;
				}
			}
		}

		return null;
	}
}
