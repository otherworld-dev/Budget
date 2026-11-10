<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\ReportScope;
use OCA\Budget\Db\RunwayOneOffMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Enum\AccountType;
use OCA\Budget\Enum\Frequency;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\CurrencyTotals;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserClock;

/**
 * The runway as every surface shows it: the web tiles, the v1 API and the
 * alert all call get(), so they can never disagree. This gathers the
 * user's one runway setup and everything it needs (balances, rows, bills,
 * budgets, one-offs), turns the money into the base currency, and leaves
 * the maths to RunwayCalculator.
 */
class RunwayService {
	public const SETTING_ACCOUNT_IDS = 'runway_account_ids';
	public const SETTING_WINDOW_MONTHS = 'runway_window_months';
	public const SETTING_INCLUDE_SCHEDULED = 'runway_include_scheduled';

	private const DEFAULT_WINDOW_MONTHS = 3;
	/** The history chart and the longest window both reach back this far */
	private const LOOKBACK_MONTHS = 12;
	/** Converted amounts keep this scale until rounded to 2 places */
	private const SCALE = 6;

	/**
	 * Money the user can spend from, and the cards it's spent on: a card's
	 * balance is in the total, so paying it off is neutral. Investments,
	 * pensions, loans and mortgages are only counted if chosen.
	 */
	private const DEFAULT_TYPES = [
		AccountType::CHECKING,
		AccountType::SAVINGS,
		AccountType::CASH,
		AccountType::MONEY_MARKET,
		AccountType::CREDIT_CARD,
	];

	public function __construct(
		private RunwayCalculator $calculator,
		private AccountMapper $accountMapper,
		private TransactionMapper $transactionMapper,
		private TransactionSplitMapper $splitMapper,
		private BillMapper $billMapper,
		private RunwayOneOffMapper $oneOffMapper,
		private BudgetStatusService $budgetStatus,
		private CurrencyTotals $currencyTotals,
		private CurrencyConversionService $conversionService,
		private GranularShareService $granularShareService,
		private SettingService $settingService,
		private UserClock $clock,
	) {
	}

	/**
	 * The runway: RunwayCalculator::calculate()'s result, plus
	 *  - currency: what every amount is in
	 *  - total: today's total of the counted accounts (2 places)
	 *  - accounts: [{id, name, balance}] the counted accounts, balance today in `currency`
	 *  - skippedAccounts: [{id, name, reason: 'no_rate'}] chosen but left out, having no rate into `currency`
	 *  - oneOffs: [{id, description, amount, date, past}] all of them, past ones too
	 *  - settings: {accountIds, windowMonths, includeScheduled, accountIdsAreDefault}
	 *    accountIds being the chosen accounts, skipped ones included
	 *
	 * @return array<string, mixed>
	 */
	public function get(string $userId): array {
		$today = $this->clock->today($userId);
		$settings = $this->settings($userId);
		$chosen = $this->chosenFrom($userId, $settings['accountIds']);

		$conversion = $this->currencyTotals->accountRates($chosen, $userId);
		$rates = $conversion['rates'];
		$counted = [];
		$skipped = [];
		foreach ($chosen as $account) {
			if (isset($rates[(int)$account->getId()])) {
				$counted[] = $account;
			} else {
				$skipped[] = ['id' => (int)$account->getId(), 'name' => (string)$account->getName(), 'reason' => 'no_rate'];
			}
		}
		$countedIds = array_map(static fn (Account $a) => (int)$a->getId(), $counted);

		$budgets = $this->budgetStatus->monthlyBaseBudgets($userId);
		$groups = $this->budgetGroups($userId, $budgets);

		[$accounts, $total] = $this->balances($counted, $rates, $today);
		[$rows, $historyRows, $futureRows] = $this->rows($countedIds, $rates, $today, $groups);
		$bills = $this->bills($userId, $rates, $countedIds, $groups);
		$futureRows = $this->withoutRecurringPreBookings($futureRows, $bills);
		$oneOffs = $this->oneOffs($userId);

		$result = $this->calculator->calculate([
			'today' => $today,
			'total' => $total,
			'chosenAccountIds' => $countedIds,
			'windowMonths' => $settings['windowMonths'],
			'firstActivityDate' => $this->transactionMapper->firstTransactionDate($countedIds),
			'rows' => $rows,
			'historyRows' => $historyRows,
			'futureRows' => $futureRows,
			'includeScheduled' => $settings['includeScheduled'],
			'bills' => $bills,
			'oneOffs' => $oneOffs,
			'budgets' => $budgets,
		]);

		return $result + [
			'currency' => $this->currency($conversion['currency'], $counted, $userId),
			'total' => $total,
			'accounts' => $accounts,
			'skippedAccounts' => $skipped,
			'oneOffs' => array_map(
				static fn (array $oneOff) => $oneOff + ['past' => $oneOff['date'] < $today],
				$oneOffs
			),
			'settings' => [
				'accountIds' => self::sortedIds($chosen),
				'windowMonths' => $settings['windowMonths'],
				'includeScheduled' => $settings['includeScheduled'],
				'accountIdsAreDefault' => $settings['accountIds'] === [],
			],
		];
	}

