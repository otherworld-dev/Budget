<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that run against the real Nextcloud database.
 *
 * Every test gets a fresh, never-before-seen user id, so nothing it reads can
 * belong to another test or to real data. Cleanup does not trust that user id
 * alone, though: several budget tables (splits, transaction tags, tag sets,
 * dismissed imports) carry no user column, and orphan rows are exactly what
 * some of these tests create on purpose. So setUp() records the highest id in
 * every budget_* table and tearDown() deletes everything above it - the whole
 * footprint of the test, whoever it ended up belonging to.
 *
 * That makes the suite unsafe to run on an instance other people are writing
 * to at the same moment. Use a throwaway server (see phpunit.xml).
 */
abstract class IntegrationTestCase extends TestCase {
	use BudgetFixtures;

	protected string $userId;

	/** @var array<string, int> table (unprefixed) => highest id before the test */
	private array $watermarks = [];

	/** @var string[]|null unprefixed budget_* table names, cached per process */
	private static ?array $budgetTables = null;

	protected function setUp(): void {
		parent::setUp();
		$this->userId = $this->newUserId();
		$this->watermarks = $this->captureWatermarks();
	}

	protected function tearDown(): void {
		// A failed test can leave a transaction open (a service that threw
		// between begin and commit); close it so the cleanup is not rolled
		// back with it.
		$db = $this->db();
		while ($db->inTransaction()) {
			$db->rollBack();
		}
		foreach ($this->watermarks as $table => $maxId) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->gt('id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
		Server::get(IConfig::class)->deleteAllUserValues($this->userId);
		parent::tearDown();
	}

	protected function newUserId(): string {
		return 'it-' . bin2hex(random_bytes(6));
	}

	/**
	 * @template T
	 * @param class-string<T> $class
	 * @return T
	 */
	protected function service(string $class) {
		return Server::get($class);
	}

	protected function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	/**
	 * Every budget_* table on this server, without the table prefix.
	 *
	 * @return string[]
	 */
	protected function budgetTables(): array {
		if (self::$budgetTables === null) {
			$prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
			$tables = [];
			foreach ($this->db()->createSchema()->getTables() as $table) {
				$name = $table->getName();
				if (str_starts_with($name, $prefix . 'budget_')) {
					$tables[] = substr($name, strlen($prefix));
				}
			}
			sort($tables);
			self::$budgetTables = $tables;
		}
		return self::$budgetTables;
	}

	/**
	 * @return array<string, int>
	 */
	private function captureWatermarks(): array {
		$marks = [];
		foreach ($this->budgetTables() as $table) {
			$qb = $this->db()->getQueryBuilder();
			$qb->select($qb->func()->max('id'))->from($table);
			$result = $qb->executeQuery();
			$marks[$table] = (int)$result->fetchOne();
			$result->closeCursor();
		}
		return $marks;
	}

	/**
	 * Insert a raw row and return its id. Values are bound with a type that
	 * matches the PHP value, so booleans reach PostgreSQL as booleans.
	 *
	 * @param array<string, mixed> $values column => value
	 */
	protected function insertRow(string $table, array $values): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, $this->paramType($value)));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/**
	 * @param array<string, mixed> $where column => value (all must match)
	 */
	protected function countRows(string $table, array $where = []): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		foreach ($where as $column => $value) {
			if ($value === null) {
				$qb->andWhere($qb->expr()->isNull($column));
			} else {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, $this->paramType($value))));
			}
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function fetchRow(string $table, int $id): ?array {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/**
	 * Rows in $table whose $column points at a transaction that no longer
	 * exists. NULL references are not orphans.
	 */
	protected function countOrphans(string $table, string $column, string $parentTable = 'budget_transactions'): int {
		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('c.id'))
			->from($table, 'c')
			->leftJoin('c', $parentTable, 'p', $qb->expr()->eq('c.' . $column, 'p.id'))
			->where($qb->expr()->isNotNull('c.' . $column))
			->andWhere($qb->expr()->isNull('p.id'));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function paramType(mixed $value): int|string {
		return match (true) {
			$value === null => IQueryBuilder::PARAM_NULL,
			is_bool($value) => IQueryBuilder::PARAM_BOOL,
			is_int($value) => IQueryBuilder::PARAM_INT,
			default => IQueryBuilder::PARAM_STR,
		};
	}
}
