<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A savings goal linked to a tag, fed by a tagged recurring transfer. The
 * transfer's tags go on both legs, and the goal summed credits less debits
 * over every account, so each payment added nothing: three payments of 100
 * into savings left the goal at 0.
 */
class GoalTagSumTest extends IntegrationTestCase {
	private int $current;
	private int $savings;
	private int $tag;

	protected function setUp(): void {
		parent::setUp();
		$this->current = $this->makeAccount(['name' => 'Current'])->getId();
		$this->savings = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$this->tag = $this->makeTag(null);
	}

	/** A tagged transfer: debit out of $from, credit into $to, linked and both tagged. */
	private function transfer(int $from, int $to, string $amount): void {
		$debit = $this->makeTransaction($from, ['amount' => $amount, 'type' => 'debit']);
		$credit = $this->makeTransaction($to, ['amount' => $amount, 'type' => 'credit', 'linked_transaction_id' => $debit]);
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_transactions')
			->set('linked_transaction_id', $qb->createNamedParameter($credit))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($debit)));
		$qb->executeStatement();
		$this->tagTransaction($debit, $this->tag);
		$this->tagTransaction($credit, $this->tag);
	}

	public function testATaggedTransferCountsOnceAsTheMoneyGoingIn(): void {
		$this->transfer($this->current, $this->savings, '100.00');
		$this->transfer($this->current, $this->savings, '100.00');
		$this->transfer($this->current, $this->savings, '100.00');
		// Tagged spending still comes off
		$spent = $this->makeTransaction($this->current, ['amount' => '30.00', 'type' => 'debit']);
		$this->tagTransaction($spent, $this->tag);

		$mapper = $this->service(TransactionTagMapper::class);

		$this->assertEqualsWithDelta(270.0, $mapper->sumTransactionAmountsByTag($this->tag, $this->userId), 0.001);
		$this->assertEqualsWithDelta(270.0, $mapper->sumTransactionAmountsByTags([$this->tag], $this->userId)[$this->tag], 0.001);
	}

	/** With the goal's account linked, it is what went in and out of that account. */
	public function testAGoalWithALinkedAccountCountsThatAccountsMoneyOnly(): void {
		$this->transfer($this->current, $this->savings, '100.00');
		$this->transfer($this->current, $this->savings, '100.00');
		// Taking 50 back out of savings for something else
		$this->transfer($this->savings, $this->current, '50.00');

		$mapper = $this->service(TransactionTagMapper::class);

		$this->assertEqualsWithDelta(150.0, $mapper->sumTransactionAmountsByTag($this->tag, $this->userId, $this->savings), 0.001);
	}
}
