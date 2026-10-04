<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Migration\Version001000122Date20261004;
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
			'schema_version' => 2,
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [[
				'type' => 'condition', 'field' => 'description', 'matchType' => 'regex',
				'pattern' => Version001000122Date20261004::DEFAULT_RULES['Online Shopping'], 'negate' => false,
			]]]]),
			'actions' => json_encode(['version' => 2, 'stopProcessing' => true, 'actions' => []]),
		]);
		$withCategory = $this->makeRule(['name' => 'Restaurants', 'pattern' => Version001000122Date20261004::DEFAULT_RULES['Restaurants'], 'category_id' => $this->makeCategory()]);
		$withAction = $this->makeRule([
			'name' => 'Utilities',
			'pattern' => Version001000122Date20261004::DEFAULT_RULES['Utilities'],
			'actions' => json_encode(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 3]]]),
		]);
		$usersOwn = $this->makeRule(['pattern' => 'shell garage']);

		$messages = $this->runMigration();

		$this->assertFalse((bool)$this->fetchRow('budget_import_rules', $empty)['active']);
		$this->assertFalse((bool)$this->fetchRow('budget_import_rules', $openedInEditor)['active']);
		foreach ([$withCategory, $withAction, $usersOwn] as $id) {
			$this->assertTrue((bool)$this->fetchRow('budget_import_rules', $id)['active'], "rule {$id} was switched off");
		}
		$this->assertSame(5, $this->countRows('budget_import_rules', ['user_id' => $this->userId]));
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
