<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Bills with no auto-detect pattern against the bank's own rows of their
 * payments, on a real database.
 *
 * The NatWest current account of August 2026: twelve bills paid in the
 * app (1-14 Aug), then the statement brought the bank's rows of the same
 * payments a day or few later with the bank's text. With no pattern the
 * import passed every bill by, the bank rows went in beside the booked
 * payments and the balance came out the sum of them too low.
 */
class BillImportNameMatchTest extends IntegrationTestCase {
	private const OPENING = 5000.0;

	/**
	 * [bill name, bank text, amount, day the app booked it, day the bank took it]
	 */
	private const AUGUST = [
		['Octopus Energy', 'OCTOPUS ENERGY', '257.05', '2026-08-01', '2026-08-03'],
		['Me Nationwide', 'ME NATIONWIDE', '200.00', '2026-08-01', '2026-08-03'],
		['Lynne Williams', 'LYNNE WILLIAMS', '135.00', '2026-08-01', '2026-08-03'],
		['Morgan Aj', 'MORGAN AJ', '250.00', '2026-08-01', '2026-08-03'],
		['Adam', 'ADAM', '150.00', '2026-08-01', '2026-08-03'],
		['Dwr Cymru Welsh Wa', 'DWR CYMRU WELSH WA', '77.60', '2026-08-01', '2026-08-03'],
		['Nptcbc Income Acct', 'NPTCBC INCOME ACCT', '197.00', '2026-08-01', '2026-08-03'],
		['Ee', 'EE LIMITED', '1.90', '2026-08-01', '2026-08-03'],
		['Aquiss', 'AQUISS', '36.00', '2026-08-05', '2026-08-06'],
		['Practice Plan', 'PRACTICE PLAN', '15.00', '2026-08-08', '2026-08-10'],
		['Sumup **neath Port', 'SUMUP **NEATH PORT', '35.00', '2026-08-08', '2026-08-10'],
		['Claude.ai', 'ANTHROPIC* CLAUDE', '180.00', '2026-08-14', '2026-08-17'],
	];

	/** The statement's other rows that day, which pay no bill */
	private const OTHERS = [
		['ADAM MORGAN', '200.00', 'credit', '2026-08-03'],
		['CELTIC LEISURE', '39.00', 'debit', '2026-08-03'],
		['TESCO BANK', '112.92', 'debit', '2026-08-03'],
	];

	private int $account;
	private int $category;
	/** @var array<string, int> bill name => id */
	private array $bills = [];
	/** @var array<string, int> bill name => the payment the app booked */
	private array $booked = [];

	/** The exact case: twelve booked payments, then the statement */
	public function testTheAugustStatementTakesThePlaceOfEveryBookedPayment(): void {
		$this->setUpAugust();
		[$bankIds, $rows] = $this->importAugust();

		$result = $this->service(BillService::class)->matchImportedRows($this->userId, $rows);

		$this->assertSame(12, $result['matched']);
		$this->assertSame([], $result['review']);
		foreach (self::AUGUST as [$name, $bankText]) {
			$this->assertNull($this->fetchRow('budget_transactions', $this->booked[$name]), "{$name}: the booked payment goes");
			$bank = $this->fetchRow('budget_transactions', $bankIds[$bankText]);
			$this->assertSame($this->bills[$name], (int)$bank['bill_id'], "{$name}: the bank row pays the bill");
			$this->assertSame($this->category, (int)$bank['category_id'], "{$name}: and takes its category");
			$this->assertSame('cleared', $bank['status']);
			$this->assertSame('bank-' . $bankText, $bank['import_id'], "{$name}: and keeps its import id, so a re-import skips it");
		}
		foreach (self::OTHERS as [$text]) {
			$this->assertNull($this->fetchRow('budget_transactions', $bankIds[$text])['bill_id'] ?? null, "{$text} pays no bill");
		}
		$this->assertEqualsWithDelta(self::OPENING - 1534.55 + 200.00 - 39.00 - 112.92, $this->balance(), 0.001, 'Every payment counted once');
	}