	/**
	 * The accounts the runway counts. With none chosen, the default set:
	 * the user's open spending accounts and cards (DEFAULT_TYPES), leaving
	 * out any flagged out of reports. Otherwise exactly the chosen ones the
	 * user can still see; one deleted or no longer shared just drops out.
	 *
	 * @return Account[]
	 */
	public function chosenAccounts(string $userId): array {
		return $this->chosenFrom($userId, $this->settings($userId)['accountIds']);
	}

	/**
	 * chosenAccounts() for the ids already read from the setup, sorted by
	 * name as the account list is.
	 *
	 * @param int[] $chosenIds empty for the default set
	 * @return Account[]
	 */
	private function chosenFrom(string $userId, array $chosenIds): array {
		$visible = array_map('intval', $this->granularShareService->getVisibleAccountIds($userId));

		if ($chosenIds !== []) {
			$accounts = $this->accountMapper->findByIds(array_values(array_intersect($chosenIds, $visible)));
		} else {
			$defaultTypes = array_map(static fn (AccountType $type) => $type->value, self::DEFAULT_TYPES);
			$accounts = array_values(array_filter(
				$this->accountMapper->findByIds($visible),
				static fn (Account $a) => in_array((string)$a->getType(), $defaultTypes, true)
					&& !($a->getClosed() ?? false)
					&& !($a->getExcludedFromReports() ?? false)
			));
		}

		usort($accounts, static fn (Account $a, Account $b) => [strtolower((string)$a->getName()), (int)$a->getId()]
			<=> [strtolower((string)$b->getName()), (int)$b->getId()]);
		return $accounts;
	}

	/**
	 * @param Account[] $accounts
	 * @return int[]
	 */
	private static function sortedIds(array $accounts): array {
		$ids = array_map(static fn (Account $a) => (int)$a->getId(), $accounts);
		sort($ids);
		return $ids;
	}

	/**
	 * The stored setup, each value falling back to its default when it is
	 * missing or doesn't read as expected (SettingController validates new
	 * values, but a restored or older one isn't checked). accountIds is
	 * empty when the default set applies: unset, '', or '[]'. A restore
	 * leaves '[]' when none of the chosen accounts came back
	 * (MigrationService drops ids it can't map), so the runway falls back
	 * to the default set rather than counting nothing.
	 *
	 * @return array{accountIds: int[], windowMonths: int, includeScheduled: bool}
	 */
	private function settings(string $userId): array {
		$ids = json_decode((string)$this->settingService->get($userId, self::SETTING_ACCOUNT_IDS), true);
		$accountIds = [];
		if (is_array($ids) && array_is_list($ids)) {
			foreach ($ids as $id) {
				if (is_int($id) && $id > 0) {
					$accountIds[] = $id;
				}
			}
		}

		$window = trim((string)$this->settingService->get($userId, self::SETTING_WINDOW_MONTHS));
		$windowMonths = ctype_digit($window) && (int)$window >= 1 && (int)$window <= 12
			? (int)$window
			: self::DEFAULT_WINDOW_MONTHS;

		// On unless switched off
		$include = $this->settingService->get($userId, self::SETTING_INCLUDE_SCHEDULED);

		return [
			'accountIds' => array_values(array_unique($accountIds)),
			'windowMonths' => $windowMonths,
			'includeScheduled' => !in_array($include, ['false', '0'], true),
		];
	}

