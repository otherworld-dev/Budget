<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;

/**
 * Weekly/monthly digest: one notification (and optionally one email)
 * summarizing budget status, balance movement, upcoming bills, goal
 * progress, anomalies and new recurring-bill suggestions.
 *
 * Content assembly is pure (buildDigest); delivery composes the existing
 * notification pipeline and BudgetMailService.
 */
class DigestService {
	/** Bills the email lists in each of its bills sections */
	private const LISTED_BILLS = 5;

	public function __construct(
		private BudgetAlertService $budgetAlertService,
		private BillService $billService,
		private GoalsService $goalsService,
		private AnomalyDetectionService $anomalyService,
		private BillSuggestionService $suggestionService,
		private AmountFormatter $amountFormatter,
		private SettingService $settingService,
		private BudgetMailService $mailService,
		private INotificationManager $notificationManager,
		private IFactory $l10nFactory,
		private \OCA\Budget\Db\TransactionMapper $transactionMapper,
		private ?UserClock $userClock = null,
		private ?ReportAggregator $reportAggregator = null,
	) {
	}

	/**
	 * Income and spending over the period, as the Cash Flow report counts
	 * them for the same dates and accounts: transfers between the user's own
	 * accounts left out, as money moved rather than earned or spent, and
	 * accounts in other currencies converted to the base currency. Adding up
	 * each account's credits and debits counted both legs of a transfer (a
	 * move to savings was income and spending at once) and added euros to
	 * pounds.
	 *
	 * @return array{income: float, expenses: float}
	 */
	private function periodTotals(string $userId, string $start, string $end): array {
		if ($this->reportAggregator !== null) {
			$totals = $this->reportAggregator->getCashFlowReport($userId, null, $start, $end)['totals'];
			return ['income' => (float)($totals['income'] ?? 0), 'expenses' => (float)($totals['expenses'] ?? 0)];
		}

		$totals = ['income' => 0.0, 'expenses' => 0.0];
		foreach ($this->transactionMapper->getAccountSummaries($userId, $start, $end) as $summary) {
			$totals['income'] += (float)($summary['income'] ?? 0);
			$totals['expenses'] += (float)($summary['expenses'] ?? 0);
		}
		return $totals;
	}

	/**
	 * Assemble digest data for the period that just ended.
	 *
	 * @param string $frequency 'weekly'|'monthly'
	 */
	public function buildDigest(string $userId, string $frequency): array {
		[$start, $end] = $this->periodRange($frequency, $userId);

		// Income/expenses over the period, as Cash Flow gives them
		$totals = $this->periodTotals($userId, $start, $end);

		$budget = $this->budgetAlertService->getSummary($userId);

		// Bills due in the coming week or month, and overdue ones apart. The
		// list was cut to five before it was counted, so the notification
		// never said more than 5, and overdue bills of any age sorted first
		// and pushed out the ones really coming up.
		$upcomingDays = $frequency === 'weekly' ? 7 : 30;
		$today = $this->getNow($userId)->format('Y-m-d');
		$due = [];
		$overdue = [];
		foreach ($this->billService->findUpcoming($userId, $upcomingDays) as $bill) {
			if (($bill->getNextDueDate() ?? '') < $today) {
				$overdue[] = $bill;
			} else {
				$due[] = $bill;
			}
		}
		$bills = $this->billService->enrichBillsWithCurrency(array_slice($due, 0, self::LISTED_BILLS), $userId);
		$overdueBills = $this->billService->enrichBillsWithCurrency(array_slice($overdue, 0, self::LISTED_BILLS), $userId);
		$serializeBill = fn ($bill) => [
			'name' => $bill->getName(),
			'amount' => (float)$bill->getAmount(),
			'currency' => $bill->getCurrency(),
			'dueDate' => $bill->getNextDueDate(),
		];

		$goals = [];
		foreach (array_slice($this->goalsService->findAll($userId), 0, 5) as $goal) {
			$target = (float)$goal->getTargetAmount();
			$goals[] = [
				'name' => $goal->getName(),
				'percentage' => $target > 0 ? round(((float)$goal->getCurrentAmount() / $target) * 100) : 0,
			];
		}

		return [
			'frequency' => $frequency,
			'periodStart' => $start,
			'periodEnd' => $end,
			'income' => round((float)($totals['income'] ?? 0), 2),
			'expenses' => round((float)($totals['expenses'] ?? 0), 2),
			'net' => round((float)($totals['income'] ?? 0) - (float)($totals['expenses'] ?? 0), 2),
			'budget' => $budget,
			'upcomingBills' => array_map($serializeBill, $bills),
			'upcomingBillCount' => count($due),
			'overdueBills' => array_map($serializeBill, $overdueBills),
			'overdueBillCount' => count($overdue),
			'goals' => $goals,
			'anomalies' => $this->anomalyService->detectForPeriod($userId, $start, $end),
			'suggestionCount' => $this->suggestionService->countSuggestions($userId),
		];
	}

