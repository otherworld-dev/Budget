<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A transfer recorded in one call, as the public API does it: both sides or
 * neither. Recording one side and then the other left a credit behind in the
 * other account whenever anything failed in between, and the retry credited
 * it again.
 */
class TransferCreateTest extends IntegrationTestCase {
	private TransactionService $transactions;
	private TransactionMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->transactions = $this->service(TransactionService::class);
		$this->mapper = $this->service(TransactionMapper::class);
	}

	private function balance(int $accountId): float {
		return (float)$this->service(AccountMapper::class)->findById($accountId)->getBalance();
	}

	/** @return array<int, array<string, mixed>> every row in the given accounts, oldest first */
	private function rowsIn(int ...$accountIds): array {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('*')->from('budget_transactions')
			->where($qb->expr()->in('account_id', $qb->createNamedParameter($accountIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function testBothSidesAreRecordedLinkedAndTheMoneyMoves(): void {
		$current = $this->makeAccount(['name' => 'Current'])->getId();
		$savings = $this->makeAccount(['name' => 'Savings'])->getId();

		$first = $this->transactions->createTransfer(
			$this->userId, $this->userId, $current, $savings, [$current, $savings],
			'2026-03-15', 'To savings', 200.0, 'debit', null, 'To savings', null, null
		);

		$rows = $this->rowsIn($current, $savings);
		$this->assertCount(2, $rows);
		[$out, $in] = $rows;
		$this->assertSame($first->getId(), (int)$out['id']);
		$this->assertSame($current, (int)$out['account_id']);
		$this->assertSame('debit', $out['type']);
		$this->assertSame($savings, (int)$in['account_id']);
		$this->assertSame('credit', $in['type']);
		$this->assertEqualsWithDelta(200.0, (float)$in['amount'], 0.001);
		$this->assertSame('2026-03-15', substr((string)$in['date'], 0, 10));
		$this->assertSame('To savings', $in['description']);
		// Linked both ways
		$this->assertSame((int)$in['id'], (int)$out['linked_transaction_id']);
		$this->assertSame((int)$out['id'], (int)$in['linked_transaction_id']);
		$this->assertSame((int)$in['id'], $first->getLinkedTransactionId());

		$this->assertEqualsWithDelta(-200.0, $this->balance($current), 0.001);
		$this->assertEqualsWithDelta(200.0, $this->balance($savings), 0.001);
	}

	public function testACreditPullsTheMoneyTheOtherWay(): void {
		$current = $this->makeAccount()->getId();
		$savings = $this->makeAccount()->getId();

		$this->transactions->createTransfer(
			$this->userId, $this->userId, $current, $savings, [$current, $savings],
			'2026-03-15', 'From savings', 50.0, 'credit', null, null, null, null
		);

		$this->assertEqualsWithDelta(50.0, $this->balance($current), 0.001);
		$this->assertEqualsWithDelta(-50.0, $this->balance($savings), 0.001);
	}

	public function testATransferDatedAheadIsScheduledOnBothSidesAndMovesNothingYet(): void {
		$current = $this->makeAccount()->getId();
		$savings = $this->makeAccount()->getId();

		$this->transactions->createTransfer(
			$this->userId, $this->userId, $current, $savings, [$current, $savings],
			'2099-01-01', 'Later', 10.0, 'debit', null, null, null, null
		);

		$this->assertSame(['scheduled', 'scheduled'], array_column($this->rowsIn($current, $savings), 'status'));
		$this->assertEqualsWithDelta(0.0, $this->balance($current), 0.001);
		$this->assertEqualsWithDelta(0.0, $this->balance($savings), 0.001);
	}

	/**
	 * Into an account someone shared with the caller: the other side lands in
	 * that account, which is its owner's ledger, not the caller's.
	 */
	public function testTheOtherSideOfATransferIntoASharedAccountIsItsOwners(): void {
		$owner = $this->newUserId();
		$mine = $this->makeAccount()->getId();
		$theirs = $this->makeAccount(['name' => 'Joint'], $owner)->getId();

		$first = $this->transactions->createTransfer(
			$this->userId, $this->userId, $mine, $theirs, [$mine, $theirs],
			'2026-03-15', 'Rent share', 75.0, 'debit', null, null, null, null
		);

		// The owner finds it with their own, user-scoped lookup
		$otherSide = $this->mapper->find((int)$first->getLinkedTransactionId(), $owner);
		$this->assertSame($theirs, $otherSide->getAccountId());
		$this->assertEqualsWithDelta(75.0, $this->balance($theirs), 0.001);
	}

	/**
	 * The other side is refused only after the first is written (two
	 * currencies). Nothing may be left of either, and no money may have moved.
	 */
	public function testWhenTheOtherSideCannotBeRecordedNeitherIs(): void {
		$pounds = $this->makeAccount(['currency' => 'GBP'])->getId();
		$euros = $this->makeAccount(['currency' => 'EUR'])->getId();

		try {
			$this->transactions->createTransfer(
				$this->userId, $this->userId, $pounds, $euros, [$pounds, $euros],
				'2026-03-15', 'Abroad', 20.0, 'debit', null, null, null, null
			);
			$this->fail('A transfer between two currencies was recorded');
		} catch (\Exception $e) {
			$this->assertStringContainsString('currency', $e->getMessage());
		}

		$this->assertSame([], $this->rowsIn($pounds, $euros));
		$this->assertEqualsWithDelta(0.0, $this->balance($pounds), 0.001);
		$this->assertFalse($this->db()->inTransaction(), 'The transaction must be closed again');
	}
}
