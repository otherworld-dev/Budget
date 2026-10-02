<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000115Date20261002;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Deleting a category could leave rows filed under it (#399 review): the
 * delete guard didn't see pre-booked rows, and nothing cleared bills. Those
 * rows kept counting in balances and totals, yet showed in no category's
 * breakdown and not under Uncategorized either, and every payment of such
 * a bill was booked to the dead category. Deleting a category now clears
 * them; this clears what earlier deletes left, the same way.
 */
class DeletedCategoryReferencesMigrationTest extends IntegrationTestCase {
	private const GONE = 987654;

	public function testRowsFiledUnderADeletedCategoryBecomeUncategorized(): void {
		$account = $this->makeAccount()->getId();
		$live = $this->makeCategory();
		$billLive = $this->bill($account, $live);
		$billDead = $this->bill($account, self::GONE);
		$txLive = $this->makeTransaction($account, ['category_id' => $live]);
		$txDead = $this->makeTransaction($account, ['category_id' => self::GONE, 'status' => 'scheduled']);
		$parent = $this->makeTransaction($account, ['is_split' => true]);
		$splitLive = $this->makeSplit($parent, $live, '5.00');
		$splitDead = $this->makeSplit($parent, self::GONE, '5.00');
		$incomeDead = $this->insertRow('budget_recurring_income', [
			'user_id' => $this->userId, 'name' => 'Salary', 'amount' => '2500.00', 'frequency' => 'monthly',
			'account_id' => $account, 'category_id' => self::GONE, 'created_at' => $this->now(), 'is_active' => true,
		]);

		$this->runMigration();

		$this->assertNull($this->fetchRow('budget_bills', $billDead)['category_id']);
		$this->assertNull($this->fetchRow('budget_transactions', $txDead)['category_id']);
		$this->assertNull($this->fetchRow('budget_tx_splits', $splitDead)['category_id']);
		$this->assertNull($this->fetchRow('budget_recurring_income', $incomeDead)['category_id']);
		$this->assertSame($live, (int)$this->fetchRow('budget_bills', $billLive)['category_id']);
		$this->assertSame($live, (int)$this->fetchRow('budget_transactions', $txLive)['category_id']);
		$this->assertSame($live, (int)$this->fetchRow('budget_tx_splits', $splitLive)['category_id']);
	}

	private function bill(int $accountId, int $categoryId): int {
		return $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Gym', 'amount' => '30.00', 'frequency' => 'monthly', 'due_day' => 1,
			'account_id' => $accountId, 'category_id' => $categoryId, 'is_active' => true,
			'next_due_date' => '2026-11-01', 'created_at' => $this->now(),
		]);
	}

	private function runMigration(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		(new Version001000115Date20261002($this->db()))
			->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
