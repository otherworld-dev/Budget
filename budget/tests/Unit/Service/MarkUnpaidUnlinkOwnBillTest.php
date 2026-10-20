<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Unpaid unlinks only a row still filed under its own bill (#399
 * review).
 *
 * While a skip stands the bill's latest payment can be unlinked, and the row
 * then filed under another bill. Undoing the skip brings back the snapshot
 * that names the row, and Mark Unpaid took it off the other bill: the
 * unlink had no bill check, unlike the delete beside it.
 */
class MarkUnpaidUnlinkOwnBillTest extends TestCase {
	/** @var array<int, Transaction> */
	private array $rows = [];
	/** @var list<array{0: int, 1: int|null}> rows written: [id, billId] */
	private array $written = [];

	private function transactions(): TransactionService {
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('findById')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? null);
		$mapper->method('find')->willReturnCallback(fn (int $id) => $this->rows[$id]);
		$mapper->method('update')->willReturnCallback(function (Transaction $row) {
			$this->written[] = [$row->getId(), $row->getBillId()];
			return $row;
		});
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findById')->willReturnCallback(function (int $id) {
			$account = new Account();
			$account->setId($id);
			$account->setUserId('terje');
			return $account;
		});
		return new TransactionService(
			$mapper,
			$accounts,
			$this->createMock(TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new UserClock($this->createMock(\OCP\IConfig::class)),
		);
	}

	private function row(int $id, ?int $billId, ?int $linked = null): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setAccountId(3);
		$row->setBillId($billId);
		$row->setLinkedTransactionId($linked);
		$this->rows[$id] = $row;
		return $row;
	}

	public function testARowFiledUnderAnotherBillKeepsItsLink(): void {
		// The payment's row L, unlinked while the skip stood, now pays bill 22
		$this->row(77, 22);

		$this->transactions()->unlinkBillAsAccountOwner(77, 9);

		$this->assertSame([], $this->written);
		$this->assertSame(22, $this->rows[77]->getBillId());
	}

	public function testARowStillFiledUnderItsBillIsUnlinked(): void {
		$this->row(77, 9, 78);
		$this->row(78, 9);

		$this->transactions()->unlinkBillAsAccountOwner(77, 9);

		$this->assertSame([[77, null], [78, null]], $this->written);
	}

	public function testWithoutABillToCheckItUnlinksAsBefore(): void {
		$this->row(77, 22);

		$this->transactions()->unlinkBillAsAccountOwner(77);

		$this->assertSame([[77, null]], $this->written);
	}

	public function testMarkUnpaidNamesItsOwnBill(): void {
		$bill = new Bill();
		$bill->setId(9);
		$bill->setUserId('terje');
		$bill->setName('SBB GA Leon');
		$bill->setAmount(340.0);
		$bill->setFrequency('monthly');
		$bill->setDueDay(10);
		$bill->setIsActive(true);
		$bill->setAccountId(3);
		$bill->setCreateTransaction(false);
		$bill->setNextDueDate('2026-11-10');
		$bill->setLastPaidDate('2026-10-10');
		$bill->setPaidUndoState((string)json_encode([
			'previousState' => ['lastPaidDate' => '2026-09-10', 'nextDueDate' => '2026-10-10', 'isActive' => true],
			'createdTransactionIds' => [],
			'linkedTransactionId' => 77,
			'paidDate' => '2026-10-10',
		]));
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('find')->willReturn($bill);
		$mapper->method('update')->willReturnArgument(0);
		$ledger = $this->createMock(TransactionService::class);
		$ledger->expects($this->once())->method('unlinkBillAsAccountOwner')->with(77, 9);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$service = new BillService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$ledger,
			$l,
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
		);

		$service->markUnpaid(9, 'terje');
	}
}
