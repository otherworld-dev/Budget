<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\LegacyIncomeRows;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * 2.54.0's Mark Received worked the next date out from the day the money
 * came in, without moving past the occurrence it was for, and dated the
 * credit on that occurrence. Received a day early, an income stayed due on
 * the date its credit was already booked for, and the next Mark Received
 * booked it again (#399). Such an income is moved on to its next date once.
 *
 * The query only narrows the candidates; every condition is checked again on
 * the rows it returns, which is what these tests feed in.
 */
class LegacyIncomeRowsTest extends TestCase {
	/** @var list<array<string, mixed>> rows the next query returns */
	private array $rows = [];

	/** @var array<string, mixed> column => value the last update set */
	private array $updated = [];

	/** @var list<mixed> every value bound as a parameter */
	private array $bound = [];

	private int $changedRows = 1;

	private function legacyIncomeRows(): LegacyIncomeRows {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		return new LegacyIncomeRows($db, new FrequencyCalculator());
	}

	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->bound[] = $value;
			return ':p' . count($this->bound);
		});
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'update'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb) {
			$this->updated[$column] = $this->bound[(int)substr((string)$value, 2) - 1] ?? $value;
			return $qb;
		});
		$result = $this->createMock(IResult::class);
		$rows = $this->rows;
		$result->method('fetch')->willReturnCallback(function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(fn () => $this->changedRows);
		return $qb;
	}

	/**
	 * The reporter's SBB Abo income: due on the 29th, the money came on the
	 * 28th, 2.54.0 booked it dated the 29th and left the income due then.
	 */
	private function sbbAbo(array $overrides = []): array {
		return $overrides + [
			'id' => 9,
			'user_id' => 'terje',
			'name' => 'Gutschrift SBB Abo Leon',
			'frequency' => 'monthly',
			'expected_day' => 29,
			'expected_month' => null,
			'start_date' => null,
			'account_id' => 1,
			'last_received_date' => '2026-09-28',
			'next_expected_date' => '2026-09-29',
			'transaction_id' => 1293,
			'transaction_amount' => '100.00',
			'transaction_date' => '2026-09-29',
			'transaction_notes' => 'Auto-generated from income: Gutschrift SBB Abo Leon',
			'transaction_created_at' => '2026-09-28 18:43:15',
		];
	}

	public function testAnEarlyReceiptLeftOnItsOwnDateIsFoundAndSettlesToTheNextOccurrence(): void {
		$this->rows = [$this->sbbAbo()];

		$stuck = $this->legacyIncomeRows()->stuckEarlyReceipts();

		$this->assertSame([[
			'incomeId' => 9,
			'userId' => 'terje',
			'name' => 'Gutschrift SBB Abo Leon',
			'accountId' => 1,
			'transactionId' => 1293,
			'amount' => 100.0,
			'lastReceivedDate' => '2026-09-28',
			'nextExpectedDate' => '2026-09-29',
			'settledNextExpectedDate' => '2026-10-29',
		]], $stuck);
	}

	public function testSettlingMovesOnlyTheNextDateAndOnlyFromTheDateItWasFoundOn(): void {
		$this->rows = [$this->sbbAbo()];
		$legacy = $this->legacyIncomeRows();
		$stuck = $legacy->stuckEarlyReceipts('terje')[0];
		$this->bound = [];

		$this->assertTrue($legacy->settle($stuck));

		// Nothing else about the income changes
		$this->assertSame(['next_expected_date' => '2026-10-29'], $this->updated);
		// Held to that income, its owner and the date it was found on, so a
		// second run, or a receipt in between, changes nothing
		$this->assertContains(9, $this->bound);
		$this->assertContains('terje', $this->bound);
		$this->assertContains('2026-09-29', $this->bound);
	}

	public function testSettlingAnIncomeThatHasMovedOnSinceReportsNothingChanged(): void {
		$this->rows = [$this->sbbAbo()];
		$legacy = $this->legacyIncomeRows();
		$stuck = $legacy->stuckEarlyReceipts()[0];
		$this->changedRows = 0;

		$this->assertFalse($legacy->settle($stuck));
	}

	public function testACreditBookedOnOrAfterItsDateIsAnOrdinaryReceipt(): void {
		// Received on the day, or auto-created once the date came: 2.54.0
		// moved those on itself
		$this->rows = [
			$this->sbbAbo(['transaction_created_at' => '2026-09-29 07:30:00']),
			$this->sbbAbo(['id' => 10, 'transaction_id' => 1400, 'transaction_created_at' => '2026-10-02 12:00:00']),
		];

		$this->assertSame([], $this->legacyIncomeRows()->stuckEarlyReceipts());
	}

	public function testAnIncomeThatMovedOnPastTheCreditIsLeftAlone(): void {
		// Skipped or received since: the credit is for an occurrence that is
		// no longer the one due
		$this->rows = [$this->sbbAbo(['next_expected_date' => '2026-10-29'])];

		$this->assertSame([], $this->legacyIncomeRows()->stuckEarlyReceipts());
	}

	public function testAReceiptOutsideTheIncomesWindowIsNotTakenForThisOccurrence(): void {
		$this->rows = [
			// A month before: the previous occurrence's
			$this->sbbAbo(['last_received_date' => '2026-08-29']),
			// After the date the credit is for
			$this->sbbAbo(['id' => 11, 'last_received_date' => '2026-09-30']),
			// Weekly: three days is all a receipt may be early
			$this->sbbAbo(['id' => 12, 'frequency' => 'weekly', 'expected_day' => 2, 'last_received_date' => '2026-09-25']),
		];

		$this->assertSame([], $this->legacyIncomeRows()->stuckEarlyReceipts());
	}

	public function testACreditAnotherIncomeBookedDoesNotCount(): void {
		$this->rows = [$this->sbbAbo(['transaction_notes' => 'Auto-generated from income: Gutschrift Swisscom Abo Leon'])];

		$this->assertSame([], $this->legacyIncomeRows()->stuckEarlyReceipts());
	}

	public function testAWeeklyIncomeReceivedADayEarlyMovesOnAWeek(): void {
		$this->rows = [$this->sbbAbo([
			'frequency' => 'weekly', 'expected_day' => 2, 'start_date' => '2026-09-01',
			'last_received_date' => '2026-09-28', 'next_expected_date' => '2026-09-29',
		])];

		$stuck = $this->legacyIncomeRows()->stuckEarlyReceipts();

		$this->assertCount(1, $stuck);
		$this->assertSame('2026-10-06', $stuck[0]['settledNextExpectedDate']);
	}

	public function testAnIncomeIsListedOnceHoweverManyCreditsMatch(): void {
		$this->rows = [$this->sbbAbo(), $this->sbbAbo(['transaction_id' => 1294])];

		$stuck = $this->legacyIncomeRows()->stuckEarlyReceipts();

		$this->assertCount(1, $stuck);
		$this->assertSame(1293, $stuck[0]['transactionId']);
	}
}
