<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;

/**
 * Tells the user when the date their money runs out (at current spending)
 * moves a month or more earlier.
 *
 * The run-out date drifts a little every day, so it is compared against a
 * stored baseline rather than yesterday's value: small slips add up against
 * the old baseline until they cross the threshold, and an improvement raises
 * the baseline so a later fall from the better position is caught too.
 * The baseline is internal (`runway_alert_baseline`, not client-writable):
 * nothing the user can click should re-arm the alert.
 *
 * The user's `notification_runway_alert` opt-out is checked by the caller
 * (UserDigestJob), like the forecast warning.
 */
class RunwayAlertService {

	private const BASELINE_KEY = 'runway_alert_baseline';
	private const MONTHS_KEY = 'runway_alert_months';
	private const DAYS_PER_MONTH = 30.4375;

	public function __construct(
		private RunwayService $runwayService,
		private SettingService $settingService,
		private INotificationManager $notificationManager,
	) {
	}

	/**
	 * @return bool whether a notification was sent
	 */
	public function checkAndNotify(string $userId): bool {
		$current = $this->runwayService->get($userId)['scenarios']['current'] ?? [];
		$runsOutOn = $current['runsOutOn'] ?? null;

		if (($current['state'] ?? null) !== 'running_out' || !is_string($runsOutOn) || $runsOutOn === '') {
			$this->settingService->delete($userId, self::BASELINE_KEY);
			return false;
		}

		$stored = $this->settingService->get($userId, self::BASELINE_KEY);
		$baseline = $this->parseDate($stored);
		$date = $this->parseDate($runsOutOn);
		if ($baseline === null || $date === null) {
			$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);
			return false;
		}

		if ($date > $baseline) {
			$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);
			return false;
		}

		$threshold = $baseline->modify('-' . $this->thresholdMonths($userId) . ' months');
		if ($date > $threshold) {
			return false;
		}

		$this->sendNotification($userId, $runsOutOn, (string)$stored, $this->monthsBetween($date, $baseline));
		$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);

		return true;
	}

	/** 1-6, falling back to 1 on anything that is not a number */
	private function thresholdMonths(string $userId): int {
		$raw = $this->settingService->get($userId, self::MONTHS_KEY);
		$months = is_numeric($raw) ? (int)$raw : 1;
		return max(1, min(6, $months));
	}

	private function monthsBetween(\DateTimeImmutable $earlier, \DateTimeImmutable $later): int {
		$days = (int)$earlier->diff($later)->days;
		return max(1, (int)round($days / self::DAYS_PER_MONTH));
	}

	private function parseDate(?string $value): ?\DateTimeImmutable {
		if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date === false ? null : $date;
	}

	private function sendNotification(string $userId, string $runsOutOn, string $previous, int $months): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(\DateTime::createFromImmutable($this->getNow()))
			->setObject('runway_warning', $runsOutOn)
			->setSubject('runway_warning', [
				'runsOutOn' => $runsOutOn,
				'previous' => $previous,
				'months' => $months,
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
