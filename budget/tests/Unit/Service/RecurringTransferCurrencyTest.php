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
	/** @var TransactionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $mapper;
	/** @var Transaction[] the bill's pre-booked rows */
	private array $scheduled = [];
	/** @var array<int, Transaction> rows a test puts in the ledger, by id */
	private array $rows = [];

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
		$mapper->method('find')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? $this->inserted[$id - 1]);
		$mapper->method('findById')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? $this->inserted[$id - 1] ?? null);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('findAllScheduledByBillId')->willReturnCallback(fn () => $this->scheduled);
		$this->mapper = $mapper;

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

	/**
	 * 2.54.0 pre-booked GBP 100 out and EUR 100 in. Paying a fixed amount
	 * cleared that pair as it stood, so the euro account got EUR 100.
	 */
	public function testClearingAPairBookedWithOneNumberConvertsTheDeposit(): void {
		$this->conversion->method('convertBetween')->willReturnCallback(
			fn (float $amount, string $from, string $to) => $from === 'GBP' && $to === 'EUR' ? (string)($amount / 0.85) : null
		);
		$this->service->createFromBill('alice', $this->transfer(), null, 'scheduled');
		$this->inserted[1]->setAmount(100.0);
		$this->scheduled = $this->inserted;

		$this->service->clearScheduledBillTransaction('alice', 9, '2026-02-03', null, true, $this->transfer());

		$this->assertSame(['debit', 100.0, 'cleared'], [$this->inserted[0]->getType(), (float)$this->inserted[0]->getAmount(), $this->inserted[0]->getStatus()]);
		$this->assertSame(['credit', 117.65, 'cleared'], [$this->inserted[1]->getType(), (float)$this->inserted[1]->getAmount(), $this->inserted[1]->getStatus()]);
	}

	public function testAPairAlreadyConvertedIsClearedAsItStands(): void {
		// Including a deposit the user put right by hand
		$this->conversion->method('convertBetween')->willReturn('117.6470588235');
		$this->service->createFromBill('alice', $this->transfer(), null, 'scheduled');
		$this->inserted[1]->setAmount(117.40);
		$this->scheduled = $this->inserted;

		$this->service->clearScheduledBillTransaction('alice', 9, '2026-02-03', null, true, $this->transfer());

		$this->assertSame([100.0, 117.40], [(float)$this->inserted[0]->getAmount(), (float)$this->inserted[1]->getAmount()]);
		$this->assertSame(['cleared', 'cleared'], [$this->inserted[0]->getStatus(), $this->inserted[1]->getStatus()]);
	}

	public function testWithNoRateAPairBookedWithOneNumberIsNotCleared(): void {
		// The same number on both legs, as 2.54.0 booked them; no rate since
		$rate = true;
		$this->conversion->method('convertBetween')->willReturnCallback(function (float $amount) use (&$rate) {
			return $rate ? (string)$amount : null;
		});
		$this->service->createFromBill('alice', $this->transfer(), null, 'scheduled');
		$this->scheduled = $this->inserted;
		$rate = false;

		try {
			$this->service->clearScheduledBillTransaction('alice', 9, '2026-02-03', null, true, $this->transfer());
			$this->fail('Clearing must not book the same number in two currencies');
		} catch (\Exception $e) {
			$this->assertStringContainsString('exchange rate', $e->getMessage());
		}
		$this->assertSame(['scheduled', 'scheduled'], [$this->inserted[0]->getStatus(), $this->inserted[1]->getStatus()]);
	}

	/** The bank's own withdrawal of the transfer, in the source's currency */
	private function bankWithdrawal(): Transaction {
		$tx = new Transaction();
		$tx->setId(70);
		$tx->setAccountId(1);
		$tx->setType('debit');
		$tx->setAmount(100.0);
		$tx->setDate('2026-02-01');
		$tx->setBillId(9);
		$tx->setImportId('bank-1');
		return $tx;
	}

	/**
	 * Paid by linking the bank's GBP 100 withdrawal, the transfer booked
	 * EUR 100 in the destination: the withdrawal's number, unconverted
	 */
	public function testALinkedWithdrawalsDepositIsConvertedIntoTheDestinationsCurrency(): void {
		$this->conversion->method('convertBetween')->with(100.0, 'GBP', 'EUR', 'alice', '2026-02-01')->willReturn('117.6470588235');
		$this->mapper->method('findTransferArrivals')->willReturn([]);

		$this->service->completeTransferPayment($this->bankWithdrawal(), $this->transfer());

		$this->assertSame([2, 'credit', 117.65], [$this->inserted[0]->getAccountId(), $this->inserted[0]->getType(), (float)$this->inserted[0]->getAmount()]);
	}

	/**
	 * The destination's own credit is the arrival. It was looked for at the
	 * GBP figure, never found, and a second deposit booked beside it. The
	 * bank's rate isn't the app's, so a credit within a tenth is taken.
	 */
	public function testTheArrivalIsLookedForAtTheConvertedAmount(): void {
		$this->conversion->method('convertBetween')->willReturn('117.6470588235');
		$arrival = new Transaction();
		$arrival->setId(71);
		$arrival->setAccountId(2);
		$arrival->setType('credit');
		$arrival->setAmount(117.40);
		$arrival->setDate('2026-02-02');
		$this->rows[71] = $arrival;
		$this->mapper->expects($this->once())->method('findTransferArrivals')
			->with(2, 117.65, '2026-01-29', '2026-02-04', $this->callback(fn (float $margin) => abs($margin - 11.765) < 0.001))
			->willReturn([$arrival]);
		$this->mapper->expects($this->once())->method('linkTransactions')->with(70, 71);

		$this->assertNull($this->service->completeTransferPayment($this->bankWithdrawal(), $this->transfer()));
		$this->assertSame([], $this->inserted);
		$this->assertSame(9, $arrival->getBillId());
	}

	public function testWithNoRateALinkedWithdrawalGetsNoDeposit(): void {
		$this->conversion->method('convertBetween')->willReturn(null);
		$this->mapper->expects($this->never())->method('findTransferArrivals');
		$this->mapper->expects($this->never())->method('linkTransactions');

		try {
			$this->service->completeTransferPayment($this->bankWithdrawal(), $this->transfer());
			$this->fail('A transfer with no rate must not book its arrival');
		} catch (\Exception $e) {
			$this->assertStringContainsString('exchange rate', $e->getMessage());
		}
		$this->assertSame([], $this->inserted);
	}
}
