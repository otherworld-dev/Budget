<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Exception\CategoryInUseException;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A refused category delete changes nothing.
 *
 * The cascade deleted the subcategories first and only then checked the
 * parent's own transactions, so a parent whose only row was a scheduled
 * payment (which the Categories page's count leaves out, so it sends a
 * plain delete) answered "has transactions" with its subcategories already
 * gone: their budgets, tag sets and tags, and the bills and split parts
 * filed under them, uncategorised. Cancelling the page's offer to reassign
 * kept the parent but not the children.
 */
class CategoryDeleteRefusalTest extends IntegrationTestCase {
	private CategoryService $categories;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->categories = $this->service(CategoryService::class);
		$this->accountId = $this->makeAccount()->getId();
	}

	public function testARefusalOnTheParentLeavesTheSubtreeAsItWas(): void {
		$home = $this->makeCategory(['name' => 'Home', 'budget_amount' => '100']);
		$garden = $this->makeCategory(['name' => 'Garden', 'parent_id' => $home, 'budget_amount' => '40']);
		$other = $this->makeCategory(['name' => 'Other']);
		// The parent's only row: a payment booked ahead
		$this->makeTransaction($this->accountId, [
			'category_id' => $home, 'status' => 'scheduled',
			'date' => date('Y-m-d', strtotime('+40 days')),
		]);
		// The child is used by a split part, a tag and a bill only
		$split = $this->makeSplitTransaction($this->accountId, [[$garden, '20.00'], [$other, '10.00']]);
		$tagSet = $this->makeTagSet($garden);
		$tag = $this->makeTag($tagSet);
		$this->tagTransaction($split, $tag);
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Gardener', 'amount' => '25.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'category_id' => $garden, 'is_active' => true,
			'created_at' => $this->now(), 'due_day' => 20,
		]);

		try {
			$this->categories->delete($home, $this->userId);
			$this->fail('The parent was deleted while a scheduled row still used it');
		} catch (CategoryInUseException $e) {
			// the page offers to move the rows to Uncategorized and retry
		}

		$this->assertNotNull($this->fetchRow('budget_categories', $home));
		$this->assertSame($home, (int)($this->fetchRow('budget_categories', $garden)['parent_id'] ?? 0), 'The subcategory was deleted by a refused delete');
		$this->assertSame($garden, (int)$this->fetchRow('budget_bills', $bill)['category_id']);
		$this->assertSame(1, $this->countRows('budget_tx_splits', ['transaction_id' => $split, 'category_id' => $garden]));
		$this->assertNotNull($this->fetchRow('budget_tag_sets', $tagSet));
		$this->assertNotNull($this->fetchRow('budget_tags', $tag));
		$this->assertSame(1, $this->countRows('budget_transaction_tags', ['transaction_id' => $split, 'tag_id' => $tag]));
	}

	public function testARefusalDeeperDownLeavesTheSiblingsAsTheyWere(): void {
		$home = $this->makeCategory(['name' => 'Home']);
		$garden = $this->makeCategory(['name' => 'Garden', 'parent_id' => $home]);
		$kitchen = $this->makeCategory(['name' => 'Kitchen', 'parent_id' => $home]);
		$tools = $this->makeCategory(['name' => 'Tools', 'parent_id' => $kitchen]);
		$this->makeTransaction($this->accountId, ['category_id' => $tools]);

		try {
			$this->categories->delete($home, $this->userId);
			$this->fail('The branch was deleted while a grandchild still had a transaction');
		} catch (CategoryInUseException $e) {
		}

		foreach ([$home, $garden, $kitchen, $tools] as $id) {
			$this->assertNotNull($this->fetchRow('budget_categories', $id));
		}

		// Moving the rows out first still deletes the whole branch
		$this->categories->deleteWithReassign($home, $this->userId);
		foreach ([$home, $garden, $kitchen, $tools] as $id) {
			$this->assertNull($this->fetchRow('budget_categories', $id));
		}
	}
}
