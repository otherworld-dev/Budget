<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The account page's tiles and balance chart for an account shared with
 * the viewer. Both looked the account up as the viewer's own, so a shared
 * account's page showed neither (#399 review).
 */
class AccountSharedViewTest extends TestCase {
	private AccountService $service;
	private TransactionMapper $transactions;

	protected function setUp(): void {
		$joint = new Account();
		$joint->setId(7);
		$joint->setUserId('alice');
		$joint->setName('Joint');
		$joint->setCurrency('GBP');
		$joint->setBalance(500.0);

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willThrowException(new DoesNotExistException('not yours'));
		$accounts->method('findById')->willReturn($joint);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canAccess')->willReturnCallback(
			fn (string $user, string $type, int $id) => $user === 'bob' && $type === 'account' && $id === 7
		);
		$this->transactions = $this->createMock(TransactionMapper::class);
		$this->transactions->method('getAccountMetrics')->willReturn(['count' => 3, 'average' => 10.0, 'monthIncome' => 0.0, 'monthExpenses' => 30.0]);
		$this->transactions->method('getDailyBalanceChanges')->willReturn([]);

		$this->service = new AccountService(
			$accounts, $this->transactions, $this->createMock(InterestRateMapper::class),
			$this->createMock(CurrencyConversionService::class), $shares,
			$this->createMock(TransactionService::class), $this->createMock(IL10N::class),
		);
	}

	public function testSomeoneItIsSharedWithSeesItsTiles(): void {
		$this->assertSame(3, $this->service->getAccountMetrics(7, 'bob')['totalTransactions']);
	}

	public function testSomeoneItIsSharedWithSeesItsBalanceHistory(): void {
		$history = $this->service->getBalanceHistory(7, 'bob', 3);

		$this->assertCount(3, $history);
		$this->assertSame(500.0, end($history)['balance']);
	}

	public function testAnyoneElseStillGetsNothing(): void {
		$this->expectException(DoesNotExistException::class);

		$this->service->getAccountMetrics(7, 'mallory');
	}
}