	/**
	 * Each counted account's balance as of today (stored balance less the
	 * rows dated after today, as ForecastService works it out) in the base
	 * currency, and their total. The total adds the rounded balances, so
	 * the accounts listed add up to it exactly.
	 *
	 * @param Account[] $accounts
	 * @param array<int, string> $rates
	 * @return array{0: list<array{id: int, name: string, balance: string}>, 1: string}
	 */
	private function balances(array $accounts, array $rates, string $today): array {
		$future = $accounts === [] ? [] : $this->transactionMapper->getNetChangeAfterDateForAccounts(
			array_map(static fn (Account $a) => (int)$a->getId(), $accounts),
			$today
		);

		$listed = [];
		$total = '0.00';
		foreach ($accounts as $account) {
			$id = (int)$account->getId();
			$balance = MoneyCalculator::subtract((float)($account->getBalance() ?? 0), (float)($future[$id] ?? 0), self::SCALE);
			$balance = $this->inBase($balance, $rates[$id]);
			$listed[] = ['id' => $id, 'name' => (string)$account->getName(), 'balance' => $balance];
			$total = MoneyCalculator::add($total, $balance, 2);
		}
		return [$listed, $total];
	}

	/**
	 * The counted accounts' rows from the start of the month a year back
	 * (the longest window and the history chart both need that far) with
	 * no end, so future rows come too, as the calculator's three lists:
	 * rows before this month (the window is cut from them), every row up to
	 * today (history) and rows after today (future).
	 *
	 * @param int[] $accountIds
	 * @param array<int, string> $rates
	 * @param array<int, int> $groups budgetGroups()
	 * @return array{0: RunwayRow[], 1: RunwayRow[], 2: RunwayRow[]}
	 */
	private function rows(array $accountIds, array $rates, string $today, array $groups): array {
		if ($accountIds === []) {
			return [[], [], []];
		}
		$monthStart = (new \DateTimeImmutable($today))->modify('first day of this month');
		$from = $monthStart->modify('-' . self::LOOKBACK_MONTHS . ' months')->format('Y-m-d');
		$data = $this->transactionMapper->findRunwayRows($accountIds, $from, null);

		$splitIds = [];
		foreach ($data as $row) {
			if ($row['is_split']) {
				$splitIds[] = (int)$row['id'];
			}
		}
		$splits = $splitIds === [] ? [] : $this->splitMapper->findByTransactionIds($splitIds);

		$windowEnd = $monthStart->format('Y-m-d');
		$rows = [];
		$history = [];
		$future = [];
		foreach ($data as $row) {
			$runwayRow = $this->runwayRow($row, $rates[(int)$row['account_id']] ?? '1', $splits[(int)$row['id']] ?? [], $groups);
			if ($runwayRow->date > $today) {
				$future[] = $runwayRow;
				continue;
			}
			$history[] = $runwayRow;
			if ($runwayRow->date < $windowEnd) {
				$rows[] = $runwayRow;
			}
		}
		return [$rows, $history, $future];
	}

