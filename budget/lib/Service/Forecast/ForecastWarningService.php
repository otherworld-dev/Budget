<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;

/**
 * Negative cash flow warnings: notifies when the live forecast projects the
 * combined balance below zero at any point in the next six months.
 *
 * The projection is a trend extrapolation, so it can change month to month
 * and would be noisy if it spoke every day. Suppression is therefore one
 * notification per calendar month — a dip that is still coming next month
 * gets one fresh reminder, not thirty.
 *
 * The user's `notification_forecast_warning` opt-out is checked by the
 * caller (DigestJob), matching how anomaly and budget alerts are gated.
 */
class ForecastWarningService {

    private const NOTIFIED_KEY = 'forecast_warning_notified';
    private const HORIZON_MONTHS = 6;

    public function __construct(
        private ForecastService $forecastService,
        private SettingService $settingService,
        private AmountFormatter $amountFormatter,
        private INotificationManager $notificationManager,
    ) {
    }

    /**
     * @return bool whether a notification was sent
     */
    public function checkAndNotify(string $userId): bool {
        $forecast = $this->forecastService->getLiveForecast($userId, self::HORIZON_MONTHS);
        $dip = $this->firstNegativeMonth($forecast['monthlyProjections'] ?? []);
        if ($dip === null) {
            return false;
        }

        $month = $this->getNow()->format('Y-m');
        if ($this->settingService->get($userId, self::NOTIFIED_KEY) === $month) {
            return false;
        }

        $this->sendNotification($userId, $dip);
        $this->settingService->set($userId, self::NOTIFIED_KEY, $month);

        return true;
    }

    /**
     * The earliest projection that goes below zero — that is the month the
     * user has to act before, so a later, deeper dip is not the headline.
     *
     * @param array[] $projections
     * @return array{month: string, balance: float}|null
     */
    private function firstNegativeMonth(array $projections): ?array {
        foreach ($projections as $projection) {
            $balance = (float) ($projection['balance'] ?? 0);
            if ($balance < 0) {
                return ['month' => (string) ($projection['month'] ?? ''), 'balance' => $balance];
            }
        }

        return null;
    }

    /**
     * @param array{month: string, balance: float} $dip
     */
    private function sendNotification(string $userId, array $dip): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('forecast_warning', $dip['month'])
            ->setSubject('forecast_warning', [
                'month' => $dip['month'],
                'balance' => $this->amountFormatter->formatForUser($userId, $dip['balance']),
            ]);
        $this->notificationManager->notify($notification);
    }

    /**
     * Overridable in tests.
     */
    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable();
    }
}
