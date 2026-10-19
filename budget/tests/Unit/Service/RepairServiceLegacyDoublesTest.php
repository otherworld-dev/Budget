<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\RepairService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

/**
 * Doubles that 2.x left in the reporter's ledger and Repair Data didn't find
 * (#399). The fixtures are the reporter's own rows.
 *
 * The 2.x scheduled job cleared a bill's pending row on its date while the
 * bill stayed due, and Mark Paid then booked the occurrence again. Repair
 * Data skipped those pairs when they were transfers, when the two amounts
 * differed, when the bill had since been deleted, or when the bill's first
 * payment was a day early. And for a one-time bill paid twice it offered the
 * correctly dated payment for deletion.
 */
class RepairServiceLegacyDoublesTest extends TestCase {
	private const USER_ID = 'terje';

	private TransactionMapper $transactionMapper;
	private BillMapper $billMapper;
	private AccountMapper $accountMapper;
	private TransactionService $transactionService;

	/** @var Bill[] bills of other users the ledger's rows may name */
	private array $otherUsersBills = [];

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->accountMapper->method('findAll')->with(self::USER_ID)->willReturn([
			$this->account(1, 'Lohnkonto', 'checking'),
			$this->account(13, 'CSX Pro Credit Card', 'credit_card'),
		]);
	}

	private function serviceOn(string $today): RepairService {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn($today);
		$clock->method('isFutureDate')->willReturnCallback(fn (string $date) => $date > $today);
		return new RepairService(
			$this->transactionMapper,
			$this->billMapper,
			$this->accountMapper,
			new FrequencyCalculator(),
			$this->createMock(AccountService::class),
			$this->transactionService,
			$clock
		);
	}

	/** @return array<array{0: int, 1: int}> each finding as [the row offered, the row it repeats] */
	private static function pairs(array $found): array {
		return array_map(fn (array $f) => [$f['duplicateId'], $f['originalId']], $found);
	}

	private function account(int $id, string $name, string $type): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($name);
		$account->setType($type);
		$account->setUserId(self::USER_ID);
		$account->setBalance(0.0);
		$account->setOpeningBalance(0.0);
		return $account;
	}

	private function bill(array $values): Bill {
		$values += [
			'frequency' => 'monthly', 'dueMonth' => null, 'startDate' => null, 'isActive' => true,
			'customRecurrencePattern' => null, 'paidUndoState' => null, 'isTransfer' => false,
			'destinationAccountId' => null, 'accountId' => 1, 'userId' => self::USER_ID,
		];
		$bill = new Bill();
		foreach ($values as $property => $value) {
			$bill->{'set' . ucfirst($property)}($value);
		}
		return $bill;
	}

	/**
	 * A row as the app writes it: [id, date, amount, created, updated] plus
	 * overrides. Created and updated are when it was written and last changed.
	 */
	private function row(int $id, ?int $billId, string $name, string $date, float $amount, string $created, ?string $updated = null, array $overrides = []): Transaction {
		$values = $overrides + [
			'accountId' => 1, 'type' => 'debit', 'status' => 'cleared', 'vendor' => $name, 'description' => '',
			'notes' => "Auto-generated from bill: {$name}", 'reconciled' => false, 'importId' => null,
			'linkedTransactionId' => null,
		];
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setCreatedAt($created);
		$tx->setUpdatedAt($updated ?? $created);
		foreach ($values as $property => $value) {
			$tx->{'set' . ucfirst($property)}($value);
		}
		return $tx;
	}

	/** Both legs of a transfer a bill booked: the withdrawal on Lohnkonto, the deposit on the card */
	private function transferLegs(int $withdrawalId, int $billId, string $name, string $date, float $amount, string $created, ?string $updated = null): array {
		$notes = ['notes' => "Auto-generated transfer: {$name}"];
		return [
			$this->row($withdrawalId, $billId, $name, $date, $amount, $created, $updated, $notes + ['linkedTransactionId' => $withdrawalId + 1]),
			$this->row($withdrawalId + 1, $billId, $name, $date, $amount, $created, $updated, $notes + [
				'accountId' => 13, 'type' => 'credit', 'linkedTransactionId' => $withdrawalId,
			]),
		];
	}

	/** What markPaid() leaves on a bill */
	private static function snapshot(string $paidDate, string $settled, array $created, array $scheduled = []): string {
		return json_encode([
			'previousState' => ['lastPaidDate' => null, 'nextDueDate' => $settled, 'isActive' => true],
			'createdTransactionIds' => $created,
			'scheduledTransactionIds' => $scheduled,
			'linkedTransactionId' => null,
			'hadScheduledTransaction' => $scheduled !== [],
			'paidDate' => $paidDate,
		]);
	}

	/**
	 * Back the mappers with the user's bills and an in-memory ledger, so
	 * every query the repair makes sees the same rows.
	 *
	 * @param Bill[] $bills
	 * @param Transaction[] $ledger
	 */
	private function with(array $bills, array $ledger): void {
		$this->billMapper->method('findAll')->with(self::USER_ID)->willReturn($bills);
		$this->billMapper->method('findActive')->with(self::USER_ID)
			->willReturn(array_values(array_filter($bills, fn (Bill $b) => $b->getIsActive())));
		$known = array_merge($bills, $this->otherUsersBills);
		$this->billMapper->method('findByIds')->willReturnCallback(
			fn (array $ids) => array_values(array_filter($known, fn (Bill $b) => in_array($b->getId(), $ids, true)))
		);
		$scheduled = fn (Transaction $t): bool => ($t->getStatus() ?? 'cleared') === 'scheduled';
		$this->transactionMapper->method('findAutoGeneratedBillTransactions')->willReturnCallback(
			fn (int $accountId) => array_values(array_filter($ledger, fn (Transaction $t) => $t->getAccountId() === $accountId
				&& str_starts_with($t->getNotes() ?? '', 'Auto-generated from bill:') && !$scheduled($t)))
		);
		$this->transactionMapper->method('findRowsByDateRange')->willReturn([]);
		$this->transactionMapper->method('findByDateRange')->willReturn([]);
		$this->transactionMapper->method('findRecordedByBillIds')->willReturnCallback(
			fn (array $billIds) => array_values(array_filter($ledger, fn (Transaction $t) => in_array($t->getBillId(), $billIds, true) && !$scheduled($t)))
		);
		$this->transactionMapper->method('findById')->willReturnCallback(function (int $id) use ($ledger) {
			foreach ($ledger as $t) {
				if ($t->getId() === $id) {
					return $t;
				}
			}
			return null;
		});
	}

	private function duplicates(RepairService $service): array {
		return $service->diagnose(self::USER_ID)['duplicateTransactions'];
	}

	// ---------------------------------------------------------------
	// Transfers: the withdrawal and its deposit are one payment
	// ---------------------------------------------------------------

	private function csx(array $overrides = []): Bill {
		return $this->bill($overrides + [
			'id' => 47, 'name' => 'CSX Pro Credit Cards', 'amount' => 50.0, 'dueDay' => 28,
			'isTransfer' => true, 'destinationAccountId' => 13,
			'lastPaidDate' => '2026-08-30', 'nextDueDate' => '2026-09-28',
		]);
	}

	/**
	 * CSX August: the pending pair written at the July payment and cleared
	 * by the job on 29 August (1014/1015), then Mark Paid's pair on the 30th
	 * (1104/1105). One finding for the two pairs, offering the job's.
	 */
	public function testATransferPaidTwiceIsOneFindingAndTakesItsDepositWithIt(): void {
		$this->with([$this->csx()], array_merge(
			$this->transferLegs(909, 47, 'CSX Pro Credit Cards', '2026-06-28', 50.0, '2026-06-28 12:12:34'),
			$this->transferLegs(1014, 47, 'CSX Pro Credit Cards', '2026-08-29', 50.0, '2026-07-25 10:55:38', '2026-08-29 01:00:07'),
			$this->transferLegs(1104, 47, 'CSX Pro Credit Cards', '2026-08-30', 50.0, '2026-08-30 19:39:30'),
			// July's withdrawal, whose deposit a version before 2.41 deleted
			[$this->row(911, 47, 'CSX Pro Credit Cards', '2026-07-25', 50.0, '2026-06-28 12:12:34', '2026-07-25 10:55:38', [
				'notes' => 'Auto-generated transfer: CSX Pro Credit Cards', 'linkedTransactionId' => 912,
			])],
		));
		$service = $this->serviceOn('2026-09-05');

		$found = $this->duplicates($service);

		$this->assertCount(1, $found);
		$this->assertSame(1014, $found[0]['duplicateId']);
		$this->assertSame(1104, $found[0]['originalId']);
		$this->assertSame('same_occurrence', $found[0]['reason']);
		$this->assertSame('2026-08-28', $found[0]['occurrence']);
		$this->assertTrue($found[0]['transfer']);
		$this->assertFalse($found[0]['selected']);

		// Deleted as the bill's row, so the service takes the deposit (1015)
		// with it, each through deleteWithChildren()
		$this->transactionService->expects($this->once())->method('deleteAsAccountOwner')
			->with(1014, false, 47)
			->willReturn(true);

		$result = $service->repair(self::USER_ID, ['duplicateTransactions'], ['transactionIds' => [1014]]);

		$this->assertSame(1, $result['duplicateTransactions']['deleted']);
	}

	/** CSX September: two clicks on 2.54.0 booked two pairs in the same second */
	public function testATransferBookedTwiceInOneSecondIsFound(): void {
		$this->with([$this->csx(['amount' => 100.0, 'lastPaidDate' => '2026-09-30', 'nextDueDate' => '2026-11-28'])], array_merge(
			$this->transferLegs(1298, 47, 'CSX Pro Credit Cards', '2026-09-30', 100.0, '2026-09-30 19:10:06'),
			$this->transferLegs(1300, 47, 'CSX Pro Credit Cards', '2026-09-30', 100.0, '2026-09-30 19:10:06'),
		));

		$found = $this->duplicates($this->serviceOn('2026-10-19'));

		$this->assertCount(1, $found);
		$this->assertSame([1300, 1298], [$found[0]['duplicateId'], $found[0]['originalId']]);
		$this->assertSame('2026-09-28', $found[0]['occurrence']);
	}

	public function testATransferWhoseDepositIsReconciledStays(): void {
		$august = array_merge(
			$this->transferLegs(1014, 47, 'CSX Pro Credit Cards', '2026-08-29', 50.0, '2026-07-25 10:55:38', '2026-08-29 01:00:07'),
			$this->transferLegs(1104, 47, 'CSX Pro Credit Cards', '2026-08-30', 50.0, '2026-08-30 19:39:30'),
		);
		// The card statement was checked with the job's deposit on it
		$august[1]->setReconciled(true);
		$this->with([$this->csx()], $august);

		$found = $this->duplicates($this->serviceOn('2026-09-05'));

		$this->assertSame([[1104, 1014]], array_map(fn (array $f) => [$f['duplicateId'], $f['originalId']], $found));
	}

	public function testATransferWithBothDepositsReconciledIsNotOffered(): void {
		$august = array_merge(
			$this->transferLegs(1014, 47, 'CSX Pro Credit Cards', '2026-08-29', 50.0, '2026-07-25 10:55:38', '2026-08-29 01:00:07'),
			$this->transferLegs(1104, 47, 'CSX Pro Credit Cards', '2026-08-30', 50.0, '2026-08-30 19:39:30'),
		);
		$august[1]->setReconciled(true);
		$august[3]->setReconciled(true);
		$this->with([$this->csx()], $august);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-09-05')));
	}

	public function testTheDepositSideIsNeverOfferedOnItsOwn(): void {
		$this->with([$this->csx()], array_merge(
			$this->transferLegs(1014, 47, 'CSX Pro Credit Cards', '2026-08-29', 50.0, '2026-07-25 10:55:38', '2026-08-29 01:00:07'),
			$this->transferLegs(1104, 47, 'CSX Pro Credit Cards', '2026-08-30', 50.0, '2026-08-30 19:39:30'),
		));

		$found = $this->duplicates($this->serviceOn('2026-09-05'));

		$this->assertSame([1014], array_column($found, 'duplicateId'));
		$this->assertSame([1104], array_column($found, 'originalId'));
	}

	// ---------------------------------------------------------------
	// Different amounts: allowed when one side is the old job's row
	// ---------------------------------------------------------------

	/** @return Transaction[] the reporter's 0793745386 rows from June */
	private function phoneBillRows(string $jobRowCreated = '2026-07-25 10:32:06', string $jobRowUpdated = '2026-08-29 01:00:07'): array {
		return [
			$this->row(771, 10, '0793745386', '2026-06-28', 172.65, '2026-05-31 13:28:50', '2026-06-28 12:03:13'),
			$this->row(904, 10, '0793745386', '2026-07-25', 165.25, '2026-06-28 12:03:13', '2026-07-25 10:32:06'),
			// Written at the July payment at that month's amount, cleared by the job
			$this->row(1004, 10, '0793745386', '2026-08-29', 161.25, $jobRowCreated, $jobRowUpdated),
			// Mark Paid the next day, at the bill's amount by then
			$this->row(1098, 10, '0793745386', '2026-08-30', 155.25, '2026-08-30 19:34:25'),
			$this->row(1099, 10, '0793745386', '2026-09-27', 155.25, '2026-08-30 19:34:25', '2026-09-27 18:35:26'),
			$this->row(1275, 10, '0793745386', '2026-10-31', 155.25, '2026-09-27 18:35:26', null, ['status' => 'scheduled']),
		];
	}

	private function phoneBill(): Bill {
		return $this->bill([
			'id' => 10, 'name' => '0793745386', 'amount' => 155.25, 'dueDay' => 31,
			'lastPaidDate' => '2026-09-27', 'nextDueDate' => '2026-10-31',
			'paidUndoState' => self::snapshot('2026-09-27', '2026-09-30', [1099], [1275]),
		]);
	}

	public function testAPairOfDifferentAmountsIsListedWhenOneIsThePendingRowTheOldJobBooked(): void {
		$this->with([$this->phoneBill()], $this->phoneBillRows());

		$found = $this->duplicates($this->serviceOn('2026-10-19'));

		$this->assertCount(1, $found);
		$this->assertSame(1004, $found[0]['duplicateId']);
		$this->assertSame(1098, $found[0]['originalId']);
		$this->assertSame(161.25, $found[0]['amount']);
		$this->assertSame(155.25, $found[0]['originalAmount']);
		$this->assertSame('2026-08-31', $found[0]['occurrence']);
		$this->assertFalse($found[0]['selected'], 'a guess must not be pre-ticked');
	}

	public function testTwoPaymentsOfDifferentAmountsAreLeftAloneOtherwise(): void {
		// The same rows, but the 08-29 one was written on its own date: two
		// payments Mark Paid booked, which a partial payment looks like too
		$this->with([$this->phoneBill()], $this->phoneBillRows('2026-08-29 18:00:00', '2026-08-29 18:00:00'));

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	public function testARowAPaymentRecordedIsNotTheOldJobsRow(): void {
		// The 08-29 row is the one the last payment's snapshot names
		$bill = $this->phoneBill();
		$bill->setPaidUndoState(self::snapshot('2026-09-27', '2026-09-30', [1099, 1004]));
		$this->with([$bill], $this->phoneBillRows());

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	/**
	 * A pending row Mark Paid took up on its due date looks like the old
	 * job's: written before its date, changed on it. But taking it up booked
	 * the next occurrence's pending row in the same moment, which the job
	 * never did. Here 3.x took up the 10 October row (902), and two days
	 * later a second, smaller payment was recorded on purpose: two payments,
	 * not one booked twice.
	 */
	public function testAPendingRowMarkPaidTookUpIsNotTheOldJobsRow(): void {
		$this->with([$this->bill([
			'id' => 90, 'name' => 'Phone', 'amount' => 50.0, 'dueDay' => 10,
			'lastPaidDate' => '2026-10-12', 'nextDueDate' => '2026-12-10',
			'paidUndoState' => self::snapshot('2026-10-12', '2026-11-10', [903], [904]),
		])], [
			$this->row(901, 90, 'Phone', '2026-09-10', 50.0, '2026-08-10 12:00:00', '2026-09-10 12:00:00'),
			$this->row(902, 90, 'Phone', '2026-10-10', 50.0, '2026-09-10 12:00:01', '2026-10-10 12:00:00'),
			$this->row(903, 90, 'Phone', '2026-10-12', 30.0, '2026-10-10 12:00:01', '2026-10-12 12:00:00'),
			$this->row(904, 90, 'Phone', '2026-12-10', 50.0, '2026-10-12 12:00:00', null, ['status' => 'scheduled']),
		]);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	// ---------------------------------------------------------------
	// The first payment a day early
	// ---------------------------------------------------------------

	/**
	 * KPT, due on the 29th: its first payment came on 28 March, a day early.
	 * Read as February's payment a month late, every later payment moved
	 * back a month and the August double passed for September's.
	 */
	public function testAFirstPaymentADayEarlyPaysThatOccurrenceSoTheAugustDoubleIsFound(): void {
		$name = 'KPT Prämienrechnung';
		$this->with([$this->bill([
			'id' => 4, 'name' => $name, 'amount' => 484.75, 'dueDay' => 29,
			'lastPaidDate' => '2026-09-27', 'nextDueDate' => '2026-10-29',
			'paidUndoState' => self::snapshot('2026-09-27', '2026-09-29', [1101], [1289]),
		])], [
			$this->row(394, 4, $name, '2026-03-28', 484.75, '2026-03-28 17:59:19', null, ['vendor' => null, 'description' => $name]),
			$this->row(558, 4, $name, '2026-04-28', 484.75, '2026-04-26 18:42:02', '2026-04-28 01:00:07'),
			$this->row(776, 4, $name, '2026-05-31', 484.75, '2026-05-31 13:29:10'),
			$this->row(902, 4, $name, '2026-06-28', 484.75, '2026-06-28 12:00:28'),
			$this->row(903, 4, $name, '2026-07-25', 484.75, '2026-06-28 12:00:28', '2026-07-25 10:33:36'),
			$this->row(1007, 4, $name, '2026-08-29', 484.75, '2026-07-25 10:33:36', '2026-08-29 01:00:07'),
			$this->row(1100, 4, $name, '2026-08-30', 484.75, '2026-08-30 19:35:58'),
			$this->row(1101, 4, $name, '2026-09-27', 484.75, '2026-08-30 19:35:58', '2026-09-27 19:11:37'),
			$this->row(1289, 4, $name, '2026-10-29', 484.75, '2026-09-27 19:11:37', null, ['status' => 'scheduled']),
		]);

		$found = $this->duplicates($this->serviceOn('2026-10-19'));

		$this->assertCount(1, $found);
		$this->assertSame(1007, $found[0]['duplicateId']);
		$this->assertSame(1100, $found[0]['originalId']);
		$this->assertSame('2026-08-29', $found[0]['occurrence']);
	}

	public function testAPaymentTwentyDaysLateIsStillLateNotTheNextOneEarly(): void {
		// Due on the 1st: January paid on the 21st, February and March on time
		$this->with([$this->bill([
			'id' => 30, 'name' => 'Water', 'amount' => 40.0, 'dueDay' => 1,
			'lastPaidDate' => '2026-03-01', 'nextDueDate' => '2026-04-01',
		])], [
			$this->row(301, 30, 'Water', '2026-01-21', 40.0, '2026-01-21 10:00:00'),
			$this->row(302, 30, 'Water', '2026-02-01', 40.0, '2026-02-01 10:00:00'),
			$this->row(303, 30, 'Water', '2026-03-01', 40.0, '2026-03-01 10:00:00'),
		]);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-03-20')));
	}

	/**
	 * @param string[] $dates
	 * @return Transaction[]
	 */
	private function paidOn(int $billId, string $name, array $dates): array {
		$rows = [];
		foreach ($dates as $i => $date) {
			$rows[] = $this->row(400 + $i, $billId, $name, $date, 40.0, "{$date} 10:00:00");
		}
		return $rows;
	}

	/**
	 * Paid about 25 days late each month, once on time (2 March). Read from
	 * February on, the first payments come a few days early, 2 March is left
	 * over as a second payment of March, and April's is still 25 days late.
	 * A reading that leaves a payment weeks late is no reason to drop the
	 * late one, where each payment has an occurrence of its own.
	 */
	public function testABillPaidLateWithOneOnTimePaymentHasNoDuplicates(): void {
		$this->with([$this->bill([
			'id' => 40, 'name' => 'Water', 'amount' => 40.0, 'dueDay' => 1,
			'lastPaidDate' => '2026-04-26', 'nextDueDate' => '2026-05-01',
		])], $this->paidOn(40, 'Water', ['2026-01-26', '2026-02-26', '2026-03-02', '2026-04-26']));

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-04-28')));
	}

	public function testABillAlwaysPaidThreeWeeksLateHasNoDuplicates(): void {
		$this->with([$this->bill([
			'id' => 31, 'name' => 'Gas', 'amount' => 40.0, 'dueDay' => 1,
			'lastPaidDate' => '2026-04-21', 'nextDueDate' => '2026-05-01',
		])], $this->paidOn(31, 'Gas', ['2026-01-21', '2026-02-21', '2026-03-21', '2026-04-21']));

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-04-25')));
	}

	/**
	 * Paid each month a few days before the next due date, but recorded as
	 * the month before: the bill is due on 1 May, so its last payment paid
	 * April, and each payment is late, not the next one's early.
	 */
	public function testABillAlwaysPaidJustBeforeTheNextDueDateHasNoDuplicates(): void {
		$this->with([$this->bill([
			'id' => 32, 'name' => 'Gym', 'amount' => 40.0, 'dueDay' => 1,
			'lastPaidDate' => '2026-04-25', 'nextDueDate' => '2026-05-01',
		])], $this->paidOn(32, 'Gym', ['2026-01-26', '2026-02-24', '2026-03-26', '2026-04-25']));

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-04-28')));
	}

	// ---------------------------------------------------------------
	// Deleted bills
	// ---------------------------------------------------------------

	/** @return Transaction[] Christian Uhrenschmuck24's payments, its bill (65) long deleted */
	private function christianRows(?int $billId = 65): array {
		$name = 'Christian Uhrenschmuck24';
		return [
			$this->row(766, $billId, $name, '2026-05-31', 91.59, '2026-05-31 13:28:08'),
			$this->row(907, $billId, $name, '2026-06-28', 91.59, '2026-06-28 12:09:59'),
			$this->row(908, $billId, $name, '2026-07-25', 91.59, '2026-06-28 12:09:59', '2026-07-25 10:51:39'),
			$this->row(1012, $billId, $name, '2026-08-29', 91.59, '2026-07-25 10:51:39', '2026-08-29 01:00:07'),
			$this->row(1102, $billId, $name, '2026-08-30', 91.59, '2026-08-30 19:37:54'),
		];
	}

	public function testADeletedBillsPaymentsBookedTwiceAreFoundByTheirNotes(): void {
		$this->with([], $this->christianRows());
		$service = $this->serviceOn('2026-10-19');

		$found = $this->duplicates($service);

		$this->assertCount(1, $found);
		$this->assertSame(1012, $found[0]['duplicateId']);
		$this->assertSame(1102, $found[0]['originalId']);
		$this->assertSame('deleted_bill', $found[0]['reason']);
		$this->assertSame('Christian Uhrenschmuck24', $found[0]['billName']);
		$this->assertFalse($found[0]['selected']);

		$this->transactionService->expects($this->once())->method('deleteAsAccountOwner')
			->with(1012)
			->willReturn(true);

		$this->assertSame(1, $service->repair(self::USER_ID, ['duplicateTransactions'], ['transactionIds' => [1012]])['duplicateTransactions']['deleted']);
	}

	public function testPaymentsThatLetGoOfTheirDeletedBillAreFoundToo(): void {
		// Deleting a bill now lets its payments go, and the upgrade clears
		// the ids older versions left
		$this->with([], $this->christianRows(null));

		$found = $this->duplicates($this->serviceOn('2026-10-19'));

		$this->assertSame([[1012, 1102]], array_map(fn (array $f) => [$f['duplicateId'], $f['originalId']], $found));
	}

	public function testDeletedBillPaymentsFarApartOrOfDifferentAmountsAreNotPaired(): void {
		$name = 'Alimente';
		$this->with([], [
			// A month apart, same amount
			$this->row(731, 13, $name, '2026-05-25', 1400.0, '2026-05-25 20:17:24'),
			$this->row(732, 13, $name, '2026-06-25', 1400.0, '2026-05-25 20:17:24', '2026-06-25 16:23:17'),
			// Four days apart
			$this->row(853, 13, $name, '2026-07-25', 1400.0, '2026-06-25 16:23:17', '2026-07-25 10:26:08'),
			$this->row(854, 13, $name, '2026-07-29', 1400.0, '2026-07-29 10:00:00'),
			// A day apart, different amounts
			$this->row(1001, 13, $name, '2026-08-27', 1368.35, '2026-07-25 10:26:08', '2026-08-27 01:00:08'),
			$this->row(1003, 13, $name, '2026-08-28', 1400.0, '2026-08-28 10:00:00'),
			// A day apart, the same amount, but booked under another name
			$this->row(1201, 99, 'Tinder Dating App', '2026-09-28', 65.95, '2026-09-27 14:55:09', '2026-09-28 01:00:07'),
			$this->row(1202, 98, 'Spotify', '2026-09-29', 65.95, '2026-09-29 10:00:00'),
		]);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	public function testPaymentsOfABillAnotherUserStillHasAreNotADeletedBills(): void {
		// The account is shared with someone whose bill books into it
		$this->otherUsersBills = [$this->bill(['id' => 65, 'name' => 'Christian Uhrenschmuck24', 'amount' => 91.59,
			'dueDay' => 29, 'userId' => 'leon', 'lastPaidDate' => '2026-08-30', 'nextDueDate' => '2026-09-29'])];
		$this->with([], $this->christianRows());

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	/**
	 * Name, amount and a few days apart are what any bill's payments share
	 * when two occurrences are caught up together, or one is paid late and
	 * the next on time. Without its schedule, nothing tells those from a
	 * double, so only a pair that shows one is listed.
	 */
	public function testTwoOverdueOccurrencesOfADeletedBillPaidOnOneDayAreNotPaired(): void {
		$this->with([], [
			$this->row(500, 77, 'Rent', '2026-06-01', 900.0, '2026-06-01 10:00:00'),
			$this->row(501, 77, 'Rent', '2026-08-20', 900.0, '2026-08-20 10:00:00'),
			$this->row(502, 77, 'Rent', '2026-08-20', 900.0, '2026-08-20 10:00:05'),
			$this->row(503, 77, 'Rent', '2026-09-01', 900.0, '2026-09-01 10:00:00'),
		]);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	public function testADeletedWeeklyBillPaidLateOnFridayAndOnTimeOnMondayIsNotPaired(): void {
		$this->with([], [
			$this->row(600, 78, 'Cleaner', '2026-09-07', 60.0, '2026-09-07 09:00:00'),
			$this->row(601, 78, 'Cleaner', '2026-09-18', 60.0, '2026-09-18 09:00:00'),
			$this->row(602, 78, 'Cleaner', '2026-09-21', 60.0, '2026-09-21 09:00:00'),
			$this->row(603, 78, 'Cleaner', '2026-09-28', 60.0, '2026-09-28 09:00:00'),
		]);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	public function testADeletedDailyBillIsNotPaired(): void {
		$rows = [];
		foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $i => $date) {
			$rows[] = $this->row(700 + $i, 79, 'Parking', $date, 5.0, "{$date} 08:00:00");
		}
		$this->with([], $rows);

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	/** Two clicks on 2.54.0: one payment booked twice in the same second */
	public function testADeletedBillsPaymentBookedTwiceInOneSecondIsPaired(): void {
		$name = 'Christian Uhrenschmuck24';
		$this->with([], [
			$this->row(1102, 65, $name, '2026-08-30', 91.59, '2026-08-30 19:37:54'),
			$this->row(1103, 65, $name, '2026-08-30', 91.59, '2026-08-30 19:37:54'),
		]);

		$this->assertSame([[1103, 1102]], self::pairs($this->duplicates($this->serviceOn('2026-10-19'))));
	}

	/**
	 * Rows that let go of a bill the user still has (Unlink from bill) carry
	 * its name: they are that bill's payments, not a deleted one's.
	 */
	public function testPaymentsNamedAfterALiveBillAreNotADeletedBills(): void {
		$this->with([$this->bill([
			'id' => 99, 'name' => 'Christian Uhrenschmuck24', 'amount' => 91.59, 'dueDay' => 29,
			'lastPaidDate' => '2026-09-27', 'nextDueDate' => '2026-10-29',
		])], $this->christianRows(null));

		$this->assertSame([], $this->duplicates($this->serviceOn('2026-10-19')));
	}

	// ---------------------------------------------------------------
	// A one-time bill paid twice: the newer payment goes
	// ---------------------------------------------------------------

	private function cembraPay(array $overrides = []): Bill {
		return $this->bill($overrides + [
			'id' => 93, 'name' => 'CembraPay Temu', 'amount' => 151.67, 'frequency' => 'one-time',
			'dueDay' => 28, 'dueMonth' => 9, 'startDate' => '2026-09-28', 'isActive' => false,
			'lastPaidDate' => '2026-10-15', 'nextDueDate' => null,
			// Switched to One-Time, re-opened and paid again on 15 October
			'paidUndoState' => self::snapshot('2026-10-15', '2026-09-28', [1585]),
		]);
	}

	/**
	 * The 27 September payment is the real one. The one from 15 October,
	 * which the bill's snapshot names, is offered; repairing it leaves the
	 * bill paid on the 27th, completed, with nothing for Mark Unpaid to
	 * re-open.
	 */
	public function testAOneTimeBillPaidAgainOffersTheNewerPaymentAndKeepsTheBillPaidByTheOlder(): void {
		$this->with([$this->cembraPay()], [
			$this->row(1277, 93, 'CembraPay Temu', '2026-09-27', 151.67, '2026-09-27 18:38:36'),
			$this->row(1585, 93, 'CembraPay Temu', '2026-10-15', 151.67, '2026-10-15 19:47:21'),
		]);
		$service = $this->serviceOn('2026-10-19');

		$found = $this->duplicates($service);

		$this->assertCount(1, $found);
		$this->assertSame(1585, $found[0]['duplicateId']);
		$this->assertSame(1277, $found[0]['originalId']);
		$this->assertSame('2026-09-28', $found[0]['occurrence']);

		$this->transactionService->expects($this->once())->method('deleteAsAccountOwner')->with(1585)->willReturn(true);
		$this->billMapper->expects($this->once())->method('updateFields')->with(93, self::USER_ID, [
			'last_paid_date' => '2026-09-27',
			'is_active' => false,
			'next_due_date' => null,
			'paid_undo_state' => null,
		]);

		$result = $service->repair(self::USER_ID, ['duplicateTransactions'], ['transactionIds' => [1585]]);

		$this->assertSame(1, $result['duplicateTransactions']['deleted']);
	}

	public function testTheBillIsLeftAloneWhenTheNewerPaymentCouldNotBeDeleted(): void {
		$this->with([$this->cembraPay()], [
			$this->row(1277, 93, 'CembraPay Temu', '2026-09-27', 151.67, '2026-09-27 18:38:36'),
			$this->row(1585, 93, 'CembraPay Temu', '2026-10-15', 151.67, '2026-10-15 19:47:21'),
		]);

		$this->transactionService->method('deleteAsAccountOwner')->willReturn(false);
		$this->billMapper->expects($this->never())->method('updateFields');

		$this->serviceOn('2026-10-19')->repair(self::USER_ID, ['duplicateTransactions'], ['transactionIds' => [1585]]);
	}

	// ---------------------------------------------------------------
	// Paid One-Time Bills Still Active
	// ---------------------------------------------------------------

	public function testAOneTimeBillWhosePaymentIsByItsDateIsListed(): void {
		// CembraPay after Mark Unpaid: due 28 September again, paid the 27th
		$this->with([$this->cembraPay([
			'isActive' => true, 'nextDueDate' => '2026-09-28', 'lastPaidDate' => '2026-09-27', 'paidUndoState' => null,
		])], [
			$this->row(1277, 93, 'CembraPay Temu', '2026-09-27', 151.67, '2026-09-27 18:38:36'),
		]);

		$found = $this->serviceOn('2026-10-19')->diagnose(self::USER_ID)['paidOneTimeBills'];

		$this->assertSame([93], array_column($found, 'billId'));
	}

	public function testABillSwitchedToOneTimeAfterPayingLastMonthIsStillDue(): void {
		// Monthly, paid 19 October, then switched to One-Time on 20 November
		$this->with([$this->bill([
			'id' => 50, 'name' => 'Garage', 'amount' => 80.0, 'frequency' => 'one-time', 'dueDay' => 20, 'dueMonth' => 11,
			'startDate' => '2026-11-20', 'nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19',
		])], [
			$this->row(501, 50, 'Garage', '2026-10-19', 80.0, '2026-10-19 09:00:00'),
		]);

		$this->assertSame([], $this->serviceOn('2026-10-20')->diagnose(self::USER_ID)['paidOneTimeBills']);
	}

	/**
	 * Marked paid on its date with no transaction recorded (#274): the paid
	 * date alone says it was paid, as it does when the bill is edited. Left
	 * active, it stays Overdue with Mark Paid, which is how a bill gets paid
	 * twice.
	 */
	public function testAOneTimeBillMarkedPaidOnItsDateWithNoPaymentRecordedIsListed(): void {
		$this->with([$this->bill([
			'id' => 51, 'name' => 'Dentist', 'amount' => 120.0, 'frequency' => 'one-time', 'dueDay' => 5, 'dueMonth' => 10,
			'startDate' => '2026-10-05', 'nextDueDate' => '2026-10-05', 'lastPaidDate' => '2026-10-05',
		])], []);

		$this->assertSame([51], array_column($this->serviceOn('2026-10-20')->diagnose(self::USER_ID)['paidOneTimeBills'], 'billId'));
	}

	/**
	 * What #163 was about: paid, its date cleared, left active. Nothing is
	 * left due on it whenever it was paid, so it is listed as before.
	 */
	public function testAOneTimeBillAnOlderVersionLeftActiveWithNoDueDateIsListed(): void {
		$this->with([$this->bill([
			'id' => 52, 'name' => 'Invoice', 'amount' => 90.0, 'frequency' => 'one-time', 'dueDay' => 3, 'dueMonth' => 3,
			'startDate' => '2026-03-03', 'nextDueDate' => null, 'lastPaidDate' => '2026-05-20',
		])], []);

		$this->assertSame([52], array_column($this->serviceOn('2026-10-20')->diagnose(self::USER_ID)['paidOneTimeBills'], 'billId'));
	}

	public function testAOneTimeBillWithAPaymentRecordedByItsDateIsListedWhateverItsPaidDateSays(): void {
		// Paid 19 October as a monthly bill, switched to One-Time on 20
		// November, and the November payment linked to it since, which
		// leaves the paid date as it was
		$this->with([$this->bill([
			'id' => 53, 'name' => 'Garage', 'amount' => 80.0, 'frequency' => 'one-time', 'dueDay' => 20, 'dueMonth' => 11,
			'startDate' => '2026-11-20', 'nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19',
		])], [
			$this->row(501, 53, 'Garage', '2026-10-19', 80.0, '2026-10-19 09:00:00'),
			$this->row(502, 53, 'Garage', '2026-11-18', 80.0, '2026-11-18 09:00:00', null, ['notes' => null]),
		]);

		$this->assertSame([53], array_column($this->serviceOn('2026-11-25')->diagnose(self::USER_ID)['paidOneTimeBills'], 'billId'));
	}

	/**
	 * Weekly, paid 1 October, switched to One-Time for an invoice due on the
	 * 8th: a week is a payment of its own, not this one paid early. The
	 * repair comes ticked, so a bill still owed must not be listed.
	 */
	public function testAOneTimeBillDueAWeekAfterItsLastPaymentIsStillDue(): void {
		$this->with([$this->bill([
			'id' => 54, 'name' => 'Cleaner', 'amount' => 60.0, 'frequency' => 'one-time', 'dueDay' => 8, 'dueMonth' => 10,
			'startDate' => '2026-10-08', 'nextDueDate' => '2026-10-08', 'lastPaidDate' => '2026-10-01',
		])], [
			$this->row(601, 54, 'Cleaner', '2026-10-01', 60.0, '2026-10-01 09:00:00'),
		]);

		$this->assertSame([], $this->serviceOn('2026-10-09')->diagnose(self::USER_ID)['paidOneTimeBills']);
	}
}
