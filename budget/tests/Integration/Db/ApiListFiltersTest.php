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
