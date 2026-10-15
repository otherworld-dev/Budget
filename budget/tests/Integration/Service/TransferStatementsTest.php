<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A recurring transfer between two accounts that are both fed by bank
 * statements, through the real import and the real database: whatever the
 * user did first and whatever order the statements come in, each account
 * ends up with the bank's own row of the transfer, once.
 */
class TransferStatementsTest extends IntegrationTestCase {
	private int $checking;
	private int $savings;
	private \DateTimeImmutable $today;

	protected function setUp(): void {
		parent::setUp();
		$this->today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$this->checking = $this->makeAccount(['name' => 'Checking', 'openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
		$this->savings = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
	}

	/** A date relative to today */
	private function day(int $offset): string {
		return $this->today->modify("{$offset} days")->format('Y-m-d');
	}

	/** A monthly transfer of 200 from Checking (or $from) to Savings (or $to), due ten days ago */
	private function transfer(?int $from = null, ?int $to = null, float $amount = 200.0): int {
		$due = $this->day(-10);
		$bill = $this->service(BillService::class)->create(
			userId: $this->userId,
			name: 'Savings top-up',
			amount: $amount,
			frequency: 'monthly',
			dueDay: (int)substr($due, 8, 2),
			accountId: $from ?? $this->checking,
			description: 'Savings top-up',
			isTransfer: true,
			destinationAccountId: $to ?? $this->savings,
			transferDescriptionPattern: 'SAVINGS TFR',
		);
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_bills')->set('next_due_date', $qb->createNamedParameter($due))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bill->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_transactions')->set('date', $qb->createNamedParameter($due))
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $bill->getId();
	}

	/** Import "date,description,amount" lines into an account as a CSV statement */
	private function importCsv(int $accountId, string $lines): array {
		$file = tempnam(sys_get_temp_dir(), 'stmt');
		file_put_contents($file, "Date,Description,Amount\n" . $lines);
		$imports = $this->service(ImportService::class);
		try {
			$upload = $imports->processUpload($this->userId, ['name' => 'statement.csv', 'tmp_name' => $file, 'size' => filesize($file)]);
			return $imports->processImport($this->userId, $upload['fileId'], ['date' => 0, 'description' => 1, 'amount' => 2, 'skipFirstRow' => true], $accountId);
		} finally {
			@unlink($file);
		}
	}

	/**
	 * Import one OFX file holding several accounts' statements, given as
	 * OFX account id => [budget account, [[date, amount, name], ...]].
	 *
	 * @param array<string, array{0: int, 1: list<array{0: string, 1: string, 2: string}>}> $accounts
	 */
	private function importOfx(array $accounts): array {
		$body = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\nCHARSET:1252\nCOMPRESSION:NONE\nOLDFILEUID:NONE\nNEWFILEUID:NONE\n\n<OFX>\n<BANKMSGSRSV1>\n";
		$mapping = [];
		foreach ($accounts as $ofxId => [$accountId, $rows]) {
			$mapping[$ofxId] = $accountId;
			$body .= "<STMTTRNRS><TRNUID>1<STATUS><CODE>0<SEVERITY>INFO</STATUS>\n<STMTRS><CURDEF>GBP<BANKACCTFROM><BANKID>1<ACCTID>{$ofxId}<ACCTTYPE>CHECKING</BANKACCTFROM><BANKTRANLIST>\n";
			foreach ($rows as $i => [$date, $amount, $name]) {
				$type = str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT';
				$body .= '<STMTTRN><TRNTYPE>' . $type . '<DTPOSTED>' . str_replace('-', '', $date) . '<TRNAMT>' . $amount . '<FITID>' . $ofxId . '-' . $i . '<NAME>' . $name . "</STMTTRN>\n";
			}
			$body .= "</BANKTRANLIST></STMTRS></STMTTRNRS>\n";
		}
		$body .= "</BANKMSGSRSV1>\n</OFX>\n";
		$file = tempnam(sys_get_temp_dir(), 'stmt');
		file_put_contents($file, $body);
		$imports = $this->service(ImportService::class);
		try {
			$upload = $imports->processUpload($this->userId, ['name' => 'statement.ofx', 'tmp_name' => $file, 'size' => filesize($file)]);
			return $imports->processImport($this->userId, $upload['fileId'], [], null, $mapping);
		} finally {
			@unlink($file);
		}
	}

	/** A euro account, and the user's rate: 1 EUR = 0.85 GBP, so GBP 100 is EUR 117.65 */
	private function euroAccount(): int {
		$rate = new ManualExchangeRate();
		$rate->setUserId($this->userId);
		$rate->setCurrency('GBP');
		$rate->setRatePerEur('0.8500000000');
		$rate->setUpdatedAt($this->now());
		$this->service(ManualExchangeRateMapper::class)->insert($rate);
		return $this->makeAccount(['name' => 'Euro savings', 'type' => 'savings', 'currency' => 'EUR'])->getId();
	}

	private function markPaid(int $bill): void {
		$bills = $this->service(BillService::class);
		$bills->markPaid($bill, $this->userId, $this->day(-11), true, null, $bills->find($bill, $this->userId)->getNextDueDate());
	}

	private function balance(int $accountId): string {
		return number_format((float)$this->service(AccountMapper::class)->findById($accountId)->getBalance(), 2, '.', '');
	}

	/** How many of an account's rows came from a statement */
	private function bankRows(int $accountId): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('import_id'));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Paid from the bank's withdrawal, the transfer booked its deposit, and
	 * Mark Unpaid deleted that withdrawal along with the deposit.
	 */
	public function testMarkUnpaidKeepsTheBanksWithdrawal(): void {
		$bill = $this->transfer();
		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");
		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);

