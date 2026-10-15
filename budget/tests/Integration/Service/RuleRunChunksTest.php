<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\IDBConnection;

/**
 * "Run rules" saves its rows in chunks, one database transaction each
 * (T6-3). Against the real database: every chunk's rows are saved, and on
 * PostgreSQL, where a failed statement makes the rest of a transaction fail
 * too, a row that can't be saved costs only itself.
 */
class RuleRunChunksTest extends IntegrationTestCase {
	private function rule(string $contains, array $actions): int {
		return $this->insertRow('budget_import_rules', [
			'user_id' => $this->userId,
			'name' => 'Rule ' . $contains,
			'pattern' => '',
			'field' => 'description',
			'match_type' => 'contains',
			'priority' => 0,
			'active' => true,
			'apply_on_import' => true,
			'schema_version' => 2,
			'stop_processing' => true,
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => $contains, 'negate' => false],
			]]]),
			'actions' => json_encode(['version' => 2, 'actions' => $actions]),
			'created_at' => $this->now(),
		]);
	}

	public function testEveryChunkOfALongRunIsSaved(): void {
		$account = $this->makeAccount()->getId();
		$category = $this->makeCategory();
		for ($i = 0; $i < 501; $i++) {
			$this->makeTransaction($account, ['description' => 'SHOP ' . $i]);
		}
		$this->rule('SHOP', [['type' => 'set_category', 'value' => $category]]);

		$result = $this->service(ImportRuleService::class)->applyRulesToTransactions($this->userId, [], []);

		$this->assertSame(501, $result['success']);
		$this->assertSame(0, $result['failed']);
		$this->assertCount(500, $result['applied']);
		$this->assertTrue($result['appliedTruncated']);
		$this->assertSame(501, $this->countRows('budget_transactions', ['account_id' => $account, 'category_id' => $category]));
		$this->assertFalse($this->db()->inTransaction());
	}

	public function testOnPostgresARowThatCannotBeSavedCostsOnlyItself(): void {
		if ($this->db()->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
			$this->markTestSkipped('Only PostgreSQL refuses the rest of a transaction after a failed statement');
		}
		$account = $this->makeAccount()->getId();
		$category = $this->makeCategory();
		$shops = [
			$this->makeTransaction($account, ['description' => 'SHOP one', 'date' => '2026-03-17']),
			$this->makeTransaction($account, ['description' => 'SHOP two', 'date' => '2026-03-15']),
		];
		$long = $this->makeTransaction($account, ['description' => 'LONG vendor', 'date' => '2026-03-16']);
		$this->rule('SHOP', [['type' => 'set_category', 'value' => $category]]);
		// Longer than the vendor column: PostgreSQL rejects the UPDATE
		$this->rule('LONG', [['type' => 'set_vendor', 'value' => str_repeat('v', 300)]]);

		$result = $this->service(ImportRuleService::class)->applyRulesToTransactions($this->userId, [], []);

		$this->assertSame(2, $result['success']);
		$this->assertSame(1, $result['failed']);
		foreach ($shops as $id) {
			$this->assertSame($category, (int)$this->fetchRow('budget_transactions', $id)['category_id']);
		}
		$this->assertNull($this->fetchRow('budget_transactions', $long)['vendor']);
		$this->assertFalse($this->db()->inTransaction());
	}
}
