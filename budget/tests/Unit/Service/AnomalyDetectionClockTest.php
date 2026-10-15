<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserClock;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * Month to date is the user's month. On the server's UTC date the month
 * turned up to a day early or late, so the check and its once-a-month
 * notice could look at the wrong month around the 1st.
 */
class AnomalyDetectionClockTest extends TestCase {
	public function testMonthToDateIsTheUsersMonth(): void {
		$category = new Category();
		$category->setId(1);
		$category->setName('Groceries');
		$category->setType('expense');
		$categories = $this->createMock(CategoryMapper::class);
		$categories->method('findAll')->willReturn([$category]);

		$asked = [];
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getCategorySpendingBatch')
			->willReturnCallback(function (array $ids, string $start, string $end) use (&$asked) {
				$asked[] = [$start, $end];
				return [];
			});
		$clock = $this->createMock(UserClock::class);
		$clock->method('now')->with('alice')
			->willReturn(new \DateTimeImmutable('2030-06-20 10:00', new \DateTimeZone('Pacific/Auckland')));

		$service = new AnomalyDetectionService(
			$categories,
			$transactions,
			$this->createMock(SettingService::class),
			$this->createMock(AmountFormatter::class),
			$this->createMock(INotificationManager::class),
			$clock
		);

		$service->detect('alice');

		$this->assertSame(['2030-06-01', '2030-06-20'], $asked[0]);
		// The six months before it
		$this->assertSame(['2029-12-01', '2029-12-31'], $asked[6]);
	}
}
