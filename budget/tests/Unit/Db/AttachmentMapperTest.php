<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\AttachmentMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * receiptPresence() feeds the v1 API's has_receipt (#455). The query builder
 * is mocked, so these prove the call shape (one query per page, the caller's
 * scope on both the account and the attachment) and the result mapping; the
 * SQL itself is proven on a real instance.
 */
class AttachmentMapperTest extends TestCase {
	private AttachmentMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;
	private IResult $result;

	/** @var array<int, array{0: string, 1: mixed}> every eq() the query built */
	private array $eqs = [];
	/** @var array<int, mixed> every value bound as a named parameter */
	private array $params = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$this->result = $this->createMock(IResult::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);
		$this->qb->method('func')->willReturn($func);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$this->qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->params[] = $value;
			return ':p' . count($this->params);
		});
		$this->expr->method('eq')->willReturnCallback(function ($x, $y) {
			$this->eqs[] = [$x, $y];
			return $x . ' = ' . $y;
		});
		$this->expr->method('andX')->willReturn($this->createMock(ICompositeExpression::class));
		$this->expr->method('in')->willReturn('in');
		foreach (['select', 'selectAlias', 'from', 'where', 'innerJoin', 'leftJoin', 'groupBy'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}
		$this->result->method('closeCursor')->willReturn(true);

		$this->mapper = new AttachmentMapper($this->db);
	}

	public function testAPageIsOneQueryWhateverItsSize(): void {
		$this->result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => '1', 'cnt' => '2'],
			['id' => '2', 'cnt' => '0'],
			false
		);
		$this->qb->expects($this->once())->method('executeQuery')->willReturn($this->result);

		$presence = $this->mapper->receiptPresence(range(1, 200), 'user1');

		// Owned rows answer true or false; anything else gets no key
		$this->assertSame([1 => true, 2 => false], $presence);
	}

	public function testALongListIsChunkedForSqlite(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->qb->expects($this->exactly(3))->method('executeQuery')->willReturn($this->result);

		$this->mapper->receiptPresence(range(1, 1200), 'user1');

		$chunks = array_values(array_filter($this->params, 'is_array'));
		$this->assertSame([500, 500, 200], array_map('count', $chunks));
	}

	public function testNothingAskedIsNoQuery(): void {
		$this->qb->expects($this->never())->method('executeQuery');

		$this->assertSame([], $this->mapper->receiptPresence([], 'user1'));
	}

	public function testOnlyTheCallersAccountsAndAttachmentsCount(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->mapper->receiptPresence([5, 5, 6], 'user1');

		// The same scope as GET /transactions/{id}/receipts: the account is
		// the caller's (TransactionMapper::find) and so is the attachment row
		// (findByTransaction)
		$scoped = array_values(array_filter($this->eqs, static fn (array $eq): bool => str_ends_with($eq[0], '.user_id')));
		$this->assertSame(['a.user_id', 'att.user_id'], array_column($scoped, 0));
		foreach ($scoped as [, $param]) {
			$this->assertSame('user1', $this->params[(int)substr($param, 2) - 1]);
		}
		$this->assertContains([5, 6], $this->params, 'ids are de-duplicated');
	}
}
