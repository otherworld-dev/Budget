<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
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

	/** A monthly transfer of 200 from Checking to Savings, due ten days ago */
	private function transfer(): int {
		$due = $this->day(-10);
		$bill = $this->service(BillService::class)->create(
			userId: $this->userId,
			name: 'Savings top-up',
			amount: 200.0,
			frequency: 'monthly',
			dueDay: (int)substr($due, 8, 2),
			accountId: $this->checking,
			description: 'Savings top-up',
			isTransfer: true,
			destinationAccountId: $this->savings,
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
}
