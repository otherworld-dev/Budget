<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The reminder job remembers the due date its last notice was for, and
 * compares it with the bill's due date as text. Every database has to give
 * it back exactly as written.
 */
class BillReminderDueTest extends IntegrationTestCase {
	public function testTheDueDateOfTheLastNoticeComesBackAsWritten(): void {
		$account = $this->makeAccount()->getId();
		$id = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '20.00', 'frequency' => 'monthly', 'due_day' => 8,
			'account_id' => $account, 'is_active' => true, 'next_due_date' => '2026-10-08', 'reminder_days' => 3,
			'created_at' => $this->now(),
		]);
		$mapper = $this->service(BillMapper::class);

		$bill = $mapper->find($id, $this->userId);
		$this->assertNull($bill->getLastReminderDue());
		$bill->setLastReminderSent('2026-10-04 14:00:00');
		$bill->setLastReminderDue('2026-10-08');
		$mapper->update($bill);

		$this->assertSame('2026-10-08', $mapper->find($id, $this->userId)->getLastReminderDue());
		$this->assertSame($mapper->find($id, $this->userId)->getNextDueDate(), $mapper->find($id, $this->userId)->getLastReminderDue());
	}
}
