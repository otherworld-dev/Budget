<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\Mail\BudgetMailService;
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
    ) {
    }

    /**
     * Assemble digest data for the period that just ended.
     *
     * @param string $frequency 'weekly'|'monthly'
     */
    public function buildDigest(string $userId, string $frequency): array {
        [$start, $end] = $this->periodRange($frequency);

        // Income/expenses over the period (all accounts, scheduled excluded)
        $totals = ['income' => 0.0, 'expenses' => 0.0];
        foreach ($this->transactionMapper->getAccountSummaries($userId, $start, $end) as $summary) {
            $totals['income'] += (float) ($summary['income'] ?? 0);
            $totals['expenses'] += (float) ($summary['expenses'] ?? 0);
        }

        $budget = $this->budgetAlertService->getSummary($userId);

        $upcomingDays = $frequency === 'weekly' ? 7 : 30;
        $bills = array_slice($this->billService->findUpcoming($userId, $upcomingDays), 0, 5);
        $bills = $this->billService->enrichBillsWithCurrency($bills, $userId);

        $goals = [];
        foreach (array_slice($this->goalsService->findAll($userId), 0, 5) as $goal) {
            $target = (float) $goal->getTargetAmount();
            $goals[] = [
                'name' => $goal->getName(),
                'percentage' => $target > 0 ? round(((float) $goal->getCurrentAmount() / $target) * 100) : 0,
            ];
        }

        return [
            'frequency' => $frequency,
            'periodStart' => $start,
            'periodEnd' => $end,
            'income' => round((float) ($totals['income'] ?? 0), 2),
            'expenses' => round((float) ($totals['expenses'] ?? 0), 2),
            'net' => round((float) ($totals['income'] ?? 0) - (float) ($totals['expenses'] ?? 0), 2),
            'budget' => $budget,
            'upcomingBills' => array_map(fn($bill) => [
                'name' => $bill->getName(),
                'amount' => (float) $bill->getAmount(),
                'currency' => $bill->getCurrency(),
                'dueDate' => $bill->getNextDueDate(),
            ], $bills),
            'goals' => $goals,
            'anomalies' => $this->anomalyService->detect($userId),
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
                'billCount' => (string) count($digest['upcomingBills']),
                'anomalyCount' => (string) count($digest['anomalies']),
            ]);
        $this->notificationManager->notify($notification);
    }

    private function sendEmail(string $userId, array $digest): void {
        $l = $this->l10nFactory->get(Application::APP_ID, $this->mailService->getUserLanguage($userId));
        $fmt = fn(float $amount) => $this->amountFormatter->formatForUser($userId, $amount);

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
                    $fmt((float) $budget['totalSpent']),
                    $fmt((float) $budget['totalBudget']),
                    (string) $budget['overallPercentage'],
                    (string) $budget['totalCategories'],
                ]),
            ];
            if (($budget['overBudgetCount'] ?? 0) > 0) {
                $lines[] = $l->n('%n category is over budget.', '%n categories are over budget.', $budget['overBudgetCount']);
            }
            $sections[] = ['heading' => $l->t('Budget'), 'lines' => $lines];
        }

        if (!empty($digest['upcomingBills'])) {
            $lines = array_map(
                fn($bill) => $bill['name'] . ' — ' . $this->amountFormatter->format($bill['amount'], $bill['currency'] ?? 'USD') . ' (' . $bill['dueDate'] . ')',
                $digest['upcomingBills']
            );
            $sections[] = ['heading' => $l->t('Upcoming bills'), 'lines' => $lines];
        }

        if (!empty($digest['anomalies'])) {
            $lines = array_map(
                fn($a) => $l->t('%1$s is %2$s%% above your typical spending (%3$s so far this month).', [
                    $a['categoryName'], (string) $a['percentAbove'], $fmt((float) $a['mtdSpend']),
                ]),
                array_slice($digest['anomalies'], 0, 3)
            );
            $sections[] = ['heading' => $l->t('Unusual spending'), 'lines' => $lines];
        }

        if (!empty($digest['goals'])) {
            $lines = array_map(fn($g) => $g['name'] . ' — ' . $g['percentage'] . '%', $digest['goals']);
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
    private function periodRange(string $frequency): array {
        $now = $this->getNow();
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
     * Overridable in tests.
     */
    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable();
    }
}
