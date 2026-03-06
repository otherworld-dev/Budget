<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Service\Import\CriteriaEvaluator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use PHPUnit\Framework\TestCase;

class ImportRuleApplicatorTest extends TestCase {
	private ImportRuleApplicator $applicator;
	private ImportRuleMapper $ruleMapper;
	private CriteriaEvaluator $evaluator;

	protected function setUp(): void {
		$this->ruleMapper = $this->createMock(ImportRuleMapper::class);
		$this->evaluator = $this->createMock(CriteriaEvaluator::class);
		$this->applicator = new ImportRuleApplicator($this->ruleMapper, $this->evaluator);
	}

	private function makeRule(array $overrides = []): ImportRule {
		$rule = new ImportRule();
		$rule->setId($overrides['id'] ?? 1);
		$rule->setName($overrides['name'] ?? 'Test Rule');
		$rule->setApplyOnImport($overrides['applyOnImport'] ?? true);
		$rule->setSchemaVersion($overrides['schemaVersion'] ?? 2);
		$rule->setStopProcessing($overrides['stopProcessing'] ?? true);
		$rule->setCriteria($overrides['criteria'] ?? null);

		if (isset($overrides['actions'])) {
			$rule->setActions(json_encode($overrides['actions']));
		}
		return $rule;
	}

	// ── applyRules ──────────────────────────────────────────────────

	public function testApplyRulesSetCategory(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_category', 'value' => 42]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$tx = ['description' => 'Groceries', 'amount' => 50.0];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertSame(42, $result['categoryId']);
		$this->assertSame(1, $result['appliedRule']['id']);
		$this->assertSame('Test Rule', $result['appliedRule']['name']);
	}

	public function testApplyRulesSetVendor(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_vendor', 'value' => 'Amazon']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'AMZN*123']);

		$this->assertSame('Amazon', $result['vendor']);
	}

	public function testApplyRulesSetNotes(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_notes', 'value' => 'Auto-categorized']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		$this->assertSame('Auto-categorized', $result['notes']);
	}

	public function testApplyRulesSetNotesAppend(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_notes', 'value' => 'tagged', 'behavior' => 'append', 'separator' => ' | ']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$tx = ['description' => 'Test', 'notes' => 'Existing note'];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertSame('Existing note | tagged', $result['notes']);
	}

