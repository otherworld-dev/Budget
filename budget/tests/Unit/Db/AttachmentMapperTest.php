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
 * receiptPresence() feeds the v1 API's has_receipt (#455) and receiptNames()
 * the CSV export's Receipts column (#475). The query builder
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
		foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'groupBy', 'orderBy', 'addOrderBy'] as $method) {
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

	// ===== receiptNames() (#475) =====

	public function testNamesAreGroupedByTransactionInOneQuery(): void {
		$this->result->method('fetch')->willReturnOnConsecutiveCalls(
			['transaction_id' => '1', 'file_name' => 'invoice.pdf'],
			['transaction_id' => '1', 'file_name' => 'delivery note.jpg'],
			['transaction_id' => '4', 'file_name' => 'receipt.png'],
			['transaction_id' => '4', 'file_name' => null],
			false
		);
		$this->qb->expects($this->once())->method('executeQuery')->willReturn($this->result);

		$names = $this->mapper->receiptNames(range(1, 200), 'user1');

		// Rows keep the query's order; a row with no recorded name is skipped
		$this->assertSame([1 => ['invoice.pdf', 'delivery note.jpg'], 4 => ['receipt.png']], $names);
	}

	public function testNamesForALongListAreChunkedForSqlite(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->qb->expects($this->exactly(3))->method('executeQuery')->willReturn($this->result);

		$this->mapper->receiptNames(range(1, 1200), 'user1');

		$chunks = array_values(array_filter($this->params, 'is_array'));
		$this->assertSame([500, 500, 200], array_map('count', $chunks));
	}

	public function testNoIdsIsNoNamesQuery(): void {
		$this->qb->expects($this->never())->method('executeQuery');

		$this->assertSame([], $this->mapper->receiptNames([], 'user1'));
	}

	public function testNamesAreScopedLikeTheReceiptsList(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->mapper->receiptNames([5, 5, 6], 'user1');

		// Owner-only, as GET /transactions/{id}/receipts is: the account is
		// the caller's and so is the attachment row
		$scoped = array_values(array_filter($this->eqs, static fn (array $eq): bool => str_ends_with($eq[0], '.user_id')));
		$this->assertSame(['a.user_id', 'att.user_id'], array_column($scoped, 0));
		foreach ($scoped as [, $param]) {
			$this->assertSame('user1', $this->params[(int)substr($param, 2) - 1]);
		}
		$this->assertContains([5, 6], $this->params, 'ids are de-duplicated');
	}
}
