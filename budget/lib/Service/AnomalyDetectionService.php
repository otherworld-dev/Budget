<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\Notification\IManager as INotificationManager;

/**
 * Spending anomaly detection: flags categories whose month-to-date spending
 * runs well above their typical level.
 *
 * Baseline = MEDIAN of the prior 6 full months (current month excluded,
 * zero-spend months skipped, at least 3 non-zero months required — medians
 * resist one-off spikes). A category is anomalous when
 *
 *   mtdSpend > median × (dayOfMonth / daysInMonth) × (1 + threshold%)
 *
 * with two guards against noise: never before day 10 of the month (rent on
 * the 1st would trip a pro-rated check), and never below a minimum spend
 * floor. Notifications are suppressed to one per category per month.
 */
class AnomalyDetectionService {

    private const BASELINE_MONTHS = 6;
    private const MIN_HISTORY_MONTHS = 3;
    private const MIN_DAY_OF_MONTH = 10;
    private const SUPPRESSION_KEY = 'anomaly_notified';

    public function __construct(
        private CategoryMapper $categoryMapper,
        private TransactionMapper $transactionMapper,
        private SettingService $settingService,
        private AmountFormatter $amountFormatter,
        private INotificationManager $notificationManager,
    ) {
    }

    /**
     * Active anomalies for a user (no notification side effects).
     *
     * @return array[] [{categoryId, categoryName, mtdSpend, baseline, percentAbove}]
     */
    public function detect(string $userId): array {
        $now = $this->getNow();
        $dayOfMonth = (int) $now->format('j');
        if ($dayOfMonth < self::MIN_DAY_OF_MONTH) {
            return [];
        }

        $threshold = $this->getFloatSetting($userId, 'anomaly_threshold_percent', 30.0);
        $minAmount = $this->getFloatSetting($userId, 'anomaly_min_amount', 50.0);

        $categories = [];
        foreach ($this->categoryMapper->findAll($userId) as $category) {
            if ($category->getType() === 'expense' && !($category->getExcludedFromReports() ?? false)) {
                $categories[$category->getId()] = $category;
            }
        }
        if (empty($categories)) {
            return [];
        }
        $categoryIds = array_keys($categories);

        // Month-to-date spending
        $monthStart = $now->format('Y-m-01');
        $mtd = $this->transactionMapper->getCategorySpendingBatch($categoryIds, $monthStart, $now->format('Y-m-d'));

        // Prior 6 full months
        $history = [];
        for ($i = 1; $i <= self::BASELINE_MONTHS; $i++) {
            $month = (clone $now)->modify('first day of this month')->modify("-{$i} months");
            $start = $month->format('Y-m-01');
            $end = $month->format('Y-m-t');
            $history[] = $this->transactionMapper->getCategorySpendingBatch($categoryIds, $start, $end);
        }

        $daysInMonth = (int) $now->format('t');
        $proRate = $dayOfMonth / $daysInMonth;

        $anomalies = [];
        foreach ($categories as $catId => $category) {
            $mtdSpend = (float) ($mtd[$catId] ?? 0);
            if ($mtdSpend < $minAmount) {
                continue;
            }

            $nonZeroMonths = [];
            foreach ($history as $monthTotals) {
                $value = (float) ($monthTotals[$catId] ?? 0);
                if ($value > 0) {
                    $nonZeroMonths[] = $value;
                }
            }
            if (count($nonZeroMonths) < self::MIN_HISTORY_MONTHS) {
                continue;
            }

            $baseline = $this->median($nonZeroMonths);
            $expectedSoFar = $baseline * $proRate;
            $limit = $expectedSoFar * (1 + $threshold / 100);

            if ($mtdSpend > $limit && $expectedSoFar > 0) {
                $anomalies[] = [
                    'categoryId' => $catId,
                    'categoryName' => $category->getName(),
                    'mtdSpend' => round($mtdSpend, 2),
                    'baseline' => round($baseline, 2),
                    'percentAbove' => (int) round(($mtdSpend / $expectedSoFar - 1) * 100),
                ];
            }
        }

        usort($anomalies, fn($a, $b) => $b['percentAbove'] <=> $a['percentAbove']);
        return $anomalies;
    }

    /**
     * Detect and send notifications, suppressed to one per category per
     * month. Returns ALL active anomalies (including suppressed ones) for
     * embedding in the digest.
     *
     * @return array[]
     */
    public function detectAndNotify(string $userId): array {
        $anomalies = $this->detect($userId);
        if (empty($anomalies)) {
            return [];
        }

        $month = $this->getNow()->format('Y-m');
        $notified = $this->getNotifiedMap($userId);
        $changed = false;

        foreach ($anomalies as $anomaly) {
            $catKey = (string) $anomaly['categoryId'];
            if (($notified[$catKey] ?? null) === $month) {
                continue; // already notified this month
            }

            $this->sendNotification($userId, $anomaly);
            $notified[$catKey] = $month;
            $changed = true;
        }

        if ($changed) {
            // Prune stale entries while writing
            $notified = array_filter($notified, fn($m) => $m >= $this->getNow()->modify('-2 months')->format('Y-m'));
            $this->settingService->set($userId, self::SUPPRESSION_KEY, json_encode($notified));
        }

        return $anomalies;
    }

    private function sendNotification(string $userId, array $anomaly): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('anomaly', (string) $anomaly['categoryId'])
            ->setSubject('spending_anomaly', [
                'categoryName' => $anomaly['categoryName'],
                'percentAbove' => (string) $anomaly['percentAbove'],
                'amount' => $this->amountFormatter->formatForUser($userId, (float) $anomaly['mtdSpend']),
            ]);
        $this->notificationManager->notify($notification);
    }

    /**
     * @return array<string, string> categoryId => YYYY-MM last notified
     */
    private function getNotifiedMap(string $userId): array {
        try {
            $raw = $this->settingService->get($userId, self::SUPPRESSION_KEY);
            $map = $raw !== null ? json_decode($raw, true) : null;
            return is_array($map) ? $map : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getFloatSetting(string $userId, string $key, float $default): float {
        try {
            $value = $this->settingService->get($userId, $key);
            return $value !== null && is_numeric($value) ? (float) $value : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * @param float[] $values non-empty
     */
    private function median(array $values): float {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * Overridable in tests.
     */
    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable();
    }
}
