<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A weekly auto-pay bill that fell behind (2.54.0 auto-paid at most once a
 * month) paid one occurrence per job run, every row dated the day of the
 * run. One run now pays every occurrence owed, each on its own due date,
 * and leaves one pending row for the next.
 */
class AutoPayCatchUpTest extends IntegrationTestCase {
	public function testOneRunPaysEveryOwedOccurrenceOnItsDueDate(): void {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$day = fn (int $offset): string => $today->modify("{$offset} days")->format('Y-m-d');
		$account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Gym', 'amount' => '10.00', 'frequency' => 'weekly',
			'account_id' => $account, 'is_active' => true, 'next_due_date' => $day(-21),
			'auto_pay_enabled' => true, 'auto_pay_failed' => false, 'created_at' => $this->now(),
		]);

		$result = $this->service(BillService::class)->processAutoPay($bill, $this->userId);

		$this->assertTrue($result['success'], (string)$result['message']);
		$this->assertSame(4, $result['count']);
		$qb = $this->db()->getQueryBuilder();
		$qb->select('date', 'status')->from('budget_transactions')
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill)))
			->orderBy('date', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$cleared = array_values(array_map(
			fn (array $r) => substr((string)$r['date'], 0, 10),
			array_filter($rows, fn (array $r) => $r['status'] === 'cleared')
		));
		$this->assertSame([$day(-21), $day(-14), $day(-7), $day(0)], $cleared);
		$this->assertSame(1, count(array_filter($rows, fn (array $r) => $r['status'] === 'scheduled')));
		$this->assertSame($day(7), substr((string)$this->fetchRow('budget_bills', $bill)['next_due_date'], 0, 10));
	}

	/**
	 * The bank's rows for three of the weeks were imported already (2.54.0
	 * never matched them, its due date lagging): catch-up booked a payment
	 * beside each, and the account paid those weeks twice.
	 */
	public function testCatchUpLinksTheBankRowsAlreadyImported(): void {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$day = fn (int $offset): string => $today->modify("{$offset} days")->format('Y-m-d');
		$account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Window cleaner', 'amount' => '25.00', 'frequency' => 'weekly',
			'account_id' => $account, 'is_active' => true, 'next_due_date' => $day(-21), 'auto_detect_pattern' => 'WINDOW CLEAN',
			'auto_pay_enabled' => true, 'auto_pay_failed' => false, 'created_at' => $this->now(),
		]);
		$bankRows = [];
		foreach ([-21, -13, -7] as $i => $offset) {
			$bankRows[] = $this->makeTransaction($account, ['date' => $day($offset), 'description' => 'WINDOW CLEAN LTD', 'amount' => '25.00', 'import_id' => 'bank-wc-' . $i]);
		}
		$this->service(TransactionService::class)->recalculateAccountBalance($account, $this->userId);

		$result = $this->service(BillService::class)->processAutoPay($bill, $this->userId);

		$this->assertTrue($result['success'], (string)$result['message']);
		$this->assertSame(4, $result['count']);
		foreach ($bankRows as $row) {
			$this->assertSame($bill, (int)$this->fetchRow('budget_transactions', $row)['bill_id'], 'The bank row pays its week');
		}
		$qb = $this->db()->getQueryBuilder();
		$qb->select('date')->from('budget_transactions')
			->where($qb->expr()->eq('bill_id', $qb->createNamedParameter($bill)))
			->andWhere($qb->expr()->isNull('import_id'))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('cleared')));
		$booked = array_map(fn (array $r) => substr((string)$r['date'], 0, 10), $qb->executeQuery()->fetchAll());
		$this->assertSame([$day(0)], $booked, 'Only the week with no bank row is booked');
		$this->assertSame(900.0, (float)$this->service(AccountMapper::class)->findById($account)->getBalance());
	}
}
