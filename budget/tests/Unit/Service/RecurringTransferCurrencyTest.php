<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;

/**
 * A recurring transfer between accounts in two currencies booked the same
 * number on both legs: GBP 100 out, EUR 100 in. The other leg is converted
 * now, and with no rate to convert at nothing is booked rather than a
 * wrong figure.
 */
class RecurringTransferCurrencyTest extends TestCase {
	/** @var Transaction[] */
	private array $inserted = [];
	private CurrencyConversionService $conversion;
	private TransactionService $service;
	/** @var Transaction[] the bill's pre-booked rows */
	private array $scheduled = [];

	protected function setUp(): void {
		$accounts = [
			1 => $this->account(1, 'GBP'),
			2 => $this->account(2, 'EUR'),
		];
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findById')->willReturnCallback(fn (int $id) => $accounts[$id]);
		$accountMapper->method('find')->willReturnCallback(fn (int $id) => $accounts[$id]);
		$accountMapper->method('updateBalance')->willReturnCallback(fn (int $id) => $accounts[$id]);

		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Transaction $tx) {
			$tx->setId(count($this->inserted) + 1);
			$this->inserted[] = $tx;
			return $tx;
		});
		$mapper->method('find')->willReturnCallback(fn (int $id) => $this->inserted[$id - 1]);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('findAllScheduledByBillId')->willReturnCallback(fn () => $this->scheduled);

		$this->conversion = $this->createMock(CurrencyConversionService::class);

		$this->service = new TransactionService(
			$mapper,
			$accountMapper,
			$this->createMock(\OCA\Budget\Db\TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new \OCA\Budget\Service\UserClock($this->createMock(\OCP\IConfig::class)),
			$this->conversion,
		);
	}

	private function account(int $id, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName('Account ' . $id);
		$account->setType('checking');
		$account->setBalance(1000.0);
		$account->setCurrency($currency);
		return $account;
	}

	private function transfer(string $amountType = 'fixed'): Bill {
		$bill = new Bill();
		$bill->setId(9);
		$bill->setUserId('alice');
		$bill->setName('To euro savings');
		$bill->setAmount(100.0);
		$bill->setAmountType($amountType);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(2);
		$bill->setIsTransfer(true);
		$bill->setNextDueDate('2026-02-01');
		return $bill;
	}

	public function testTheDepositIsConvertedIntoTheDestinationsCurrency(): void {
		$this->conversion->method('convertBetween')->with(100.0, 'GBP', 'EUR', 'alice')->willReturn('117.3456');

		$this->service->createFromBill('alice', $this->transfer(), '2026-02-01');

		$this->assertSame([1, 'debit', 100.0], [$this->inserted[0]->getAccountId(), $this->inserted[0]->getType(), (float)$this->inserted[0]->getAmount()]);
		$this->assertSame([2, 'credit', 117.35], [$this->inserted[1]->getAccountId(), $this->inserted[1]->getType(), (float)$this->inserted[1]->getAmount()]);
	}

	/** A statement amount comes from the card, in the card's currency: the source leg is converted */
	public function testAStatementAmountIsTheCardsAndTheDebitIsConverted(): void {
		$this->conversion->method('convertBetween')->with(100.0, 'EUR', 'GBP', 'alice')->willReturn('85.2');

		$this->service->createFromBill('alice', $this->transfer('statement'), '2026-02-01');

		$this->assertSame(85.2, (float)$this->inserted[0]->getAmount());
		$this->assertSame(100.0, (float)$this->inserted[1]->getAmount());
	}

	public function testWithNoRateNothingIsBooked(): void {
		$this->conversion->method('convertBetween')->willReturn(null);

		try {
			$this->service->createFromBill('alice', $this->transfer(), '2026-02-01');
			$this->fail('A transfer with no rate must not book');
		} catch (\Exception $e) {
			$this->assertStringContainsString('exchange rate', $e->getMessage());
		}
		$this->assertSame([], $this->inserted);
	}

	/**
	 * Paying a EUR card's 500 statement from a GBP account by clearing the
	 * pre-booked pair (the default) booked 500 on both legs: 500 GBP out
	 */
	public function testClearingThePreBookedPairConvertsTheWithdrawal(): void {
		$this->conversion->method('convertBetween')->willReturnCallback(
			fn (float $amount, string $from, string $to) => $amount === 500.0 && $from === 'EUR' && $to === 'GBP' ? '426.1' : (string)$amount
		);
		$this->service->createFromBill('alice', $this->transfer('statement'), null, 'scheduled');
		$this->scheduled = $this->inserted;
		$bill = $this->transfer('statement');
		$bill->setAmount(500.0);

		$cleared = $this->service->clearScheduledBillTransaction('alice', 9, '2026-02-03', 500.0, true, $bill);

		$this->assertSame(1, $cleared->getId());
		$this->assertSame(['debit', 426.1, 'cleared'], [$this->inserted[0]->getType(), (float)$this->inserted[0]->getAmount(), $this->inserted[0]->getStatus()]);
		$this->assertSame(['credit', 500.0, 'cleared'], [$this->inserted[1]->getType(), (float)$this->inserted[1]->getAmount(), $this->inserted[1]->getStatus()]);
	}
}
