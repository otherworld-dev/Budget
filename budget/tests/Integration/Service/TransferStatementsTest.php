<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ImportService;
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

	public function testBetweenCurrenciesTheDestinationsStatementFirst(): void {
		$euro = $this->euroAccount();
		$this->transfer($this->checking, $euro, 100.0);

		$this->importCsv($euro, $this->day(-10) . ",FROM GBP ACCOUNT,117.40\n");
		$this->importCsv($this->checking, $this->day(-11) . ",SAVINGS TFR 0042,-100.00\n");

		$this->assertSame(['900.00', '117.40'], [$this->balance($this->checking), $this->balance($euro)]);
	}
}
