<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Import\RuleActionApplicator;
use OCA\Budget\Service\TransactionTagService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuleActionApplicatorTest extends TestCase {
	private RuleActionApplicator $applicator;
	private TransactionTagService $tagService;
	private CategoryMapper $categoryMapper;
	private AccountMapper $accountMapper;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->tagService = $this->createMock(TransactionTagService::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->applicator = new RuleActionApplicator(
			$this->tagService,
			$this->categoryMapper,
			$this->accountMapper,
			$this->logger
		);
	}

	private function createMockTransaction(array $data = []): Transaction {
		$transaction = $this->createMock(Transaction::class);

		// Default values
		$defaults = [
			'categoryId' => null,
			'vendor' => null,
			'notes' => null,
			'accountId' => 1,
			'type' => 'expense',
			'reference' => null
		];

		$data = array_merge($defaults, $data);

		// Setup getters
		$transaction->method('getCategoryId')->willReturn($data['categoryId']);
		$transaction->method('getVendor')->willReturn($data['vendor']);
		$transaction->method('getNotes')->willReturn($data['notes']);
		$transaction->method('getAccountId')->willReturn($data['accountId']);
		$transaction->method('getType')->willReturn($data['type']);
		$transaction->method('getReference')->willReturn($data['reference']);

		return $transaction;
	}

	private function createMockRule(array $actions, bool $stopProcessing = true): ImportRule {
		$rule = $this->createMock(ImportRule::class);
		$rule->method('getParsedActions')->willReturn($actions);
		$rule->method('getStopProcessing')->willReturn($stopProcessing);
		return $rule;
	}

	// ===== Single Action Tests =====

	public function testSetCategoryAlways(): void {
		$transaction = $this->createMockTransaction(['categoryId' => null]);
		$transaction->expects($this->once())
			->method('setCategoryId')
			->with(5);

		$this->categoryMapper->expects($this->once())
			->method('find')
			->with(5, 'user123')
			->willReturn(new \stdClass()); // Mock category

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('category', $changes);
	}

	public function testSetCategoryIfEmpty(): void {
		// Should set when empty
		$transaction1 = $this->createMockTransaction(['categoryId' => null]);
		$transaction1->expects($this->once())
			->method('setCategoryId')
			->with(5);

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$this->applicator->applyRules($transaction1, [$rule], 'user123');

		// Should NOT set when already has value
		$transaction2 = $this->createMockTransaction(['categoryId' => 3]);
		$transaction2->expects($this->never())
			->method('setCategoryId');

		$this->applicator->applyRules($transaction2, [$rule], 'user123');
	}

	public function testSetVendor(): void {
		$transaction = $this->createMockTransaction(['vendor' => null]);
		$transaction->expects($this->once())
			->method('setVendor')
			->with('Amazon');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'Amazon',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('vendor', $changes);
	}

	public function testSetNotesReplace(): void {
		$transaction = $this->createMockTransaction(['notes' => 'Old notes']);
		$transaction->expects($this->once())
			->method('setNotes')
			->with('New notes');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'New notes',
					'behavior' => 'replace',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('notes', $changes);
		$this->assertEquals('New notes', $changes['notes']['new']);
	}

	public function testSetNotesAppend(): void {
		$transaction = $this->createMockTransaction(['notes' => 'Existing notes']);
		$transaction->expects($this->once())
			->method('setNotes')
			->with('Existing notes | Added note');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'Added note',
					'behavior' => 'append',
					'separator' => ' | ',
					'priority' => 100
				]
			]
		]);

		$this->applicator->applyRules($transaction, [$rule], 'user123');
	}

	public function testSetNotesAppendToEmpty(): void {
		$transaction = $this->createMockTransaction(['notes' => null]);
		$transaction->expects($this->once())
			->method('setNotes')
			->with('New note');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'New note',
					'behavior' => 'append',
					'separator' => ' | ',
					'priority' => 100
				]
			]
		]);

		$this->applicator->applyRules($transaction, [$rule], 'user123');
	}

	public function testSetAccount(): void {
		$transaction = $this->createMockTransaction(['accountId' => 1]);
		$transaction->expects($this->once())
			->method('setAccountId')
			->with(5);

		$this->accountMapper->expects($this->once())
			->method('find')
			->with(5, 'user123')
			->willReturn(new \stdClass());

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_account',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('account', $changes);
	}

	public function testSetTransactionType(): void {
		$transaction = $this->createMockTransaction(['type' => 'expense']);
		$transaction->expects($this->once())
			->method('setType')
			->with('income');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_type',
					'value' => 'income',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('type', $changes);
	}

	public function testSetReference(): void {
		$transaction = $this->createMockTransaction(['reference' => null]);
		$transaction->expects($this->once())
			->method('setReference')
			->with('AUTO-12345');

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_reference',
					'value' => 'AUTO-12345',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('reference', $changes);
	}

	// ===== Multiple Actions Tests =====

	public function testMultipleActionsInSingleRule(): void {
		$transaction = $this->createMockTransaction([
			'categoryId' => null,
			'vendor' => null,
			'notes' => null
		]);

		$transaction->expects($this->once())->method('setCategoryId')->with(5);
		$transaction->expects($this->once())->method('setVendor')->with('Amazon');
		$transaction->expects($this->once())->method('setNotes')->with('Shopping');

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				],
				[
					'type' => 'set_vendor',
					'value' => 'Amazon',
					'behavior' => 'always',
					'priority' => 90
				],
				[
					'type' => 'set_notes',
					'value' => 'Shopping',
					'behavior' => 'always',
					'priority' => 80
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayHasKey('vendor', $changes);
		$this->assertArrayHasKey('notes', $changes);
	}

	// ===== Conflict Resolution Tests =====

	public function testMultipleRulesHigherPriorityWins(): void {
		$transaction = $this->createMockTransaction(['categoryId' => null]);

		// First rule (higher priority) should win
		$transaction->expects($this->once())
			->method('setCategoryId')
			->with(5);

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule1 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		], false); // Don't stop processing

		$rule2 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 10,
					'behavior' => 'always',
					'priority' => 90
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule1, $rule2], 'user123');

		$this->assertEquals(5, $changes['category']['new']);
	}

	public function testStopProcessing(): void {
		$transaction = $this->createMockTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		// Only first rule should be applied
		$transaction->expects($this->once())
			->method('setCategoryId')
			->with(5);
		$transaction->expects($this->never())
			->method('setVendor');

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule1 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		], true); // Stop processing

		$rule2 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'Test Vendor',
					'behavior' => 'always',
					'priority' => 90
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule1, $rule2], 'user123');

		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayNotHasKey('vendor', $changes);
	}

	public function testContinueProcessingWhenStopProcessingFalse(): void {
		$transaction = $this->createMockTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		// Both rules should be applied
		$transaction->expects($this->once())
			->method('setCategoryId')
			->with(5);
		$transaction->expects($this->once())
			->method('setVendor')
			->with('Test Vendor');

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule1 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		], false); // Continue processing

		$rule2 = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'Test Vendor',
					'behavior' => 'always',
					'priority' => 90
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule1, $rule2], 'user123');

		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayHasKey('vendor', $changes);
	}

	// ===== Legacy Format Tests =====

	public function testLegacyV1ActionsFormat(): void {
		$transaction = $this->createMockTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		$transaction->expects($this->once())
			->method('setCategoryId')
			->with(5);
		$transaction->expects($this->once())
			->method('setVendor')
			->with('Amazon');

		$this->categoryMapper->method('find')->willReturn(new \stdClass());

		$rule = $this->createMockRule([
			'categoryId' => 5,
			'vendor' => 'Amazon'
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayHasKey('vendor', $changes);
	}

	// ===== Error Handling Tests =====

	public function testInvalidCategoryReference(): void {
		$transaction = $this->createMockTransaction(['categoryId' => null]);
		$transaction->expects($this->never())
			->method('setCategoryId');

		$this->categoryMapper->expects($this->once())
			->method('find')
			->with(999, 'user123')
			->willThrowException(new \Exception('Category not found'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Invalid category reference'),
				$this->anything()
			);

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 999,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('category', $changes);
	}

	public function testInvalidAccountReference(): void {
		$transaction = $this->createMockTransaction(['accountId' => 1]);
		$transaction->expects($this->never())
			->method('setAccountId');

		$this->accountMapper->expects($this->once())
			->method('find')
			->with(999, 'user123')
			->willThrowException(new \Exception('Account not found'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Invalid account reference'),
				$this->anything()
			);

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_account',
					'value' => 999,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('account', $changes);
	}

	public function testInvalidTransactionType(): void {
		$transaction = $this->createMockTransaction(['type' => 'expense']);
		$transaction->expects($this->never())
			->method('setType');

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Invalid transaction type'),
				$this->anything()
			);

		$rule = $this->createMockRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_type',
					'value' => 'invalid_type',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('type', $changes);
	}

	// ===== Validation Tests =====

	public function testValidateActionsSuccess(): void {
		$this->categoryMapper->method('find')->willReturn(new \stdClass());
		$this->accountMapper->method('find')->willReturn(new \stdClass());

		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				],
				[
					'type' => 'set_vendor',
					'value' => 'Amazon',
					'behavior' => 'always',
					'priority' => 90
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
	}

	public function testValidateTooManyActions(): void {
		$actions = [
			'version' => 2,
			'actions' => array_fill(0, 25, [ // More than MAX_ACTIONS (20)
				'type' => 'set_vendor',
				'value' => 'Test',
				'behavior' => 'always',
				'priority' => 100
			])
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('Too many actions', $result['errors'][0]);
	}

	public function testValidateMissingActionType(): void {
		$actions = [
			'version' => 2,
			'actions' => [
				[
					'value' => 'Test',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('missing type', strtolower($result['errors'][0]));
	}

	public function testValidateInvalidCategoryId(): void {
		$this->categoryMapper->method('find')
			->willThrowException(new \Exception('Category not found'));

		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_category',
					'value' => 999,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('invalid category', strtolower($result['errors'][0]));
	}
}
