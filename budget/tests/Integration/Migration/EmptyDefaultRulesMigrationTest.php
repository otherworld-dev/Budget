<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000122Date20261004;
use OCA\Budget\Service\Import\SetupDefaultRules;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Version001000122Date20261004 switches off the action-less rules "Create
 * default categories" used to add. Run against real rows: only the untouched
 * defaults are switched off, nothing is deleted, and a second run is a no-op.
 *
 * The migration is global, so this relies on a throwaway database with no
 * other active default rules in it.
 */
class EmptyDefaultRulesMigrationTest extends IntegrationTestCase {
	private function makeRule(array $overrides): int {
		return $this->insertRow('budget_import_rules', $overrides + [
			'user_id' => $this->userId,
			'name' => 'Gas Stations',
			'pattern' => Version001000122Date20261004::DEFAULT_RULES['Gas Stations'],
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

	public function testOnlyTheUntouchedEmptyDefaultsAreSwitchedOff(): void {
		$empty = $this->makeRule([]);
		$openedInEditor = $this->makeRule([
			'name' => 'Online Shopping',
			'pattern' => Version001000122Date20261004::DEFAULT_RULES['Online Shopping'],
			'priority' => 5,
			'schema_version' => 2,
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [[
				'type' => 'condition', 'field' => 'description', 'matchType' => 'regex',
				'pattern' => Version001000122Date20261004::DEFAULT_RULES['Online Shopping'], 'negate' => false,
			]]]]),
			'actions' => json_encode(['version' => 2, 'stopProcessing' => true, 'actions' => []]),
		]);
		$withCategory = $this->makeRule(['name' => 'Restaurants', 'pattern' => Version001000122Date20261004::DEFAULT_RULES['Restaurants'], 'priority' => 8, 'category_id' => $this->makeCategory()]);
		$withAction = $this->makeRule([
			'name' => 'Utilities',
			'pattern' => Version001000122Date20261004::DEFAULT_RULES['Utilities'],
			'priority' => 9,
			'actions' => json_encode(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 3]]]),
		]);
		$usersOwn = $this->makeRule(['pattern' => 'shell garage']);
		$priorityChanged = $this->makeRule(['name' => 'ATM Withdrawals', 'pattern' => Version001000122Date20261004::DEFAULT_RULES['ATM Withdrawals'], 'priority' => 40]);

		$messages = $this->runMigration();

		foreach ([$empty, $openedInEditor] as $id) {
			$row = $this->fetchRow('budget_import_rules', $id);
			$this->assertFalse((bool)$row['active'], "rule {$id} is still on");
			$this->assertSame(0, (int)$row['priority'], "rule {$id} still outranks the user's rules");
		}
		$untouched = [$withCategory => 8, $withAction => 9, $usersOwn => 10, $priorityChanged => 40];
		foreach ($untouched as $id => $priority) {
			$row = $this->fetchRow('budget_import_rules', $id);
			$this->assertTrue((bool)$row['active'], "rule {$id} was switched off");
			$this->assertSame($priority, (int)$row['priority'], "rule {$id} was moved");
		}
		$this->assertSame(6, $this->countRows('budget_import_rules', ['user_id' => $this->userId]));
		$this->assertContains('Switched off 2 default import rule(s) that had no action', $messages);
	}

	public function testASecondRunChangesNothing(): void {
		$empty = $this->makeRule([]);

		$this->runMigration();
		$second = $this->runMigration();

		$this->assertSame([], $second);
		$this->assertFalse((bool)$this->fetchRow('budget_import_rules', $empty)['active']);
	}

	/**
	 * A default a 3.0 pre-release made with a category stays on and is given
	 * the whole-word pattern (V3-4); one the user changed keeps its own.
	 */
	public function testUntouchedCategorisedDefaultsGetWholeWordPatterns(): void {
		$category = $this->makeCategory();
		$untouched = $this->makeRule($this->categorisedDefault('Utilities', $category));
		$edited = $this->makeRule(['priority' => 3] + $this->categorisedDefault('Gas Stations', $category));

		$messages = $this->runMigration();

		$row = $this->fetchRow('budget_import_rules', $untouched);
		$pattern = SetupDefaultRules::DEFINITIONS['Utilities']['pattern'];
		$this->assertSame($pattern, $row['pattern']);
		$this->assertSame($pattern, json_decode($row['criteria'], true)['root']['conditions'][0]['pattern']);
		$this->assertTrue((bool)$row['active']);
		$this->assertSame(0, (int)$row['priority']);
		$this->assertSame($category, json_decode($row['actions'], true)['actions'][0]['value']);
		$this->assertSame(SetupDefaultRules::RULES['Gas Stations'], $this->fetchRow('budget_import_rules', $edited)['pattern']);
		$this->assertContains('Gave 1 default import rule(s) whole-word patterns', $messages);
		$this->assertSame([], $this->runMigration(), 'a second run changed something');
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

	/**
	 * @return string[] the info lines the migration printed
	 */
	private function runMigration(): array {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
			$messages[] = $message;
		});

		(new Version001000122Date20261004($this->db()))->postSchemaChange($output, static fn () => $schema, []);

		return $messages;
	}
}
