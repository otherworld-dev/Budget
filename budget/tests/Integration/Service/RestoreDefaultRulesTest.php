<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\Import\SetupDefaultRules;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A backup made before 3.0 holds the empty rules "Create default categories"
 * used to make, still on and at priorities 5-10. Migration 122 switches
 * those off on upgrade, but a restore brought them back as they were, and
 * as schema-1 rules match at import again they blocked the user's own rules
 * (V2-1). The restore now treats them as the migration does.
 */
class RestoreDefaultRulesTest extends IntegrationTestCase {
	private function rule(array $overrides): int {
		return $this->insertRow('budget_import_rules', $overrides + [
			'user_id' => $this->userId,
			'name' => 'Gas Stations',
			'pattern' => SetupDefaultRules::RULES['Gas Stations'],
			'field' => 'description',
			'match_type' => 'regex',
			'priority' => 10,
			'active' => true,
			'apply_on_import' => true,
			'schema_version' => 1,
			'stop_processing' => true,
			'created_at' => $this->now(),
		]);
	}

	/**
	 * @return array<string, array<string, mixed>> restored rules by name
	 */
	private function restoredRules(string $user): array {
		$rows = $this->db()->executeQuery(
			'SELECT name, active, priority, pattern, criteria, actions FROM *PREFIX*budget_import_rules WHERE user_id = ?', [$user]
		)->fetchAll();
		return array_column($rows, null, 'name');
	}

	public function testARestoreSwitchesOffTheEmptyDefaultsAndKeepsEverythingElse(): void {
		$this->makeAccount();
		$category = $this->makeCategory(['name' => 'Groceries']);
		$this->rule([]);
		$this->rule(['name' => 'ATM Withdrawals', 'pattern' => SetupDefaultRules::RULES['ATM Withdrawals'], 'priority' => 7]);
		// The user gave this one a category: theirs now
		$this->rule(['name' => 'Grocery Stores', 'pattern' => SetupDefaultRules::RULES['Grocery Stores'], 'category_id' => $category]);
		$this->rule(['name' => 'Shell garage', 'pattern' => 'SHELL GARAGE', 'match_type' => 'contains', 'priority' => 0]);
		$migration = $this->service(MigrationService::class);
		$target = $this->newUserId();

		$migration->importAll($target, $migration->exportAll($this->userId)['content']);

		$rules = $this->restoredRules($target);
		foreach (['Gas Stations', 'ATM Withdrawals'] as $name) {
			$this->assertFalse((bool)$rules[$name]['active'], "{$name} came back on");
			$this->assertSame(0, (int)$rules[$name]['priority']);
		}
		$this->assertTrue((bool)$rules['Grocery Stores']['active']);
		$this->assertSame(10, (int)$rules['Grocery Stores']['priority']);
		$this->assertTrue((bool)$rules['Shell garage']['active']);
	}

	/**
	 * A default a 3.0 pre-release made with a category, still as it made it,
	 * comes back matching whole words (V3-4); one the user changed doesn't.
	 */
	public function testARestoreGivesUntouchedCategorisedDefaultsWholeWordPatterns(): void {
		$this->makeAccount();
		$category = $this->makeCategory(['name' => 'Utilities']);
		$this->rule($this->categorisedDefault('Utilities', $category));
		$this->rule(['stop_processing' => false] + $this->categorisedDefault('Restaurants', $category));
		$migration = $this->service(MigrationService::class);
		$target = $this->newUserId();

		$migration->importAll($target, $migration->exportAll($this->userId)['content']);

		$rules = $this->restoredRules($target);
		$pattern = SetupDefaultRules::DEFINITIONS['Utilities']['pattern'];
		$this->assertSame($pattern, $rules['Utilities']['pattern']);
		$this->assertSame($pattern, json_decode($rules['Utilities']['criteria'], true)['root']['conditions'][0]['pattern']);
		$this->assertTrue((bool)$rules['Utilities']['active']);
		$this->assertSame(0, (int)$rules['Utilities']['priority']);
		$action = json_decode($rules['Utilities']['actions'], true)['actions'][0];
		$this->assertSame('set_category', $action['type']);
		$this->assertNotSame($category, $action['value'], 'the category was not remapped to the restored one');
		$this->assertSame(SetupDefaultRules::RULES['Restaurants'], $rules['Restaurants']['pattern']);
	}

	/**
	 * @return array<string, mixed> a rule row as a 3.0 pre-release's setup made it
	 */
	private function categorisedDefault(string $name, int $category): array {
		$pattern = SetupDefaultRules::RULES[$name];
		return [
			'name' => $name,
			'pattern' => $pattern,
			'priority' => 0,
			'schema_version' => 2,
			'criteria' => json_encode(SetupDefaultRules::criteriaFor($pattern)),
			'actions' => json_encode(['version' => 2, 'stopProcessing' => true, 'actions' => [
				['type' => 'set_category', 'value' => $category, 'behavior' => 'always', 'priority' => 100],
			]]),
		];
	}
}
