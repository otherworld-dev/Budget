<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\UpcomingBillsService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

class UpcomingBillsServiceTest extends TestCase {
	private BillService $bills;
	private GranularShareService $shares;
	private AccountMapper $accounts;
	private UpcomingBillsService $service;

	protected function setUp(): void {
		$this->bills = $this->createMock(BillService::class);
		$this->bills->method('enrichBillsWithCurrency')->willReturnArgument(0);
		$this->bills->method('enrichSharedBillsWithCurrency')->willReturnCallback(
			fn (array $bills) => array_map(fn ($b) => $b + ['currency' => 'EUR'], $bills)
		);
		$this->shares = $this->createMock(GranularShareService::class);
		$this->shares->method('getVisibleAccountIds')->willReturn([1, 9]);
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->accounts->method('findByIds')->with([1, 9])->willReturn([self::account(1, 'Current'), self::account(9, 'Joint')]);

		// The user's own date, not the server's
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-09-28');
		$this->service = new UpcomingBillsService($this->bills, $this->shares, $this->accounts, $clock);
	}

	private static function account(int $id, string $name): Account {
		$a = new Account();
		$a->setId($id);
		$a->setName($name);
		return $a;
	}

	private static function bill(int $id, string $due, ?int $accountId = null): Bill {
		$b = new Bill();
		$b->setId($id);
		$b->setName("Bill $id");
		$b->setAmount(10.0);
		$b->setIsActive(true);
		$b->setNextDueDate($due);
		$b->setAccountId($accountId);
		return $b;
	}

	private static function shared(int $id, string $due, bool $active = true, ?int $accountId = null): array {
		return ['id' => $id, 'name' => "Shared $id", 'amount' => 5.0, 'isActive' => $active, 'nextDueDate' => $due,
			'accountId' => $accountId, 'userId' => 'owner1', '_shared' => true];
	}

	public function testOwnAndSharedBillsInOneListOverdueFirst(): void {
		$this->bills->method('findUpcoming')->with('user1', 14)->willReturn([self::bill(1, '2026-10-02', 1), self::bill(2, '2026-09-20')]);
		$this->shares->method('getSharedBills')->willReturn([
			self::shared(10, '2026-09-29', true, 9),
			self::shared(11, '2026-09-01'),          // overdue: included
			self::shared(12, '2026-10-20'),          // beyond 14 days: left out
			self::shared(13, '2026-09-30', false),   // inactive: left out
		]);

		$bills = $this->service->upcoming('user1', 14);

		$this->assertSame([11, 2, 10, 1], array_column($bills, 'id'));
		$this->assertSame([true, true, false, false], array_column($bills, 'overdue'));
		$this->assertSame('EUR', $bills[2]['currency']);
	}

	public function testAnAccountNameOnlyForAnAccountTheUserCanSee(): void {
		$this->bills->method('findUpcoming')->willReturn([self::bill(1, '2026-10-01', 1)]);
		// The owner's own account 44 was never shared
		$this->shares->method('getSharedBills')->willReturn([self::shared(10, '2026-10-01', true, 44)]);

		$bills = $this->service->upcoming('user1', 14);

		$this->assertSame(['Current', null], array_column($bills, 'accountName'));
	}
}
