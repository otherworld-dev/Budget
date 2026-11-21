<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The dashboard's Uncategorized tile against the transactions list filtered
 * to Uncategorized, as real SQL (#493).
 *
 * The tile counted the rows GET /transactions/uncategorized returned, which
 * stop at a limit of 100 and cover only the user's own accounts, so a ledger
 * of thousands of transactions showed 100 (or fewer) while the list said
 * otherwise. The tile's figure now comes from countUncategorized(), which must
 * be exactly the list's total for the same accounts.
 */
class UncategorizedCountTest extends IntegrationTestCase {
	private TransactionService $transactions;
	private TransactionMapper $mapper;
	private int $accountId;
	private int $food;

	protected function setUp(): void {
		parent::setUp();
		$this->transactions = $this->service(TransactionService::class);
		$this->mapper = $this->service(TransactionMapper::class);
		$this->accountId = $this->makeAccount()->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
	}

	/** What the transactions list reports as its total, filtered to Uncategorized */
	private function listTotal(?array $visibleAccountIds = null): int {
		return $this->mapper->findWithFilters($this->userId, ['category' => 'uncategorized'], 25, 0, $visibleAccountIds)['total'];
	}

	public function testTheCountIsNotCappedAtOneHundred(): void {
		for ($i = 0; $i < 105; $i++) {
			$this->makeTransaction($this->accountId, ['category_id' => null]);
		}
		$this->makeTransaction($this->accountId, ['category_id' => $this->food]);

		// The row list the tile used to count stops at its limit
		$this->assertCount(100, $this->transactions->findUncategorized($this->userId));
		$this->assertSame(105, $this->transactions->countUncategorized($this->userId, [$this->accountId]));
		$this->assertSame(105, $this->listTotal([$this->accountId]));
	}

	public function testTheCountAgreesWithTheListFilterOnSplitParents(): void {
		$this->makeTransaction($this->accountId, ['category_id' => null]);
		// Split parents whose parts carry the categories: not uncategorised
		$this->makeSplitTransaction($this->accountId, [[$this->food, '2.00'], [$this->food, '3.00']]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '4.00']], ['is_split' => null]);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food]);

		$visible = [$this->accountId];

		$this->assertSame(1, $this->listTotal($visible));
		$this->assertSame(1, $this->transactions->countUncategorized($this->userId, $visible));
	}

	public function testTheCountCoversTheSameAccountsAndRowsAsTheList(): void {
		// Counted, as the list shows them: a plain row, a transfer leg, a
		// scheduled row, a row in an account excluded from reports (#286)
		// and a row in an account shared with the user
		$savings = $this->makeAccount(['name' => 'Savings'])->getId();
		$hidden = $this->makeAccount(['name' => 'Reimbursements', 'excludedFromReports' => true])->getId();
		$owner = $this->newUserId();
		$joint = $this->makeAccount(['name' => 'Joint'], $owner)->getId();

		$this->makeTransaction($this->accountId, ['category_id' => null]);
		$out = $this->makeTransaction($this->accountId, ['category_id' => null, 'type' => 'debit']);
		$in = $this->makeTransaction($savings, ['category_id' => null, 'type' => 'credit', 'linked_transaction_id' => $out]);
		$link = $this->db()->getQueryBuilder();
		$link->update('budget_transactions')
			->set('linked_transaction_id', $link->createNamedParameter($in, IQueryBuilder::PARAM_INT))
			->where($link->expr()->eq('id', $link->createNamedParameter($out, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->makeTransaction($this->accountId, ['category_id' => null, 'status' => 'scheduled', 'date' => '2099-01-01']);
		$this->makeTransaction($hidden, ['category_id' => null]);
		$this->makeTransaction($joint, ['category_id' => null]);

		// Not counted: categorised, a split parent, another user's account
		// the user cannot see
		$this->makeTransaction($this->accountId, ['category_id' => $this->food]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '5.00']]);
		$stranger = $this->makeAccount([], $this->newUserId())->getId();
		$this->makeTransaction($stranger, ['category_id' => null]);

		$visible = [$this->accountId, $savings, $hidden, $joint];

		$this->assertSame(6, $this->listTotal($visible));
		$this->assertSame(6, $this->transactions->countUncategorized($this->userId, $visible));

		// Own accounts only (the list's excludeShared), still in step
		$own = [$this->accountId, $savings, $hidden];
		$this->assertSame(5, $this->listTotal($own));
		$this->assertSame(5, $this->transactions->countUncategorized($this->userId, $own));
	}
}