		$this->service(BillService::class)->markUnpaid($bill, $this->userId);

		$this->assertSame(1, $this->bankRows($this->checking));
		$this->assertSame('800.00', $this->balance($this->checking), 'The bank\'s withdrawal stays');
		$this->assertSame('0.00', $this->balance($this->savings), 'The deposit booked for it goes');
	}

	/**
	 * The source's statement paid the transfer and booked its deposit; the
	 * destination's statement then added its own credit beside it.
	 */
	public function testTheSourcesStatementThenTheDestinations(): void {
		$this->transfer();

		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");
		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n" . $this->day(-15) . ",Interest,0.50\n");

		$this->assertSame(['800.00', '200.50'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(2, $this->bankRows($this->savings));
	}

	public function testTheDestinationsStatementThenTheSources(): void {
		$this->transfer();

		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n");
		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
	}

	public function testBothAccountsInOneOfxFile(): void {
		$this->transfer();

		$this->importOfx([
			'111' => [$this->checking, [[$this->day(-11), '-200.00', 'SAVINGS TFR 0042']]],
			'222' => [$this->savings, [[$this->day(-11), '200.00', 'FROM CHECKING 0042']]],
		]);

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
	}

	/** Marked paid by hand, and only the destination is fed by statements */
	public function testMarkPaidThenTheDestinationsStatement(): void {
		$bill = $this->transfer();
		$this->markPaid($bill);

		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n");

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->savings, 'status' => 'cleared']));
	}

	/** The destination's statement first, then Mark Paid booked its deposit beside the bank's credit */
	public function testTheDestinationsStatementThenMarkPaid(): void {
		$bill = $this->transfer();
		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n");

		$this->markPaid($bill);

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->savings, 'status' => 'cleared']));

		$this->service(BillService::class)->markUnpaid($bill, $this->userId);

		$this->assertSame(1, $this->bankRows($this->savings), 'Mark Unpaid keeps the bank\'s credit');
		$this->assertSame('1000.00', $this->balance($this->checking));
	}

	public function testMarkPaidThenTheSourcesStatementThenTheDestinations(): void {
		$bill = $this->transfer();
		$this->markPaid($bill);

		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");
		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n");

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame([1, 1], [$this->bankRows($this->checking), $this->bankRows($this->savings)]);
	}

	public function testMarkPaidThenTheDestinationsStatementThenTheSourcesThenMarkUnpaid(): void {
		$bill = $this->transfer();
		$this->markPaid($bill);

		$this->importCsv($this->savings, $this->day(-11) . ",FROM CHECKING 0042,200.00\n");
		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");
		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);

		$this->service(BillService::class)->markUnpaid($bill, $this->userId);

		$this->assertSame([1, 1], [$this->bankRows($this->checking), $this->bankRows($this->savings)], 'Both bank rows stay');
		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
	}

	/**
	 * The savings account was reconciled with the deposit Mark Paid booked;
	 * the source's statement then swapped the booked pair out and deleted
	 * that reconciled deposit.
	 */
	public function testAReconciledDepositStaysWhenTheSourcesStatementComesIn(): void {
		$bill = $this->transfer();
		$this->markPaid($bill);
		$qb = $this->db()->getQueryBuilder();
		$qb->select('id')->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($this->savings, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('cleared')));
		$deposit = (int)$qb->executeQuery()->fetchOne();
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_transactions')->set('reconciled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($deposit, IQueryBuilder::PARAM_INT)))->executeStatement();

		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-200.00\n");

		$row = $this->fetchRow('budget_transactions', $deposit);
		$this->assertNotNull($row, 'The reconciled deposit stays');
		$withdrawal = $this->fetchRow('budget_transactions', (int)$row['linked_transaction_id']);
		$this->assertNotNull($withdrawal['import_id'], 'paired with the bank\'s withdrawal');
		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(0, $this->countRows('budget_audit_log', ['user_id' => $this->userId, 'action' => 'reconciled_tx_deleted']));
	}

	/**
	 * GBP to EUR: the booked deposit is the app's estimate (117.65) and the
	 * bank's own credit (117.40) takes its place, whichever statement comes
	 * first.
	 */
	public function testBetweenCurrenciesMarkPaidThenBothStatements(): void {
		$euro = $this->euroAccount();
		$bill = $this->transfer($this->checking, $euro, 100.0);
		$this->markPaid($bill);
		$this->assertSame('117.65', $this->balance($euro));

		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-100.00\n");
		$this->assertSame('117.65', $this->balance($euro), 'The withdrawal\'s arrival is converted');
		$this->importCsv($euro, $this->day(-10) . ",FROM GBP ACCOUNT,117.40\n");

		$this->assertSame(['900.00', '117.40'], [$this->balance($this->checking), $this->balance($euro)]);
	}

	public function testBetweenCurrenciesTheSourcesStatementFirst(): void {
		$euro = $this->euroAccount();
		$this->transfer($this->checking, $euro, 100.0);

		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-100.00\n");
		$this->assertSame('117.65', $this->balance($euro));
		$this->importCsv($euro, $this->day(-10) . ",FROM GBP ACCOUNT,117.40\n");

		$this->assertSame(['900.00', '117.40'], [$this->balance($this->checking), $this->balance($euro)]);
	}

	/**
	 * 2.54.0 pre-booked GBP 100 out and EUR 100 in; paying it after the
	 * upgrade cleared the pair as it stood.
	 */
	public function testAPairBookedBy254BetweenCurrenciesIsConvertedWhenPaid(): void {
		$euro = $this->euroAccount();
		$bill = $this->transfer($this->checking, $euro, 100.0);
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_transactions')->set('amount', $qb->createNamedParameter('100.00'))
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('credit')))
			->executeStatement();

		$this->markPaid($bill);

		$this->assertSame(['900.00', '117.65'], [$this->balance($this->checking), $this->balance($euro)]);
	}

	public function testBetweenCurrenciesTheDestinationsStatementFirst(): void {
		$euro = $this->euroAccount();
		$this->transfer($this->checking, $euro, 100.0);

		$this->importCsv($euro, $this->day(-10) . ",FROM GBP ACCOUNT,117.40\n");
		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-100.00\n");

		$this->assertSame(['900.00', '117.40'], [$this->balance($this->checking), $this->balance($euro)]);
	}

	/**
	 * The bank credited the savings five days after the withdrawal (a
	 * weekend, a bank holiday), and that statement came in first: the
	 * source's statement then paid the transfer by linking its withdrawal,
	 * looked three days either side, and booked a deposit beside it.
	 */
	public function testTheDestinationsCreditDaysLaterIsTheArrivalOfALinkedWithdrawal(): void {
		$this->transfer();

		$this->importCsv($this->savings, $this->day(-5) . ",FROM CHECKING 0042,200.00\n");
		$this->importCsv($this->checking, $this->day(-10) . ",SAVINGS TFR 0042,-200.00\n");

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->savings, 'status' => 'cleared']));
	}

	/** The same through the Mark Paid dialog's link, and through auto-pay catching up */
	public function testEveryWayOfLinkingTheWithdrawalFindsTheCreditAlreadyThere(): void {
		$bills = $this->service(BillService::class);
		$bill = $this->transfer();
		$this->importCsv($this->savings, $this->day(-5) . ",FROM CHECKING 0042,200.00\n");
		$withdrawal = $this->service(TransactionService::class)->create($this->userId, $this->checking, $this->day(-10), 'SAVINGS TFR 0042', 200.0, 'debit', importId: 'bank-w1');

		$bills->markPaid($bill, $this->userId, null, false, $withdrawal->getId(), $this->day(-10));

		$this->assertSame(['800.00', '200.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->savings, 'status' => 'cleared']));

		// Auto-pay: next month's payment is due, its rows are already in
		$second = $this->transfer();
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_bills')->set('auto_pay_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($second, IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->importCsv($this->savings, $this->day(-4) . ",FROM CHECKING 0043,200.00\n");
		$this->service(TransactionService::class)->create($this->userId, $this->checking, $this->day(-9), 'SAVINGS TFR 0043', 200.0, 'debit', importId: 'bank-w2');

		$result = $bills->processAutoPay($second, $this->userId);

		$this->assertTrue($result['success'], (string)$result['message']);
		$this->assertSame(['600.00', '400.00'], [$this->balance($this->checking), $this->balance($this->savings)]);
		$this->assertSame(2, $this->countRows('budget_transactions', ['account_id' => $this->savings, 'status' => 'cleared']));
	}

	/**
	 * A 2,000 top-up marked paid, a 2,000 salary due two days later into the
	 * same account: the salary was taken as the top-up's arrival, the income
	 * stayed expected, and auto-create booked the salary a second time.
	 */
	public function testASalaryIsNotTakenForATransfersArrival(): void {
		$bill = $this->transfer($this->savings, $this->checking, 2000.0);
		$this->markPaid($bill);
		$incomes = $this->service(RecurringIncomeService::class);
		$income = $incomes->create(userId: $this->userId, name: 'Salary', amount: 2000.0, frequency: 'monthly',
			accountId: $this->checking, autoDetectPattern: 'ACME', autoCreateEnabled: true, startDate: $this->day(-40));
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_recurring_income')->set('next_expected_date', $qb->createNamedParameter($this->day(-9)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($income->getId(), IQueryBuilder::PARAM_INT)))->executeStatement();

		$this->importCsv($this->checking, $this->day(-9) . ",ACME PAYROLL OCT,2000.00\n" . $this->day(-7) . ",TOPUP FROM SAVINGS,2000.00\n");
		$incomes->processAutoCreate($income->getId(), $this->userId);

		$this->assertSame('5000.00', $this->balance($this->checking), '1,000 + the top-up + one salary');
		$this->assertSame(2, $this->countRows('budget_transactions', ['account_id' => $this->checking, 'status' => 'cleared']));
		$this->assertSame($this->day(-9), substr((string)$this->fetchRow('budget_recurring_income', $income->getId())['last_received_date'], 0, 10));
	}

	/** A joint account: the partner's own credit of the same amount isn't the transfer's */
	public function testTheCreditNamingTheTransferIsItsArrival(): void {
		$bill = $this->transfer();
		$this->markPaid($bill);

		$this->importCsv($this->savings, $this->day(-11) . ",BOB SMITH SHARE,200.00\n" . $this->day(-10) . ",A SMITH SAVINGS TFR,200.00\n");

		$qb = $this->db()->getQueryBuilder();
		$qb->select('description', 'bill_id')->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($this->savings, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('import_id'))
			->orderBy('date');
		$rows = $qb->executeQuery()->fetchAll();
		$this->assertSame(['BOB SMITH SHARE' => null, 'A SMITH SAVINGS TFR' => $bill], array_map(
			fn ($id) => $id === null ? null : (int)$id,
			array_column($rows, 'bill_id', 'description')
		));
	}
}
