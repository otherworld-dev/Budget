<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The SQL behind scheduled pension contributions, against a real database.
 */
class PensionScheduleQueriesTest extends IntegrationTestCase {
	private function makeSchedule(array $overrides = []): int {
		return $this->insertRow('budget_pen_recur', $overrides + [
			'user_id' => $this->userId,
			'pension_id' => 999001,
			'amount' => '200.00',
			'frequency' => 'monthly',
			'source_account_id' => null,
			'auto_post_enabled' => true,
			'next_due_date' => '2026-10-01',
			'is_active' => true,
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		]);
	}

	/**
	 * Deleting an account takes it off every schedule it funded, another
	 * user's included, and turns their auto-post off; other schedules keep
	 * theirs.
	 */
	public function testDetachSourceAccountOnlyTouchesTheSchedulesItFunded(): void {
		$account = $this->makeAccount()->getId();
		$other = $this->makeAccount(['name' => 'Savings'])->getId();
		$mine = $this->makeSchedule(['source_account_id' => $account]);
		$sharee = $this->makeSchedule(['source_account_id' => $account, 'user_id' => $this->newUserId()]);
		$untouched = $this->makeSchedule(['source_account_id' => $other]);

		$changed = $this->service(PensionRecurringContributionMapper::class)->detachSourceAccount($account);

		$this->assertSame(2, $changed);
		foreach ([$mine, $sharee] as $id) {
			$row = $this->fetchRow('budget_pen_recur', $id);
			$this->assertNull($row['source_account_id']);
			$this->assertFalse((bool)$row['auto_post_enabled']);
		}
		$row = $this->fetchRow('budget_pen_recur', $untouched);
		$this->assertSame($other, (int)$row['source_account_id']);
		$this->assertTrue((bool)$row['auto_post_enabled']);
	}

	/** The job asks with the user's own date */
	public function testFindDueForAutoPostUsesTheDateItIsGiven(): void {
		$due = $this->makeSchedule(['next_due_date' => '2026-10-02']);
		$this->makeSchedule(['next_due_date' => '2026-10-03']);
		$this->makeSchedule(['next_due_date' => '2026-10-01', 'auto_post_enabled' => false]);

		$found = $this->service(PensionRecurringContributionMapper::class)->findDueForAutoPost($this->userId, '2026-10-02');

		$this->assertSame([$due], array_map(fn ($r) => $r->getId(), $found));
	}
}