	/**
	 * One mapper row as the calculator takes it. Split parts are stored
	 * the way the parent's amount is, as a share of it before its sign:
	 * a part of a debit is money out, so it takes the parent's sign.
	 * Categories are given as their budget group (budgetGroups()).
	 *
	 * @param array<string, mixed> $row TransactionMapper::findRunwayRows()
	 * @param list<array{categoryId: ?int, amount: float|string}> $parts
	 * @param array<int, int> $groups
	 */
	private function runwayRow(array $row, string $rate, array $parts, array $groups): RunwayRow {
		$amount = $this->inBase($row['amount'], $rate);
		$isDebit = MoneyCalculator::compare(ReportScope::sqlMoney($row['amount']), '0', self::SCALE) < 0;

		$splits = [];
		if ($row['is_split']) {
			foreach ($parts as $part) {
				$partAmount = ReportScope::sqlMoney($part['amount'] ?? null);
				$splits[] = [
					'categoryId' => self::group(isset($part['categoryId']) ? (int)$part['categoryId'] : null, $groups),
					'amount' => $this->inBase($isDebit ? MoneyCalculator::negate($partAmount, self::SCALE) : $partAmount, $rate),
				];
			}
		}

		return new RunwayRow(
			(int)$row['id'],
			substr((string)$row['date'], 0, 10),
			$amount,
			self::group($row['category_id'] !== null ? (int)$row['category_id'] : null, $groups),
			// Rows older than the status column hold NULL: they were never scheduled
			(string)($row['status'] ?? 'cleared'),
			(bool)$row['excluded_from_forecast'],
			$row['pension_contrib_id'] !== null,
			$row['linked_account_id'] !== null ? (int)$row['linked_account_id'] : null,
			$row['bill_id'] !== null ? (int)$row['bill_id'] : null,
			$splits,
			(string)($row['description'] ?? ''),
		);
	}

	/**
	 * The user's active bills the calculator smooths and checks pre-bookings
	 * against: paid from a counted account, not a transfer (money moved
	 * between accounts isn't spending), and a fixed amount. A statement or
	 * balance amount comes from the card's ledger at payment time, so it
	 * has no monthly figure. A frequency that doesn't parse is skipped.
	 * The category is the bill's budget group (budgetGroups()).
	 *
	 * @param array<int, string> $rates
	 * @param int[] $accountIds
	 * @param array<int, int> $groups
	 * @return array<int, RunwayBill> by bill id
	 */
	private function bills(string $userId, array $rates, array $accountIds, array $groups): array {
		if ($accountIds === []) {
			return [];
		}
		$counted = array_flip($accountIds);
		$bills = [];
		foreach ($this->billMapper->findActive($userId) as $bill) {
			$accountId = $bill->getAccountId();
			$amountType = $bill->getAmountType();
			if (($bill->getIsTransfer() ?? false)
				|| ($amountType !== null && $amountType !== 'fixed')
				|| $accountId === null || !isset($counted[(int)$accountId])) {
				continue;
			}
			$frequency = Frequency::tryFromString((string)$bill->getFrequency());
			if ($frequency === null) {
				continue;
			}
			$monthly = $frequency->toMonthlyAmount(abs((float)$bill->getAmount()));
			$bills[(int)$bill->getId()] = new RunwayBill(
				(int)$bill->getId(),
				$frequency->value,
				$this->inBase($monthly, $rates[(int)$accountId]),
				self::group($bill->getCategoryId() !== null ? (int)$bill->getCategoryId() : null, $groups),
			);
		}
		return $bills;
	}

