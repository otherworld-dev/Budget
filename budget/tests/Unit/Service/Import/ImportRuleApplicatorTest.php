<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\Import\CriteriaEvaluator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use PHPUnit\Framework\TestCase;

class ImportRuleApplicatorTest extends TestCase {
	/** An action any rule can carry out, for tests about matching */
	private const VENDOR_ACTION = ['version' => 2, 'actions' => [['type' => 'set_vendor', 'value' => 'Shop']]];

	private ImportRuleApplicator $applicator;
	private ImportRuleMapper $ruleMapper;
	private CriteriaEvaluator $evaluator;
	private GranularShareService $granularShareService;

	protected function setUp(): void {
		$this->ruleMapper = $this->createMock(ImportRuleMapper::class);
		$this->evaluator = $this->createMock(CriteriaEvaluator::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);
		// No shared rules by default — tests exercise own rules
		$this->granularShareService->method('getSharedImportRuleIds')->willReturn([]);
		$this->ruleMapper->method('findActiveByIds')->willReturn([]);
		$this->applicator = new ImportRuleApplicator(
			$this->ruleMapper,
			$this->evaluator,
			$this->granularShareService
		);
	}

	private function makeRule(array $overrides = []): ImportRule {
		$rule = new ImportRule();
		$rule->setId($overrides['id'] ?? 1);
		// Own the rule by the acting user in tests unless overridden, so its
		// actions apply without a co-share check.
		$rule->setUserId($overrides['userId'] ?? 'user1');
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
		$this->granularShareService->method('canAccess')
			->with('user1', 'category', 42)->willReturn(true);

		$tx = ['description' => 'Groceries', 'amount' => 50.0];
		$result = $this->applicator->applyRules('user1', $tx);

		$this->assertSame(42, $result['categoryId']);
		$this->assertSame(1, $result['appliedRule']['id']);
		$this->assertSame('Test Rule', $result['appliedRule']['name']);
	}