	public function testApplyRulesSetType(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_type', 'value' => 'income']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Refund']);

		$this->assertSame('income', $result['type']);
	}

	public function testApplyRulesSetTypeRejectsInvalid(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_type', 'value' => 'transfer']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$tx = ['description' => 'Test', 'type' => 'expense'];
		$result = $this->applicator->applyRules('user1', $tx);

		// 'transfer' is not in ['income', 'expense'], so type unchanged
		$this->assertSame('expense', $result['type']);
	}

	public function testApplyRulesSetReference(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_reference', 'value' => 'REF-001']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		$this->assertSame('REF-001', $result['reference']);
	}

	public function testApplyRulesIfEmptyBehavior(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_vendor', 'value' => 'Default Vendor', 'behavior' => 'if_empty']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		// Vendor already set → should NOT overwrite
		$tx = ['description' => 'Test', 'vendor' => 'Existing Vendor'];
		$result = $this->applicator->applyRules('user1', $tx);
		$this->assertSame('Existing Vendor', $result['vendor']);

		// Vendor empty → should set
		$tx2 = ['description' => 'Test', 'vendor' => ''];
		$result2 = $this->applicator->applyRules('user1', $tx2);
		$this->assertSame('Default Vendor', $result2['vendor']);
	}

	public function testApplyRulesPriorityOrdering(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [
					['type' => 'set_vendor', 'value' => 'Low Priority', 'priority' => 10],
					['type' => 'set_vendor', 'value' => 'High Priority', 'priority' => 90],
				],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		// High priority runs first, then low priority overwrites (both have behavior='always')
		$this->assertSame('Low Priority', $result['vendor']);
	}

	public function testApplyRulesMultipleActionsOnSameTransaction(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [
					['type' => 'set_category', 'value' => 5],
					['type' => 'set_vendor', 'value' => 'Cleaned Vendor'],
					['type' => 'set_notes', 'value' => 'Auto-tagged'],
				],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		$this->assertSame(5, $result['categoryId']);
		$this->assertSame('Cleaned Vendor', $result['vendor']);
		$this->assertSame('Auto-tagged', $result['notes']);
	}

	// ── Rule selection behavior ─────────────────────────────────────

	public function testApplyRulesSkipsNonImportRules(): void {
		$rule = $this->makeRule(['applyOnImport' => false]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->expects($this->never())->method('evaluate');

		$tx = ['description' => 'Test', 'amount' => 10.0];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertArrayNotHasKey('appliedRule', $result);
	}

	public function testApplyRulesSkipsNonMatchingRules(): void {
		$rule = $this->makeRule([
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(false);

		$tx = ['description' => 'No match'];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertArrayNotHasKey('categoryId', $result);
		$this->assertArrayNotHasKey('appliedRule', $result);
	}

	public function testApplyRulesStopProcessingTrue(): void {
		$rule1 = $this->makeRule([
			'id' => 1,
			'stopProcessing' => true,
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_vendor', 'value' => 'First']]],
		]);
		$rule2 = $this->makeRule([
			'id' => 2,
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_vendor', 'value' => 'Second']]],
		]);

		$this->ruleMapper->method('findActive')->willReturn([$rule1, $rule2]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		// First rule matches and stops, second rule never applied
		$this->assertSame('First', $result['vendor']);
		$this->assertSame(1, $result['appliedRule']['id']);
	}

	public function testApplyRulesStopProcessingFalse(): void {
		$rule1 = $this->makeRule([
			'id' => 1,
			'stopProcessing' => false,
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_vendor', 'value' => 'First']]],
		]);
		$rule2 = $this->makeRule([
			'id' => 2,
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_notes', 'value' => 'From Rule 2']]],
		]);

		$this->ruleMapper->method('findActive')->willReturn([$rule1, $rule2]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);

		// Both rules applied: rule1 sets vendor, rule2 sets notes
		$this->assertSame('First', $result['vendor']);
		$this->assertSame('From Rule 2', $result['notes']);
		// appliedRule is the last one that matched
		$this->assertSame(2, $result['appliedRule']['id']);
	}

	public function testApplyRulesNoRules(): void {
		$this->ruleMapper->method('findActive')->willReturn([]);

		$tx = ['description' => 'Test', 'amount' => 10.0];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertSame($tx, $result);
	}

	// ── applyRulesToMany ────────────────────────────────────────────

	public function testApplyRulesToManyProcessesAll(): void {
		$rule = $this->makeRule([
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]]],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$txns = [
			['description' => 'A'],
			['description' => 'B'],
			['description' => 'C'],
		];

		$results = $this->applicator->applyRulesToMany('user1', $txns);

		$this->assertCount(3, $results);
		foreach ($results as $r) {
			$this->assertSame(7, $r['categoryId']);
		}
	}

	// ── previewRuleApplications ─────────────────────────────────────

	public function testPreviewRuleApplications(): void {
		$rule = $this->makeRule(['id' => 5, 'name' => 'Grocery Rule']);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);

		// Only first transaction matches
		$this->evaluator->method('evaluate')
			->willReturnOnConsecutiveCalls(true, false);

		$txns = [
			['description' => 'Grocery Store'],
			['description' => 'Electronics'],
		];

		$previews = $this->applicator->previewRuleApplications('user1', $txns);

		$this->assertCount(1, $previews);
		$this->assertSame(0, $previews[0]['transactionIndex']);
		$this->assertSame(5, $previews[0]['rule']['id']);
		$this->assertSame('Grocery Rule', $previews[0]['rule']['name']);
	}

	public function testPreviewSkipsNonImportRules(): void {
		$rule = $this->makeRule(['applyOnImport' => false]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->expects($this->never())->method('evaluate');

		$previews = $this->applicator->previewRuleApplications('user1', [['description' => 'Test']]);
		$this->assertEmpty($previews);
	}

	// ── getMatchStatistics ──────────────────────────────────────────

	public function testGetMatchStatistics(): void {
		$rule1 = $this->makeRule(['id' => 1]);
		$rule2 = $this->makeRule(['id' => 2]);
		$this->ruleMapper->method('findActive')->willReturn([$rule1, $rule2]);

		// tx1 matches rule1, tx2 matches rule1, tx3 no match
		$this->evaluator->method('evaluate')
			->willReturnOnConsecutiveCalls(
				true,   // tx1 vs rule1 → match
				true,   // tx2 vs rule1 → match
				false,  // tx3 vs rule1 → no
				false   // tx3 vs rule2 → no
			);

		$txns = [
			['description' => 'A'],
			['description' => 'B'],
			['description' => 'C'],
		];

		$stats = $this->applicator->getMatchStatistics('user1', $txns);

		$this->assertSame(3, $stats['total']);
		$this->assertSame(2, $stats['matched']);
		$this->assertSame(1, $stats['unmatched']);
		$this->assertEqualsWithDelta(2 / 3, $stats['matchRate'], 0.001);
		$this->assertSame(2, $stats['ruleUsage'][1]); // rule1 matched twice
	}

	public function testGetMatchStatisticsEmpty(): void {
		$this->ruleMapper->method('findActive')->willReturn([]);

		$stats = $this->applicator->getMatchStatistics('user1', []);

		$this->assertSame(0, $stats['total']);
		$this->assertSame(0, $stats['matched']);
		$this->assertSame(0, $stats['unmatched']);
		$this->assertSame(0, $stats['matchRate']);
	}

	// ── Legacy action format ────────────────────────────────────────

	public function testApplyRulesLegacyActionsFormat(): void {
		// Actions without version wrapper — just {actions: [...]}
		$rule = $this->makeRule([
			'actions' => [
				'actions' => [['type' => 'set_category', 'value' => 10]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Test']);
		$this->assertSame(10, $result['categoryId']);
	}

	public function testApplyRulesSkipsNullActionType(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['value' => 'no-type-field']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$tx = ['description' => 'Test'];
		$result = $this->applicator->applyRules('user1', $tx);

		// No action applied, but appliedRule still tracked
		$this->assertArrayHasKey('appliedRule', $result);
		$this->assertArrayNotHasKey('categoryId', $result);
	}
}
