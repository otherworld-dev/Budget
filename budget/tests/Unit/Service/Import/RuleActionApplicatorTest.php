<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Category;
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

	private function createTransaction(array $data = []): Transaction {
		$transaction = new Transaction();

		$defaults = [
			'accountId' => 1,
			'type' => 'expense',
			'categoryId' => null,
			'vendor' => null,
			'notes' => null,
			'reference' => null,
		];

		$data = array_merge($defaults, $data);

		$transaction->setAccountId($data['accountId']);
		$transaction->setType($data['type']);
		if ($data['categoryId'] !== null) {
			$transaction->setCategoryId($data['categoryId']);
		}
		if ($data['vendor'] !== null) {
			$transaction->setVendor($data['vendor']);
		}
		if ($data['notes'] !== null) {
			$transaction->setNotes($data['notes']);
		}
		if ($data['reference'] !== null) {
			$transaction->setReference($data['reference']);
		}

		return $transaction;
	}

	private function createRule(array $actions, bool $stopProcessing = true): ImportRule {
		$rule = new ImportRule();
		$rule->setActionsFromArray($actions);
		$rule->setStopProcessing($stopProcessing);
		return $rule;
	}

	private function makeCategory(int $id): Category {
		$category = new Category();
		$category->setId($id);
		return $category;
	}

	private function makeAccount(int $id): Account {
		$account = new Account();
		$account->setId($id);
		return $account;
	}

	// ===== Single Action Tests =====

	public function testSetCategoryAlways(): void {
		$transaction = $this->createTransaction(['categoryId' => null]);

		$this->categoryMapper->expects($this->once())
			->method('find')
			->with(5, 'user123')
			->willReturn($this->makeCategory(5));

		$rule = $this->createRule([
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
		$this->assertEquals(5, $transaction->getCategoryId());
	}

	public function testSetCategoryIfEmpty(): void {
		// Should set when empty
		$transaction1 = $this->createTransaction(['categoryId' => null]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule = $this->createRule([
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
		$this->assertEquals(5, $transaction1->getCategoryId());

		// Should NOT set when already has value
		$transaction2 = $this->createTransaction(['categoryId' => 3]);

		$this->applicator->applyRules($transaction2, [$rule], 'user123');
		$this->assertEquals(3, $transaction2->getCategoryId());
	}

	public function testSetVendor(): void {
		$transaction = $this->createTransaction(['vendor' => null]);

		$rule = $this->createRule([
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
		$this->assertEquals('Amazon', $transaction->getVendor());
	}

	public function testSetNotesReplace(): void {
		$transaction = $this->createTransaction(['notes' => 'Old notes']);

		$rule = $this->createRule([
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
		$this->assertEquals('New notes', $transaction->getNotes());
	}

	public function testSetNotesAppend(): void {
		$transaction = $this->createTransaction(['notes' => 'Existing notes']);

		$rule = $this->createRule([
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

		$this->assertEquals('Existing notes | Added note', $transaction->getNotes());
	}

	public function testSetNotesAppendToEmpty(): void {
		$transaction = $this->createTransaction(['notes' => null]);

		$rule = $this->createRule([
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

		$this->assertEquals('New note', $transaction->getNotes());
	}

	public function testSetAccount(): void {
		$transaction = $this->createTransaction(['accountId' => 1]);

		$this->accountMapper->expects($this->once())
			->method('find')
			->with(5, 'user123')
			->willReturn($this->makeAccount(5));

		$rule = $this->createRule([
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
		$this->assertEquals(5, $transaction->getAccountId());
	}

	public function testSetTransactionType(): void {
		$transaction = $this->createTransaction(['type' => 'expense']);

		$rule = $this->createRule([
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
		$this->assertEquals('income', $transaction->getType());
	}

	public function testSetReference(): void {
		$transaction = $this->createTransaction(['reference' => null]);

		$rule = $this->createRule([
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
		$this->assertEquals('AUTO-12345', $transaction->getReference());
	}

	// ===== Multiple Actions Tests =====

	public function testMultipleActionsInSingleRule(): void {
		$transaction = $this->createTransaction([
			'categoryId' => null,
			'vendor' => null,
			'notes' => null
		]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule = $this->createRule([
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
		$this->assertEquals(5, $transaction->getCategoryId());
		$this->assertEquals('Amazon', $transaction->getVendor());
		$this->assertEquals('Shopping', $transaction->getNotes());
	}

	// ===== Conflict Resolution Tests =====

	public function testMultipleRulesHigherPriorityWins(): void {
		$transaction = $this->createTransaction(['categoryId' => null]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule1 = $this->createRule([
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

		$rule2 = $this->createRule([
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
		$this->assertEquals(5, $transaction->getCategoryId());
	}

	public function testStopProcessing(): void {
		$transaction = $this->createTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule1 = $this->createRule([
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

		$rule2 = $this->createRule([
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
		$this->assertEquals(5, $transaction->getCategoryId());
		$this->assertNull($transaction->getVendor());
	}

	public function testContinueProcessingWhenStopProcessingFalse(): void {
		$transaction = $this->createTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule1 = $this->createRule([
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

		$rule2 = $this->createRule([
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
		$this->assertEquals(5, $transaction->getCategoryId());
		$this->assertEquals('Test Vendor', $transaction->getVendor());
	}

	// ===== Legacy Format Tests =====

	public function testLegacyV1ActionsFormat(): void {
		$transaction = $this->createTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		$rule = $this->createRule([
			'categoryId' => 5,
			'vendor' => 'Amazon'
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayHasKey('vendor', $changes);
		$this->assertEquals(5, $transaction->getCategoryId());
		$this->assertEquals('Amazon', $transaction->getVendor());
	}

	// ===== Error Handling Tests =====

	public function testInvalidCategoryReference(): void {
		$transaction = $this->createTransaction(['categoryId' => null]);

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

		$rule = $this->createRule([
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
		$this->assertNull($transaction->getCategoryId());
	}

	public function testInvalidAccountReference(): void {
		$transaction = $this->createTransaction(['accountId' => 1]);

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

		$rule = $this->createRule([
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
		$this->assertEquals(1, $transaction->getAccountId());
	}

	public function testInvalidTransactionType(): void {
		$transaction = $this->createTransaction(['type' => 'expense']);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Invalid transaction type'),
				$this->anything()
			);

		$rule = $this->createRule([
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
		$this->assertEquals('expense', $transaction->getType());
	}

	// ===== Validation Tests =====

	public function testValidateActionsSuccess(): void {
		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));
		$this->accountMapper->method('find')->willReturn($this->makeAccount(1));

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

	public function testValidateInvalidAccountId(): void {
		$this->accountMapper->method('find')
			->willThrowException(new \Exception('Account not found'));

		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_account',
					'value' => 999,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('invalid account', strtolower($result['errors'][0]));
	}

	public function testValidateInvalidTransactionType(): void {
		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_type',
					'value' => 'bogus',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString("invalid transaction type", strtolower($result['errors'][0]));
	}

	public function testValidateTagsMustBeArray(): void {
		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'add_tags',
					'value' => 'not-an-array',
					'behavior' => 'merge',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('tags must be an array', strtolower($result['errors'][0]));
	}

	public function testValidateUnknownActionType(): void {
		$actions = [
			'version' => 2,
			'actions' => [
				[
					'type' => 'do_magic',
					'value' => 'test',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('unknown action type', strtolower($result['errors'][0]));
	}

	public function testValidateLegacyFormatAlwaysValid(): void {
		$actions = ['categoryId' => 5, 'vendor' => 'Test'];

		$result = $this->applicator->validateActions($actions, 'user123');

		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
	}

	// ===== Vendor if_empty Tests =====

	public function testSetVendorIfEmptyWhenAlreadySet(): void {
		$transaction = $this->createTransaction(['vendor' => 'Existing Vendor']);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'New Vendor',
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('vendor', $changes);
		$this->assertEquals('Existing Vendor', $transaction->getVendor());
	}

	public function testSetVendorIfEmptyWhenNull(): void {
		$transaction = $this->createTransaction(['vendor' => null]);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'New Vendor',
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('vendor', $changes);
		$this->assertEquals('New Vendor', $transaction->getVendor());
	}

	// ===== Reference if_empty Tests =====

	public function testSetReferenceIfEmptyWhenAlreadySet(): void {
		$transaction = $this->createTransaction(['reference' => 'REF-001']);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_reference',
					'value' => 'REF-NEW',
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('reference', $changes);
		$this->assertEquals('REF-001', $transaction->getReference());
	}

	// ===== Notes Append with Default Separator =====

	public function testSetNotesAppendWithDefaultSeparator(): void {
		$transaction = $this->createTransaction(['notes' => 'First']);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'Second',
					'behavior' => 'append',
					'priority' => 100
				]
			]
		]);

		$this->applicator->applyRules($transaction, [$rule], 'user123');

		// Default separator is ' '
		$this->assertEquals('First Second', $transaction->getNotes());
	}

	// ===== Legacy Format with Notes =====

	public function testLegacyV1WithNotes(): void {
		$transaction = $this->createTransaction(['notes' => null]);

		$rule = $this->createRule([
			'categoryId' => null,
			'vendor' => null,
			'notes' => 'Auto-tagged'
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('notes', $changes);
		$this->assertEquals('Auto-tagged', $transaction->getNotes());
	}

	public function testLegacyV1SkipsNullAndEmptyValues(): void {
		$transaction = $this->createTransaction();

		$rule = $this->createRule([
			'categoryId' => null,
			'vendor' => '',
			'notes' => ''
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertEmpty($changes);
	}

	// ===== Deferred Tag Actions =====

	public function testAddTagsDeferredAction(): void {
		$transaction = $this->createTransaction();

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'add_tags',
					'value' => [1, 2, 3],
					'behavior' => 'merge',
					'priority' => 100
				]
			]
		]);

		// add_tags doesn't change the transaction directly
		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('tags', $changes);
	}

	public function testApplyDeferredTagsMerge(): void {
		$transaction = $this->createTransaction();
		$transaction->setId(42);

		$this->tagService->method('getTransactionTags')
			->with(42, 'user123')
			->willReturn([['tagId' => 1], ['tagId' => 2]]);

		$this->tagService->expects($this->once())
			->method('setTransactionTags')
			->with(42, 'user123', $this->callback(function ($tagIds) {
				sort($tagIds);
				return $tagIds === [1, 2, 3, 4];
			}));

		$appliedActions = [
			'_deferred_tags' => [
				['tagIds' => [3, 4], 'behavior' => 'merge']
			]
		];

		$changes = [];
		$this->applicator->applyDeferredTagActions($transaction, $appliedActions, 'user123', $changes);

		$this->assertArrayHasKey('tags', $changes);
		$this->assertEquals([1, 2], $changes['tags']['old']);
	}

	public function testApplyDeferredTagsReplace(): void {
		$transaction = $this->createTransaction();
		$transaction->setId(42);

		$this->tagService->method('getTransactionTags')
			->with(42, 'user123')
			->willReturn([['tagId' => 1], ['tagId' => 2]]);

		$this->tagService->expects($this->once())
			->method('setTransactionTags')
			->with(42, 'user123', [5, 6]);

		$appliedActions = [
			'_deferred_tags' => [
				['tagIds' => [5, 6], 'behavior' => 'replace']
			]
		];

		$changes = [];
		$this->applicator->applyDeferredTagActions($transaction, $appliedActions, 'user123', $changes);

		$this->assertArrayHasKey('tags', $changes);
		$this->assertEquals([5, 6], $changes['tags']['new']);
	}

	public function testApplyDeferredTagsNoop(): void {
		$changes = [];
		$this->applicator->applyDeferredTagActions(
			$this->createTransaction(),
			[], // No deferred tags
			'user123',
			$changes
		);

		$this->assertArrayNotHasKey('tags', $changes);
	}

	// ===== Empty Rules =====

	public function testApplyEmptyRulesArray(): void {
		$transaction = $this->createTransaction();

		$changes = $this->applicator->applyRules($transaction, [], 'user123');

		$this->assertEmpty($changes);
	}

	// ===== Unknown Action Type =====

	public function testUnknownActionTypeLogsWarning(): void {
		$transaction = $this->createTransaction();

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Unknown action type'),
				$this->anything()
			);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'do_magic',
					'value' => 'test',
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertEmpty($changes);
	}

	// ===== Notes if_empty Behavior =====

	public function testSetNotesIfEmptyWhenAlreadySet(): void {
		$transaction = $this->createTransaction(['notes' => 'Existing']);

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'New',
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayNotHasKey('notes', $changes);
		$this->assertEquals('Existing', $transaction->getNotes());
	}

	public function testSetNotesIfEmptyWhenEmptyString(): void {
		$transaction = $this->createTransaction();
		$transaction->setNotes('');

		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_notes',
					'value' => 'Filled',
					'behavior' => 'if_empty',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		$this->assertArrayHasKey('notes', $changes);
		$this->assertEquals('Filled', $transaction->getNotes());
	}

	// ===== Action Priority Sorting =====

	public function testActionsSortedByPriority(): void {
		$transaction = $this->createTransaction([
			'categoryId' => null,
			'vendor' => null
		]);

		$this->categoryMapper->method('find')->willReturn($this->makeCategory(5));

		// Actions listed in wrong order - should be sorted by priority
		$rule = $this->createRule([
			'version' => 2,
			'actions' => [
				[
					'type' => 'set_vendor',
					'value' => 'Low Priority Vendor',
					'behavior' => 'always',
					'priority' => 10
				],
				[
					'type' => 'set_category',
					'value' => 5,
					'behavior' => 'always',
					'priority' => 100
				]
			]
		]);

		$changes = $this->applicator->applyRules($transaction, [$rule], 'user123');

		// Both should be applied regardless of order in array
		$this->assertArrayHasKey('category', $changes);
		$this->assertArrayHasKey('vendor', $changes);
	}
}
