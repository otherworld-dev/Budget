<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * After "Create default categories", a rule the user makes in the rule
 * editor (which starts it at priority 1) has to win over an overlapping
 * default rule, at import and in Run rules: T3 found "contains SHELL
 * GARAGE -> Groceries" never ran, because Gas Stations matched "shell" first.
 */
class DefaultRulesRankTest extends IntegrationTestCase {
	private function categoryId(string $name): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('id')->from('budget_categories')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		return $id;
	}

	public function testTheUsersOwnRuleWinsOverAnOverlappingDefault(): void {
		$this->service(CategoryService::class)->createDefaultCategories($this->userId);
		$rules = $this->service(ImportRuleService::class);
		$this->assertNotEmpty($rules->createDefaultRules($this->userId));
		$groceries = $this->categoryId('Groceries');
		$gas = $this->categoryId('Gas');

		// Made after the defaults, at the priority the rule editor starts at
		$rules->create(
			userId: $this->userId,
			name: 'Shell garage snacks',
			criteria: ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => 'SHELL GARAGE', 'negate' => false],
			]]],
			schemaVersion: 2,
			priority: 1,
			actions: ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => $groceries, 'behavior' => 'always']]],
		);

		$imported = $this->service(ImportRuleApplicator::class)
			->applyRules($this->userId, ['description' => 'SHELL GARAGE SHOP', 'amount' => 12.0, 'type' => 'debit']);
		$this->assertSame($groceries, $imported['categoryId']);
		$this->assertSame('Shell garage snacks', $imported['appliedRule']['name']);

		$account = $this->makeAccount()->getId();
		$snacks = $this->makeTransaction($account, ['description' => 'SHELL GARAGE SHOP']);
		$fuel = $this->makeTransaction($account, ['description' => 'SHELL FUEL 1234']);
		$rules->applyRulesToTransactions($this->userId, [], []);

		$this->assertSame($groceries, (int)$this->fetchRow('budget_transactions', $snacks)['category_id']);
		// The default still does its job where the user's rule doesn't match
		$this->assertSame($gas, (int)$this->fetchRow('budget_transactions', $fuel)['category_id']);
	}
}
