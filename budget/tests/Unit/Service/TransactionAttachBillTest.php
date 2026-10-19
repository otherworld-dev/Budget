<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Link to bill and Unlink from bill on a transaction (#399).
 *
 * Bills by Month reads a bill's payments from the rows that carry its id.
 * A payment typed in by hand (the reporter's 'Monatsrechnung' of 28 Sep,
 * entered on 15 Oct) or one of a bill since deleted never showed under any
 * bill, and the only way to give a row a bill was Mark Paid, which pays the
 * occurrence that is due now. Filing a row under a bill sets its bill and
 * nothing else.
 */
class TransactionAttachBillTest extends TestCase {
	private const USER = 'terje';
	private const LOHNKONTO = 3;
	private const CARD = 8;
	private const SAVINGS = 7;
	/** An account someone else owns and shared with the user */
	private const SHARED = 20;

	/** @var array<int, Transaction> the ledger, by id */
	private array $rows = [];
	/** @var array<int, Bill> */
	private array $bills = [];
	/** @var array<int, string> account id => owner */
	private array $owners = [
		self::LOHNKONTO => self::USER,
		self::CARD => self::USER,
		self::SAVINGS => self::USER,
		self::SHARED => 'anna',
	];
	/** @var list<array{0: int, 1: int|null}> rows written: [id, billId] */
	private array $written = [];

	private TransactionService $service;

	protected function setUp(): void {
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('find')->willReturnCallback(function (int $id, string $userId) {
			$row = $this->rows[$id] ?? null;
			if ($row === null || $this->owners[$row->getAccountId()] !== $userId) {
				throw new DoesNotExistException("Transaction {$id} not found");
			}
			return $row;
		});
		$mapper->method('findForAccounts')->willReturnCallback(function (int $id, array $accountIds) {
			$row = $this->rows[$id] ?? null;
			if ($row === null || !in_array($row->getAccountId(), $accountIds, true)) {
				throw new DoesNotExistException("Transaction {$id} not found");
			}
			return $row;
		});
		$mapper->method('findById')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? null);
		$mapper->method('update')->willReturnCallback(function (Transaction $row) {
			$this->written[] = [$row->getId(), $row->getBillId()];
			return $row;
		});

		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findById')->willReturnCallback(function (int $id) {
			$account = new Account();
			$account->setId($id);
			$account->setUserId($this->owners[$id]);
			$account->setCurrency('CHF');
			return $account;
		});

		$billMapper = $this->createMock(BillMapper::class);
		$billMapper->method('find')->willReturnCallback(function (int $id, string $userId) {
			$bill = $this->bills[$id] ?? null;
			if ($bill === null || $bill->getUserId() !== $userId) {
				throw new DoesNotExistException("Bill {$id} not found");
			}
			return $bill;
		});
		$billMapper->method('findByIds')->willReturnCallback(
			fn (array $ids) => array_values(array_intersect_key($this->bills, array_flip(array_map('intval', $ids))))
		);
		// Filing a row under a bill, or taking it out, never changes the bill
		foreach (['insert', 'update', 'updateFields', 'delete'] as $write) {
			$billMapper->expects($this->never())->method($write);
		}

		// Nor does it give the row the bill's category or tags, as Mark
		// Paid's link does: the row is a payment the user already filed
		$tagMapper = $this->createMock(TransactionTagMapper::class);
		$tagMapper->expects($this->never())->method('insert');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new TransactionService(
			$mapper,
			$accountMapper,
			$tagMapper,
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new \OCA\Budget\Service\UserClock($this->createMock(\OCP\IConfig::class)),
			l: $l,
			billMapper: $billMapper,
		);
	}

	private function row(int $id, array $fields = []): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setAccountId($fields['accountId'] ?? self::LOHNKONTO);
		$row->setDate($fields['date'] ?? '2026-09-28');
		$row->setDescription($fields['description'] ?? 'Payment');
		$row->setAmount($fields['amount'] ?? 100.0);
		$row->setType($fields['type'] ?? 'debit');
		$row->setStatus($fields['status'] ?? 'cleared');
		$row->setBillId($fields['billId'] ?? null);
		$row->setLinkedTransactionId($fields['linkedTransactionId'] ?? null);
		$row->setNotes($fields['notes'] ?? null);
		$row->setCategoryId($fields['categoryId'] ?? null);
		$row->setReconciled(false);
		$row->setIsSplit(false);
		$row->setCreatedAt($fields['createdAt'] ?? '2026-10-15 20:15:13');
		$row->setUpdatedAt($fields['createdAt'] ?? '2026-10-15 20:15:13');
		$this->rows[$id] = $row;
		return $row;
	}

	private function bill(int $id, array $fields = []): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setUserId($fields['userId'] ?? self::USER);
		$bill->setName($fields['name'] ?? "Bill {$id}");
		$bill->setAmount($fields['amount'] ?? 100.0);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setAccountId(array_key_exists('accountId', $fields) ? $fields['accountId'] : self::LOHNKONTO);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setPaidUndoState($fields['paidUndoState'] ?? null);
		$bill->setCategoryId($fields['categoryId'] ?? null);
		$bill->setTagIds($fields['tagIds'] ?? null);
		$this->bills[$id] = $bill;
		return $bill;
	}

	/** A Mark Paid snapshot naming the rows that payment booked or linked */
	private static function snapshot(array $created, ?int $linked = null, array $scheduled = []): string {
		return (string)json_encode([
			'previousState' => ['lastPaidDate' => null, 'nextDueDate' => '2026-09-28', 'isActive' => true],
			'createdTransactionIds' => $created,
			'scheduledTransactionIds' => $scheduled,
			'linkedTransactionId' => $linked,
			'hadScheduledTransaction' => false,
			'paidDate' => '2026-10-15',
		]);
	}

	private function refusal(callable $action): string {
		try {
			$action();
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->fail('Expected the request to be refused');
	}

	// ── Link to bill ────────────────────────────────────────────────

	/**
	 * The reporter's own case: a 'Monatsrechnung' of 100 dated 28 Sep, typed
	 * in on 15 Oct, filed under a one-time Corner Card bill on the same
	 * account. The bill isn't paid by it: its date, status and snapshot
	 * stay as they were (Mark Paid is still how an occurrence is settled).
	 */
	public function testATypedPaymentIsFiledUnderAOneTimeBillOnItsAccount(): void {
		$this->row(1586, ['description' => 'Monatsrechnung', 'date' => '2026-09-28', 'amount' => 100.0]);
		$bill = $this->bill(120, [
			'name' => 'Corner Card SA',
			'frequency' => 'one-time',
			'nextDueDate' => '2026-09-28',
			'categoryId' => 12,
			'tagIds' => '[5]',
		]);

		$linked = $this->service->attachToBill(1586, 120, self::USER);

		$this->assertSame(120, $linked->getBillId());
		$this->assertSame([[1586, 120]], $this->written);
		$this->assertNull($linked->getCategoryId());
		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-09-28', $bill->getNextDueDate());
		$this->assertNull($bill->getLastPaidDate());
		$this->assertNull($bill->getPaidUndoState());
	}

	public function testABillOnAnotherAccountIsRefused(): void {
		$this->row(1586);
		$this->bill(120, ['accountId' => self::SAVINGS]);

		$message = $this->refusal(fn () => $this->service->attachToBill(1586, 120, self::USER));

		$this->assertSame("That transaction can't pay this bill", $message);
		$this->assertSame([], $this->written);
	}

	/** A bill with no account pays from nowhere in particular, so no row is its own */
	public function testABillWithNoAccountIsRefused(): void {
		$this->row(1586);
		$this->bill(120, ['accountId' => null]);

		$this->refusal(fn () => $this->service->attachToBill(1586, 120, self::USER));

		$this->assertSame([], $this->written);
	}

	/** Money in is never a bill's payment, and the report only counts debits */
	public function testACreditIsRefused(): void {
		$this->row(1700, ['type' => 'credit']);
		$this->bill(120);

		$this->refusal(fn () => $this->service->attachToBill(1700, 120, self::USER));

		$this->assertSame([], $this->written);
	}

	/**
	 * CSX's second 30 Sep payment still pays the live CSX transfer. Filing
	 * it elsewhere would take a payment away from that bill.
	 */
	public function testARowAlreadyPayingALiveBillIsRefused(): void {
		$this->row(1300, ['date' => '2026-09-30', 'billId' => 47, 'notes' => 'Auto-generated transfer: CSX Pro Credit Cards']);
		$this->bill(47, ['name' => 'CSX Pro Credit Cards', 'isTransfer' => true, 'destinationAccountId' => self::CARD]);
		$this->bill(120);

		$message = $this->refusal(fn () => $this->service->attachToBill(1300, 120, self::USER));

		$this->assertSame('That transaction already pays another bill', $message);
		$this->assertSame([], $this->written);
	}

	/**
	 * Alimente February to August still carry the id of the Alimente bill
	 * that was deleted, so they showed under no bill. A row the app booked
	 * for a bill that is gone is as free as one the user typed.
	 */
	public function testARowOfADeletedBillCanBeFiledUnderAnother(): void {
		$this->row(410, ['date' => '2026-03-01', 'amount' => 1250.0, 'billId' => 13, 'notes' => 'Auto-generated from bill: Alimente']);
		$this->bill(101, ['name' => 'Alimente', 'amount' => 1250.0]);

		$linked = $this->service->attachToBill(410, 101, self::USER);

		$this->assertSame(101, $linked->getBillId());
		$this->assertSame([[410, 101]], $this->written);
	}

	/** A bill id of 0 is no bill, as Mark Paid's own candidate search treats it */
	public function testABillIdOfZeroIsFree(): void {
		$this->row(411, ['billId' => 0]);
		$this->bill(101);

		$this->assertSame(101, $this->service->attachToBill(411, 101, self::USER)->getBillId());
	}

	public function testLinkingARowToTheBillItAlreadyPaysChangesNothing(): void {
		$this->row(1586, ['billId' => 120]);
		$this->bill(120);

		$this->assertSame(120, $this->service->attachToBill(1586, 120, self::USER)->getBillId());
		$this->assertSame([], $this->written);
	}

	/** A scheduled row is money that hasn't moved, so it pays nothing yet */
	public function testAScheduledRowIsRefused(): void {
		$this->row(1800, ['status' => 'scheduled', 'date' => '2026-11-28']);
		$this->bill(120);

		$this->refusal(fn () => $this->service->attachToBill(1800, 120, self::USER));

		$this->assertSame([], $this->written);
	}

	/** Only the user's own bills: someone else's id names nothing of theirs */
	public function testSomeoneElsesBillIsNotFound(): void {
		$this->row(1586);
		$this->bill(120, ['userId' => 'anna']);

		$message = $this->refusal(fn () => $this->service->attachToBill(1586, 120, self::USER));

		$this->assertSame('Bill not found', $message);
		$this->assertSame([], $this->written);
	}

	public function testARowTheUserCannotSeeIsNotFound(): void {
		$this->row(1586, ['accountId' => self::SHARED]);
		$this->bill(120, ['accountId' => self::SHARED]);

		$this->expectException(DoesNotExistException::class);
		$this->service->attachToBill(1586, 120, self::USER);
	}

	/**
	 * A row in an account shared with the user at write: found within the
	 * accounts the caller may write to, and written as the account's owner,
	 * whose ledger it is (#334).
	 */
	public function testARowInAnAccountSharedAtWriteCanBeFiled(): void {
		$this->row(1900, ['accountId' => self::SHARED]);
		$this->bill(120, ['accountId' => self::SHARED]);

		$linked = $this->service->attachToBill(1900, 120, self::USER, [self::LOHNKONTO, self::SHARED]);

		$this->assertSame(120, $linked->getBillId());
		$this->assertSame([[1900, 120]], $this->written);
	}

	public function testARowOutsideTheWritableAccountsIsNotFound(): void {
		$this->row(1900, ['accountId' => self::SHARED]);
		$this->bill(120, ['accountId' => self::SHARED]);

		$this->expectException(DoesNotExistException::class);
		$this->service->attachToBill(1900, 120, self::USER, [self::LOHNKONTO]);
	}

	/**
	 * A recurring transfer's payment is its withdrawal from the source, and
	 * its arrival in the destination goes with it, as when Mark Paid links
	 * a bank row: both sides of the money carry the transfer.
	 */
	public function testATransfersWithdrawalIsFiledWithItsArrival(): void {
		$this->row(2001, ['date' => '2026-07-25', 'amount' => 50.0, 'linkedTransactionId' => 2002]);
		$this->row(2002, ['accountId' => self::CARD, 'date' => '2026-07-25', 'amount' => 50.0, 'type' => 'credit', 'linkedTransactionId' => 2001]);
		$this->bill(47, ['name' => 'CSX Pro Credit Cards', 'amount' => 50.0, 'isTransfer' => true, 'destinationAccountId' => self::CARD]);

		$linked = $this->service->attachToBill(2001, 47, self::USER);

		$this->assertSame(47, $linked->getBillId());
		$this->assertSame(47, $this->rows[2002]->getBillId());
		$this->assertSame([[2001, 47], [2002, 47]], $this->written);
	}

	public function testATransfersArrivalIsNotItsPayment(): void {
		$this->row(2002, ['accountId' => self::CARD, 'type' => 'credit']);
		$this->bill(47, ['isTransfer' => true, 'destinationAccountId' => self::CARD]);

		$this->refusal(fn () => $this->service->attachToBill(2002, 47, self::USER));

		$this->assertSame([], $this->written);
	}

	/**
	 * The other side is left alone when it isn't the transfer's arrival:
	 * in another account, already another live bill's, or in an account the
	 * user may not write to.
	 */
	public function testAnOtherSideThatIsntTheArrivalIsLeftAlone(): void {
		$this->bill(47, ['isTransfer' => true, 'destinationAccountId' => self::CARD]);
		$this->bill(55, ['accountId' => self::CARD]);

		$this->row(2101, ['linkedTransactionId' => 2102]);
		$this->row(2102, ['accountId' => self::SAVINGS, 'type' => 'credit', 'linkedTransactionId' => 2101]);
		$this->service->attachToBill(2101, 47, self::USER);
		$this->assertNull($this->rows[2102]->getBillId());

		$this->row(2201, ['linkedTransactionId' => 2202]);
		$this->row(2202, ['accountId' => self::CARD, 'type' => 'credit', 'billId' => 55, 'linkedTransactionId' => 2201]);
		$this->service->attachToBill(2201, 47, self::USER);
		$this->assertSame(55, $this->rows[2202]->getBillId());

		$this->assertSame([[2101, 47], [2201, 47]], $this->written);

		$this->written = [];
		$this->row(2301, ['linkedTransactionId' => 2302]);
		$this->row(2302, ['accountId' => self::CARD, 'type' => 'credit', 'linkedTransactionId' => 2301]);
		$this->service->attachToBill(2301, 47, self::USER, [self::LOHNKONTO]);
		$this->assertNull($this->rows[2302]->getBillId());
		$this->assertSame([[2301, 47]], $this->written);
	}

	// ── Unlink from bill ────────────────────────────────────────────

	/**
	 * The second CembraPay payment Mark Paid booked on 15 Oct is what the
	 * bill's Mark Unpaid takes back. Unlinked by hand it would stay in the
	 * account and Mark Unpaid could no longer find it.
	 */
	public function testTheBillsLatestPaymentIsLeftToMarkUnpaid(): void {
		$this->row(1590, ['date' => '2026-10-15', 'amount' => 156.39, 'billId' => 90, 'notes' => 'Auto-generated from bill: CembraPay Temu']);
		$this->bill(90, ['name' => 'CembraPay Temu', 'frequency' => 'one-time', 'isActive' => false, 'lastPaidDate' => '2026-10-15', 'paidUndoState' => self::snapshot([1590])]);

		$message = $this->refusal(fn () => $this->service->detachFromBill(1590, self::USER));

		$this->assertStringContainsString('Mark Unpaid', $message);
		$this->assertSame([], $this->written);
		$this->assertSame(90, $this->rows[1590]->getBillId());
	}

	public function testARowTheLatestPaymentLinkedIsLeftToMarkUnpaidToo(): void {
		$this->row(1586, ['billId' => 120]);
		$this->bill(120, ['paidUndoState' => self::snapshot([], 1586)]);

		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->service->detachFromBill(1586, self::USER)));
		$this->assertSame([], $this->written);
	}

	/**
	 * Mark Paid on September booked 1590 and pre-booked October's row 1800,
	 * which has since gone through on its own (the 2.x job cleared
	 * pre-booked rows on their date, a status edit through the v1 API does
	 * it now). Mark Unpaid deletes the next occurrence's pre-booked row only
	 * while it is still scheduled, so 1800 is no longer its to undo. Sent
	 * there, the user would have reverted September's payment and still had
	 * 1800 filed under the bill.
	 */
	public function testAPreBookedRowThatWentThroughCanBeUnlinked(): void {
		$this->row(1590, ['date' => '2026-09-28', 'billId' => 120]);
		$this->row(1800, ['date' => '2026-10-28', 'billId' => 120]);
		$this->bill(120, ['lastPaidDate' => '2026-09-28', 'nextDueDate' => '2026-10-28', 'paidUndoState' => self::snapshot([1590], null, [1800])]);

		$this->assertNull($this->service->detachFromBill(1800, self::USER)->getBillId());
		$this->assertSame([[1800, null]], $this->written);

		// September's payment is still Mark Unpaid's
		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->service->detachFromBill(1590, self::USER)));
		$this->assertSame([[1800, null]], $this->written);
	}

	/** An earlier payment is no longer in the snapshot, so nothing else would undo it */
	public function testAnEarlierPaymentCanBeUnlinked(): void {
		$this->row(1225, ['date' => '2026-09-25', 'billId' => 120]);
		$this->row(1590, ['date' => '2026-10-15', 'billId' => 120]);
		$bill = $this->bill(120, ['lastPaidDate' => '2026-10-15', 'nextDueDate' => '2026-11-28', 'paidUndoState' => self::snapshot([1590])]);

		$unlinked = $this->service->detachFromBill(1225, self::USER);

		$this->assertNull($unlinked->getBillId());
		$this->assertSame([[1225, null]], $this->written);
		$this->assertSame('2026-10-15', $bill->getLastPaidDate());
		$this->assertSame('2026-11-28', $bill->getNextDueDate());
		$this->assertSame(self::snapshot([1590]), $bill->getPaidUndoState());
	}

	public function testARowOfADeletedBillCanBeUnlinked(): void {
		$this->row(410, ['billId' => 13]);

		$this->assertNull($this->service->detachFromBill(410, self::USER)->getBillId());
		$this->assertSame([[410, null]], $this->written);
	}

	public function testARowWithNoBillHasNothingToUnlink(): void {
		$this->row(1586);

		$this->refusal(fn () => $this->service->detachFromBill(1586, self::USER));

		$this->assertSame([], $this->written);
	}

	/**
	 * A bill's pre-booked row stands for its next occurrence. Unlinked, it
	 * would linger as a scheduled debit of nothing while the bill booked
	 * another, and the forecast counted the payment twice.
	 */
	public function testABillsUpcomingPaymentStaysWithTheBill(): void {
		$this->row(1800, ['status' => 'scheduled', 'date' => '2026-11-28', 'billId' => 120]);
		$this->bill(120, ['nextDueDate' => '2026-11-28']);

		$this->refusal(fn () => $this->service->detachFromBill(1800, self::USER));

		$this->assertSame([], $this->written);
	}

	/** A transfer's two sides let go of it together, as Mark Unpaid's unlink does */
	public function testATransfersArrivalIsUnlinkedWithIt(): void {
		$this->row(2001, ['billId' => 47, 'linkedTransactionId' => 2002]);
		$this->row(2002, ['accountId' => self::CARD, 'type' => 'credit', 'billId' => 47, 'linkedTransactionId' => 2001]);
		$this->bill(47, ['isTransfer' => true, 'destinationAccountId' => self::CARD]);

		$this->service->detachFromBill(2001, self::USER);

		$this->assertNull($this->rows[2001]->getBillId());
		$this->assertNull($this->rows[2002]->getBillId());
		$this->assertSame([[2001, null], [2002, null]], $this->written);
	}

	/** ...unless that arrival is the latest payment's: then Mark Unpaid does it */
	public function testATransferWhoseArrivalIsInTheSnapshotIsLeftToMarkUnpaid(): void {
		$this->row(2001, ['billId' => 47, 'linkedTransactionId' => 2002]);
		$this->row(2002, ['accountId' => self::CARD, 'type' => 'credit', 'billId' => 47, 'linkedTransactionId' => 2001]);
		$this->bill(47, ['isTransfer' => true, 'destinationAccountId' => self::CARD, 'paidUndoState' => self::snapshot([2002], 2001)]);

		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->service->detachFromBill(2001, self::USER)));
		$this->assertSame([], $this->written);
	}

	public function testUnlinkingKeepsToTheWritableAccounts(): void {
		$this->row(1900, ['accountId' => self::SHARED, 'billId' => 120]);
		$this->bill(120, ['accountId' => self::SHARED]);

		$this->assertNull($this->service->detachFromBill(1900, self::USER, [self::SHARED])->getBillId());

		$this->row(1901, ['accountId' => self::SHARED, 'billId' => 120]);
		$this->expectException(DoesNotExistException::class);
		$this->service->detachFromBill(1901, self::USER, [self::LOHNKONTO]);
	}
}
