<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A single transaction carries its category's name as the list shows it.
 * A row in an account shared with someone is filed under the owner's
 * category, which may not be shared with them; the row is, so its
 * category is named for them too (the form opened from a link reads it).
 */
class SharedRowCategoryNameTest extends IntegrationTestCase {
	public function testTheOwnersCategoryIsNamedForWhoeverSeesTheRow(): void {
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$secret = $this->makeCategory(['name' => 'Secret Stuff']);
		$row = $this->makeTransaction($joint, ['category_id' => $secret]);
		$bare = $this->makeTransaction($joint);

		$mapper = $this->service(TransactionMapper::class);
		$service = $this->service(TransactionService::class);
		$viewer = $this->newUserId();

		$this->assertSame('Secret Stuff', $service->categoryNameOf($mapper->findForAccounts($row, [$joint]), $viewer));
		$this->assertSame('Secret Stuff', $service->categoryNameOf($mapper->findForAccounts($row, [$joint]), $this->userId));
		$this->assertNull($service->categoryNameOf($mapper->findForAccounts($bare, [$joint]), $viewer));
	}
}
