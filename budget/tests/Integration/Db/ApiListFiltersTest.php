<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReconciliationQueries;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The filters the public API passes to the filter builder, as real SQL on
 * every database: reconciled is a BOOLEAN that is NULL on rows older than the
 * flag, and a bound of zero is a bound.
 */
class ApiListFiltersTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->accountId = $this->makeAccount()->getId();
	}

	/** @return int[] ids matching the filters, sorted */
	private function matching(array $filters): array {
		$page = $this->mapper->findWithFilters($this->userId, $filters, 50, 0);
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $page['transactions']);
		$this->assertSame(count($ids), (int)$page['total'], 'The total must match the rows');
		sort($ids);
		return $ids;
	}

	public function testReconciledSplitsTheListInTwoWithNothingLeftOver(): void {
		$yes = $this->makeTransaction($this->accountId, ['reconciled' => true]);
		$no = $this->makeTransaction($this->accountId, ['reconciled' => false]);
		// Older than the flag: never reconciled, and reported as false
		$never = $this->makeTransaction($this->accountId, ['reconciled' => null]);

		$this->assertSame([$yes], $this->matching(['reconciled' => true]));
		$this->assertSame([$no, $never], $this->matching(['reconciled' => false]));
	}

	public function testStatusTypeAndUncategorized(): void {
		$food = $this->makeCategory();
		$cleared = $this->makeTransaction($this->accountId, ['category_id' => $food]);
		$old = $this->makeTransaction($this->accountId, ['status' => null]);
		$scheduled = $this->makeTransaction($this->accountId, ['status' => 'scheduled', 'date' => '2099-01-01']);
		$pending = $this->makeTransaction($this->accountId, ['status' => 'pending', 'type' => 'credit']);
		$split = $this->makeSplitTransaction($this->accountId, [[$food, '1.00'], [null, '2.00']]);

		$this->assertSame([$cleared, $old, $split], $this->matching(['status' => 'cleared']));
		$this->assertSame([$scheduled], $this->matching(['status' => 'scheduled']));
		$this->assertSame([$pending], $this->matching(['status' => 'pending']));
		$this->assertSame([$pending], $this->matching(['type' => 'credit']));
		// A split has no category of its own and is not uncategorised
		$this->assertSame([$old, $scheduled, $pending], $this->matching(['category' => 'uncategorized']));
	}

	/**
	 * uncategorized=false is the rest of the list, as a client reading the
	 * whole list would pick it out: a category of its own, or split (the
	 * categories are on the parts). The two halves cover every row once,
	 * including the grey states #356/#360 left behind.
	 */
	public function testUncategorizedAndTheRestSplitTheListInTwo(): void {
		$food = $this->makeCategory();
		$this->makeTransaction($this->accountId, ['category_id' => $food]);
		$this->makeTransaction($this->accountId);
		$this->makeSplitTransaction($this->accountId, [[$food, '1.00'], [null, '2.00']]);
		// Older than the is_split flag, with parts: a split
		$legacySplit = $this->makeTransaction($this->accountId, ['is_split' => null]);
		$this->makeSplit($legacySplit, $food, '4.00');
		$this->makeSplit($legacySplit, null, '6.00');
		// Older than the flag, no parts: uncategorised
		$this->makeTransaction($this->accountId, ['is_split' => null]);
		// Unsplit, with parts left behind (#356): uncategorised
		$leftover = $this->makeTransaction($this->accountId, ['is_split' => false]);
		$this->makeSplit($leftover, $food, '10.00');
		// Flagged split with no parts left: nothing to show, uncategorised
		$this->makeTransaction($this->accountId, ['is_split' => true]);
		// A category stamped onto a split parent (#360): has a category
		$stamped = $this->makeTransaction($this->accountId, ['is_split' => null, 'category_id' => $food]);
		$this->makeSplit($stamped, $food, '10.00');

		$service = $this->service(TransactionService::class);
		$read = function (array $filters) use ($service): array {
			$page = $service->findWithFilters($this->userId, $filters, 100, 0);
			$this->assertSame(count($page['transactions']), (int)$page['total'], 'The total must match the rows');
			return $page['transactions'];
		};
		$ids = static function (array $rows): array {
			$ids = array_map(static fn (array $row): int => (int)$row['id'], $rows);
			sort($ids);
			return $ids;
		};

		$all = $read([]);
		$this->assertCount(8, $all);
		$hasCategory = static fn (array $row): bool => $row['categoryId'] !== null || $row['isSplit'] === true;

		$rest = $ids($read(['categorized' => true]));
		$uncategorised = $ids($read(['category' => 'uncategorized']));

		$this->assertSame($ids(array_filter($all, $hasCategory)), $rest);
		$this->assertSame($ids(array_filter($all, static fn (array $row): bool => !$hasCategory($row))), $uncategorised);
		$this->assertCount(4, $rest);
		$this->assertSame([], array_intersect($rest, $uncategorised));
		$both = array_merge($rest, $uncategorised);
		sort($both);
		$this->assertSame($ids($all), $both);
		// With a category as well: that category's rows, split ones included
		$this->assertSame($ids($read(['category' => (string)$food])), $ids($read(['category' => (string)$food, 'categorized' => true])));
	}

	/** As the API sends them: fixed decimals, so a bound of zero is not lost. */
	public function testAmountBoundsIncludingZero(): void {
		$free = $this->makeTransaction($this->accountId, ['amount' => '0.00']);
		$small = $this->makeTransaction($this->accountId, ['amount' => '4.99']);
		$exact = $this->makeTransaction($this->accountId, ['amount' => '5.00']);
		$large = $this->makeTransaction($this->accountId, ['amount' => '120.00']);
		// A reversed row from an old import
		$negative = $this->makeTransaction($this->accountId, ['amount' => '-3.00']);

		$this->assertSame([$exact, $large], $this->matching(['amountMin' => '5.00']));
		$this->assertSame([$free, $small, $exact, $negative], $this->matching(['amountMax' => '5.00']));
		$this->assertSame([$small, $exact], $this->matching(['amountMin' => '4.99', 'amountMax' => '5.00']));
		$this->assertSame([$free, $negative], $this->matching(['amountMax' => '0.00']));
		$this->assertSame([$free, $small, $exact, $large], $this->matching(['amountMin' => '0.00']));
	}

	/**
	 * Marking a transaction unreconciled takes it out of the statement it was
	 * reconciled against. Left tied to that statement it could never be
	 * ticked on a later one, and nothing said so.
	 */
	public function testAnUnreconciledTransactionCanBeReconciledAgainstALaterStatement(): void {
		$id = $this->makeTransaction($this->accountId, ['reconciled' => true, 'recon_session_id' => 4242]);

		$this->service(TransactionService::class)->update($id, $this->userId, ['reconciled' => false]);

		$row = $this->mapper->find($id, $this->userId);
		$this->assertFalse((bool)$row->getReconciled());
		$this->assertNull($row->getReconSessionId());
		$ticked = $this->service(TransactionReconciliationQueries::class)->tickIntoSession($this->accountId, [$id], 4243);
		$this->assertSame(1, $ticked);
	}

	public function testMarkingUnreconciledLeavesATrace(): void {
		$id = $this->makeTransaction($this->accountId, ['reconciled' => true, 'recon_session_id' => 4242]);

		$this->service(TransactionService::class)->update($id, $this->userId, ['reconciled' => false]);

		$qb = $this->db()->getQueryBuilder();
		$qb->select('action')->from('budget_audit_log')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$actions = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		$this->assertSame(['reconciled_tx_unreconciled'], $actions);
	}

	/**
	 * On a shared account the write runs as the account's owner, so the entry
	 * is in the owner's log. It must still say who did it.
	 */
	public function testTheTraceNamesWhoeverDidItWhenThatIsNotTheOwner(): void {
		$id = $this->makeTransaction($this->accountId, ['reconciled' => true]);

		$this->service(TransactionService::class)->update($id, $this->userId, ['reconciled' => false], 'someone-sharing');

		$qb = $this->db()->getQueryBuilder();
		$qb->select('user_id', 'details')->from('budget_audit_log')
			->where($qb->expr()->eq('entity_id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('reconciled_tx_unreconciled')));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		$this->assertSame($this->userId, $row['user_id']);
		$this->assertSame('someone-sharing', json_decode((string)$row['details'], true)['actedBy']);
	}

	public function testTheOwnersOwnChangeNamesNobodyElse(): void {
		$id = $this->makeTransaction($this->accountId, ['reconciled' => true, 'recon_session_id' => 4242]);

		$this->service(TransactionService::class)->update($id, $this->userId, ['reconciled' => false], $this->userId);

		$qb = $this->db()->getQueryBuilder();
		$qb->select('details')->from('budget_audit_log')
			->where($qb->expr()->eq('entity_id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$details = json_decode((string)$result->fetchOne(), true);
		$result->closeCursor();

		$this->assertArrayNotHasKey('actedBy', $details);
		$this->assertSame(4242, $details['reconSessionId']);
	}

	public function testReconcilingOrLeavingTheFlagAloneWritesNoSuchTrace(): void {
		$id = $this->makeTransaction($this->accountId, ['reconciled' => false]);

		$service = $this->service(TransactionService::class);
		$service->update($id, $this->userId, ['reconciled' => true]);
		$service->update($id, $this->userId, ['notes' => 'still reconciled']);

		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('budget_audit_log')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		$this->assertSame(0, $count);
	}
}