	/** The preview says what the import will do, before it does any of it */
	public function testThePreviewCountsTheRowsThatWillMatch(): void {
		$this->setUpAugust();
		$rows = [];
		foreach ([...self::AUGUST, ['', 'TESCO BANK', '112.92', '', '2026-08-03']] as [, $bankText, $amount, , $date]) {
			$rows[] = $this->unsaved($bankText, $amount, $date);
		}

		$preview = $this->service(BillService::class)->previewImportMatches($this->userId, $rows);

		$this->assertSame(12, $preview['matched']);
		$this->assertSame(0, $preview['review']);
		$this->assertSame('Claude.ai', $preview['rows'][11]['billName']);
		$this->assertArrayNotHasKey(12, $preview['rows'], 'TESCO BANK pays nothing');
		$this->assertSame(12, $this->countRows('budget_transactions', ['account_id' => $this->account]), 'Nothing written');
	}

	/** Two rows the one booked payment could be: neither is guessed */
	public function testTwoRowsForOnePaymentAreLeftForReview(): void {
		$this->setUpAugust();
		$first = $this->makeTransaction($this->account, ['date' => '2026-08-03', 'description' => 'ADAM', 'amount' => '150.00', 'import_id' => 'bank-adam-1']);
		$second = $this->makeTransaction($this->account, ['date' => '2026-08-04', 'description' => 'ADAM', 'amount' => '150.00', 'import_id' => 'bank-adam-2']);

		$result = $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$first, $second]));

		$this->assertSame(0, $result['matched']);
		$this->assertEqualsCanonicalizing([$first, $second], $result['review']);
		$this->assertNotNull($this->fetchRow('budget_transactions', $this->booked['Adam']), 'The booked payment stays');
		$preview = $this->service(BillService::class)->previewImportMatches($this->userId, [
			$this->unsaved('ADAM', '150.00', '2026-08-03'), $this->unsaved('ADAM', '150.00', '2026-08-04'),
		]);
		$this->assertSame(['matched' => 0, 'review' => 2], ['matched' => $preview['matched'], 'review' => $preview['review']]);

		// ...and the duplicate finder offers both pairs to merge
		$pairs = array_filter($this->service(BillService::class)->findPaymentPairs($this->userId), fn ($p) => $p['billName'] === 'Adam');
		$this->assertEqualsCanonicalizing([$first, $second], array_map(fn ($p) => $p['bank']['id'], $pairs));
	}

	/** Two bills of the one name and amount, both due: the row could pay either */
	public function testARowTwoBillsCouldBeIsLeftForReview(): void {
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$this->makeBill('Adam', '150.00', '2026-08-01');
		$this->makeBill('Adam', '150.00', '2026-08-02');
		$row = $this->makeTransaction($this->account, ['date' => '2026-08-03', 'description' => 'ADAM', 'amount' => '150.00', 'import_id' => 'bank-adam']);

		$result = $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$row]));

		$this->assertSame(['matched' => 0, 'review' => [$row]], $result);
	}

	/**
	 * Rows 20075 and 20078: a bill no payment was matched to keeps its
	 * scheduled placeholder. The bank's row of it pays the bill now, in the
	 * placeholder's place, and moves it on to the next month.
	 */
	public function testTheBankRowPaysABillStillWaitingOnIt(): void {
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$bill = $this->makeBill('Me Nationwide', '200.00', '2026-09-01');
		$placeholder = $this->makeTransaction($this->account, [
			'date' => '2026-09-01', 'description' => '', 'vendor' => 'Me Nationwide', 'amount' => '200.00',
			'status' => 'scheduled', 'bill_id' => $bill, 'notes' => 'Auto-generated from bill: Me Nationwide',
		]);
		$row = $this->makeTransaction($this->account, ['date' => '2026-09-02', 'description' => 'ME NATIONWIDE', 'amount' => '200.00', 'import_id' => 'bank-nw']);

		$result = $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$row]));

		$this->assertSame(1, $result['matched']);
		$this->assertNull($this->fetchRow('budget_transactions', $placeholder));
		$this->assertSame($bill, (int)$this->fetchRow('budget_transactions', $row)['bill_id']);
		$this->assertSame('2026-10-01', substr((string)$this->fetchRow('budget_bills', $bill)['next_due_date'], 0, 10));
	}

	/** A pound out is a different payment: amounts agree to the penny */
	public function testAnAmountOffByAPennyIsNotMatched(): void {
		$this->setUpAugust();
		$row = $this->makeTransaction($this->account, ['date' => '2026-08-03', 'description' => 'OCTOPUS ENERGY', 'amount' => '257.06', 'import_id' => 'bank-oct']);

		$result = $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$row]));

		$this->assertSame(['matched' => 0, 'review' => []], $result);
		$this->assertNotNull($this->fetchRow('budget_transactions', $this->booked['Octopus Energy']));
	}

	/** Five days either side by default */
	public function testSixDaysOffIsOutsideTheDefaultWindow(): void {
		$this->setUpAugust();
		$sixDaysOn = $this->makeTransaction($this->account, ['date' => '2026-08-07', 'description' => 'OCTOPUS ENERGY', 'amount' => '257.05', 'import_id' => 'bank-oct']);

		$this->assertSame(0, $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$sixDaysOn]))['matched']);
	}

	/** The user's setting widens it (read once per owner, so set first) */
	public function testTheMatchWindowIsTheUsersSetting(): void {
		$this->service(SettingService::class)->set($this->userId, BillService::MATCH_WINDOW_SETTING, '7');
		$this->setUpAugust();
		$sixDaysOn = $this->makeTransaction($this->account, ['date' => '2026-08-07', 'description' => 'OCTOPUS ENERGY', 'amount' => '257.05', 'import_id' => 'bank-oct']);

		$this->assertSame(1, $this->service(BillService::class)->matchImportedRows($this->userId, $this->rows([$sixDaysOn]))['matched']);
	}

	/** The double count the import used to leave, merged from the duplicate finder */
	public function testTheDuplicateFinderPairsAndMergesThem(): void {
		$this->setUpAugust();
		[$bankIds] = $this->importAugust();
		$bills = $this->service(BillService::class);

		$pairs = $bills->findPaymentPairs($this->userId);

		$this->assertCount(12, $pairs);
		$octopus = array_values(array_filter($pairs, fn ($p) => $p['billName'] === 'Octopus Energy'))[0];
		$this->assertSame($this->booked['Octopus Energy'], $octopus['booked']['id']);
		$this->assertSame($bankIds['OCTOPUS ENERGY'], $octopus['bank']['id']);

		$bills->mergePayment($this->userId, $this->bills['Octopus Energy'], $this->booked['Octopus Energy'], $bankIds['OCTOPUS ENERGY']);

		$this->assertNull($this->fetchRow('budget_transactions', $this->booked['Octopus Energy']));
		$bank = $this->fetchRow('budget_transactions', $bankIds['OCTOPUS ENERGY']);
		$this->assertSame($this->bills['Octopus Energy'], (int)$bank['bill_id']);
		$this->assertSame('2026-08-03', substr((string)$bank['date'], 0, 10), 'The bank keeps its date');
		$this->assertSame('OCTOPUS ENERGY', $bank['description'], 'and its text');
		$this->assertCount(11, $bills->findPaymentPairs($this->userId));

		$this->expectException(\InvalidArgumentException::class);
		$bills->mergePayment($this->userId, $this->bills['Octopus Energy'], $this->booked['Octopus Energy'], $bankIds['OCTOPUS ENERGY']);
	}

	/**
	 * Auto-pay in an account the bank's rows come into books the payment
	 * pending, and a week on with no bank row it is listed; an account kept
	 * by hand still gets a cleared one
	 */
	public function testAutoPayBooksPendingUntilTheBankShowsIt(): void {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$day = fn (int $offset): string => $today->modify("{$offset} days")->format('Y-m-d');
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$this->makeTransaction($this->account, ['date' => $day(-40), 'description' => 'EARLIER STATEMENT', 'import_id' => 'bank-earlier']);
		$bill = $this->makeBill('Aquiss', '36.00', $day(-8), ['auto_pay_enabled' => true, 'auto_pay_failed' => false]);
		$bills = $this->service(BillService::class);

		$this->assertTrue($bills->processAutoPay($bill, $this->userId)['success']);

		$qb = $this->db()->getQueryBuilder();
		$qb->select('id', 'status')->from('budget_transactions')
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($day(-8))));
		$paid = $qb->executeQuery()->fetch();
		$this->assertSame('pending', $paid['status']);

		$items = array_values(array_filter($bills->findUnconfirmedPayments($this->userId), fn ($i) => $i['kind'] === 'unconfirmed'));
		$this->assertCount(1, $items);
		$this->assertSame((int)$paid['id'], $items[0]['transactionId']);

		$bills->confirmPayment($this->userId, $bill, (int)$paid['id']);
		$this->assertSame('cleared', $this->fetchRow('budget_transactions', (int)$paid['id'])['status']);
		$this->assertSame([], array_filter($bills->findUnconfirmedPayments($this->userId), fn ($i) => $i['kind'] === 'unconfirmed'));
	}

	/** An account kept by hand has no bank row to wait for: auto-pay books it cleared */
	public function testAutoPayInAHandKeptAccountBooksCleared(): void {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$bill = $this->makeBill('Window cleaner', '20.00', $today->modify('-2 days')->format('Y-m-d'), ['auto_pay_enabled' => true, 'auto_pay_failed' => false]);

		$result = $this->service(BillService::class)->processAutoPay($bill, $this->userId);

		$this->assertTrue($result['success'], (string)$result['message']);
		$this->assertSame(1, $this->countRows('budget_transactions', ['bill_id' => $bill, 'status' => 'cleared']));
	}

	/** A bill a week overdue in a statement account is listed; one in a hand-kept account isn't */
	public function testABillLongOverdueIsListed(): void {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$day = fn (int $offset): string => $today->modify("{$offset} days")->format('Y-m-d');
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$this->makeTransaction($this->account, ['date' => $day(-40), 'description' => 'EARLIER STATEMENT', 'import_id' => 'bank-earlier']);
		$late = $this->makeBill('Me Nationwide', '200.00', $day(-8));
		$this->makeBill('Adam', '150.00', $day(-6));

		$items = $this->service(BillService::class)->findUnconfirmedPayments($this->userId);

		$this->assertSame([[$late, 'overdue', $day(-8)]], array_map(fn ($i) => [$i['billId'], $i['kind'], $i['date']], $items));
	}

	private function setUpAugust(): void {
		$this->account = $this->makeAccount(['openingBalance' => self::OPENING, 'balance' => self::OPENING])->getId();
		$this->category = $this->makeCategory(['name' => 'Bills']);
		foreach (self::AUGUST as [$name, , $amount, $bookedOn]) {
			// Paid in August: due again in September
			$this->bills[$name] = $this->makeBill($name, $amount, '2026-09-' . substr($bookedOn, 8, 2), ['category_id' => $this->category]);
			// As the app booked it: the bill's name, no description, cleared
			$this->booked[$name] = $this->makeTransaction($this->account, [
				'date' => $bookedOn, 'description' => '', 'vendor' => $name, 'amount' => $amount,
				'bill_id' => $this->bills[$name], 'category_id' => $this->category,
				'notes' => 'Auto-generated from bill: ' . $name,
			]);
		}
		$this->service(TransactionService::class)->recalculateAccountBalance($this->account, $this->userId);
	}

	/**
	 * The statement's rows, stored as the import stores them
	 *
	 * @return array{0: array<string, int>, 1: Transaction[]} bank text => id, and the rows
	 */
	private function importAugust(): array {
		$ids = [];
		foreach (self::AUGUST as [, $bankText, $amount, , $date]) {
			$ids[$bankText] = $this->makeTransaction($this->account, ['date' => $date, 'description' => $bankText, 'amount' => $amount, 'import_id' => 'bank-' . $bankText]);
		}
		foreach (self::OTHERS as [$text, $amount, $type, $date]) {
			$ids[$text] = $this->makeTransaction($this->account, ['date' => $date, 'description' => $text, 'amount' => $amount, 'type' => $type, 'import_id' => 'bank-' . $text]);
		}
		$this->service(TransactionService::class)->recalculateAccountBalance($this->account, $this->userId);
		return [$ids, $this->rows(array_values($ids))];
	}

	/** @param array<string, mixed> $extra */
	private function makeBill(string $name, string $amount, string $nextDue, array $extra = []): int {
		return $this->insertRow('budget_bills', $extra + [
			'user_id' => $this->userId, 'name' => $name, 'amount' => $amount, 'frequency' => 'monthly',
			'due_day' => (int)substr($nextDue, 8, 2), 'account_id' => $this->account, 'is_active' => true,
			'next_due_date' => $nextDue, 'create_transaction' => true, 'created_at' => $this->now(),
		]);
	}

	/**
	 * @param int[] $ids
	 * @return Transaction[]
	 */
	private function rows(array $ids): array {
		return array_map(fn (int $id) => $this->service(TransactionService::class)->findTransaction($id), $ids);
	}

	private function unsaved(string $text, string $amount, string $date): Transaction {
		$row = new Transaction();
		$row->setAccountId($this->account);
		$row->setDate($date);
		$row->setAmount((float)$amount);
		$row->setType('debit');
		$row->setDescription($text);
		$row->setStatus('cleared');
		return $row;
	}

	private function balance(): float {
		return (float)$this->service(AccountMapper::class)->findById($this->account)->getBalance();
	}
}
