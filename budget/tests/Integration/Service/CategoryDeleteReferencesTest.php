<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Exception\CategoryInUseException;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * What deleting a category leaves pointing at it.
 */
class CategoryDeleteReferencesTest extends IntegrationTestCase {
	private CategoryService $categories;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->categories = $this->service(CategoryService::class);
		$this->accountId = $this->makeAccount()->getId();
	}

	/**
	 * The guard used the report scope, which hides scheduled rows dated after
	 * today: a category used only by a bill's pre-booked payment was deleted
	 * with no prompt, and the row - and later the payment - kept the dead id.
	 */
	public function testAFutureScheduledRowStopsAPlainDelete(): void {
		$utilities = $this->makeCategory(['name' => 'Utilities']);
		$placeholder = $this->makeTransaction($this->accountId, [
			'category_id' => $utilities, 'status' => 'scheduled', 'bill_id' => 999301,
			'date' => date('Y-m-d', strtotime('+20 days')),
		]);

		try {
			$this->categories->delete($utilities, $this->userId);
			$this->fail('The category was deleted while a scheduled row still used it');
		} catch (CategoryInUseException $e) {
			// what the Categories page answers by offering to reassign
		}

		$this->assertNotNull($this->fetchRow('budget_categories', $utilities));
		$this->categories->deleteWithReassign($utilities, $this->userId);
		$this->assertNull($this->fetchRow('budget_transactions', $placeholder)['category_id']);
	}

	/**
	 * Bills and recurring income kept a deleted category and copied it onto
	 * every row they booked afterwards; a split template naming it made every
	 * split fail and the whole payment went unsplit and uncategorised.
	 */
	public function testBillsIncomeAndSplitTemplatesLetGoOfADeletedCategory(): void {
		$home = $this->makeCategory(['name' => 'Home']);
		$repairs = $this->makeCategory(['name' => 'Repairs', 'parent_id' => $home]);
		$groceries = $this->makeCategory(['name' => 'Groceries']);
		$power = $this->insertBill(['name' => 'Power', 'category_id' => $repairs]);
		$insurance = $this->insertBill(['name' => 'Insurance', 'split_template' => json_encode([
			['categoryId' => $groceries, 'amount' => 30, 'description' => null],
			['categoryId' => $repairs, 'amount' => 20, 'description' => null],
		])]);
		$untouched = $this->insertBill(['name' => 'Food box', 'category_id' => $groceries]);
		$salary = $this->insertRow('budget_recurring_income', [
			'user_id' => $this->userId, 'name' => 'Rent in', 'amount' => '500.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'category_id' => $home, 'created_at' => $this->now(), 'is_active' => true,
		]);

		// The parent goes, and its subcategory with it
		$this->categories->delete($home, $this->userId);

		$this->assertNull($this->fetchRow('budget_bills', $power)['category_id']);
		$this->assertNull($this->fetchRow('budget_recurring_income', $salary)['category_id']);
		$this->assertSame($groceries, (int)$this->fetchRow('budget_bills', $untouched)['category_id']);
		$template = json_decode($this->fetchRow('budget_bills', $insurance)['split_template'], true);
		$this->assertSame($groceries, (int)$template[0]['categoryId']);
		$this->assertNull($template[1]['categoryId']);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function insertBill(array $overrides): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'amount' => '50.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 1,
		]);
	}
}
