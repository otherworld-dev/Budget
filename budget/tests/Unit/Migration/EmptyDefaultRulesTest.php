<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000122Date20261004;
use PHPUnit\Framework\TestCase;

/**
 * "Create default categories" used to add six import rules with no action,
 * which matched first and stopped the user's own rules. Only rules still
 * exactly as setup made them, with no action, are switched off.
 */
class EmptyDefaultRulesTest extends TestCase {
	private const GAS = 'gas|fuel|shell|chevron|exxon|bp|mobil';

	private function row(array $overrides = []): array {
		return $overrides + [
			'name' => 'Gas Stations',
			'pattern' => self::GAS,
			'field' => 'description',
			'match_type' => 'regex',
			'category_id' => null,
			'vendor_name' => null,
			'actions' => null,
			'criteria' => null,
			'priority' => 10,
			'stop_processing' => '1',
			'apply_on_import' => '1',
			'group_name' => null,
		];
	}

	public static function booleanSpellings(): array {
		return [[true], [1], ['1'], ['t'], [null]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('booleanSpellings')]
	public function testEveryDatabasesTrueCounts(mixed $true): void {
		$row = $this->row(['stop_processing' => $true, 'apply_on_import' => $true]);

		$this->assertTrue(Version001000122Date20261004::isUntouchedEmptyDefault($row));
	}

	private function editorCriteria(string $pattern = self::GAS): string {
		return json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [[
			'type' => 'condition', 'field' => 'description', 'matchType' => 'regex',
			'pattern' => $pattern, 'negate' => false,
		]]]]);
	}

	public function testTheRuleSetupMadeIsSwitchedOff(): void {
		$this->assertTrue(Version001000122Date20261004::isUntouchedEmptyDefault($this->row()));
	}

	public function testOpeningItInTheEditorStillCountsAsUnchanged(): void {
		// The editor converts an old rule to criteria + an empty action list
		$row = $this->row([
			'criteria' => $this->editorCriteria(),
			'actions' => json_encode(['version' => 2, 'stopProcessing' => true, 'actions' => []]),
		]);

		$this->assertTrue(Version001000122Date20261004::isUntouchedEmptyDefault($row));
	}

	public static function rulesTheUserMadeTheirOwn(): array {
		$setCategory = json_encode(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]]]);
		return [
			'given a category' => [['category_id' => 7]],
			'given a vendor' => [['vendor_name' => 'Shell']],
			'given an action' => [['actions' => $setCategory]],
			'given a legacy category action' => [['actions' => json_encode(['categoryId' => 7])]],
			'pattern changed' => [['pattern' => 'shell']],
			'another field' => [['field' => 'vendor']],
			'not a regex' => [['match_type' => 'contains']],
			'a rule of the user\'s with the same name' => [['name' => 'Gas Stations', 'pattern' => 'BP ']],
			'criteria changed in the editor' => [['criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'regex', 'pattern' => self::GAS, 'negate' => false],
				['type' => 'condition', 'field' => 'amount', 'matchType' => 'greater_than', 'pattern' => 20, 'negate' => false],
			]]])]],
			'not a default rule at all' => [['name' => 'Coffee', 'pattern' => 'coffee']],
			'priority changed' => [['priority' => 50]],
			'set to carry on to later rules' => [['stop_processing' => '0']],
			'not applied on import' => [['apply_on_import' => false]],
			'put in a group' => [['group_name' => 'Car']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('rulesTheUserMadeTheirOwn')]
	public function testARuleTheUserChangedIsLeftAlone(array $overrides): void {
		$this->assertFalse(Version001000122Date20261004::isUntouchedEmptyDefault($this->row($overrides)));
	}
}
