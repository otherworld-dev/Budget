<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\PromoRateWarningService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PromoRateWarningServiceTest extends TestCase {
	private AccountMapper&MockObject $mapper;
	private IManager&MockObject $manager;
	private PromoRateWarningService $service;

	/** @var array<int, array{0: string, 1: array}> */
	private array $subjects = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(AccountMapper::class);
		$this->manager = $this->createMock(IManager::class);
		$this->service = new PromoRateWarningService($this->mapper, $this->manager);

		$this->subjects = [];
		$this->manager->method('createNotification')->willReturnCallback(function () {
			$n = $this->createMock(INotification::class);
			$n->method('setApp')->willReturnSelf();
			$n->method('setUser')->willReturnSelf();
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setObject')->willReturnSelf();
			$n->method('setSubject')->willReturnCallback(function (string $s, array $p) use ($n) {
				$this->subjects[] = [$s, $p];
				return $n;
			});
			return $n;
		});
	}

	private function account(string $end, ?string $warned = null, bool $closed = false): Account {
		$a = new Account();
		$a->setId(7);
		$a->setUserId('alice');
		$a->setName('Barclaycard');
		$a->setInterestRate(22.9);
		$a->setPromoRate(0.0);
		$a->setPromoStart('2026-01-01');
		$a->setPromoEnd($end);
		$a->setPromoWarned($warned);
		$a->setClosed($closed);
		return $a;
	}

	private function sendFor(Account $account, int $expectedMarks): int {
		$this->mapper->method('findAll')->with('alice')->willReturn([$account]);
		$this->mapper->expects($this->exactly($expectedMarks))->method('markPromoWarned')
			->with(7, $account->getPromoEnd());
		return $this->service->sendDueWarnings('alice', '2026-11-09');
	}

	public function testWarnsThirtyDaysBefore(): void {
		$this->manager->expects($this->once())->method('notify');
		$this->assertSame(1, $this->sendFor($this->account('2026-12-09'), 1));
		$this->assertSame('promo_rate_ending', $this->subjects[0][0]);
		$this->assertSame(
			['accountName' => 'Barclaycard', 'promoRate' => '0', 'promoEnd' => '2026-12-09', 'normalRate' => '22.9'],
			$this->subjects[0][1]
		);
	}

	public function testDoesNotWarnEarlier(): void {
		$this->manager->expects($this->never())->method('notify');
		$this->assertSame(0, $this->sendFor($this->account('2026-12-10'), 0));
	}

	public function testWarnsWhenEnteredLate(): void {
		$this->assertSame(1, $this->sendFor($this->account('2026-11-20'), 1));
	}

	public function testEndedPromoIsNotWarned(): void {
		$this->manager->expects($this->never())->method('notify');
		$this->assertSame(0, $this->sendFor($this->account('2026-11-09'), 0));
	}

	public function testAlreadyWarnedIsNotRepeated(): void {
		$this->manager->expects($this->never())->method('notify');
		$this->assertSame(0, $this->sendFor($this->account('2026-12-01', '2026-12-01'), 0));
	}

	public function testChangedEndDateReArmsWarning(): void {
		$this->assertSame(1, $this->sendFor($this->account('2026-12-05', '2026-12-01'), 1));
	}

	public function testClosedAccountIsNotWarned(): void {
		$this->manager->expects($this->never())->method('notify');
		$this->assertSame(0, $this->sendFor($this->account('2026-12-01', null, true), 0));
	}
}
