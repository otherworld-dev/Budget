<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Import\CriteriaEvaluator;
use OCA\Budget\Service\Import\RuleActionApplicator;
use OCA\Budget\Service\ImportRuleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ImportRuleServiceTest extends TestCase {
	private ImportRuleService $service;
	private ImportRuleMapper $mapper;
	private CategoryMapper $categoryMapper;
	private TransactionMapper $transactionMapper;
	private IDBConnection $db;
	private CriteriaEvaluator $criteriaEvaluator;
	private RuleActionApplicator $actionApplicator;
	private \OCA\Budget\Service\GranularShareService $granularShareService;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ImportRuleMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$transactionService = $this->createMock(\OCA\Budget\Service\TransactionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->criteriaEvaluator = $this->createMock(CriteriaEvaluator::class);
		$this->actionApplicator = $this->createMock(RuleActionApplicator::class);
		$this->granularShareService = $this->createMock(\OCA\Budget\Service\GranularShareService::class);

		$this->service = new ImportRuleService(
			$this->mapper,
			$this->categoryMapper,
			$this->transactionMapper,
			$transactionService,
			$this->db,
			$this->criteriaEvaluator,
			$this->actionApplicator,
			$this->granularShareService
		);
	}

	private function makeRule(array $overrides = []): ImportRule {
		$rule = new ImportRule();
		$defaults = [
			'id' => 1,
			'userId' => 'user1',
			'name' => 'Test Rule',
			'pattern' => 'grocery',
			'field' => 'description',
			'matchType' => 'contains',
			'categoryId' => 5,
			'vendorName' => null,
			'priority' => 10,
			'active' => true,
			'schemaVersion' => 1,
			'stopProcessing' => true,
		];
		$data = array_merge($defaults, $overrides);

		$rule->setId($data['id']);
		$rule->setUserId($data['userId']);
		$rule->setName($data['name']);
		$rule->setPattern($data['pattern']);
		$rule->setField($data['field']);
		$rule->setMatchType($data['matchType']);
		$rule->setCategoryId($data['categoryId']);
		$rule->setVendorName($data['vendorName']);
		$rule->setPriority($data['priority']);
		$rule->setActive($data['active']);
		$rule->setSchemaVersion($data['schemaVersion']);
		$rule->setStopProcessing($data['stopProcessing']);

		return $rule;
	}

	// ===== find / findAll =====

	public function testFindDelegatesToMapper(): void {
		$rule = $this->makeRule();
		$this->mapper->expects($this->once())->method('find')
			->with(1, 'user1')->willReturn($rule);

		$result = $this->service->find(1, 'user1');
		$this->assertSame($rule, $result);
	}

	public function testFindAllDelegatesToMapper(): void {
		$rules = [$this->makeRule()];
		$this->mapper->expects($this->once())->method('findAll')
			->with('user1')->willReturn($rules);

		$result = $this->service->findAll('user1');
		$this->assertSame($rules, $result);
	}

	// ===== create v1 =====

	public function testCreateV1RuleValidatesAndInserts(): void {
		$this->categoryMapper->expects($this->once())->method('find')->with(5, 'user1');

		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (ImportRule $r) {
				$this->assertEquals('user1', $r->getUserId());
				$this->assertEquals('Grocery Rule', $r->getName());
				$this->assertEquals('grocery', $r->getPattern());
				$this->assertEquals('description', $r->getField());
				$this->assertEquals('contains', $r->getMatchType());
				$this->assertEquals(5, $r->getCategoryId());
				$this->assertEquals(10, $r->getPriority());
				$this->assertTrue($r->getActive());
				$r->setId(1);
				return $r;
			});

		$result = $this->service->create(
			'user1', 'Grocery Rule', 'grocery', 'description', 'contains',
			null, 1, 5, null, 10
		);

		$this->assertEquals('Grocery Rule', $result->getName());
	}

	public function testCreateV1RejectsInvalidMatchType(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid match type');

		$this->service->create('user1', 'Bad Rule', 'test', 'description', 'fuzzy');
	}

	public function testCreateV1RejectsInvalidField(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid field');

		$this->service->create('user1', 'Bad Rule', 'test', 'invalid_field', 'contains');
	}

	public function testCreateV1RequiresPatternFieldMatch(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create('user1', 'Bad Rule', null, null, null);
	}

	// ===== create v2 =====

	public function testCreateV2RuleValidatesCriteria(): void {
		$criteria = ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]];

		$this->criteriaEvaluator->expects($this->once())->method('validate')
			->with($criteria)->willReturn(['valid' => true]);

		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (ImportRule $r) {
				$this->assertEquals(2, $r->getSchemaVersion());
				$r->setId(1);
				return $r;
			});

		$this->service->create('user1', 'V2 Rule', null, null, null, $criteria, 2);
	}

	public function testCreateV2RejectsInvalidCriteria(): void {
		$this->criteriaEvaluator->method('validate')
			->willReturn(['valid' => false, 'errors' => ['Missing conditions']]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid criteria');

		$this->service->create('user1', 'Bad V2', null, null, null, ['bad' => true], 2);
	}

	public function testCreateV2RequiresCriteria(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Criteria required');

		$this->service->create('user1', 'No Criteria', null, null, null, null, 2);
	}

	public function testCreateV2ValidatesActions(): void {
		$criteria = ['version' => 2, 'root' => []];
		$actions = ['actions' => [['type' => 'set_category', 'value' => 5]]];

		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->expects($this->once())->method('validateActions')
			->with($actions, 'user1')->willReturn(['valid' => true]);

		$this->mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(1);
			return $r;
		});

		$this->service->create('user1', 'With Actions', null, null, null, $criteria, 2, null, null, 0, $actions);
	}

	// ===== category actions must belong to the ledger (R6-4) =====

	private function refusedActions(): array {
		return ['valid' => false, 'errors' => ["Action 0: category 99 is not available to the rule's owner"]];
	}

	/**
	 * A v1-schema rule stored its actions without validating them, so it
	 * could carry another user's category, and switching it to v2 later
	 * kept them unchecked.
	 */
	public function testCreateV1ValidatesItsActions(): void {
		$actions = ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]];
		$this->actionApplicator->method('validateActions')->with($actions, 'user1')->willReturn($this->refusedActions());
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('category 99');
		$this->service->create('user1', 'V1', 'shop', 'description', 'contains', null, 1, null, null, 0, $actions);
	}

	public function testCreateV2ValidatesTheCategoryColumn(): void {
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->method('validateActions')->with(['categoryId' => 99], 'user1')->willReturn($this->refusedActions());
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('user1', 'V2', null, null, null, ['version' => 2, 'root' => []], 2, 99);
	}

	public function testUpdateV1ValidatesItsActions(): void {
		$this->mapper->method('find')->willReturn($this->makeRule(['categoryId' => null]));
		$actions = ['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]];
		$this->actionApplicator->method('validateActions')->with($actions, 'user1')->willReturn($this->refusedActions());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'user1', ['actions' => $actions]);
	}

	public function testSwitchingToV2ChecksTheActionsItAlreadyHas(): void {
		$rule = $this->makeRule(['categoryId' => null]);
		$rule->setActionsFromArray(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]]);
		$this->mapper->method('find')->willReturn($rule);
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->method('validateActions')
			->with(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]], 'user1')
			->willReturn($this->refusedActions());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'user1', [
			'schemaVersion' => 2,
			'criteria' => ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]],
		]);
	}

	public function testUpdateV2ValidatesACategoryColumnChange(): void {
		$rule = $this->makeRule(['schemaVersion' => 2, 'categoryId' => null]);
		$rule->setCriteriaFromArray(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]]);
		$this->mapper->method('find')->willReturn($rule);
		$this->actionApplicator->method('validateActions')->with(['categoryId' => 99], 'user1')->willReturn($this->refusedActions());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'user1', ['categoryId' => 99]);
	}

	public function testAnUnrelatedEditDoesNotRecheckStoredActions(): void {
		// A rule whose category was unshared since must still be editable,
		// not least to switch it off
		$rule = $this->makeRule(['schemaVersion' => 2, 'categoryId' => null]);
		$rule->setCriteriaFromArray(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]]);
		$rule->setActionsFromArray(['version' => 2, 'actions' => [['type' => 'set_category', 'value' => 99]]]);
		$this->mapper->method('find')->willReturn($rule);
		$this->actionApplicator->expects($this->never())->method('validateActions');
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(fn ($r) => $r);

		$this->service->update(1, 'user1', ['active' => false]);
	}

	// ===== update =====

	public function testUpdateSetsTimestampAndCallsMapper(): void {
		$rule = $this->makeRule();
		$this->mapper->method('find')->willReturn($rule);
		$this->mapper->expects($this->once())->method('update')
			->willReturnCallback(function (ImportRule $r) {
				// updatedAt is always set
				$this->assertNotNull($r->getUpdatedAt());
				return $r;
			});

		$this->service->update(1, 'user1', []);
	}

	public function testUpdateV2ValidatesCriteria(): void {
		$rule = $this->makeRule(['schemaVersion' => 2]);
		$this->mapper->method('find')->willReturn($rule);

		$criteria = ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]];
		$this->criteriaEvaluator->expects($this->once())->method('validate')
			->with($criteria)->willReturn(['valid' => true]);
		$this->mapper->method('update')->willReturnCallback(fn ($r) => $r);

		$this->service->update(1, 'user1', ['criteria' => $criteria]);
	}

	public function testUpdateRejectsV1ToV2UpgradeWithoutCriteria(): void {
		// A JSON edit that sets schemaVersion=2 but omits criteria on a v1 rule
		// must error, not silently save an inert (never-matching) rule (#318)
		$rule = $this->makeRule(['schemaVersion' => 1]);
		$rule->setCriteria(null);
		$this->mapper->method('find')->willReturn($rule);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Criteria required for v2 rules');

		$this->service->update(1, 'user1', ['schemaVersion' => 2]);
	}

	public function testUpdateAllowsV2PartialUpdateKeepingExistingCriteria(): void {
		// Editing another field on an already-v2 rule (criteria omitted) is a
		// valid partial update — the stored criteria is left untouched
		$rule = $this->makeRule(['schemaVersion' => 2]);
		$rule->setCriteriaFromArray(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => []]]);
		$this->mapper->method('find')->willReturn($rule);
		$this->criteriaEvaluator->expects($this->never())->method('validate');
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(fn ($r) => $r);

		$this->service->update(1, 'user1', ['priority' => 42]);
	}

	// ===== between ranges are stored canonically =====

	private function builderBetweenCriteria(string $field, string $pattern): array {
		return ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [[
			'type' => 'condition', 'field' => $field, 'matchType' => 'between',
			'pattern' => $pattern, 'negate' => false,
		]]]];
	}

	public function testCreateStoresABuilderBetweenRangeAsAnArray(): void {
		// The visual builder posts the range as the JSON text the user typed
		$criteria = $this->builderBetweenCriteria('amount', '{"min": 0, "max": 100}');
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);

		$stored = null;
		$this->mapper->method('insert')->willReturnCallback(function (ImportRule $r) use (&$stored) {
			$stored = json_decode($r->getCriteria(), true);
			$r->setId(1);
			return $r;
		});

		$this->service->create('user1', 'Range', null, null, null, $criteria, 2);

		$this->assertSame(['min' => 0, 'max' => 100], $stored['root']['conditions'][0]['pattern']);
	}

	public function testUpdateStoresABuilderBetweenRangeAsAnArray(): void {
		$rule = $this->makeRule(['schemaVersion' => 2]);
		$this->mapper->method('find')->willReturn($rule);
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->mapper->method('update')->willReturnCallback(fn ($r) => $r);

		$updated = $this->service->update(1, 'user1', [
			'criteria' => $this->builderBetweenCriteria('date', '{"min": "2026-01-01", "max": "2026-12-31"}'),
		]);

		$stored = json_decode($updated->getCriteria(), true);
		$this->assertSame(['min' => '2026-01-01', 'max' => '2026-12-31'], $stored['root']['conditions'][0]['pattern']);
	}

	// ===== default rules =====

	/**
	 * @return \OCA\Budget\Db\Category[] the default tree's categories the rules name
	 */
	private function defaultCategories(): array {
		$tree = [
			[10, 'Food', null], [11, 'Groceries', 10], [14, 'Dining Out', 10],
			[12, 'Transportation', null], [13, 'Gas', 12],
			[15, 'Shopping', null],
			[16, 'Housing', null], [17, 'Utilities', 16],
			[18, 'Income', null],
		];
		$categories = [];
		foreach ($tree as [$id, $name, $parentId]) {
			$category = new \OCA\Budget\Db\Category();
			$category->setId($id);
			$category->setName($name);
			$category->setType($name === 'Income' ? 'income' : 'expense');
			$category->setParentId($parentId);
			$categories[] = $category;
		}
		return $categories;
	}

	/**
	 * "Create default categories" also made six rules with no category, which
	 * matched first, did nothing and stopped every rule after them (T3). Each
	 * default rule now sets the category it is named for, found in the tree
	 * the button has just made, subcategories included.
	 */
	public function testDefaultRulesSetTheCategoryTheyAreNamedFor(): void {
		$this->categoryMapper->method('findAll')->willReturn($this->defaultCategories());
		$this->mapper->method('findAll')->willReturn([]);
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->method('validateActions')->willReturn(['valid' => true, 'errors' => []]);

		$inserted = [];
		$this->mapper->method('insert')->willReturnCallback(function (ImportRule $r) use (&$inserted) {
			$r->setId(count($inserted) + 1);
			$inserted[] = $r;
			return $r;
		});

		$created = $this->service->createDefaultRules('user1');

		$categoryByRule = [];
		foreach ($created as $rule) {
			$this->assertSame(2, $rule->getSchemaVersion());
			$this->assertTrue($rule->getActive());
			$actions = $rule->getParsedActions()['actions'];
			$this->assertCount(1, $actions);
			$this->assertSame('set_category', $actions[0]['type']);
			$condition = $rule->getParsedCriteria()['root']['conditions'][0];
			$this->assertSame('regex', $condition['matchType']);
			$this->assertSame($rule->getPattern(), $condition['pattern']);
			$categoryByRule[$rule->getName()] = $actions[0]['value'];
		}

		// No "Cash" category exists, so no ATM rule that could only block
		// others. Created in the order they used to rank.
		$this->assertSame([
			'Grocery Stores' => 11,
			'Gas Stations' => 13,
			'Utilities' => 17,
			'Restaurants' => 14,
			'Online Shopping' => 15,
		], $categoryByRule);
	}

	/**
	 * At 5-10 the defaults outranked a rule made in the editor (which starts
	 * at 1, and used to start at 0), so an overlapping rule of the user's
	 * never ran. They now take the lowest priority there is.
	 */
	public function testDefaultRulesRankBelowTheUsersOwnRules(): void {
		$this->categoryMapper->method('findAll')->willReturn($this->defaultCategories());
		$this->mapper->method('findAll')->willReturn([]);
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->method('validateActions')->willReturn(['valid' => true, 'errors' => []]);
		$this->mapper->method('insert')->willReturnCallback(function (ImportRule $r) {
			$r->setId(1);
			return $r;
		});

		$created = $this->service->createDefaultRules('user1');

		$this->assertNotEmpty($created);
		$this->assertSame([0], array_values(array_unique(array_map(fn (ImportRule $r) => $r->getPriority(), $created))));
		$this->assertSame(0, ImportRuleService::DEFAULT_RULE_PRIORITY);
	}

	public function testDefaultRulesAreNotAddedTwice(): void {
		$this->categoryMapper->method('findAll')->willReturn($this->defaultCategories());
		$this->criteriaEvaluator->method('validate')->willReturn(['valid' => true]);
		$this->actionApplicator->method('validateActions')->willReturn(['valid' => true, 'errors' => []]);
		$existing = $this->makeRule([
			'name' => 'Grocery Stores',
			'pattern' => 'grocery|supermarket|safeway|kroger|trader joe|whole foods',
		]);
		$this->mapper->method('findAll')->willReturn([$existing]);

		$names = [];
		$this->mapper->method('insert')->willReturnCallback(function (ImportRule $r) use (&$names) {
			$names[] = $r->getName();
			$r->setId(99);
			return $r;
		});

		$this->service->createDefaultRules('user1');

		$this->assertNotContains('Grocery Stores', $names);
		$this->assertContains('Gas Stations', $names);
	}

	public function testNoDefaultRuleIsMadeWithoutItsCategory(): void {
		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame([], $this->service->createDefaultRules('user1'));
	}

	// ===== delete =====

	public function testDeleteFindsAndRemoves(): void {
		$rule = $this->makeRule();
		$this->mapper->method('find')->willReturn($rule);
		$this->mapper->expects($this->once())->method('delete')->with($rule);

		$this->service->delete(1, 'user1');
	}

	// ===== testRules =====

	public function testTestRulesReturnsMatchingRules(): void {
		$rule1 = $this->makeRule(['id' => 1, 'name' => 'Grocery', 'priority' => 10, 'categoryId' => 5]);
		$rule2 = $this->makeRule(['id' => 2, 'name' => 'Shopping', 'priority' => 5, 'categoryId' => 6]);

		$this->mapper->method('findActive')->willReturn([$rule1, $rule2]);

		// rule1 matches, rule2 doesn't
		$this->criteriaEvaluator->method('evaluate')
			->willReturnOnConsecutiveCalls(true, false);

		$result = $this->service->testRules('user1', ['description' => 'Grocery Store']);

		$this->assertCount(1, $result);
		$this->assertEquals(1, $result[0]['ruleId']);
		$this->assertEquals('Grocery', $result[0]['ruleName']);
	}

	public function testTestRulesReportsTheCategoryOfAV2ActionsRule(): void {
		// Rules from the current builder (schema v2) keep their category in a
		// set_category action and leave the legacy column NULL. testRules
		// must report the EFFECTIVE category, or every modern rule reads as
		// category-less — which silently broke the receipt-draft suggestion.
		$rule = $this->makeRule(['id' => 7, 'name' => 'Fuel', 'categoryId' => null, 'priority' => 55]);
		$rule->setSchemaVersion(2);
		$rule->setActions(json_encode([
			['type' => 'set_vendor', 'value' => 'Tesco'],
			['type' => 'set_category', 'value' => 423],
		]));

		$this->mapper->method('findActive')->willReturn([$rule]);
		$this->criteriaEvaluator->method('evaluate')->willReturn(true);

		$result = $this->service->testRules('user1', ['description' => 'TESCO PAY AT PUMP']);

		$this->assertSame(423, $result[0]['categoryId']);
	}

	public function testEffectiveCategoryIdPrefersTheLegacyColumn(): void {
		$legacy = $this->makeRule(['categoryId' => 5]);
		$this->assertSame(5, $this->service->effectiveCategoryId($legacy));

		$none = $this->makeRule(['categoryId' => null]);
		$none->setActions(json_encode([['type' => 'set_vendor', 'value' => 'X']]));
		$this->assertNull($this->service->effectiveCategoryId($none));
	}

	public function testTestRulesSortsByPriority(): void {
		$rule1 = $this->makeRule(['id' => 1, 'name' => 'Low', 'priority' => 5]);
		$rule2 = $this->makeRule(['id' => 2, 'name' => 'High', 'priority' => 20]);

		$this->mapper->method('findActive')->willReturn([$rule1, $rule2]);
		$this->criteriaEvaluator->method('evaluate')->willReturn(true);

		$result = $this->service->testRules('user1', ['description' => 'test']);

		$this->assertEquals('High', $result[0]['ruleName']); // Higher priority first
		$this->assertEquals('Low', $result[1]['ruleName']);
	}

	// ===== findActive =====

	public function testFindActiveDelegates(): void {
		$rules = [$this->makeRule()];
		$this->mapper->expects($this->once())->method('findActive')
			->with('user1')->willReturn($rules);

		$result = $this->service->findActive('user1');
		$this->assertSame($rules, $result);
	}

	// ===== migrateLegacyRule =====

	public function testMigrateLegacyRuleConvertsV1ToV2(): void {
		$rule = $this->makeRule([
			'schemaVersion' => 1,
			'pattern' => 'grocery',
			'field' => 'description',
			'matchType' => 'contains',
			'categoryId' => 5,
		]);

		$this->mapper->method('find')->willReturn($rule);
		$this->mapper->expects($this->once())->method('update')
			->willReturnCallback(function (ImportRule $r) {
				$this->assertEquals(2, $r->getSchemaVersion());
				$this->assertTrue($r->getStopProcessing());
				// Criteria should be set
				$criteria = $r->getParsedCriteria();
				$this->assertEquals(2, $criteria['version']);
				$this->assertEquals('AND', $criteria['root']['operator']);
				$this->assertEquals('description', $criteria['root']['conditions'][0]['field']);
				return $r;
			});

		$this->service->migrateLegacyRule(1, 'user1');
	}

	public function testMigrateLegacyRuleSkipsAlreadyMigrated(): void {
		$rule = $this->makeRule(['schemaVersion' => 2]);
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [['type' => 'condition', 'field' => 'description']],
			],
		];
		$rule->setCriteriaFromArray($criteria);

		$this->mapper->method('find')->willReturn($rule);
		// Should NOT call update since already valid v2
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->migrateLegacyRule(1, 'user1');
		$this->assertSame($rule, $result);
	}

	// ===== migrateAllLegacyRules =====

	public function testMigrateAllLegacyRulesOnlyMigratesV1(): void {
		$v1Rule = $this->makeRule(['id' => 1, 'schemaVersion' => 1]);
		$v2Rule = $this->makeRule(['id' => 2, 'schemaVersion' => 2]);

		$this->mapper->method('findAll')->willReturn([$v1Rule, $v2Rule]);
		$this->mapper->method('find')->willReturn($v1Rule);
		$this->mapper->method('update')->willReturnCallback(fn ($r) => $r);

		$result = $this->service->migrateAllLegacyRules('user1');

		// Only the v1 rule should be in the migrated list
		$this->assertEquals([1], $result);
	}

	// ===== findTransactionsForRules =====

	/**
	 * "Only uncategorised transactions" must not offer up split parents: their
	 * category is deliberately null because the categories live on the split
	 * rows, so a rule run would re-categorise the parent and double-count it
	 * against its own splits (#356). The guard is the partition complement —
	 * explicitly-unsplit rows, or rows with no parts at all — so a NULL-flag
	 * parent from before the column existed is excluded too (#360).
	 */
	public function testFindTransactionsForRulesUncategorizedOnlyExcludesSplitParents(): void {
		$nulled = [];
		$orParts = [];
		$eqCalls = [];
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('isNull')->willReturnCallback(function (string $column) use (&$nulled) {
			$nulled[] = $column;
			return $column . ' IS NULL';
		});
		$expr->method('eq')->willReturnCallback(function (string $a, $b) use (&$eqCalls) {
			$eqCalls[] = $a;
			return $a . ' = ' . (string)$b;
		});
		$composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
		$expr->method('orX')->willReturnCallback(function (...$parts) use (&$orParts, $composite) {
			$orParts[] = array_map('strval', $parts);
			return $composite;
		});

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('getTableName')->willReturnCallback(fn (string $t) => '*PREFIX*' . $t);
		$qb->method('executeQuery')->willReturn($result);
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->service->findTransactionsForRules('user1', ['uncategorizedOnly' => true]);

		$this->assertContains('t.category_id', $nulled);
		$this->assertContains('t.is_split', $eqCalls);

		$splitGuard = null;
		foreach ($orParts as $parts) {
			foreach ($parts as $part) {
				if (str_contains($part, 't.is_split')) {
					$splitGuard = $parts;
				}
			}
		}
		$this->assertNotNull($splitGuard, 'no orX() guard mentioning t.is_split was built');
		$this->assertTrue(
			(bool)array_filter($splitGuard, fn (string $p) => str_contains($p, 'NOT EXISTS') && str_contains($p, 'budget_tx_splits')),
			'the split guard must exclude rows that have parts, not rows whose flag is NULL'
		);
	}

	// ===== a run over many rows (T6-3) =====

	/**
	 * @return ImportRuleService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function serviceWithRows(array ...$batches): ImportRuleService {
		$transactionService = $this->createMock(\OCA\Budget\Service\TransactionService::class);
		$transactionService->method('findAccountById')->willReturnCallback(function (int $id) {
			$account = new \OCA\Budget\Db\Account();
			$account->setUserId('user1');
			return $account;
		});
		$service = $this->getMockBuilder(ImportRuleService::class)
			->setConstructorArgs([
				$this->mapper, $this->categoryMapper, $this->transactionMapper, $transactionService,
				$this->db, $this->criteriaEvaluator, $this->actionApplicator, $this->granularShareService,
			])
			->onlyMethods(['findTransactionsForRules', 'findActiveIncludingShared'])
			->getMock();
		$service->method('findTransactionsForRules')->willReturnOnConsecutiveCalls(...$batches);
		$service->method('findActiveIncludingShared')->willReturn([$this->makeRule(['schemaVersion' => 2])]);
		$this->criteriaEvaluator->method('evaluate')->willReturn(true);

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn('checking');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		return $service;
	}

	/** @return \OCA\Budget\Db\Transaction[] */
	private function rows(int $count): array {
		$rows = [];
		for ($id = 1; $id <= $count; $id++) {
			$tx = new \OCA\Budget\Db\Transaction();
			$tx->setId($id);
			$tx->setAccountId(3);
			$tx->setDescription('grocery run');
			$tx->setAmount(10.0);
			$tx->setType('debit');
			$tx->setDate('2026-09-01');
			$rows[] = $tx;
		}
		return $rows;
	}

	/**
	 * Every changed row was its own autocommitted UPDATE: 27,000 rows took
	 * 96 s. Rows are now saved in chunks, one database transaction each.
	 */
	public function testARunSavesItsRowsInOneTransactionPerChunk(): void {
		$service = $this->serviceWithRows($this->rows(3));
		$this->actionApplicator->method('applyRules')->willReturn(['category' => ['old' => null, 'new' => 5]]);
		$this->transactionMapper->expects($this->exactly(3))->method('update')->willReturnArgument(0);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$outcome = $service->applyRulesToTransactions('user1', [], []);

		$this->assertSame(3, $outcome['success']);
	}

	/**
	 * After a failed statement PostgreSQL refuses the rest of the transaction,
	 * and committing it then throws everything away: the chunk is rolled back
	 * and done again one row at a time from the rows as stored, so only the
	 * row that fails is lost, as it was before.
	 */
	public function testAFailedSaveRedoesTheChunkOneRowAtATime(): void {
		$service = $this->serviceWithRows($this->rows(3), $this->rows(3));
		$this->actionApplicator->method('applyRules')->willReturn(['category' => ['old' => null, 'new' => 5]]);
		$this->transactionMapper->method('update')->willReturnCallback(function (\OCA\Budget\Db\Transaction $tx) {
			if ($tx->getId() === 2) {
				throw new \OCP\DB\Exception('value too long');
			}
			return $tx;
		});
		$this->db->method('inTransaction')->willReturn(true);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$outcome = $service->applyRulesToTransactions('user1', [], []);

		$this->assertSame(2, $outcome['success']);
		$this->assertSame(1, $outcome['failed']);
		$this->assertSame([1, 3], array_column($outcome['applied'], 'transactionId'));
	}

	public function testTheListOfChangedRowsIsCapped(): void {
		$service = $this->serviceWithRows($this->rows(501), []);
		$this->actionApplicator->method('applyRules')->willReturn(['category' => ['old' => null, 'new' => 5]]);
		$this->transactionMapper->method('update')->willReturnArgument(0);

		$outcome = $service->applyRulesToTransactions('user1', [], []);

		$this->assertSame(501, $outcome['success']);
		$this->assertCount(500, $outcome['applied']);
		$this->assertTrue($outcome['appliedTruncated']);
	}

	public function testThePreviewListIsCapped(): void {
		$service = $this->serviceWithRows($this->rows(501));

		$preview = $service->previewRuleApplication('user1', [], []);

		$this->assertSame(501, $preview['matchCount']);
		$this->assertCount(500, $preview['preview']);
		$this->assertTrue($preview['previewTruncated']);
	}

	/**
	 * A bulk rule run recomputes each touched account's ledger once, after
	 * the run — not once per changed row, which re-summed the whole account
	 * for every row a rule touched. An account a row moved OUT of is touched
	 * too; rows whose changes leave balances alone touch nothing.
	 */
	public function testApplyRulesRecomputesEachTouchedAccountOnceAfterTheRun(): void {
		$transactionService = $this->createMock(\OCA\Budget\Service\TransactionService::class);
		$recomputed = [];
		$transactionService->method('recalculateAccountBalance')
			->willReturnCallback(function (int $accountId, string $userId) use (&$recomputed) {
				$recomputed[] = [$accountId, $userId];
			});
		// Balances are recomputed as each account's owner, whoever ran the rules
		$transactionService->method('findAccountById')->willReturnCallback(function (int $id) {
			$account = new \OCA\Budget\Db\Account();
			$account->setId($id);
			$account->setUserId($id === 7 ? 'owner7' : 'user1');
			return $account;
		});

		$service = $this->getMockBuilder(ImportRuleService::class)
			->setConstructorArgs([
				$this->mapper, $this->categoryMapper, $this->transactionMapper, $transactionService,
				$this->db, $this->criteriaEvaluator, $this->actionApplicator, $this->granularShareService,
			])
			->onlyMethods(['findTransactionsForRules', 'findActiveIncludingShared'])
			->getMock();

		$rows = [];
		foreach ([[1, 3], [2, 3], [3, 3], [4, 5]] as [$id, $accountId]) {
			$tx = new \OCA\Budget\Db\Transaction();
			$tx->setId($id);
			$tx->setAccountId($accountId);
			$tx->setDescription('grocery run');
			$tx->setAmount(10.0);
			$tx->setType('debit');
			$tx->setDate('2026-09-01');
			$rows[] = $tx;
		}
		$service->method('findTransactionsForRules')->willReturn($rows);
		$service->method('findActiveIncludingShared')->willReturn([$this->makeRule(['schemaVersion' => 2])]);
		$this->criteriaEvaluator->method('evaluate')->willReturn(true);
		$this->transactionMapper->method('update')->willReturnArgument(0);

		// Account-type lookups for the rule fields
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn('checking');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		foreach (['select', 'from', 'where', 'andWhere'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->actionApplicator->method('applyRules')
			->willReturnCallback(function (\OCA\Budget\Db\Transaction $tx) use (&$recomputed) {
				// Nothing may be recomputed while the rows are still being changed
				$this->assertSame([], $recomputed);
				return match ($tx->getId()) {
					1, 2 => ['type' => ['old' => 'debit', 'new' => 'credit']],
					// moved from account 7 into account 3
					3 => ['account' => ['old' => 7, 'new' => 3]],
					// a category change leaves every balance alone
					4 => ['category' => ['old' => null, 'new' => 5]],
				};
			});

		$outcome = $service->applyRulesToTransactions('user1', [], []);

		$this->assertSame(4, $outcome['success']);
		$this->assertSame([[3, 'user1'], [7, 'owner7']], $recomputed);
	}
}
