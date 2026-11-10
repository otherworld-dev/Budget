<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Runway;

use OCA\Budget\Service\Runway\RunwayAlertService;
use OCA\Budget\Service\Runway\RunwayCalculator;
use OCA\Budget\Service\Runway\RunwayRow;
use OCA\Budget\Service\Runway\RunwayService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class RunwayAlertServiceTest extends TestCase {
	private RunwayAlertService $service;

	/** What the runway says about the current spending */
	private array $current = ['state' => 'running_out', 'monthlyDrain' => '1000.00', 'months' => 20.0, 'runsOutOn' => '2028-07-20'];
	private int $windowMonths = 3;
	private int $effectiveMonths = 3;
	/** When set, get() runs the real calculator over this ledger instead of returning $current */
	private ?array $ledger = null;
	private \DateTimeImmutable $now;
	private int $gets = 0;
	/** @var array<string, string> */
	private array $settings = [];
	/** @var array[] subject parameters of each notification sent */
	private array $sent = [];
	/** @var string[] objects of each notification sent */
	private array $objects = [];
	/** @var string[] the day each notification was sent */
	private array $sentOn = [];

	private const USER_ID = 'alice';
	private const KEY = 'runway_alert_baseline';
	private const CHECKS = 'runway_alert_checks';

	protected function setUp(): void {
		$this->now = new \DateTimeImmutable('2026-01-08 09:00');
		// Uses the runway: the chart tile is on the dashboard
		$this->settings['dashboard_widgets_config'] = '{"order":["runwayChart"],"visibility":{"runwayChart":true}}';

		$runway = $this->createMock(RunwayService::class);
		$runway->method('get')->willReturnCallback(function () {
			$this->gets++;
			return $this->ledger !== null
				? $this->ledgerRunway()
				: ['window' => ['months' => $this->windowMonths, 'effectiveMonths' => $this->effectiveMonths], 'scenarios' => ['current' => $this->current]];
		});

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')
			->willReturnCallback(fn (string $userId, string $key) => $this->settings[$key] ?? null);
		$settingService->method('set')
			->willReturnCallback(function (string $userId, string $key, string $value) {
				$this->settings[$key] = $value;
				return new \OCA\Budget\Db\Setting();
			});
		$settingService->method('delete')
			->willReturnCallback(function (string $userId, string $key) {
				$had = isset($this->settings[$key]);
				unset($this->settings[$key]);
				return $had ? 1 : 0;
			});

		$notification = $this->createMock(INotification::class);
		$notification->method('setSubject')
			->willReturnCallback(function (string $subject, array $params) use ($notification) {
				$this->assertSame('runway_warning', $subject);
				$this->sent[] = $params;
				$this->sentOn[] = $this->now->format('Y-m-d');
				return $notification;
			});
		$notification->method('setObject')
			->willReturnCallback(function (string $type, string $id) use ($notification) {
				$this->objects[] = $type . ':' . $id;
				return $notification;
			});
		$notification->method($this->anything())->willReturnSelf();
		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);

		$test = $this;
		$this->service = new class($runway, $settingService, $notificationManager, $test) extends RunwayAlertService {
			public function __construct(
				$runway,
				$settingService,
				$notificationManager,
				private RunwayAlertServiceTest $test,
			) {
				parent::__construct($runway, $settingService, $notificationManager);
			}

			protected function getNow(): \DateTimeImmutable {
				return $this->test->now();
			}
		};
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	private function runsOutOn(string $date): void {
		$this->current['runsOutOn'] = $date;
	}

	/** The check on the 8th of the month after the last one */
	private function nextMonthCheck(?string $runsOutOn = null): bool {
		$this->now = $this->now->modify('first day of next month')->setTime(9, 0)->modify('+7 days');
		if ($runsOutOn !== null) {
			$this->runsOutOn($runsOutOn);
		}
		return $this->service->checkAndNotify(self::USER_ID);
	}

	/**
	 * From next month, seeds the baseline at $date and lets it settle there
	 * (six monthly checks), so the check after is the first that compares.
	 */
	private function settledAt(string $date): void {
		unset($this->settings[self::KEY], $this->settings[self::CHECKS]);
		$this->runsOutOn($date);
		for ($i = 0; $i < 6; $i++) {
			$this->assertFalse($this->nextMonthCheck());
		}
		$this->assertSame($date, $this->settings[self::KEY]);
	}

	// ── when it checks ──────────────────────────────────────────────

	public function testFirstCheckStoresBaselineSilently(): void {
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));

		$this->assertSame('2028-07-20', $this->settings[self::KEY]);
		$this->assertSame([], $this->sent);
	}

	public function testChecksOncePerMonthFromThe8th(): void {
		$this->now = new \DateTimeImmutable('2026-01-07 23:00');
		$this->service->checkAndNotify(self::USER_ID);
		$this->assertSame(0, $this->gets, 'the window has only just rolled');

		$this->now = new \DateTimeImmutable('2026-01-08 01:00');
		$this->service->checkAndNotify(self::USER_ID);
		$this->now = new \DateTimeImmutable('2026-01-20 01:00');
		$this->service->checkAndNotify(self::USER_ID);
		$this->assertSame(1, $this->gets);

		// A run missed on the 8th is made up later in the month
		$this->now = new \DateTimeImmutable('2026-02-11 01:00');
		$this->service->checkAndNotify(self::USER_ID);
		$this->assertSame(2, $this->gets);
	}

	// ── who it checks ───────────────────────────────────────────────

	public function testUsersWithoutRunwayAreNeverChecked(): void {
		unset($this->settings['dashboard_widgets_config']);
		$this->settings['dashboard_hero_config'] = '{"order":["netWorth","runway"],"visibility":{"netWorth":true,"runway":false}}';
		$this->settings['runway_account_ids'] = '';

		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->settings['runway_account_ids'] = '[]';
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->settings['dashboard_widgets_config'] = '{"order":["runwayChart"],"visibility":{"runwayChart":false}}';
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));

		$this->assertSame(0, $this->gets);
		$this->assertArrayNotHasKey(self::KEY, $this->settings);
	}

	public function testAVisibleRunwayTileOrChosenAccountsGetChecked(): void {
		$cases = [
			['dashboard_hero_config', '{"visibility":{"runway":true}}'],
			['dashboard_widgets_config', '{"visibility":{"runwayOverview":true}}'],
			['runway_account_ids', '[4,7]'],
		];
		foreach ($cases as $i => [$key, $value]) {
			$this->settings = [$key => $value];
			$this->now = new \DateTimeImmutable('2026-0' . ($i + 1) . '-08 09:00');
			$this->service->checkAndNotify(self::USER_ID);
			$this->assertSame($i + 1, $this->gets, $key);
		}
	}

	public function testRemovingTheTilesForgetsTheBaseline(): void {
		$this->service->checkAndNotify(self::USER_ID);
		$this->assertArrayHasKey(self::KEY, $this->settings);

		unset($this->settings['dashboard_widgets_config']);
		$this->nextMonthCheck();

		$this->assertArrayNotHasKey(self::KEY, $this->settings);
		$this->assertArrayNotHasKey(self::CHECKS, $this->settings);
		$this->assertSame(1, $this->gets);
	}

	// ── the rule ────────────────────────────────────────────────────

	public function testBaselineFollowsTheDateDownWhileSettling(): void {
		$this->service->checkAndNotify(self::USER_ID);
		foreach (['2028-05-01', '2028-06-10', '2028-02-15', '2028-04-01', '2028-03-01'] as $date) {
			$this->assertFalse($this->nextMonthCheck($date));
		}

		$this->assertSame([], $this->sent, 'five months earlier, but still settling');
		$this->assertSame('2028-02-15', $this->settings[self::KEY]);
	}

	public function testSettlingWaitsForAFullWindowOfHistory(): void {
		$this->effectiveMonths = 1;
		foreach (['2028-07-20', '2029-01-01', '2028-09-01'] as $date) {
			$this->assertFalse($this->nextMonthCheck($date));
		}
		$this->assertSame('2028-09-01', $this->settings[self::KEY], 'taken afresh while the window fills');

		$this->effectiveMonths = 3;
		for ($i = 0; $i < 5; $i++) {
			$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		}
		// The sixth check with a full window is still settling
		$this->assertFalse($this->nextMonthCheck('2027-12-01'));
		$this->assertSame([], $this->sent);
		$this->assertSame('2027-12-01', $this->settings[self::KEY]);
	}

	public function testTwoChecksRunningAMonthEarlierNotifyOnceAndRestart(): void {
		$this->settledAt('2028-07-20');

		$this->assertFalse($this->nextMonthCheck('2028-06-20'), 'one check is not enough');
		$this->assertTrue($this->nextMonthCheck('2028-06-19'));
		$this->assertSame([['runsOutOn' => '2028-06-19', 'previous' => '2028-07-20', 'months' => 1]], $this->sent);
		$this->assertSame(['runway_warning:2028-06-19'], $this->objects);
		$this->assertSame('2028-06-19', $this->settings[self::KEY]);

		// The window is still taking the change in: the baseline settles again
		$this->assertFalse($this->nextMonthCheck('2028-05-01'));
		$this->assertFalse($this->nextMonthCheck('2028-04-25'));
		$this->assertCount(1, $this->sent);
		$this->assertSame('2028-04-25', $this->settings[self::KEY]);
	}

	public function testOneEarlyCheckBetweenOrdinaryOnesDoesNothing(): void {
		$this->settledAt('2028-07-20');

		$this->assertFalse($this->nextMonthCheck('2028-05-01'));
		$this->assertFalse($this->nextMonthCheck('2028-07-25'));
		$this->assertFalse($this->nextMonthCheck('2028-05-01'));

		$this->assertSame([], $this->sent);
		$this->assertSame('2028-07-20', $this->settings[self::KEY]);
	}

	public function testSmallSlipsAddUpOnceSettled(): void {
		$this->settledAt('2028-07-20');

		foreach (['2028-07-05', '2028-06-28', '2028-06-24'] as $date) {
			$this->assertFalse($this->nextMonthCheck($date));
		}
		$this->assertSame('2028-07-20', $this->settings[self::KEY], 'not followed down');
		$this->assertFalse($this->nextMonthCheck('2028-06-20'));
		$this->assertTrue($this->nextMonthCheck('2028-06-18'));
		$this->assertSame(1, $this->sent[0]['months']);
	}

	public function testLaterDatesRaiseTheBaselineOnlyOnceTheyHaveHeldForSixChecks(): void {
		$this->settledAt('2028-07-20');

		foreach (['2028-10-01', '2028-11-15', '2028-10-20', '2028-12-01', '2028-10-10'] as $date) {
			$this->nextMonthCheck($date);
			$this->assertSame('2028-07-20', $this->settings[self::KEY]);
		}
		$this->nextMonthCheck('2028-11-01');
		$this->assertSame('2028-10-01', $this->settings[self::KEY], 'the earliest of the six');

		// and a fall from the improved position is now caught
		$this->assertFalse($this->nextMonthCheck('2028-08-30'));
		$this->assertTrue($this->nextMonthCheck('2028-08-31'));
		$this->assertSame('2028-10-01', $this->sent[0]['previous']);
	}

	public function testALongerWindowSettlesForAsLong(): void {
		$this->windowMonths = 9;
		$this->effectiveMonths = 9;
		$this->settledAt('2028-07-20');

		$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		$this->assertSame('2028-03-01', $this->settings[self::KEY], 'still settling: nine checks');
		$this->assertSame([], $this->sent);
	}

	public function testNotRunningOutClearsBaseline(): void {
		$this->settledAt('2028-07-20');
		$this->current = ['state' => 'not_running_out', 'monthlyDrain' => null, 'months' => null, 'runsOutOn' => null];

		$this->assertFalse($this->nextMonthCheck());
		$this->assertArrayNotHasKey(self::KEY, $this->settings);

		// Running out again starts afresh, silently
		$this->current = ['state' => 'running_out', 'monthlyDrain' => '1000.00', 'months' => 20.0, 'runsOutOn' => '2028-01-01'];
		$this->assertFalse($this->nextMonthCheck());
		$this->assertSame('2028-01-01', $this->settings[self::KEY]);
	}

	public function testThresholdSettingRespected(): void {
		$this->settings['runway_alert_months'] = '2';
		$this->settledAt('2028-07-20');

		$this->assertFalse($this->nextMonthCheck('2028-06-19'));
		$this->assertFalse($this->nextMonthCheck('2028-06-19'));

		$this->assertFalse($this->nextMonthCheck('2028-05-19'));
		$this->assertTrue($this->nextMonthCheck('2028-05-19'));
		$this->assertSame(2, $this->sent[0]['months']);
	}

	public function testGarbageThresholdFallsBackToOneAndIsClamped(): void {
		$this->settings['runway_alert_months'] = 'soon';
		$this->settledAt('2028-07-20');
		$this->nextMonthCheck('2028-06-19');
		$this->assertTrue($this->nextMonthCheck('2028-06-19'));

		$this->settings['runway_alert_months'] = '99';
		$this->settledAt('2028-07-20');
		$this->assertFalse($this->nextMonthCheck('2028-01-25'));
		$this->assertFalse($this->nextMonthCheck('2028-01-25'), '99 is not honoured: 6 months is');
		$this->assertFalse($this->nextMonthCheck('2028-01-20'));
		$this->assertTrue($this->nextMonthCheck('2028-01-20'), 'clamped to 6 months');
	}

	public function testMonthsAreWholeCalendarMonths(): void {
		$this->settledAt('2028-07-20');
		$this->nextMonthCheck('2028-01-05');
		$this->nextMonthCheck('2028-01-05');

		$this->assertSame(6, $this->sent[0]['months'], '6½ months is 6 whole ones');
	}

	public function testAMonthBeforeTheLastDayOfAMonthDoesNotOverflow(): void {
		$this->settledAt('2028-03-31');

		// modify('-1 month') makes 31 March less a month 2 March, so the 1st
		// counted as a month earlier; a month before 31 March is 29 February
		$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		$this->assertFalse($this->nextMonthCheck('2028-03-01'));
		$this->assertFalse($this->nextMonthCheck('2028-02-29'));
		$this->assertTrue($this->nextMonthCheck('2028-02-29'));
		$this->assertSame(1, $this->sent[0]['months']);
	}

	// ── a real ledger, checked every day for 18 months ──────────────

	public function testASteadyLedgerNeverNotifies(): void {
		$this->runDaily($this->buildLedger());

		$this->assertSame([], $this->sentOn);
		$this->assertSame(18, $this->gets, 'once a month');
	}

	public static function noiseSeeds(): array {
		return ['seed 12345' => [12345], 'seed 987654' => [987654]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('noiseSeeds')]
	public function testSteadyHabitsWithNoisySpendingNeverNotify(int $seed): void {
		$this->runDaily($this->buildLedger(noiseSeed: $seed));

		$this->assertSame([], $this->sentOn);
	}

	public function testSpendingUpAThirdNotifiesOnceWithinThreeMonths(): void {
		$this->runDaily($this->buildLedger(riseFrom: '2026-09-01'));

		$this->assertCount(1, $this->sentOn, 'one change, one notification');
		$this->assertLessThanOrEqual('2026-11-30', $this->sentOn[0]);
		$this->assertGreaterThanOrEqual(1, $this->sent[0]['months']);
	}

	/**
	 * Calls the check every day from 1 January 2026 to 30 June 2027, as
	 * UserDigestJob does.
	 */
	private function runDaily(array $ledger): void {
		$this->ledger = $ledger;
		for ($day = new \DateTimeImmutable('2026-01-01 03:00'); $day <= new \DateTimeImmutable('2027-06-30 03:00'); $day = $day->modify('+1 day')) {
			$this->now = $day;
			$this->service->checkAndNotify(self::USER_ID);
		}
	}

	/**
	 * A regular life, from 1 October 2025 with £40,000: bills £600 on the
	 * 1st, £450 in on the 5th, a card payment of £200 on the 15th, a weekly
	 * shop of £80.77, other spending of £250 four times a month and a
	 * 4-weekly £320 in (so a 3-month window holds three or four of them).
	 *
	 * @param ?int $noiseSeed ±20% on each "other spending" payment, from a fixed sequence
	 * @param ?string $riseFrom every payment out is 30% higher from this day on
	 * @return RunwayRow[]
	 */
	private function buildLedger(?int $noiseSeed = null, ?string $riseFrom = null): array {
		$state = $noiseSeed ?? 0;
		$rows = [];
		$nextIn = new \DateTimeImmutable('2025-10-09');
		$add = function (string $date, float $amount) use (&$rows): void {
			$rows[] = new RunwayRow(count($rows) + 1, $date, number_format($amount, 2, '.', ''), null, 'cleared', false, false, null, null);
		};
		for ($day = new \DateTimeImmutable('2025-10-01'); $day <= new \DateTimeImmutable('2027-06-30'); $day = $day->modify('+1 day')) {
			$date = $day->format('Y-m-d');
			$dayOfMonth = (int)$day->format('j');
			$out = $riseFrom !== null && $date >= $riseFrom ? 1.3 : 1.0;

			if ($dayOfMonth === 1) {
				$add($date, -600 * $out);
			}
			if ($dayOfMonth === 5) {
				$add($date, 450);
			}
			if ($dayOfMonth === 15) {
				$add($date, -200 * $out);
			}
			if ($day->format('N') === '6') {
				$add($date, -80.77 * $out);
			}
			if (in_array($dayOfMonth, [3, 10, 17, 24], true)) {
				$noise = 1.0;
				if ($noiseSeed !== null) {
					$state = ($state * 1103515245 + 12345) % 2147483648;
					$noise = 0.8 + 0.4 * $state / 2147483648;
				}
				$add($date, -250 * $noise * $out);
			}
			if ($date === $nextIn->format('Y-m-d')) {
				$add($date, 320);
				$nextIn = $nextIn->modify('+28 days');
			}
		}
		return $rows;
	}

	/**
	 * What RunwayService::get() would say today: the real calculator over
	 * the ledger's rows up to today, one account, a 3-month window.
	 */
	private function ledgerRunway(): array {
		$today = $this->now->format('Y-m-d');
		$rows = array_values(array_filter($this->ledger, fn (RunwayRow $row) => $row->date <= $today));
		$total = '40000.00';
		foreach ($rows as $row) {
			$total = bcadd($total, $row->amount, 2);
		}
		return (new RunwayCalculator())->calculate([
			'today' => $today,
			'total' => $total,
			'chosenAccountIds' => [1],
			'windowMonths' => 3,
			'firstActivityDate' => '2025-10-01',
			'rows' => $rows,
			'historyRows' => $rows,
			'futureRows' => [],
			'includeScheduled' => true,
			'bills' => [],
			'oneOffs' => [],
			'budgets' => [],
		]);
	}
}