	/**
	 * Future rows without the pre-bookings of any recurring bill. Such a
	 * bill is in the average already (as actual rows, or smoothed), so its
	 * pre-booked rows would count it twice. The calculator skips those of
	 * the bills it is given; this drops the rest: transfers, statement or
	 * balance amounts, frequencies that don't parse, another user's bill on
	 * a shared account. Only a one-time bill's row is applied (it is in no
	 * average), and so is one whose bill is gone, as nothing says it recurs.
	 * Bills are looked up by id, whoever owns them, only to read how often
	 * they come round.
	 *
	 * @param RunwayRow[] $futureRows
	 * @param array<int, RunwayBill> $bills the calculator's
	 * @return RunwayRow[]
	 */
	private function withoutRecurringPreBookings(array $futureRows, array $bills): array {
		$billIds = [];
		foreach ($futureRows as $row) {
			if ($row->billId !== null && !isset($bills[$row->billId])) {
				$billIds[$row->billId] = true;
			}
		}
		if ($billIds === []) {
			return $futureRows;
		}

		$recurring = [];
		foreach ($this->billMapper->findByIds(array_keys($billIds)) as $bill) {
			if (Frequency::tryFromString((string)$bill->getFrequency()) !== Frequency::ONE_TIME) {
				$recurring[(int)$bill->getId()] = true;
			}
		}
		return array_values(array_filter(
			$futureRows,
			static fn (RunwayRow $row) => $row->billId === null || !isset($recurring[$row->billId])
		));
	}

	/**
	 * Each category's budget group for the on-budget swap: itself when it
	 * has a budget, else its nearest ancestor with one, on the Budget page's
	 * tree. The page lets a parent's budget cover its unbudgeted children,
	 * so their spending must be swapped with the parent's; a child with its
	 * own budget stays its own group. Categories with no budgeted ancestor
	 * have no entry and keep their own id.
	 *
	 * @param array<int, string> $budgets monthlyBaseBudgets()
	 * @return array<int, int> category id => group id
	 */
	private function budgetGroups(string $userId, array $budgets): array {
		if ($budgets === []) {
			return [];
		}
		$parents = $this->budgetStatus->budgetParentMap($userId);
		$groups = [];
		foreach (array_keys($parents) as $id) {
			$seen = [];
			// $seen guards against a cycle in bad data
			for ($at = $id; $at !== null && !isset($seen[$at]); $at = $parents[$at] ?? null) {
				if (isset($budgets[$at])) {
					$groups[$id] = $at;
					break;
				}
				$seen[$at] = true;
			}
		}
		return $groups;
	}

	/**
	 * @param array<int, int> $groups
	 */
	private static function group(?int $categoryId, array $groups): ?int {
		return $categoryId === null ? null : ($groups[$categoryId] ?? $categoryId);
	}

	/**
	 * Every one-off, earliest first, amounts to 2 places. They have no
	 * account, so they are taken to be in the base currency already.
	 *
	 * @return list<array{id: int, description: string, amount: string, date: string}>
	 */
	private function oneOffs(string $userId): array {
		$oneOffs = [];
		foreach ($this->oneOffMapper->findAll($userId) as $oneOff) {
			$oneOffs[] = [
				'id' => (int)$oneOff->getId(),
				'description' => (string)$oneOff->getDescription(),
				'amount' => $this->inBase($oneOff->getAmount(), '1'),
				'date' => substr((string)$oneOff->getDate(), 0, 10),
			];
		}
		return $oneOffs;
	}

	/**
	 * The currency the amounts are in: the base currency when the accounts
	 * are converted, else the one they share, else (no accounts) the base.
	 *
	 * @param Account[] $counted
	 */
	private function currency(?string $converted, array $counted, string $userId): string {
		if ($converted !== null) {
			return $converted;
		}
		if ($counted !== []) {
			return strtoupper((string)($counted[0]->getCurrency() ?: 'USD'));
		}
		return strtoupper($this->conversionService->getBaseCurrency($userId));
	}

	/**
	 * An amount (as the database gives it: "-12.5", "-0.0", a float) times
	 * the account's rate, rounded half away from zero to 2 places.
	 */
	private function inBase(mixed $amount, string $rate): string {
		$value = MoneyCalculator::multiply(ReportScope::sqlMoney($amount), $rate, self::SCALE);
		// bcmath truncates, so add half a cent in the value's direction first
		$half = MoneyCalculator::compare($value, '0', self::SCALE) < 0 ? '-0.005' : '0.005';
		$rounded = MoneyCalculator::add($value, $half, 2);
		return MoneyCalculator::compare($rounded, '0') === 0 ? '0.00' : $rounded;
	}
}
