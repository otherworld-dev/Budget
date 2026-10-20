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
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unlink from bill on the payment Mark Paid's "Use that payment" chose
 * (#399).
 *
 * The reporter's case, as the browser test ran it: the 'Monatsrechnung' of
 * 28 Sep linked to a new One-Time Corner Card SA bill, then Mark Paid, "Use
 * that payment". Nothing is booked: the bill's snapshot names the row as
 * usedTransactionId, and that row is what tells the unrecorded-payments card
 * the payment is in the ledger. Unlink took the row off the bill all the
 * same. The bill stayed paid with nothing behind it, the Bills page listed
 * it under "Payments without a recorded transaction", and Record transaction
 * booked the 100 a second time.
 */
class TransactionDetachUsedPaymentTest extends TestCase {
	private const USER = 'terje';
	private const LOHNKONTO = 3;
	private const CARD = 8;
	private const TODAY = '2026-10-19';

	/** @var array<int, Transaction> the ledger, by id */
	private array $rows = [];
	private ?Bill $stored = null;
	/** @var list<array{0: int, 1: int|null}> rows written: [id, billId] */
	private array $written = [];

	private TransactionService $transactions;
	private BillService $bills;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		// The bill, kept as the database would: what a service writes lands here
		$billMapper = $this->createMock(BillMapper::class);
		$billMapper->method('find')->willReturnCallback(function (int $id, string $userId) {
			if ($this->stored === null || $this->stored->getId() !== $id || $this->stored->getUserId() !== $userId) {
				throw new DoesNotExistException("Bill {$id} not found");
			}
			return clone $this->stored;
		});
		$billMapper->method('findAll')->willReturnCallback(fn () => $this->stored !== null ? [clone $this->stored] : []);
		$billMapper->method('findByIds')->willReturnCallback(
			fn (array $ids) => ($this->stored !== null && in_array($this->stored->getId(), array_map('intval', $ids), true)) ? [clone $this->stored] : []
		);
		$billMapper->method('update')->willReturnCallback(function (Bill $bill) {
			foreach (array_keys($bill->getUpdatedFields()) as $property) {
				$this->stored->{'set' . ucfirst($property)}($bill->{'get' . ucfirst($property)}());
			}
			return $bill;
		});
		$write = function (array $fields): void {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		};
		$billMapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => $write($fields));
		$billMapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) use ($write) {
			if (!$this->stored->getIsActive() || $this->stored->getNextDueDate() !== $expected) {
				return false;
			}
			$write($fields);
			return true;
		});

		// Unlink from bill runs on the real TransactionService, over the ledger
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('find')->willReturnCallback(function (int $id) {
			return $this->rows[$id] ?? throw new DoesNotExistException("Transaction {$id} not found");
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
			$account->setUserId(self::USER);
			$account->setCurrency('CHF');
			return $account;
		});

		$this->transactions = new TransactionService(
			$mapper,
			$accountMapper,
			$this->createMock(TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new UserClock($this->createMock(\OCP\IConfig::class)),
			l: $l,
			billMapper: $billMapper,
		);

		// Mark Paid and Mark Unpaid run on the real BillService, which books
		// nothing for "Use that payment": its ledger calls only read the rows
		$ledger = $this->createMock(TransactionService::class);
		$ledger->method('findRecordedBillTransactions')->willReturnCallback(fn (array $billIds) => array_values(array_filter(
			$this->rows,
			fn (Transaction $row) => in_array((int)$row->getBillId(), array_map('intval', $billIds), true)
				&& ($row->getStatus() ?? 'cleared') !== 'scheduled'
		)));
		$ledger->expects($this->never())->method('createFromBill');
		$ledger->expects($this->never())->method('clearScheduledBillTransaction');
		$ledger->expects($this->never())->method('deleteAsAccountOwner');
		$ledger->expects($this->never())->method('unlinkBillAsAccountOwner');

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canWrite')->willReturn(true);

		$this->bills = new BillService(
			$billMapper,
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
			$shares,
			$clock,
		);
	}

	private function row(int $id, array $fields = []): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setAccountId($fields['accountId'] ?? self::LOHNKONTO);
		$row->setDate($fields['date'] ?? '2026-09-28');
		$row->setDescription($fields['description'] ?? 'Monatsrechnung');
		$row->setAmount($fields['amount'] ?? 100.0);
		$row->setType($fields['type'] ?? 'debit');
		$row->setStatus($fields['status'] ?? 'cleared');
		$row->setBillId($fields['billId'] ?? 120);
		$row->setLinkedTransactionId($fields['linkedTransactionId'] ?? null);
		$row->setReconciled(false);
		$row->setIsSplit(false);
		$row->setCreatedAt('2026-10-15 20:15:13');
		$row->setUpdatedAt('2026-10-15 20:15:13');
		$this->rows[$id] = $row;
		return $row;
	}

	/** The new One-Time Corner Card SA bill, pre-booking off, still due */
	private function cornerCard(array $fields = []): Bill {
		$bill = new Bill();
		$bill->setId(120);
		$bill->setUserId(self::USER);
		$bill->setName('Corner Card SA');
		$bill->setAmount(100.0);
		$bill->setFrequency($fields['frequency'] ?? 'one-time');
		$bill->setDueDay(28);
		$bill->setDueMonth(9);
		$bill->setStartDate('2026-09-28');
		$bill->setAccountId(self::LOHNKONTO);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $fields) ? $fields['nextDueDate'] : '2026-09-28');
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setAutoPayEnabled(false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setCreateTransaction(false);
		$bill->setPaidUndoState($fields['paidUndoState'] ?? null);
		$bill->resetUpdatedFields();
		$this->stored = $bill;
		return $bill;
	}

	/** The snapshot Mark Paid leaves for "Use that payment": nothing booked or linked, $used named */
	private static function usedSnapshot(int $used): string {
		return (string)json_encode([
			'previousState' => ['lastPaidDate' => null, 'nextDueDate' => '2026-09-28', 'remainingPayments' => null, 'isActive' => true, 'autoPayFailed' => false, 'amount' => 100.0, 'startDate' => '2026-09-28'],
			'createdTransactionIds' => [],
			'scheduledTransactionIds' => [],
			'linkedTransactionId' => null,
			'usedTransactionId' => $used,
			'hadScheduledTransaction' => false,
			'paidDate' => '2026-09-28',
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

	public function testThePaymentUseThatPaymentChoseIsLeftToMarkUnpaid(): void {
		$this->row(1586);
		$this->cornerCard(['isActive' => false, 'nextDueDate' => null, 'lastPaidDate' => '2026-09-28', 'paidUndoState' => self::usedSnapshot(1586)]);

		$message = $this->refusal(fn () => $this->transactions->detachFromBill(1586, self::USER));

		$this->assertSame("This is the bill's latest payment. Use Mark Unpaid on the bill to undo it.", $message);
		$this->assertSame([], $this->written);
		$this->assertSame(120, $this->rows[1586]->getBillId());
	}

	/**
	 * A transfer paid with its withdrawal already in the ledger: the arrival
	 * carries the bill too, and taking either side off took the payment.
	 */
	public function testEitherSideOfAUsedTransferIsLeftToMarkUnpaid(): void {
		$this->row(2001, ['billId' => 120, 'linkedTransactionId' => 2002]);
		$this->row(2002, ['accountId' => self::CARD, 'type' => 'credit', 'billId' => 120, 'linkedTransactionId' => 2001]);
		$this->cornerCard(['isTransfer' => true, 'destinationAccountId' => self::CARD, 'isActive' => false, 'nextDueDate' => null,
			'lastPaidDate' => '2026-09-28', 'paidUndoState' => self::usedSnapshot(2001)]);

		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->transactions->detachFromBill(2001, self::USER)));
		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->transactions->detachFromBill(2002, self::USER)));
		$this->assertSame([], $this->written);
	}

	/**
	 * Once Mark Unpaid has undone the payment, the row is an ordinary
	 * payment filed under the bill again: the snapshot is spent and the bill
	 * due, so taking the row off leaves nothing paid without it.
	 */
	public function testAfterMarkUnpaidTheRowCanBeUnlinked(): void {
		$this->row(1586);
		$this->cornerCard();

		$this->assertNull($this->transactions->detachFromBill(1586, self::USER)->getBillId());
		$this->assertSame([[1586, null]], $this->written);
	}

	/**
	 * The whole round on the real services: Link to bill, Mark Paid "Use that
	 * payment", Unlink (refused), Mark Unpaid, Unlink (allowed). The refusal
	 * reads the snapshot Mark Paid itself wrote, so the two can't drift apart.
	 */
	public function testTheRoundFromUseThatPaymentToUnlink(): void {
		$this->row(1586, ['billId' => null]);
		$this->cornerCard();
		$this->transactions->attachToBill(1586, 120, self::USER);
		$this->written = [];

		$paid = $this->bills->markPaid(120, self::USER, '2026-09-28', false, null, '2026-09-28');

		$this->assertTrue($paid['paymentTransactionRecorded']);
		$this->assertFalse($this->stored->getIsActive());
		$this->assertSame(1586, json_decode((string)$this->stored->getPaidUndoState(), true)['usedTransactionId'] ?? null);
		$this->assertStringContainsString('Mark Unpaid', $this->refusal(fn () => $this->transactions->detachFromBill(1586, self::USER)));
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->bills->findUnrecordedPayments(self::USER), 'The payment is still the one in the ledger');

		// Mark Unpaid leaves the used row on the bill and spends the snapshot
		$this->bills->markUnpaid(120, self::USER);
		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame('2026-09-28', $this->stored->getNextDueDate());
		$this->assertNull($this->stored->getPaidUndoState());
		$this->assertSame(120, $this->rows[1586]->getBillId());

		$this->assertNull($this->transactions->detachFromBill(1586, self::USER)->getBillId());
		$this->assertSame([[1586, null]], $this->written);
	}
}
