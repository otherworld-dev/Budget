<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * QueryFilterBuilder through findWithFilters(), as real SQL.
 *
 * The category filter reaches split parts through a correlated EXISTS (#359).
 * A join would repeat a transaction once per matching part, and because the
 * same predicate drives both COUNT(t.id) and the paged row query, the total
 * would stop matching the rows the pages actually return.
 */
class TransactionFilterTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private int $accountId;
	private int $food;
	private int $fuel;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->accountId = $this->makeAccount()->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->fuel = $this->makeCategory(['name' => 'Fuel']);
	}

	public function testCategoryFilterCountMatchesTheRowsAcrossEveryPage(): void {
		$expected = [
			$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'date' => '2026-03-01']),
			$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'date' => '2026-03-02']),
			$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'date' => '2026-03-03']),
			// Two parts in the filtered category: still exactly one row
			$this->makeSplitTransaction($this->accountId, [[$this->food, '2.00'], [$this->food, '3.00']], ['date' => '2026-03-04']),
			$this->makeSplitTransaction($this->accountId, [[$this->food, '4.00'], [$this->fuel, '6.00']], ['date' => '2026-03-05']),
		];
		$this->makeTransaction($this->accountId, ['category_id' => $this->fuel, 'date' => '2026-03-06']);
		$this->makeSplitTransaction($this->accountId, [[$this->fuel, '1.00'], [$this->fuel, '1.00']], ['date' => '2026-03-07']);

		$filters = ['category' => (string)$this->food];
		$seen = [];
		$totals = [];
		for ($offset = 0; $offset < 10; $offset += 2) {
			$page = $this->mapper->findWithFilters($this->userId, $filters, 2, $offset);
			$totals[] = $page['total'];
			foreach ($page['transactions'] as $row) {
				$seen[] = $row['id'];
			}
		}

		$this->assertSame([5], array_values(array_unique($totals)), 'The total must be the same on every page');
		$this->assertCount(5, $seen, 'Pages must return exactly the counted rows');
		$this->assertSame(count($seen), count(array_unique($seen)), 'No transaction may appear twice');
		sort($expected);
		sort($seen);
		$this->assertSame($expected, $seen);
	}

	public function testCategoryListFilterFindsSplitsWithPartsInParentAndChild(): void {
		$child = $this->makeCategory(['name' => 'Takeaway', 'parent_id' => $this->food]);
		$split = $this->makeSplitTransaction($this->accountId, [[$this->food, '2.00'], [$child, '3.00']]);
		$direct = $this->makeTransaction($this->accountId, ['category_id' => $child]);

		$page = $this->mapper->findWithFilters($this->userId, ['category' => $this->food . ',' . $child], 50, 0);

		$this->assertSame(2, $page['total']);
		$ids = array_column($page['transactions'], 'id');
		sort($ids);
		$expected = [$split, $direct];
		sort($expected);
		$this->assertSame($expected, $ids);
	}

	public function testCategoryFilterIgnoresStrayPartsUnderAnExplicitlyUnsplitRow(): void {
		$unsplit = $this->makeTransaction($this->accountId, ['category_id' => $this->fuel, 'is_split' => false]);
		$this->makeSplit($unsplit, $this->food, '3.00');

		$page = $this->mapper->findWithFilters($this->userId, ['category' => (string)$this->food], 50, 0);

		$this->assertSame(0, $page['total']);
		$this->assertSame([], $page['transactions']);
	}

	public function testCategoryFilterKeepsNullFlagSplitParents(): void {
		$legacy = $this->makeSplitTransaction($this->accountId, [[$this->food, '3.00']], ['is_split' => null]);

		$page = $this->mapper->findWithFilters($this->userId, ['category' => (string)$this->food], 50, 0);

		$this->assertSame(1, $page['total']);
		$this->assertSame($legacy, $page['transactions'][0]['id']);
	}

	public function testUncategorisedFilterDoesNotListSplitParents(): void {
		$plain = $this->makeTransaction($this->accountId, ['category_id' => null]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '3.00']]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '3.00']], ['is_split' => null]);

		$page = $this->mapper->findWithFilters($this->userId, ['category' => 'uncategorized'], 50, 0);

		$this->assertSame(1, $page['total']);
		$this->assertSame($plain, $page['transactions'][0]['id']);
	}

	/**
	 * Also a portability check: QueryFilterBuilder (and TransactionMapper::
	 * search()) call $qb->escapeLikeParameter(), which the query builder only
	 * has from Nextcloud 31. On 30, the oldest version info.xml allows, every
	 * transaction search throws "Call to undefined method" - so this fails on
	 * the stable30 CI legs until the call moves to IDBConnection::
	 * escapeLikeParameter(), which every supported version has.
	 */
	#[Group('known-bug')]
	public function testSearchFilterMatchesDescriptionRegardlessOfCase(): void {
		$match = $this->makeTransaction($this->accountId, ['description' => 'TESCO Metro 50%']);
		$this->makeTransaction($this->accountId, ['description' => 'Shell garage']);

		$page = $this->mapper->findWithFilters($this->userId, ['search' => 'tesco metro 50%'], 50, 0);

		$this->assertSame(1, $page['total']);
		$this->assertSame($match, $page['transactions'][0]['id']);
	}

	public function testFiltersNeverReachAnotherUsersTransactions(): void {
		$otherAccount = $this->makeAccount([], $this->newUserId())->getId();
		$this->makeTransaction($otherAccount, ['category_id' => $this->food]);
		$mine = $this->makeTransaction($this->accountId, ['category_id' => $this->food]);

		$page = $this->mapper->findWithFilters($this->userId, ['category' => (string)$this->food], 50, 0);

		$this->assertSame(1, $page['total']);
		$this->assertSame($mine, $page['transactions'][0]['id']);
	}
}
