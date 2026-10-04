<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The Category Details panel of a category shared with someone (T4-2).
 *
 * Its queries ran as the category's owner over every account the owner has,
 * so sharing a category showed the other person the owner's rows, totals and
 * monthly figures from accounts that were never shared with them. Given the
 * viewer's visible accounts, only rows in those accounts count; without them
 * the owner's own accounts are read as before.
 */
class CategoryDetailScopeTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private string $recipient;
	private int $joint;
	private int $private;
	private int $recipientAccount;
	private int $groceries;
	/** @var array<string, int> */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->recipient = $this->newUserId();

		// The owner shares Joint and Groceries; Private stays theirs alone
		$this->joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$this->private = $this->makeAccount(['name' => 'Private'])->getId();
		$this->recipientAccount = $this->makeAccount(['name' => 'Their own'], $this->recipient)->getId();
		$this->groceries = $this->makeCategory(['name' => 'Groceries']);

		$this->rows['jointDirect'] = $this->makeTransaction($this->joint, ['amount' => '10.00', 'category_id' => $this->groceries]);
		$this->rows['jointSplit'] = $this->makeSplitTransaction($this->joint, [[$this->groceries, '4.00'], [null, '1.00']]);
		$this->rows['privateDirect'] = $this->makeTransaction($this->private, ['amount' => '20.00', 'category_id' => $this->groceries, 'description' => 'PRIVATE']);
		$this->rows['privateSplit'] = $this->makeSplitTransaction($this->private, [[$this->groceries, '7.00'], [null, '3.00']]);
		// The recipient's own shopping filed under the shared category
		$this->rows['recipientOwn'] = $this->makeTransaction($this->recipientAccount, ['amount' => '2.00', 'category_id' => $this->groceries]);
	}

	/** @return int[] */
	private function recipientView(): array {
		return [$this->joint, $this->recipientAccount];
	}

	public function testTheRecipientsSummaryLeavesOutTheOwnersUnsharedAccounts(): void {
		$summary = $this->mapper->getCategorySummary($this->userId, $this->groceries, null, $this->recipientView());

		$this->assertSame(3, $summary['count']);
		$this->assertEqualsWithDelta(16.0, $summary['total'], 0.001);
	}

	public function testTheOwnersSummaryStillCoversTheirOwnAccounts(): void {
		$summary = $this->mapper->getCategorySummary($this->userId, $this->groceries);

		$this->assertSame(4, $summary['count']);
		$this->assertEqualsWithDelta(41.0, $summary['total'], 0.001);
	}

	public function testTheRecipientsListShowsNoRowFromAnUnsharedAccount(): void {
		$rows = $this->mapper->findCategoryTransactionRows($this->userId, [$this->groceries], 50, $this->recipientView());
		$ids = array_column($rows, 'id');
		sort($ids);

		$expected = [$this->rows['jointDirect'], $this->rows['jointSplit'], $this->rows['recipientOwn']];
		sort($expected);
		$this->assertSame($expected, $ids);
	}

	public function testTheRecipientsMonthlyFiguresLeaveOutTheOwnersUnsharedAccounts(): void {
		$series = $this->mapper->getCategoryMonthlySpending(
			$this->userId, $this->groceries, 12, null, '2026-01-01', '2026-12-31', null, 'expense', false, $this->recipientView()
		);

		$this->assertCount(1, $series);
		$this->assertSame('2026-03', $series[0]['month']);
		$this->assertEqualsWithDelta(16.0, $series[0]['total'], 0.001);
		$this->assertSame(3, $series[0]['count']);
	}

	public function testAnAccountOutsideTheViewersAccountsReadsAsEmpty(): void {
		$series = $this->mapper->getCategoryMonthlySpending(
			$this->userId, $this->groceries, 12, null, '2026-01-01', '2026-12-31', $this->private, 'expense', false, $this->recipientView()
		);

		$this->assertSame([], $series);
	}

	public function testNoVisibleAccountsMeansNothingAtAll(): void {
		$this->assertSame(['count' => 0, 'total' => 0.0], $this->mapper->getCategorySummary($this->userId, $this->groceries, null, []));
		$this->assertSame([], $this->mapper->findCategoryTransactionRows($this->userId, [$this->groceries], 50, []));
		$this->assertSame([], $this->mapper->getCategoryMonthlySpending(
			$this->userId, $this->groceries, 12, null, '2026-01-01', '2026-12-31', null, 'expense', false, []
		));
	}
}
