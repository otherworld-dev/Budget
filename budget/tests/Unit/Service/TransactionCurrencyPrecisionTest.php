<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Money in a transaction's own currency keeps that currency's decimals:
 * eight for bitcoin, none for yen. The account register's running and
 * projected balances and the rescale of split parts after an amount edit
 * worked in pennies.
 */
class TransactionCurrencyPrecisionTest extends TestCase {
	private TransactionMapper $mapper;
	private AccountMapper $accounts;
	private TransactionSplitMapper $splits;
	private TransactionService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(TransactionMapper::class);
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->splits = $this->createMock(TransactionSplitMapper::class);
		$this->service = new TransactionService(
			$this->mapper,
			$this->accounts,
			$this->createMock(\OCA\Budget\Db\TransactionTagMapper::class),
			$this->splits,
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new UserClock($this->createMock(IConfig::class))
		);
	}

	private function account(string $currency, float $opening = 0.0): Account {
		$account = new Account();
		$account->setId(10);
		$account->setUserId('alice');
		$account->setName('Wallet');
		$account->setCurrency($currency);
		$account->setBalance($opening);
		$account->setOpeningBalance($opening);
		return $account;
	}

	public function testTheRegisterKeepsABitcoinBalanceToTheSatoshi(): void {
		$this->accounts->method('findById')->willReturn($this->account('BTC', 0.5));
		$this->mapper->method('findWithFilters')->willReturn(['transactions' => [
			['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4],
		], 'total' => 4]);
		$this->mapper->method('getAllTransactionsForBalance')->willReturn([
			['id' => 1, 'amount' => '0.00123456', 'type' => 'credit', 'status' => 'cleared'],
			['id' => 2, 'amount' => '0.00123456', 'type' => 'credit', 'status' => 'cleared'],
			['id' => 3, 'amount' => '0.00500000', 'type' => 'debit', 'status' => 'cleared'],
			['id' => 4, 'amount' => '0.00010000', 'type' => 'credit', 'status' => 'scheduled'],
		]);

		$balances = $this->service->findWithFilters('alice', ['accountId' => 10], 50, 0)['runningBalances'];

		$this->assertSame(['0.50123456', '0.50246912', '0.49746912', '0.49756912'], [
			$balances[1], $balances[2], $balances[3], $balances[4],
		]);
	}

	/**
	 * @param string[] $parts
	 * @param string[] $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('rescaleCases')]
	public function testSplitPartsRescaleToTheCurrencysDecimals(string $currency, float $old, array $parts, float $new, array $expected): void {
		$this->accounts->method('findById')->willReturn($this->account($currency));
		$this->accounts->method('find')->willReturn($this->account($currency));
		$transaction = new Transaction();
		$transaction->setId(1);
		$transaction->setAccountId(10);
		$transaction->setAmount($old);
		$transaction->setType('debit');
		$transaction->setIsSplit(true);
		$this->mapper->method('find')->willReturn($transaction);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('getNetChangeAll')->willReturn(0.0);

		$entities = [];
		foreach ($parts as $i => $amount) {
			$split = new TransactionSplit();
			$split->setId($i + 1);
			$split->setTransactionId(1);
			$split->setAmount($amount);
			$entities[] = $split;
		}
		$this->splits->method('findByTransaction')->willReturn($entities);

		$this->service->update(1, 'alice', ['amount' => $new]);

		$this->assertSame($expected, array_map(static fn (TransactionSplit $s) => $s->getAmount(), $entities));
	}

	public static function rescaleCases(): array {
		return [
			// Pennies made these 0.06 + 0.06, and 0.00345678 BTC left the categories
			'bitcoin keeps eight places' => ['BTC', 0.5, ['0.25', '0.25'], 0.12345678, ['0.06172839', '0.06172839']],
			// A change smaller than a penny is still a change in bitcoin
			'a sub-penny bitcoin edit rescales' => ['BTC', 0.5, ['0.3', '0.2'], 0.5005, ['0.30030000', '0.20020000']],
			'yen has no decimals' => ['JPY', 1000.0, ['500', '500'], 1001.0, ['501', '500']],
			'pounds stay in pennies' => ['GBP', 3.0, ['1.00', '1.00', '1.00'], 10.0, ['3.33', '3.33', '3.34']],
		];
	}
}