	/**
	 * The importer's own rule was trusted: an id saved in its actions without
	 * a check (a v1-schema rule, R6-4) stamped another user's category on the
	 * row, whose name the list then showed. Every rule's category must now be
	 * one the importer's ledger can use.
	 */
	/**
	 * An import loads the active rules once and hands them in for every row:
	 * loading them per row was one query, plus hydrating and sorting every
	 * rule, for each row of the file (T6-4).
	 */
	public function testRulesHandedInAreUsedWithoutLoadingThem(): void {
		$rule = $this->makeRule([
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_vendor', 'value' => 'Shop']]],
		]);
		$this->ruleMapper->expects($this->never())->method('findActive');
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'x'], [$rule]);

		$this->assertSame('Shop', $result['vendor']);
	}

	public function testRulesForLoadsOwnAndSharedRulesByPriority(): void {
		$low = $this->makeRule(['id' => 1]);
		$low->setPriority(1);
		$high = $this->makeRule(['id' => 2]);
		$high->setPriority(9);
		$this->ruleMapper->expects($this->once())->method('findActive')->with('user1')->willReturn([$low, $high]);

		$this->assertSame([$high, $low], $this->applicator->rulesFor('user1'));
	}

	// ── rules from before the rules engine (schema 1) ───────────────

	/**
	 * A rule made before 2.28: field, pattern and match type in their own
	 * columns, the category in category_id, no criteria and no actions.
	 */
	private function legacyRule(array $overrides = []): ImportRule {
		$rule = new ImportRule();
		$rule->setId($overrides['id'] ?? 3);
		$rule->setUserId('user1');
		$rule->setName($overrides['name'] ?? 'Tesco');
		$rule->setField($overrides['field'] ?? 'description');
		$rule->setPattern($overrides['pattern'] ?? 'tesco');
		$rule->setMatchType($overrides['matchType'] ?? 'contains');
		$rule->setCategoryId($overrides['categoryId'] ?? 5);
		$rule->setVendorName($overrides['vendorName'] ?? null);
		$rule->setPriority($overrides['priority'] ?? 0);
		$rule->setActive(true);
		$rule->setSchemaVersion(1);
		if (isset($overrides['actions'])) {
			$rule->setActionsFromArray($overrides['actions']);
		}
		return $rule;
	}

	private function applicatorWithRealMatching(array $rules): ImportRuleApplicator {
		$mapper = $this->createMock(ImportRuleMapper::class);
		$mapper->method('findActive')->willReturn($rules);
		$mapper->method('findActiveByIds')->willReturn([]);
		return new ImportRuleApplicator(
			$mapper,
			new CriteriaEvaluator($this->createMock(\Psr\Log\LoggerInterface::class)),
			$this->granularShareService
		);
	}

	/**
	 * Up to 2.27 the import matched these rules on their columns and set
	 * their category. Since 2.28 it read only the criteria column, which
	 * they don't have, so they matched nothing at import or bank sync,
	 * while Run rules still applied them.
	 */
	public function testARuleFromBeforeTheRulesEngineCategorisesAtImport(): void {
		$this->granularShareService->method('canAccess')->with('user1', 'category', 5)->willReturn(true);
		$applicator = $this->applicatorWithRealMatching([$this->legacyRule()]);

		$result = $applicator->applyRules('user1', ['description' => 'TESCO STORES 2041', 'amount' => 12.5, 'type' => 'debit']);

		$this->assertSame(5, $result['categoryId']);
		$this->assertSame('Tesco', $result['appliedRule']['name']);
	}

	public function testItsVendorIsSetToo(): void {
		$this->granularShareService->method('canAccess')->willReturn(true);
		$applicator = $this->applicatorWithRealMatching([$this->legacyRule(['vendorName' => 'Tesco'])]);

		$result = $applicator->applyRules('user1', ['description' => 'TESCO STORES 2041']);

		$this->assertSame('Tesco', $result['vendor']);
	}

	public function testItMatchesOnItsOwnFieldAndMatchType(): void {
		$this->granularShareService->method('canAccess')->willReturn(true);
		$applicator = $this->applicatorWithRealMatching([
			$this->legacyRule(['field' => 'vendor', 'matchType' => 'starts_with', 'pattern' => 'acme']),
		]);

		$this->assertSame(5, $applicator->applyRules('user1', ['description' => 'x', 'vendor' => 'ACME LTD'])['categoryId'] ?? null);
		$this->assertArrayNotHasKey('categoryId', $applicator->applyRules('user1', ['description' => 'ACME', 'vendor' => 'THE ACME']));
	}

	public function testItsCategoryMustStillBelongToTheLedger(): void {
		// The R6-4 check applies to it like any other rule
		$this->granularShareService->method('canAccess')->willReturn(false);
		$applicator = $this->applicatorWithRealMatching([$this->legacyRule()]);

		$result = $applicator->applyRules('user1', ['description' => 'TESCO STORES']);

		$this->assertArrayNotHasKey('categoryId', $result);
	}

	public function testItsCategoryInTheOldActionShapeIsSetToo(): void {
		$this->granularShareService->method('canAccess')->willReturn(true);
		$applicator = $this->applicatorWithRealMatching([
			$this->legacyRule(['categoryId' => null, 'actions' => ['categoryId' => 8, 'vendor' => 'Tesco']]),
		]);

		$result = $applicator->applyRules('user1', ['description' => 'TESCO']);

		$this->assertSame(8, $result['categoryId']);
		$this->assertSame('Tesco', $result['vendor']);
	}

	public function testItCountsInThePreviewAndStatistics(): void {
		$this->granularShareService->method('canAccess')->willReturn(true);
		$applicator = $this->applicatorWithRealMatching([$this->legacyRule()]);
		$rows = [['description' => 'TESCO'], ['description' => 'ALDI']];

		$this->assertSame([0], array_column($applicator->previewRuleApplications('user1', $rows), 'transactionIndex'));
		$this->assertSame(1, $applicator->getMatchStatistics('user1', $rows)['matched']);
	}

	public function testOwnRuleSetCategorySkippedWhenTheCategoryIsNotUsable(): void {
		$rule = $this->makeRule([
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);
		$this->granularShareService->method('canAccess')->willReturn(false);

		$result = $this->applicator->applyRules('user1', ['description' => 'x']);

		$this->assertArrayNotHasKey('categoryId', $result);
	}

	public function testSharedRuleSetCategoryAppliedWhenCoShared(): void {
		// A rule owned by someone else, shared with the importer. Its category is
		// co-shared, so it stamps during the importer's import.
		$rule = $this->makeRule([
			'userId' => 'owner2',
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]]],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);
		$this->granularShareService->method('canAccess')
			->with('user1', 'category', 7)->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'x']);

		$this->assertSame(7, $result['categoryId']);
	}

	public function testSharedRuleSetCategorySkippedWhenNotCoShared(): void {
		// The category the shared rule targets isn't visible to the importer, so
		// it must not be stamped onto their transaction.
		$rule = $this->makeRule([
			'userId' => 'owner2',
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]]],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);
		$this->granularShareService->method('canAccess')->willReturn(false);

		$result = $this->applicator->applyRules('user1', ['description' => 'x']);

		$this->assertArrayNotHasKey('categoryId', $result);
	}

	public function testApplyRulesSetDescription(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_description', 'value' => 'Updated Description']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Original']);

		$this->assertSame('Updated Description', $result['description']);
	}

	public function testApplyRulesRegexReplaceDescription(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'regex_replace',
					'field' => 'description',
					'pattern' => '/\\d+/',
					'replacement' => 'X',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Invoice 12345']);

		$this->assertSame('Invoice X', $result['description']);
	}

	public function testApplyRulesRegexReplaceIfEmptyChecksItsTargetField(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'regex_replace',
					'field' => 'description',
					'target' => 'notes',
					'pattern' => '/\\d+/',
					'replacement' => 'X',
					'behavior' => 'if_empty',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Invoice 123', 'notes' => null]);

		$this->assertSame('Invoice X', $result['notes']);
	}

	public function testApplyRulesRegexReplaceLeavesTargetUnchangedWhenPatternDoesNotMatch(): void {
		// preg_replace hands back the source unchanged when the pattern doesn't
		// match; writing that into a different target field would copy the
		// whole source value into it rather than doing nothing.
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'regex_replace',
					'field' => 'description',
					'target' => 'notes',
					'pattern' => '/\\d+/',
					'replacement' => 'X',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Groceries', 'notes' => 'original notes']);

		$this->assertSame('original notes', $result['notes']);
	}

	/**
	 * The import path runs the same replacement as "Run rules": it has to
	 * count characters too, or the row is stored with half a "ü" in it.
	 */
	public function testApplyRulesRegexReplaceCountsCharactersNotBytes(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'regex_replace',
					'field' => 'description',
					'pattern' => '^(.{20}).+$',
					'replacement' => '$1',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Zahlung Bäckerei Müller GmbH Berlin']);

		$this->assertSame('Zahlung Bäckerei Mül', $result['description']);
	}

	public function testApplyRulesRegexReplaceNeverProducesTextThatIsNotUtf8(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'regex_replace',
					'field' => 'description',
					// \C matches one byte even on characters
					'pattern' => '(?<=^.{18})\C',
					'replacement' => '',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Zahlung Bäckerei Müller']);

		$this->assertSame('Zahlung Bäckerei Müller', $result['description']);
	}

	public function testApplyRulesRunsMultipleRegexReplacementsInPriorityOrder(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [
					['type' => 'regex_replace', 'field' => 'description', 'pattern' => '/123/', 'replacement' => 'ABC', 'priority' => 20],
					['type' => 'regex_replace', 'field' => 'description', 'pattern' => '/ABC/', 'replacement' => 'XYZ', 'priority' => 10],
				],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Invoice 123']);

		$this->assertSame('Invoice XYZ', $result['description']);
	}

	public function testApplyRulesChangeCaseDescription(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'change_case',
					'field' => 'description',
					'mode' => 'upper',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'grocery run']);

		$this->assertSame('GROCERY RUN', $result['description']);
	}

	public function testApplyRulesChangeCaseDescriptionSentence(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'change_case',
					'field' => 'description',
					'mode' => 'sentence',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'grocery run']);

		$this->assertSame('Grocery run', $result['description']);
	}

	public function testApplyRulesChangeCaseUpperHandlesDiacritics(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'change_case', 'field' => 'description', 'mode' => 'upper']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'école']);

		$this->assertSame('ÉCOLE', $result['description']);
	}

	public function testApplyRulesReplaceTextDescription(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [[
					'type' => 'replace_text',
					'field' => 'description',
					'find' => '12345',
					'replace' => '67890',
				]],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Invoice 12345']);

		$this->assertSame('Invoice 67890', $result['description']);
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

	public function testApplyRulesSetForecastExclude(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_forecast_exclude', 'value' => 'true']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Car sale']);

		$this->assertTrue($result['excludedFromForecast']);
	}

	public function testApplyRulesSetForecastExcludeDefaultsTrueWhenNoValue(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_forecast_exclude']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Bonus']);

		$this->assertTrue($result['excludedFromForecast']);
	}

	public function testApplyRulesSetForecastExcludeCanInclude(): void {
		$rule = $this->makeRule([
			'actions' => [
				'version' => 2,
				'actions' => [['type' => 'set_forecast_exclude', 'value' => 'false']],
			],
		]);
		$this->ruleMapper->method('findActive')->willReturn([$rule]);
		$this->evaluator->method('evaluate')->willReturn(true);

		$result = $this->applicator->applyRules('user1', ['description' => 'Normal']);

		$this->assertFalse($result['excludedFromForecast']);
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

		$this->assertSame('credit', $result['type']);
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
		$this->granularShareService->method('canAccess')->willReturn(true);

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
		$this->granularShareService->method('canAccess')->willReturn(true);

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
		$rule = $this->makeRule(['id' => 5, 'name' => 'Grocery Rule', 'actions' => self::VENDOR_ACTION]);
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
		$rule1 = $this->makeRule(['id' => 1, 'actions' => self::VENDOR_ACTION]);
		$rule2 = $this->makeRule(['id' => 2, 'actions' => self::VENDOR_ACTION]);
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
		$this->granularShareService->method('canAccess')->willReturn(true);

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

		// A rule with nothing it can do is not the row's rule (V2-1)
		$this->assertArrayNotHasKey('appliedRule', $result);
		$this->assertArrayNotHasKey('categoryId', $result);
	}

	// ── a rule with nothing to do never takes a row (V2-1) ──────────

	public static function rulesWithNothingToDo(): array {
		return [
			'no actions at all' => [['version' => 2, 'stopProcessing' => true, 'actions' => []]],
			'an action of no known type' => [['version' => 2, 'actions' => [['type' => 'bogus', 'value' => 1]]]],
			'a category nobody can use' => [['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 404]]]],
			'only Set Account, which an import leaves alone' => [['version' => 2, 'actions' => [['type' => 'set_account', 'value' => 9]]]],
		];
	}

	/**
	 * Matching stops the rules after it, so a rule that changes nothing
	 * would keep the user's own rules from running. Setup's empty default
	 * rules, back from a pre-3.0 backup, did exactly that once older rules
	 * matched at import again.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('rulesWithNothingToDo')]
	public function testARuleWithNothingToDoDoesNotStopTheNextRule(array $actions): void {
		$blocker = $this->makeRule(['id' => 1, 'name' => 'Gas Stations', 'actions' => $actions]);
		$blocker->setPriority(10);
		$mine = $this->makeRule(['id' => 2, 'name' => 'Shell garage', 'actions' => [
			'version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]],
		]]);
		$this->ruleMapper->method('findActive')->willReturn([$blocker, $mine]);
		$this->evaluator->method('evaluate')->willReturn(true);
		$this->granularShareService->method('canAccess')
			->willReturnCallback(fn (string $user, string $type, int $id) => $id === 7);

		$result = $this->applicator->applyRules('user1', ['description' => 'SHELL GARAGE SHOP']);

		$this->assertSame(7, $result['categoryId']);
		$this->assertSame('Shell garage', $result['appliedRule']['name']);
		$this->assertSame([0], array_column($this->applicator->previewRuleApplications('user1', [['description' => 'x']]), 'transactionIndex'));
		$this->assertSame([2 => 1], $this->applicator->getMatchStatistics('user1', [['description' => 'x']])['ruleUsage']);
	}

	public function testAnOldRuleWhoseCategoryIsGoneDoesNotStopTheNextRule(): void {
		// Deleting a category leaves rules pointing at it; before 3.0 such a
		// rule never matched at import, so newer rules ran
		$this->granularShareService->method('canAccess')
			->willReturnCallback(fn (string $user, string $type, int $id) => $id === 7);
		$mine = $this->makeRule(['id' => 9, 'name' => 'Tesco groceries',
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => 'TESCO', 'negate' => false],
			]]]),
			'actions' => ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 7]]],
		]);
		$mine->setPriority(0);
		$applicator = $this->applicatorWithRealMatching([$this->legacyRule(['categoryId' => 404, 'priority' => 5]), $mine]);

		$result = $applicator->applyRules('user1', ['description' => 'TESCO STORES']);

		$this->assertSame('Tesco groceries', $result['appliedRule']['name'] ?? null);
	}
}
