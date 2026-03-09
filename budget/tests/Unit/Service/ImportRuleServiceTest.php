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

    protected function setUp(): void {
        $this->mapper = $this->createMock(ImportRuleMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->criteriaEvaluator = $this->createMock(CriteriaEvaluator::class);
        $this->actionApplicator = $this->createMock(RuleActionApplicator::class);

        $this->service = new ImportRuleService(
            $this->mapper,
            $this->categoryMapper,
            $this->transactionMapper,
            $this->db,
            $this->criteriaEvaluator,
            $this->actionApplicator
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
        $this->mapper->method('update')->willReturnCallback(fn($r) => $r);

        $this->service->update(1, 'user1', ['criteria' => $criteria]);
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
        $this->mapper->method('update')->willReturnCallback(fn($r) => $r);

        $result = $this->service->migrateAllLegacyRules('user1');

        // Only the v1 rule should be in the migrated list
        $this->assertEquals([1], $result);
    }
}
