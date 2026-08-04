<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\UserClock;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of this class is that "today" is the USER's today. These
 * tests pin the timezone resolution order and, crucially, the case that
 * caused the bug: a user whose local date runs ahead of the server's.
 */
class UserClockTest extends TestCase {
	private IConfig $config;

	/** userValue['user']['timezone'] and systemValue['default_timezone'] */
	private array $userTz = [];
	private string $systemTz = '';

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')
			->willReturnCallback(fn (string $uid, string $app, string $key, $default = '') => $this->userTz[$uid] ?? $default);
		$this->config->method('getSystemValue')
			->willReturnCallback(fn (string $key, $default = '') => $key === 'default_timezone' ? $this->systemTz : $default);
	}

	private function clock(): UserClock {
		return new UserClock($this->config);
	}

	public function testUsesTheUsersOwnTimezone(): void {
		$this->userTz['sydney'] = 'Australia/Sydney';
		$this->userTz['la'] = 'America/Los_Angeles';

		$sydney = $this->clock()->today('sydney');
		$la = $this->clock()->today('la');

		$this->assertSame(
			(new \DateTimeImmutable('now', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d'),
			$sydney
		);
		$this->assertSame(
			(new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'),
			$la
		);
	}

	public function testFallsBackToTheInstanceTimezoneThenTheServer(): void {
		$this->systemTz = 'Europe/Berlin';

		$this->assertSame(
			(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
			$this->clock()->today('nobody')
		);

		$this->systemTz = '';
		$this->assertSame(date('Y-m-d'), $this->clock()->today('nobody'));
	}

	public function testAnUnusableStoredTimezoneDoesNotBreakASave(): void {
		// Garbage in user config must degrade to the next candidate, never
		// throw out of a transaction write.
		$this->userTz['broken'] = 'Not/AZone';
		$this->systemTz = 'Europe/Berlin';

		$this->assertSame(
			(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
			$this->clock()->today('broken')
		);
	}

	public function testANullUserFallsBackWithoutTouchingUserConfig(): void {
		$this->systemTz = 'Europe/Berlin';

		$this->assertSame(
			(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
			$this->clock()->today(null)
		);
	}

	// ── isFutureDate: the actual scheduled/cleared decision ─────────

	public function testTheUsersOwnTodayIsNeverInTheFuture(): void {
		// THE REGRESSION. Just after midnight in Sydney the user's date is
		// already tomorrow by UTC — recording "today" must still be today,
		// or it is filed as scheduled and left out of the balance.
		$this->userTz['sydney'] = 'Australia/Sydney';
		$clock = $this->clock();
		$sydneyToday = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Sydney')))->format('Y-m-d');

		$this->assertFalse($clock->isFutureDate($sydneyToday, 'sydney'));
	}

	public function testGenuineFutureDatesAreStillFuture(): void {
		// The fix must not clear real scheduled payments.
		$this->userTz['london'] = 'Europe/London';
		$clock = $this->clock();
		$londonToday = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));

		$this->assertTrue($clock->isFutureDate($londonToday->modify('+2 days')->format('Y-m-d'), 'london'));
		$this->assertFalse($clock->isFutureDate($londonToday->modify('-1 day')->format('Y-m-d'), 'london'));
	}
}
