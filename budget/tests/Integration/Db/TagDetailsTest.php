<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Tags as the public API hands them out (discussion 420): each with the tag
 * set, the category and the owner it belongs to, read in one query whether it
 * is for a page of transactions or for the whole of a user's tags.
 */
class TagDetailsTest extends IntegrationTestCase {
	private TagMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TagMapper::class);
	}

	private function tag(?int $tagSetId, string $name, array $overrides = [], ?string $userId = null): int {
		return $this->insertRow('budget_tags', $overrides + [
			'tag_set_id' => $tagSetId,
			'user_id' => $tagSetId === null ? ($userId ?? $this->userId) : null,
			'name' => $name,
			'color' => '#00aa00',
			'sort_order' => 0,
			'hidden' => false,
			'created_at' => $this->now(),
		]);
	}

	private function tagSet(int $categoryId, string $name, int $sortOrder = 0): int {
		return $this->insertRow('budget_tag_sets', [
			'category_id' => $categoryId,
			'name' => $name,
			'sort_order' => $sortOrder,
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		]);
	}

	public function testACategoryTagComesWithItsTagSetCategoryAndOwner(): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$shops = $this->tagSet($food, 'Shop');
		$tesco = $this->tag($shops, 'Tesco', ['hidden' => true, 'color' => null]);

		$row = $this->mapper->findDetailedByIds([$tesco])[$tesco];

		$this->assertSame($tesco, $row['id']);
		$this->assertSame('Tesco', $row['name']);
		$this->assertNull($row['color']);
		$this->assertTrue($row['hidden']);
		$this->assertSame($shops, $row['tagSetId']);
		$this->assertSame('Shop', $row['tagSetName']);
		$this->assertSame($food, $row['categoryId']);
		// A category tag has no user of its own: it is its category's owner's
		$this->assertSame($this->userId, $row['userId']);
	}

	public function testAGlobalTagHasNoTagSetOrCategory(): void {
		$holiday = $this->tag(null, 'Holiday');

		$row = $this->mapper->findDetailedByIds([$holiday])[$holiday];

		$this->assertNull($row['tagSetId']);
		$this->assertNull($row['tagSetName']);
		$this->assertNull($row['categoryId']);
		$this->assertFalse($row['hidden']);
		$this->assertSame($this->userId, $row['userId']);
	}

	public function testNoIdsIsNoQuery(): void {
		$this->assertSame([], $this->mapper->findDetailedByIds([]));
	}

	public function testAUsersTagsAreTheirGlobalOnesThenEachTagSetInItsOrder(): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$travel = $this->makeCategory(['name' => 'Travel']);
		$second = $this->tagSet($food, 'Shop', 2);
		$first = $this->tagSet($travel, 'Country', 1);
		$tesco = $this->tag($second, 'Tesco', ['sort_order' => 1]);
		$aldi = $this->tag($second, 'Aldi', ['sort_order' => 0]);
		$italy = $this->tag($first, 'Italy');
		$work = $this->tag(null, 'Work', ['sort_order' => 1]);
		$holiday = $this->tag(null, 'Holiday', ['sort_order' => 0]);

		// Somebody else's: a global tag, and a tag on one of their categories
		$theirs = $this->newUserId();
		$this->tag(null, 'Theirs', [], $theirs);
		$this->tag($this->tagSet($this->makeCategory([], $theirs), 'Shop'), 'Their shop');

		$rows = $this->mapper->findDetailedForUser($this->userId);

		$this->assertSame([$holiday, $work, $italy, $aldi, $tesco], array_column($rows, 'id'));
		$this->assertSame([null, null, 'Country', 'Shop', 'Shop'], array_column($rows, 'tagSetName'));
	}

	public function testEachTransactionOfAPageGetsItsTagsInTheSameOrder(): void {
		$account = $this->makeAccount()->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$tesco = $this->tag($this->tagSet($food, 'Shop'), 'Tesco');
		$holiday = $this->tag(null, 'Holiday');
		$a = $this->makeTransaction($account, ['category_id' => $food]);
		$b = $this->makeTransaction($account);
		$untagged = $this->makeTransaction($account);
		$this->tagTransaction($a, $tesco);
		$this->tagTransaction($a, $holiday);
		$this->tagTransaction($b, $holiday);

		$byTransaction = $this->service(TransactionTagService::class)->tagDetailsForTransactions([$a, $b, $untagged]);

		$this->assertSame([$a, $b], array_keys($byTransaction));
		// Global first, as in the list of all tags
		$this->assertSame([$holiday, $tesco], array_column($byTransaction[$a], 'id'));
		$this->assertSame('Shop', $byTransaction[$a][1]['tagSetName']);
		$this->assertSame([$holiday], array_column($byTransaction[$b], 'id'));
	}

	public function testATagThatHasGoneLeavesNoHoleInTheList(): void {
		$account = $this->makeAccount()->getId();
		$holiday = $this->tag(null, 'Holiday');
		$a = $this->makeTransaction($account);
		$this->tagTransaction($a, $holiday);
		// A link to a tag that no longer exists (no foreign key stops it)
		$this->tagTransaction($a, $holiday + 100000);

		$byTransaction = $this->service(TransactionTagService::class)->tagDetailsForTransactions([$a]);

		$this->assertSame([$holiday], array_column($byTransaction[$a], 'id'));
	}

	/**
	 * The tag filter joins the link table. One tag is one link per
	 * transaction (the pair is unique), so the count and the pages agree.
	 */
	public function testFilteringByOneTagCountsEachTransactionOnce(): void {
		$account = $this->makeAccount()->getId();
		$holiday = $this->tag(null, 'Holiday');
		$work = $this->tag(null, 'Work');
		$expected = [];
		foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $date) {
			$id = $this->makeTransaction($account, ['date' => $date]);
			$this->tagTransaction($id, $holiday);
			$this->tagTransaction($id, $work);
			$expected[] = $id;
		}
		$other = $this->makeTransaction($account);
		$this->tagTransaction($other, $work);
		$this->makeTransaction($account);

		$transactions = $this->service(TransactionMapper::class);
		$seen = [];
		$totals = [];
		for ($offset = 0; $offset < 6; $offset += 2) {
			$page = $transactions->findWithFilters($this->userId, ['tagIds' => [$holiday]], 2, $offset);
			$totals[] = $page['total'];
			foreach ($page['transactions'] as $row) {
				$seen[] = $row['id'];
			}
		}

		$this->assertSame([3], array_values(array_unique($totals)));
		sort($seen);
		$this->assertSame($expected, $seen);
	}
}