	/**
	 * Build and deliver the digest: notification always, email when the
	 * user opted in. Returns the digest data (for tests/inspection).
	 */
	public function sendDigest(string $userId, string $frequency): array {
		$digest = $this->buildDigest($userId, $frequency);

		$this->sendNotification($userId, $digest);

		if ($this->settingService->get($userId, 'digest_email_enabled') === 'true') {
			$this->sendEmail($userId, $digest);
		}

		return $digest;
	}

	private function sendNotification(string $userId, array $digest): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('digest', $digest['periodEnd'])
			->setSubject('digest', [
				'frequency' => $digest['frequency'],
				'income' => $this->amountFormatter->formatForUser($userId, $digest['income']),
				'expenses' => $this->amountFormatter->formatForUser($userId, $digest['expenses']),
				'net' => $this->amountFormatter->formatForUser($userId, $digest['net']),
				'billCount' => (string)$digest['upcomingBillCount'],
				'overdueCount' => (string)$digest['overdueBillCount'],
				'anomalyCount' => (string)count($digest['anomalies']),
			]);
		$this->notificationManager->notify($notification);
	}

	private function sendEmail(string $userId, array $digest): void {
		$l = $this->l10nFactory->get(Application::APP_ID, $this->mailService->getUserLanguage($userId));
		$fmt = fn (float $amount) => $this->amountFormatter->formatForUser($userId, $amount);

		$heading = $digest['frequency'] === 'weekly'
			? $l->t('Your weekly budget digest')
			: $l->t('Your monthly budget digest');

		$sections = [];

		$sections[] = ['heading' => null, 'lines' => [
			$l->t('Income %1$s, spending %2$s (%3$s net).', [
				$fmt($digest['income']), $fmt($digest['expenses']), $fmt($digest['net']),
			]),
		]];

		$budget = $digest['budget'];
		if (($budget['totalCategories'] ?? 0) > 0) {
			$lines = [
				$l->t('%1$s of %2$s spent (%3$s%%) across %4$s budgeted categories.', [
					$fmt((float)$budget['totalSpent']),
					$fmt((float)$budget['totalBudget']),
					(string)$budget['overallPercentage'],
					(string)$budget['totalCategories'],
				]),
			];
			if (($budget['overBudgetCount'] ?? 0) > 0) {
				$lines[] = $l->n('%n category is over budget.', '%n categories are over budget.', $budget['overBudgetCount']);
			}
			$sections[] = ['heading' => $l->t('Budget'), 'lines' => $lines];
		}

		$billLines = function (array $bills, int $count) use ($l): array {
			$lines = array_map(
				fn ($bill) => $bill['name'] . ' — ' . $this->amountFormatter->format($bill['amount'], $bill['currency'] ?? 'USD') . ' (' . $bill['dueDate'] . ')',
				$bills
			);
			if ($count > count($bills)) {
				$lines[] = $l->n('and %n more', 'and %n more', $count - count($bills));
			}
			return $lines;
		};
		if (!empty($digest['overdueBills'])) {
			$sections[] = ['heading' => $l->t('Overdue bills'), 'lines' => $billLines($digest['overdueBills'], $digest['overdueBillCount'])];
		}
		if (!empty($digest['upcomingBills'])) {
			$sections[] = ['heading' => $l->t('Upcoming bills'), 'lines' => $billLines($digest['upcomingBills'], $digest['upcomingBillCount'])];
		}

		if (!empty($digest['anomalies'])) {
			$lines = array_map(
				fn ($a) => $l->t('%1$s is %2$s%% above your typical spending (%3$s so far this month).', [
					$a['categoryName'], (string)$a['percentAbove'], $fmt((float)$a['mtdSpend']),
				]),
				array_slice($digest['anomalies'], 0, 3)
			);
			$sections[] = ['heading' => $l->t('Unusual spending'), 'lines' => $lines];
		}

		if (!empty($digest['goals'])) {
			$lines = array_map(fn ($g) => $g['name'] . ' — ' . $g['percentage'] . '%', $digest['goals']);
			$sections[] = ['heading' => $l->t('Savings goals'), 'lines' => $lines];
		}

		if ($digest['suggestionCount'] > 0) {
			$sections[] = ['heading' => null, 'lines' => [
				$l->n(
					'Budget found %n possible recurring bill you are not tracking yet.',
					'Budget found %n possible recurring bills you are not tracking yet.',
					$digest['suggestionCount']
				),
			]];
		}

		$this->mailService->send($userId, $heading, $heading, $sections);
	}

	/**
	 * The period that just ended: previous ISO week (Mon–Sun) or previous
	 * calendar month.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function periodRange(string $frequency, string $userId): array {
		$now = $this->getNow($userId);
		if ($frequency === 'weekly') {
			$start = $now->modify('monday last week');
			$end = $start->modify('+6 days');
		} else {
			$start = $now->modify('first day of last month');
			$end = $now->modify('last day of last month');
		}
		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}

	/**
	 * Now on the user's clock: "last week" and "last month" are theirs, not
	 * the server's. Overridable in tests.
	 */
	protected function getNow(?string $userId = null): \DateTimeImmutable {
		return $this->userClock?->now($userId) ?? new \DateTimeImmutable();
	}
}
