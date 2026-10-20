<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\UndoSnapshotSkips;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The Bills page keeps an inactive bill only while it can be reverted
 * (#365), and a snapshot a skip set aside can't be (#399 review). A bill
 * paused after a skip kept one, and the page listed it as Paid with no
 * action on it.
 */
class BillMapperRevertibleSkipTest extends TestCase {
	/** @param list<array<string, mixed>> $rows */
	private function mapper(array $rows): BillMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return new BillMapper($db);
	}

	private static function row(int $id, bool $active, ?string $snapshot): array {
		return ['id' => $id, 'user_id' => 'terje', 'name' => "Bill {$id}", 'amount' => 10.0, 'frequency' => 'monthly',
			'is_active' => $active ? 1 : 0, 'paid_undo_state' => $snapshot];
	}

	public function testAPausedBillASkipSetAsideIsNotListedAsRevertible(): void {
		$payment = (string)json_encode(['previousState' => ['nextDueDate' => '2026-09-24'], 'createdTransactionIds' => [5], 'paidDate' => '2026-09-24']);
		$mapper = $this->mapper([
			self::row(1, true, UndoSnapshotSkips::skip($payment, '2026-10-24')),
			self::row(2, false, $payment),
			self::row(3, false, UndoSnapshotSkips::skip($payment, '2026-10-24')),
			self::row(4, false, UndoSnapshotSkips::skip(null, '2026-10-24')),
		]);

		$ids = array_map(fn ($bill) => $bill->getId(), $mapper->findByType('terje', false, null, true));

		$this->assertSame([1, 2], $ids);
	}

	public function testAnExplicitFilterIsLeftAsItWas(): void {
		$payment = (string)json_encode(['previousState' => [], 'paidDate' => '2026-09-24']);
		$mapper = $this->mapper([self::row(3, false, UndoSnapshotSkips::skip($payment, '2026-10-24'))]);

		$this->assertCount(1, $mapper->findByType('terje', false, false, true));
	}
}
