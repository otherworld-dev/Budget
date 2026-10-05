<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A bill posts into the account it names, which may be another user's,
 * shared with the bill's owner. Once that share is revoked, left or cut to
 * read, the bill must stop writing to (and reading from) the account: its
 * actions used to check only access to the bill, which the owner keeps.
 */
class BillAccountAccessTest extends TestCase {
	private BillService $service;
	private BillMapper $mapper;
	private TransactionService $transactionService;
	private GranularShareService $shares;
	/** @var array<int, bool> account id => writable by the bill owner */
	private array $writable = [];
	/** @var array<int, bool> account id => visible to the acting user */
	private array $visible = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->shares = $this->createMock(GranularShareService::class);
		$this->shares->method('canWrite')->willReturnCallback(
			fn (string $user, string $type, int $id) => $type === 'account' && ($this->writable[$id] ?? false)
		);
		$this->shares->method('canAccess')->willReturnCallback(
			fn (string $user, string $type, int $id) => $type === 'account' && ($this->visible[$id] ?? false)
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$calculator = $this->createMock(FrequencyCalculator::class);
		$calculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->service = new BillService(
			$this->mapper,
			$calculator,
			$this->createMock(RecurringBillDetector::class),
			$this->transactionService,
			$l,
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			$this->shares,
		);
	}

	private function bill(array $overrides = []): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('bob');
		$bill->setName('Rent');
		$bill->setAmount(500.0);
		$bill->setFrequency($overrides['frequency'] ?? 'monthly');
		$bill->setDueDay(15);
		$bill->setIsActive(true);
		$bill->setAccountId(10);
		$bill->setNextDueDate('2099-06-15');
		$bill->setLastPaidDate($overrides['lastPaidDate'] ?? null);
		$bill->setAutoPayEnabled($overrides['autoPayEnabled'] ?? false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer($overrides['isTransfer'] ?? false);
		$bill->setDestinationAccountId($overrides['destinationAccountId'] ?? null);
		$bill->setPaidUndoState($overrides['paidUndoState'] ?? null);
		$this->mapper->method('find')->willReturn($bill);
		return $bill;
	}

	private function expectNothingWritten(): void {
		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->transactionService->expects($this->never())->method('clearScheduledBillTransaction');
		$this->transactionService->expects($this->never())->method('deleteScheduledBillTransactions');
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->mapper->expects($this->never())->method('update');
	}

	public function testMarkPaidIsRefusedOnceTheAccountIsNoLongerWritable(): void {
		$this->bill();
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markPaid(1, 'bob');
	}

	public function testMarkPaidIsRefusedWhenATransfersDestinationIsNoLongerWritable(): void {
		$this->writable[10] = true;
		$this->bill(['isTransfer' => true, 'destinationAccountId' => 20]);
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markPaid(1, 'bob');
	}

	/**
	 * A transfer whose destination was taken away (a share ended, the owner
	 * of that account reset or was deleted, or it was closed) still said
	 * "transfer": Mark Paid then "paid" it with nothing recorded and moved
	 * it on. It is refused with the reason until a destination is chosen.
	 */
	public function testATransferThatLostItsDestinationIsRefusedNotPaidWithNothingRecorded(): void {
		$this->writable[10] = true;
		$this->bill(['isTransfer' => true, 'destinationAccountId' => null]);
		$this->expectNothingWritten();

		$this->expectExceptionMessage('This bill uses an account you can no longer change. Edit the bill and choose another account.');
		$this->service->markPaid(1, 'bob', null, true);
	}

	public function testATransferThatLostItsDestinationCannotBeSkippedEither(): void {
		$this->writable[10] = true;
		$this->bill(['isTransfer' => true, 'destinationAccountId' => null]);
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->skipPayment(1, 'bob');
	}

	public function testATransferThatLostItsDestinationStopsAutoPaying(): void {
		$this->writable[10] = true;
		$this->bill(['isTransfer' => true, 'destinationAccountId' => null, 'autoPayEnabled' => true])->setNextDueDate('2026-06-15');
		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->transactionService->expects($this->never())->method('clearScheduledBillTransaction');
		$this->mapper->expects($this->once())->method('updateFields')
			->with(1, 'bob', ['auto_pay_enabled' => false, 'auto_pay_failed' => true]);

		$this->assertFalse($this->service->processAutoPay(1, 'bob')['success']);
	}

