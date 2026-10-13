<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Which tags a write may put on a transaction (discussion 420), the web UI's
 * rules in one place: a new tag must be one the caller can see and one the
 * account's owner can put on a transaction of that category; a tag already on
 * it may stay; the owner's tags the caller cannot see are never stripped.
 */
class TagSetAsTest extends IntegrationTestCase {
	private TransactionTagService $tags;
	private string $bob;

	protected function setUp(): void {
		parent::setUp();
		$this->tags = $this->service(TransactionTagService::class);
		$this->bob = $this->newUserId();
	}

	private function tag(?int $tagSetId, string $name, ?string $userId = null): int {
		return $this->insertRow('budget_tags', [
			'tag_set_id' => $tagSetId,
			'user_id' => $tagSetId === null ? ($userId ?? $this->userId) : null,
			'name' => $name,
			'color' => null,
			'sort_order' => 0,
			'hidden' => false,
			'created_at' => $this->now(),
		]);
	}

	/** Share these accounts and categories of the test user's with bob, write access. */
	private function shareWithBob(array $accountIds, array $categoryIds): void {
		$now = $this->now();
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		foreach (['account' => $accountIds, 'category' => $categoryIds] as $type => $ids) {
			foreach ($ids as $id) {
				$this->insertRow('budget_share_items', [
					'share_id' => $share, 'entity_type' => $type, 'entity_id' => $id, 'permission' => 'write',
					'created_at' => $now, 'updated_at' => $now,
				]);
			}
		}
	}

	/** @return int[] */
	private function tagsOn(int $transactionId): array {
		$ids = array_map(static fn ($tt) => (int)$tt->getTagId(), $this->service(\OCA\Budget\Db\TransactionTagMapper::class)->findByTransaction($transactionId));
		sort($ids);
		return $ids;
	}

	public function testTheOwnerPutsAGlobalTagAndACategoryTagOnTheirOwnTransaction(): void {
		$account = $this->makeAccount()->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$holiday = $this->tag(null, 'Holiday');
		$id = $this->makeTransaction($account, ['category_id' => $food]);

		$final = $this->tags->resolveTagsAs($this->userId, $this->userId, $id, $food, [$shop, $holiday]);
		$this->tags->replaceTransactionTags($id, $final);

		$this->assertSame([min($shop, $holiday), max($shop, $holiday)], $this->tagsOn($id));
	}

	public function testACategoryTagOfAnotherCategoryIsRefused(): void {
		$account = $this->makeAccount()->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$fuel = $this->makeCategory(['name' => 'Fuel']);
		$station = $this->tag($this->makeTagSet($fuel), 'Shell');
		$id = $this->makeTransaction($account, ['category_id' => $food]);

		$this->expectException(\InvalidArgumentException::class);
		$this->tags->resolveTagsAs($this->userId, $this->userId, $id, $food, [$station]);
	}

	public function testAnUncategorisedTransactionTakesGlobalTagsOnly(): void {
		$account = $this->makeAccount()->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$id = $this->makeTransaction($account);

		$this->expectException(\InvalidArgumentException::class);
		$this->tags->resolveTagsAs($this->userId, $this->userId, $id, null, [$shop]);
	}

	/** It was moved to another category since: sending it back keeps it. */
	public function testATagAlreadyOnTheTransactionMayStayWhenItNoLongerFits(): void {
		$account = $this->makeAccount()->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$fuel = $this->makeCategory(['name' => 'Fuel']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$id = $this->makeTransaction($account, ['category_id' => $fuel]);
		$this->tagTransaction($id, $shop);

		$final = $this->tags->resolveTagsAs($this->userId, $this->userId, $id, $fuel, [$shop]);
		$this->tags->replaceTransactionTags($id, $final);

		$this->assertSame([$shop], $this->tagsOn($id));
	}

	public function testAnEmptyListClearsTheTags(): void {
		$account = $this->makeAccount()->getId();
		$holiday = $this->tag(null, 'Holiday');
		$id = $this->makeTransaction($account);
		$this->tagTransaction($id, $holiday);

		$this->tags->replaceTransactionTags($id, $this->tags->resolveTagsAs($this->userId, $this->userId, $id, null, []));

		$this->assertSame([], $this->tagsOn($id));
	}

	public function testOnASharedAccountTheOwnersCategoryTagOfASharedCategoryWorks(): void {
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$this->shareWithBob([$joint], [$food]);
		$id = $this->makeTransaction($joint, ['category_id' => $food]);

		$final = $this->tags->resolveTagsAs($this->bob, $this->userId, $id, $food, [$shop]);
		$this->tags->replaceTransactionTags($id, $final);

		$this->assertSame([$shop], $this->tagsOn($id));
	}

	public function testOnASharedAccountTheCallersOwnGlobalTagIsRefused(): void {
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$this->shareWithBob([$joint], []);
		$bobsTag = $this->tag(null, 'Mine', $this->bob);
		$id = $this->makeTransaction($joint);

		$this->expectException(\InvalidArgumentException::class);
		$this->tags->resolveTagsAs($this->bob, $this->userId, $id, null, [$bobsTag]);
	}

	public function testOnASharedAccountTheOwnersGlobalTagIsRefused(): void {
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$this->shareWithBob([$joint], []);
		$ownersTag = $this->tag(null, 'Theirs');
		$id = $this->makeTransaction($joint);

		$this->expectException(\InvalidArgumentException::class);
		$this->tags->resolveTagsAs($this->bob, $this->userId, $id, null, [$ownersTag]);
	}

	/** Bob saves the tags he can see; the owner's he was never shown stay on. */
	public function testTheOwnersTagsTheCallerCannotSeeStayOn(): void {
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$private = $this->tag(null, 'Private');
		$this->shareWithBob([$joint], [$food]);
		$id = $this->makeTransaction($joint, ['category_id' => $food]);
		$this->tagTransaction($id, $private);

		$final = $this->tags->resolveTagsAs($this->bob, $this->userId, $id, $food, [$shop]);
		$this->tags->replaceTransactionTags($id, $final);

		$this->assertSame([min($shop, $private), max($shop, $private)], $this->tagsOn($id));
	}

	public function testAnUnknownTagIsRefused(): void {
		$account = $this->makeAccount()->getId();
		$id = $this->makeTransaction($account);

		$this->expectException(\InvalidArgumentException::class);
		$this->tags->resolveTagsAs($this->userId, $this->userId, $id, null, [999999999]);
	}

	public function testTheTagsOfCategoriesSharedWithTheCallerAreListedAfterTheirOwn(): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$fuel = $this->makeCategory(['name' => 'Fuel']);
		$shop = $this->tag($this->makeTagSet($food), 'Tesco');
		$this->tag($this->makeTagSet($fuel), 'Shell');
		$this->tag(null, 'Owner global');
		$this->shareWithBob([], [$food]);
		$bobsOwn = $this->tag(null, 'Bob global', $this->bob);

		$rows = $this->tags->tagDetailsForUser($this->bob);

		$this->assertSame([$bobsOwn, $shop], array_column($rows, 'id'));
		$this->assertSame($this->userId, $rows[1]['userId']);
		$this->assertSame($food, $rows[1]['categoryId']);
	}
}
