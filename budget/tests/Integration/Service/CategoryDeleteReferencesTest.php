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
}
