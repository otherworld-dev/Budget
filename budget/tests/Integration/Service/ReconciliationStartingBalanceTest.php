<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ReconciliationService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Where a statement starts from, through the real queries (#473).
 *
 * Finish only unlocks at a zero difference, and the difference only moves
 * by ticking. When every row on the statement was already marked reconciled
 * - by the bulk Reconciled button, the API, or the old pre-session flow -
 * there is nothing left to tick, so a starting balance that is wrong left
 * the session stuck with only Leave and Cancel: the reconciled rows had to
 * land in the starting balance, and they didn't.
 */
class ReconciliationStartingBalanceTest extends IntegrationTestCase {
	private ReconciliationService $reconciliation;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->reconciliation = $this->service(ReconciliationService::class);
		$this->accountId = $this->makeAccount(['openingBalance' => 100.0, 'balance' => 100.0])->getId();
	}

	// A first statement starts from the opening balance plus what is already
	// reconciled - but only what the statement covers. A row reconciled
	// later than the statement date belongs to a later statement.
	public function testAFirstStatementLeavesOutReconciledRowsDatedAfterIt(): void {
		$this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'type' => 'credit', 'amount' => '50.00', 'reconciled' => true]);
		$this->makeTransaction($this->accountId, ['date' => '2026-04-10', 'type' => 'debit', 'amount' => '20.00', 'reconciled' => true]);

		$state = $this->reconciliation->startSession($this->accountId, $this->userId, 150.0, '2026-03-31');

		$this->assertSame(0, $state['untickedCount']);
		$this->assertEqualsWithDelta(150.0, (float)$state['session']['startingBalance'], 0.001);
		$this->assertTrue($state['isBalanced']);
	}

	// After a completed statement, rows marked reconciled outside any session
	// (the bulk Reconciled button) could not be ticked into the next one and
	// were not in its starting balance either.
	public function testRowsReconciledOutsideASessionCountTowardsTheNextStatement(): void {
		$march = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'type' => 'credit', 'amount' => '50.00']);
		$april = $this->makeTransaction($this->accountId, ['date' => '2026-04-10', 'type' => 'debit', 'amount' => '20.00']);

		$this->reconciliation->startSession($this->accountId, $this->userId, 150.0, '2026-03-31');
		$this->reconciliation->tick($this->accountId, $this->userId, [$march], true);
		$this->reconciliation->complete($this->accountId, $this->userId);

		$result = $this->service(TransactionService::class)->bulkReconcile($this->userId, [$april], true, $this->userId);
		$this->assertSame(1, $result['success']);

		$state = $this->reconciliation->startSession($this->accountId, $this->userId, 130.0, '2026-04-30');

		$this->assertSame(0, $state['untickedCount']);
		$this->assertEqualsWithDelta(130.0, (float)$state['session']['startingBalance'], 0.001);
		$this->assertTrue($state['isBalanced']);
	}

	// The rows the last statement reconciled are inside its balance already,
	// and so is anything reconciled outside a session but dated on or before
	// it: neither is counted a second time.
	public function testTheNextStatementDoesNotCountTheLastOnesRowsAgain(): void {
		$march = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'type' => 'credit', 'amount' => '50.00']);
		$this->makeTransaction($this->accountId, ['date' => '2026-03-20', 'type' => 'debit', 'amount' => '5.00', 'reconciled' => true]);
		$april = $this->makeTransaction($this->accountId, ['date' => '2026-04-10', 'type' => 'debit', 'amount' => '20.00']);

		// The first statement starts at 100 + (-5) = 95 and ticks March's 50
		$this->reconciliation->startSession($this->accountId, $this->userId, 145.0, '2026-03-31');
		$this->reconciliation->tick($this->accountId, $this->userId, [$march], true);
		$this->reconciliation->complete($this->accountId, $this->userId);

		$state = $this->reconciliation->startSession($this->accountId, $this->userId, 125.0, '2026-04-30');

		$this->assertEqualsWithDelta(145.0, (float)$state['session']['startingBalance'], 0.001);
		$this->assertSame(1, $state['untickedCount']);
		$this->assertFalse($state['isBalanced']);

		$state = $this->reconciliation->tick($this->accountId, $this->userId, [$april], true);
		$this->assertTrue($state['isBalanced']);
	}

	// Moving the statement date of a first statement moves what it starts
	// from with it.
	public function testChangingAFirstStatementsDateRecomputesItsStart(): void {
		$this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'type' => 'credit', 'amount' => '50.00', 'reconciled' => true]);
		$this->makeTransaction($this->accountId, ['date' => '2026-04-10', 'type' => 'debit', 'amount' => '20.00', 'reconciled' => true]);

		$this->reconciliation->startSession($this->accountId, $this->userId, 130.0, '2026-03-31');
		$state = $this->reconciliation->updateSession($this->accountId, $this->userId, null, '2026-04-30');

		$this->assertEqualsWithDelta(130.0, (float)$state['session']['startingBalance'], 0.001);
		$this->assertTrue($state['isBalanced']);
	}
}
