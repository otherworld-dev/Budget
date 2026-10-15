<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionReconciliationQueries;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A scheduled row - most often a bill's payment due today, before the job or
 * Mark Paid gets to it - could be ticked into a statement. It never moved the
 * difference, so the user balanced with an adjustment, and Finish marked it
 * reconciled anyway. Once the bill was paid that reconciled row counted in
 * the balance and the ledger sat one payment off the bank, with every later
 * statement still balancing.
 */
class ReconciliationTickScopeTest extends IntegrationTestCase {
	private const SESSION = 987654321;

	private TransactionReconciliationQueries $queries;
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->queries = $this->service(TransactionReconciliationQueries::class);
		$this->accountId = $this->makeAccount()->getId();
	}

	public function testAScheduledRowCannotBeTicked(): void {
		$cleared = $this->makeTransaction($this->accountId, ['date' => '2026-03-10']);
		$placeholder = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'status' => 'scheduled', 'bill_id' => 999201]);
		$legacy = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'status' => null]);

		$ticked = $this->queries->tickIntoSession($this->accountId, [$cleared, $placeholder, $legacy], self::SESSION);

		$this->assertSame(2, $ticked);
		$this->assertEqualsCanonicalizing([$cleared, $legacy], $this->queries->getSessionTransactionIds(self::SESSION));
	}

	public function testFinishingReleasesAScheduledRowTickedBeforeThatWasRefused(): void {
		$cleared = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'recon_session_id' => self::SESSION]);
		$placeholder = $this->makeTransaction($this->accountId, ['date' => '2026-03-10', 'status' => 'scheduled',
			'bill_id' => 999202, 'recon_session_id' => self::SESSION]);

		$this->assertSame(1, $this->queries->markSessionReconciled(self::SESSION));

		$this->assertTrue((bool)$this->fetchRow('budget_transactions', $cleared)['reconciled']);
		$row = $this->fetchRow('budget_transactions', $placeholder);
		$this->assertFalse((bool)$row['reconciled']);
		$this->assertNull($row['recon_session_id']);
	}
}
