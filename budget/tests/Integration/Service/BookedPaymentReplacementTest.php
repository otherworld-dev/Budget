<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The bank's own row of a payment the app already booked takes the booked
 * row's place, through the real import and database, and keeps what the
 * user added to the booked row: a split with a contact, a receipt, tags,
 * their notes.
 */
class BookedPaymentReplacementTest extends IntegrationTestCase {
	private int $account;
	private \DateTimeImmutable $today;

	protected function setUp(): void {
		parent::setUp();
		$this->today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$this->account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
	}

	private function day(int $offset): string {
		return $this->today->modify("{$offset} days")->format('Y-m-d');
	}

	/** Import "date,description,amount" lines into the account as a CSV statement */
	private function importCsv(string $lines): array {
		$file = tempnam(sys_get_temp_dir(), 'stmt');
		file_put_contents($file, "Date,Description,Amount\n" . $lines);
		$imports = $this->service(ImportService::class);
		try {
			$upload = $imports->processUpload($this->userId, ['name' => 'statement.csv', 'tmp_name' => $file, 'size' => filesize($file)]);
			return $imports->processImport($this->userId, $upload['fileId'], ['date' => 0, 'description' => 1, 'amount' => 2, 'skipFirstRow' => true], $this->account);
		} finally {
			@unlink($file);
		}
	}

	private function balance(): string {
		return number_format((float)$this->service(AccountMapper::class)->findById($this->account)->getBalance(), 2, '.', '');
	}

	/** The id of the account's one row that came from a statement */
	private function bankRow(): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('id')->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($this->account, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('import_id'));
		$result = $qb->executeQuery();
		$ids = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		$this->assertCount(1, $ids);
		return (int)$ids[0];
	}

	public function testABillsBankRowKeepsWhatTheUserAddedToTheBookedPayment(): void {
		$due = $this->day(-10);
		$bills = $this->service(BillService::class);
		$bill = $bills->create(userId: $this->userId, name: 'Electric', amount: 50.0, frequency: 'monthly',
			dueDay: (int)substr($due, 8, 2), accountId: $this->account, autoDetectPattern: 'ELECTRIC CO');
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_bills')->set('next_due_date', $qb->createNamedParameter($due))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bill->getId(), IQueryBuilder::PARAM_INT)))->executeStatement();
		$paid = $bills->markPaid($bill->getId(), $this->userId, $due, true, null, $due);
		$booked = (int)$paid['createdTransactionIds'][0];
		// Split 50/50 with a contact (settled already), the invoice attached,
		// tagged, and a note added
		$share = $this->makeExpenseShare($booked, $this->makeContact());
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_expense_shares')->set('is_settled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($share, IQueryBuilder::PARAM_INT)))->executeStatement();
		$attachment = $this->makeAttachment($booked);
		$tag = $this->makeTag(null);
		$this->tagTransaction($booked, $tag);
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_transactions')->set('notes', $qb->createNamedParameter('Auto-generated from bill: Electric - includes the late fee'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($booked, IQueryBuilder::PARAM_INT)))->executeStatement();

		$this->importCsv($due . ",ELECTRIC CO DD 778,-50.00\n");

		$bank = $this->bankRow();
		$this->assertNull($this->fetchRow('budget_transactions', $booked), 'The booked payment gave way');
		$this->assertSame('950.00', $this->balance());
		$this->assertSame($bank, (int)$this->fetchRow('budget_expense_shares', $share)['transaction_id']);
		$this->assertTrue((bool)$this->fetchRow('budget_expense_shares', $share)['is_settled']);
		$this->assertSame($bank, (int)$this->fetchRow('budget_attachments', $attachment)['transaction_id']);
		$this->assertSame(1, $this->countRows('budget_transaction_tags', ['transaction_id' => $bank, 'tag_id' => $tag]));
		$this->assertSame('includes the late fee', $this->fetchRow('budget_transactions', $bank)['notes']);
		$this->assertSame(0, $this->countOrphans('budget_expense_shares', 'transaction_id'));
	}

	public function testAnIncomesBankRowKeepsWhatTheUserAddedToTheBookedCredit(): void {
		$incomes = $this->service(RecurringIncomeService::class);
		$category = $this->makeCategory(['name' => 'Salary', 'type' => 'income']);
		$income = $incomes->create(userId: $this->userId, name: 'Wages', amount: 500.0, frequency: 'monthly',
			categoryId: $category, accountId: $this->account, autoDetectPattern: 'ACME PAYROLL', startDate: $this->day(-40));
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_recurring_income')->set('next_expected_date', $qb->createNamedParameter($this->day(-10)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($income->getId(), IQueryBuilder::PARAM_INT)))->executeStatement();
		$incomes->markReceived($income->getId(), $this->userId, $this->day(-10), true);
		$qb = $this->db()->getQueryBuilder();
		$qb->select('id')->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($this->account, IQueryBuilder::PARAM_INT)));
		$booked = (int)$qb->executeQuery()->fetchOne();
		$attachment = $this->makeAttachment($booked);

		$this->importCsv($this->day(-10) . ",ACME PAYROLL 1002,500.00\n");

		$bank = $this->bankRow();
		$this->assertNull($this->fetchRow('budget_transactions', $booked));
		$this->assertSame('1500.00', $this->balance());
		$this->assertSame($bank, (int)$this->fetchRow('budget_attachments', $attachment)['transaction_id']);
		$this->assertSame($category, (int)$this->fetchRow('budget_transactions', $bank)['category_id'], 'Still counted as salary');
	}
}
