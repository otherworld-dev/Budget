<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use PHPUnit\Framework\TestCase;

class AccountBalanceCalculatorTest extends TestCase {
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private AccountBalanceCalculator $calculator;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->calculator = new AccountBalanceCalculator($this->accountMapper, $this->transactionMapper);
	}

	private function account(string $currency, ?float $opening): Account {
		$account = new Account();
		$account->setId(7);
		$account->setUserId('owner');
		$account->setCurrency($currency);
		$account->setOpeningBalance($opening);
		return $account;
	}

	public function testFiatBalanceIsTwoDecimalPlaces(): void {
		$this->transactionMapper->method('getNetChangeAll')->with(7)->willReturn(-12.5);

		$this->assertSame('87.50', $this->calculator->expectedBalance($this->account('GBP', 100.0)));
	}

	public function testCryptoBalanceKeepsEightDecimalPlaces(): void {
		$this->transactionMapper->method('getNetChangeAll')->willReturn(0.00000001);

		$this->assertSame('0.12345679', $this->calculator->expectedBalance($this->account('BTC', 0.12345678)));
	}

	public function testMissingOpeningBalanceCountsAsZero(): void {
		$this->transactionMapper->method('getNetChangeAll')->willReturn(40.0);

		$this->assertSame('40.00', $this->calculator->expectedBalance($this->account('EUR', null)));
	}

	public function testTinyOpeningBalanceDoesNotBreakOnScientificNotation(): void {
		$this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);

		// (string) 1.0E-5 is "1.0E-5", which bcmath rejects
		$this->assertSame('0.00001000', $this->calculator->expectedBalance($this->account('ETH', 0.00001)));
	}

	public function testRecalculateWritesTheBalanceToTheOwnersRow(): void {
		$this->transactionMapper->method('getNetChangeAll')->willReturn(1.0);
		$this->accountMapper->expects($this->once())
			->method('updateBalance')
			->with(7, '11.00', 'owner');

		$this->assertSame('11.00', $this->calculator->recalculate($this->account('USD', 10.0)));
	}
}