	public function testMarkPaidStillWorksOnAWritableAccount(): void {
		$this->writable[10] = true;
		$this->bill();
		$payment = new Transaction();
		$payment->setId(99);
		$this->transactionService->method('clearScheduledBillTransaction')->willReturn($payment);

		$result = $this->service->markPaid(1, 'bob');

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
	}

	public function testSkipIsRefusedOnceTheAccountIsNoLongerWritable(): void {
		$this->bill();
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->skipPayment(1, 'bob');
	}

	public function testUndoSkipIsRefusedOnceTheAccountIsNoLongerWritable(): void {
		$this->bill();
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'bob', '2099-05-15');
	}

	public function testRecordMissedPaymentIsRefusedOnceTheAccountIsNoLongerWritable(): void {
		$this->bill(['lastPaidDate' => '2099-05-15']);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(1, 'bob');
	}

	public function testMarkUnpaidIsRefusedOnceTheAccountIsNoLongerWritable(): void {
		// Reverting deletes the payment from the owner's ledger, where it
		// may have been reconciled since
		$this->bill(['paidUndoState' => json_encode([
			'previousState' => ['nextDueDate' => '2099-05-15', 'isActive' => true],
			'createdTransactionIds' => [55],
		])]);
		$this->expectNothingWritten();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markUnpaid(1, 'bob');
	}

	public function testAutoPayStopsAndIsDisabledOnceTheAccountIsNoLongerWritable(): void {
		// Due, as the job only asks for bills that are
		$this->bill(['autoPayEnabled' => true])->setNextDueDate('2026-06-15');
		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->transactionService->expects($this->never())->method('clearScheduledBillTransaction');
		$this->mapper->expects($this->once())->method('updateFields')
			->with(1, 'bob', ['auto_pay_enabled' => false, 'auto_pay_failed' => true]);

		$result = $this->service->processAutoPay(1, 'bob');

		$this->assertFalse($result['success']);
	}

	public function testTurningPreBookingOnDoesNotBookIntoAnAccountNoLongerWritable(): void {
		$bill = $this->bill();
		$bill->setCreateTransaction(false);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->service->update(1, 'bob', ['createTransaction' => true]);
	}

	public function testEndingAShareDropsThePlaceholdersBookedIntoTheLostAccount(): void {
		// The recipient's pending rows sat in the owner's ledger after the
		// share ended, where the owner could neither see the bill nor stop them
		$this->writable[11] = true;
		$lost = new Bill();
		$lost->setId(1);
		$lost->setUserId('bob');
		$lost->setAccountId(10);
		$kept = new Bill();
		$kept->setId(2);
		$kept->setUserId('bob');
		$kept->setAccountId(11);
		$noAccount = new Bill();
		$noAccount->setId(3);
		$noAccount->setUserId('bob');
		$this->mapper->method('findAll')->with('bob')->willReturn([$lost, $kept, $noAccount]);
		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);

		$this->service->dropUnwritablePlaceholders('bob');
	}

	public function testMatchingTransactionsAreHiddenFromAUserWhoCannotSeeTheAccount(): void {
		$this->bill();
		$this->transactionService->expects($this->never())->method('findBillPaymentCandidates');

		$this->assertSame([], $this->service->findMatchingTransactions(1, 'bob', 'bob'));
	}

	public function testMatchingTransactionsAreHiddenFromAUserWhoCanOnlyReadTheAccount(): void {
		// Linking one changes it, which a read-only share doesn't allow
		$this->visible[10] = true;
		$this->bill();
		$this->transactionService->expects($this->never())->method('findBillPaymentCandidates');

		$this->assertSame([], $this->service->findMatchingTransactions(1, 'bob', 'carol'));
	}

	public function testMatchingTransactionsAreListedForAUserWhoCanWriteToTheAccount(): void {
		$this->visible[10] = true;
		$this->writable[10] = true;
		$this->bill();
		$this->transactionService->expects($this->once())->method('findBillPaymentCandidates')->willReturn([['id' => 3]]);

		$this->assertSame([['id' => 3]], $this->service->findMatchingTransactions(1, 'bob', 'bob'));
	}
}
