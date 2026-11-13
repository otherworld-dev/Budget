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
 * The run-out date swings by a month or two with no change in habits: a
 * 4-weekly payment lands three or four times in a 3-month window, and the
 * bills on the 1st take a fortnight off the date until the month moves on.
 * Checked daily against a running peak, that noise alone sent "runs out
 * sooner" several times a year (a steady ledger got three in 18 months).
 * So the check is deliberately slow:
 *  - Once a month, on the first run on or after the 8th, when the window
 *    has rolled and the 1st's bills have landed.
 *  - The baseline settles first. For SETTLE_CHECKS monthly checks (or the
 *    window's length, if longer) after it is set, and once there is a full
 *    window of history, it follows the date down, silently, to the low end
 *    of the usual swing. That is also the time a
 *    change takes to pass through the window, so one change in habits
 *    sends one notification, not one a month while the average catches up.
 *  - Once settled it only moves for a reason. Small slips add up against
 *    it until they cross the threshold, and it is raised only when every
 *    one of the last SETTLE_CHECKS dates was later, so a few months of
 *    extra income don't become the bar an ordinary month then fails.
 *  - It notifies when the date is the threshold or more before the
 *    baseline at two monthly checks running, then starts again from the
 *    new date.
 *
 * The state is internal (`runway_alert_baseline`, `runway_alert_checks`,
 * neither client-writable): nothing the user can click should re-arm the
 * alert. Changing the runway setup clears it (SettingController), as the
 * date then moves for a reason that isn't spending.
 *
 * Only users who use the runway are checked: a runway tile on the dashboard
 * or accounts chosen for it. The `notification_runway_alert` opt-out is
 * checked by the caller (UserDigestJob), like the forecast warning.
 */
class RunwayAlertService {

	public const BASELINE_KEY = 'runway_alert_baseline';
	/** JSON {month: last month checked (Y-m), dates: recent run-out dates, pending: bool} */
	public const CHECKS_KEY = 'runway_alert_checks';
	private const MONTHS_KEY = 'runway_alert_months';

	/** The day of the month from which that month's check runs */
	private const CHECK_DAY = 8;
	/** Monthly checks the baseline takes to settle, and a later date has to hold to raise it */
	private const SETTLE_CHECKS = 6;

	private const HERO_TILES = ['runway'];
	private const WIDGET_TILES = ['runwayChart', 'runwayOneOffs', 'runwayScenarios', 'runwayBreakdown', 'runwayOverview'];

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
		$now = $this->getNow();
		if ((int)$now->format('j') < self::CHECK_DAY) {
			return false;
		}
		$checks = $this->readChecks($userId);
		if (!$this->usesRunway($userId)) {
			// Forget it, so coming back later starts afresh rather than
			// against a date from before the gap
			if ($checks !== null) {
				$this->settingService->delete($userId, self::BASELINE_KEY);
				$this->settingService->delete($userId, self::CHECKS_KEY);
			}
			return false;
		}
		$month = $now->format('Y-m');
		if ($checks !== null && $checks['month'] === $month) {
			return false;
		}

		$runway = $this->runwayService->get($userId);
		$current = $runway['scenarios']['current'] ?? [];
		$runsOutOn = $current['runsOutOn'] ?? null;
		$date = is_string($runsOutOn) ? $this->parseDate($runsOutOn) : null;

		if (($current['state'] ?? null) !== 'running_out' || $date === null) {
			$this->settingService->delete($userId, self::BASELINE_KEY);
			$this->writeChecks($userId, $month, [], false);
			return false;
		}

		$stored = $this->settingService->get($userId, self::BASELINE_KEY);
		$baseline = $this->parseDate($stored);
		$dates = $checks['dates'] ?? [];
		$windowMonths = (int)($runway['window']['months'] ?? 0);
		// Until the history fills the window, the average (and so the date)
		// moves with every month that joins it: settling starts after that
		if ((int)($runway['window']['effectiveMonths'] ?? 0) < $windowMonths) {
			$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);
			$this->writeChecks($userId, $month, [], false);
			return false;
		}
		if ($baseline === null || $dates === []) {
			$this->startFrom($userId, $month, $runsOutOn);
			return false;
		}

		$settle = max(self::SETTLE_CHECKS, $windowMonths);
		$dates[] = $runsOutOn;

		if (count($dates) <= $settle) {
			if ($date < $baseline) {
				$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);
			}
			$this->writeChecks($userId, $month, $dates, false);
			return false;
		}
		$dates = array_slice($dates, -$settle);

		$earlier = $date <= self::monthsBefore($baseline, $this->thresholdMonths($userId));
		if ($earlier && $checks['pending']) {
			$this->sendNotification($userId, $runsOutOn, (string)$stored, self::wholeMonthsEarlier($date, $baseline));
			$this->startFrom($userId, $month, $runsOutOn);
			return true;
		}

		if (!$earlier) {
			// Y-m-d strings, so the earliest sorts lowest
			$floor = min($dates);
			if ($floor > $baseline->format('Y-m-d')) {
				$this->settingService->set($userId, self::BASELINE_KEY, $floor);
			}
		}
		$this->writeChecks($userId, $month, $dates, $earlier);
		return false;
	}

	/**
	 * A runway tile visible on the dashboard, or accounts chosen in the
	 * setup. The alert is on by default like the others, so this is what
	 * keeps it from users who have never looked at a runway.
	 */
	private function usesRunway(string $userId): bool {
		$ids = json_decode((string)$this->settingService->get($userId, RunwayService::SETTING_ACCOUNT_IDS), true);
		if (is_array($ids) && $ids !== []) {
			return true;
		}
		return $this->showsTile($userId, 'dashboard_hero_config', self::HERO_TILES)
			|| $this->showsTile($userId, 'dashboard_widgets_config', self::WIDGET_TILES);
	}

	/**
	 * The dashboard shows a tile whose visibility is true. A copy of a tile
	 * is keyed `<type>__<n>`: the runway tiles can't be copied today, but a
	 * copy would count.
	 *
	 * @param string[] $tiles
	 */
	private function showsTile(string $userId, string $key, array $tiles): bool {
		$config = json_decode((string)$this->settingService->get($userId, $key), true);
		$visibility = is_array($config) && is_array($config['visibility'] ?? null) ? $config['visibility'] : [];
		foreach ($visibility as $id => $visible) {
			if ($visible === true && in_array(explode('__', (string)$id)[0], $tiles, true)) {
				return true;
			}
		}
		return false;
	}

	private function startFrom(string $userId, string $month, string $runsOutOn): void {
		$this->settingService->set($userId, self::BASELINE_KEY, $runsOutOn);
		$this->writeChecks($userId, $month, [$runsOutOn], false);
	}

	/**
	 * @return ?array{month: string, dates: list<string>, pending: bool} null when none is stored
	 */
	private function readChecks(string $userId): ?array {
		$stored = $this->settingService->get($userId, self::CHECKS_KEY);
		if ($stored === null) {
			return null;
		}
		$checks = json_decode($stored, true);
		$checks = is_array($checks) ? $checks : [];
		$dates = [];
		foreach (is_array($checks['dates'] ?? null) ? $checks['dates'] : [] as $date) {
			if (is_string($date) && $this->parseDate($date) !== null) {
				$dates[] = $date;
			}
		}
		return [
			'month' => is_string($checks['month'] ?? null) ? $checks['month'] : '',
			'dates' => $dates,
			'pending' => ($checks['pending'] ?? false) === true,
		];
	}

	/**
	 * @param list<string> $dates
	 */
	private function writeChecks(string $userId, string $month, array $dates, bool $pending): void {
		$this->settingService->set($userId, self::CHECKS_KEY, (string)json_encode([
			'month' => $month,
			'dates' => $dates,
			'pending' => $pending,
		]));
	}

	/** 1-6, falling back to 1 on anything that is not a number */
	private function thresholdMonths(string $userId): int {
		$raw = $this->settingService->get($userId, self::MONTHS_KEY);
		$months = is_numeric($raw) ? (int)$raw : 1;
		return max(1, min(6, $months));
	}

	/**
	 * The same day $months calendar months earlier, or that month's last
	 * day when it is shorter: 31 March less a month is 28/29 February,
	 * where modify('-1 month') overflows to 2 or 3 March.
	 */
	private static function monthsBefore(\DateTimeImmutable $date, int $months): \DateTimeImmutable {
		$first = $date->modify('first day of this month')->modify("-{$months} months");
		$day = min((int)$date->format('j'), (int)$first->format('t'));
		return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day);
	}

	/**
	 * Whole calendar months $earlier is before $later, by the threshold's
	 * own arithmetic, so a notification never names fewer months than the
	 * setting it crossed.
	 */
	private static function wholeMonthsEarlier(\DateTimeImmutable $earlier, \DateTimeImmutable $later): int {
		$months = 0;
		while ($months < 1200 && $earlier <= self::monthsBefore($later, $months + 1)) {
			$months++;
		}
		return $months;
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
