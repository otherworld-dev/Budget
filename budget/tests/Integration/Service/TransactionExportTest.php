<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The CSV export reads the matching ids once and then the rows by id, instead
 * of paging the list query with OFFSET (T6-2). Against the real database it
 * must list exactly the rows, in exactly the order, that one unpaged page of
 * the transactions list does: ties on the sort column, NULLs, a transfer, a
 * split, and the row the tag filter's join repeats included. Batches of two
 * put a batch boundary everywhere.
 */
class TransactionExportTest extends IntegrationTestCase {
	/** Keys only the export adds, from the split parts */
	private const SPLIT_KEYS = ['isSplit', 'splitCategories', 'matchedSplitAmount', 'matchedSplitCategoryName'];

	public function testTheExportListsWhatTheListShowsInTheSameOrder(): void {
		$current = $this->makeAccount(['name' => 'Current'])->getId();
		$savings = $this->makeAccount(['name' => 'Savings'])->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$fuel = $this->makeCategory(['name' => 'Fuel']);

		// Six on one date: the order between them is the id tiebreak
		foreach (['12.50', '3.00', '12.50', '99.99', '0.01', '45.00'] as $i => $amount) {
			$this->makeTransaction($current, [
				'date' => '2026-03-15', 'amount' => $amount, 'description' => 'Shop ' . ($i % 3),
				'vendor' => $i % 2 === 0 ? null : 'Vendor ' . (3 - $i % 3),
				'category_id' => $i < 3 ? $food : null,
			]);
		}
		foreach (['2026-01-02', '2026-05-30', '2025-12-31'] as $i => $date) {
			$this->makeTransaction($savings, ['date' => $date, 'amount' => (string)(7 + $i), 'category_id' => $fuel]);
		}
		$out = $this->makeTransaction($current, ['date' => '2026-02-01', 'amount' => '100.00', 'description' => 'To savings']);
		$in = $this->makeTransaction($savings, ['date' => '2026-02-01', 'amount' => '100.00', 'type' => 'credit',
			'description' => 'From current', 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);
		$this->makeSplitTransaction($current, [[$food, '6.00'], [$fuel, '4.00']], ['date' => '2026-03-15', 'description' => 'Split shop']);

		$tagA = $this->makeTag(null);
		$tagB = $this->makeTag(null);
		$both = $this->makeTransaction($current, ['date' => '2026-04-01', 'description' => 'Tagged twice']);
		$this->tagTransaction($both, $tagA);
		$this->tagTransaction($both, $tagB);
		$this->tagTransaction($this->makeTransaction($current, ['date' => '2026-04-02']), $tagA);

		$filterSets = [
			'default' => [],
			'amount ascending' => ['sort' => 'amount', 'direction' => 'asc'],
			'vendor, NULLs and ties' => ['sort' => 'vendor', 'direction' => 'desc'],
			'description ascending' => ['sort' => 'description', 'direction' => 'asc'],
			'category' => ['sort' => 'category'],
			'a category with a split' => ['category' => (string)$food],
			'uncategorized' => ['category' => 'uncategorized'],
			'tags (the join repeats a row)' => ['tagIds' => [$tagA, $tagB]],
			'search' => ['search' => 'shop'],
			'one account' => ['accountId' => $current, 'sort' => 'amount'],
			'date range' => ['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31', 'sort' => 'date', 'direction' => 'asc'],
		];

		$mapper = $this->service(TransactionMapper::class);
		$service = $this->service(TransactionService::class);
		foreach ($filterSets as $name => $filters) {
			$list = $mapper->findWithFilters($this->userId, $filters, 1000, 0)['transactions'];
			$exported = array_merge([], ...iterator_to_array($service->findAllForExport($this->userId, $filters, null, 2), false));

			$this->assertNotSame([], $list, $name);
			$this->assertSame(array_column($list, 'id'), array_column($exported, 'id'), $name);
			foreach ($list as $i => $row) {
				$this->assertSame(
					array_diff_key($row, array_flip(self::SPLIT_KEYS)),
					array_diff_key($exported[$i], array_flip(self::SPLIT_KEYS)),
					"$name, row $i"
				);
			}
		}

		$tagged = array_merge([], ...iterator_to_array($service->findAllForExport($this->userId, ['tagIds' => [$tagA, $tagB]], null, 2), false));
		$this->assertSame(2, count(array_keys(array_column($tagged, 'id'), $both)), 'the tag join lists the twice-tagged row twice, as the list does');
	}

	public function testAShareRecipientExportsOnlyWhatTheyCanSee(): void {
		$alice = $this->newUserId();
		$shared = $this->makeAccount(['name' => 'Joint'], $alice)->getId();
		$private = $this->makeAccount(['name' => 'Private'], $alice)->getId();
		$seen = $this->makeTransaction($shared, ['description' => 'Joint shop']);
		$this->makeTransaction($private, ['description' => 'Private shop']);

		$rows = array_merge([], ...iterator_to_array(
			$this->service(TransactionService::class)->findAllForExport($this->userId, [], [$shared]),
			false
		));

		$this->assertSame([$seen], array_column($rows, 'id'));
	}
}
