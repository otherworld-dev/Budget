<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000126Date20261021;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 126 adds budget_transactions.import_vendor, the payee an import
 * stored (#399): nullable, as long as the payee. Then it fills it in for the
 * rows a file import put there, from their payee now ('' for none), in one statement
 * (ImportVendorBackfillTest runs that statement against a real database).
 */
class Version001000126Test extends TestCase {
	private const FILE = __DIR__ . '/../../../lib/Migration/Version001000126Date20261021.php';

	/** A table that records the columns added to it */
	private function table(array $columns): object {
		return new class($columns) {
			/** @var array<string, array{0: string, 1: array<string, mixed>}> */
			public array $added = [];

			public function __construct(
				private array $columns,
			) {
			}

			public function hasColumn(string $name): bool {
				return in_array($name, $this->columns, true) || isset($this->added[$name]);
			}

			public function addColumn(string $name, string $type, array $options = []): void {
				$this->added[$name] = [$type, $options];
			}
		};
	}

	/** @var list<array{0: string, 1: mixed}> What the backfill's statement was built from, in order */
	private array $built = [];
	private int $statements = 0;
	/** @var string[] */
	private array $info = [];

	private function migration(int $filled = 0): Version001000126Date20261021 {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('like')->willReturnCallback(fn (string $column, string $pattern) => "$column LIKE $pattern");
		$expr->method('isNull')->willReturnCallback(fn (string $column) => "$column IS NULL");
		$expr->method('isNotNull')->willReturnCallback(fn (string $column) => "$column IS NOT NULL");
		// A composite stands for its parts, written out when it is used
		$composites = [];
		$expr->method('orX')->willReturnCallback(function (...$parts) use (&$composites) {
			$composite = $this->createMock(ICompositeExpression::class);
			$composites[spl_object_id($composite)] = '(' . implode(' OR ', $parts) . ')';
			return $composite;
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => "'" . $value . "'");
		$qb->method('getColumnName')->willReturnCallback(fn (string $column) => $column);
		$qb->method('createFunction')->willReturnCallback(function (string $call) use (&$composites) {
			$function = $this->createMock(IQueryFunction::class);
			$composites[spl_object_id($function)] = $call;
			return $function;
		});
		foreach (['update', 'set', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($method, $qb, &$composites) {
				// Without the defaults the mock fills in (update()'s alias)
				$args = array_map(fn ($arg) => is_object($arg) ? $composites[spl_object_id($arg)] : $arg, array_values(array_filter($args, fn ($arg) => $arg !== null)));
				$this->built[] = [$method, count($args) === 1 ? $args[0] : $args];
				return $qb;
			});
		}
		$qb->method('executeStatement')->willReturnCallback(function () use ($filled) {
			$this->statements++;
			return $filled;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $value) => addcslashes($value, '\_%'));
		return new Version001000126Date20261021($db);
	}

	private function schema(?object $table): \Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => $name === 'budget_transactions' && $table !== null);
		$schema->method('getTable')->willReturnCallback(fn () => $table);
		return static fn () => $schema;
	}

	private function migrationOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->info[] = $message;
		});
		return $output;
	}

	private function run126(?object $table): ?ISchemaWrapper {
		return $this->migration()->changeSchema($this->migrationOutput(), $this->schema($table), []);
	}

	public function testItAddsANullableColumnAsLongAsThePayee(): void {
		$table = $this->table(['id', 'vendor', 'import_id']);

		$this->assertNotNull($this->run126($table));

		$this->assertSame(['import_vendor'], array_keys($table->added));
		[$type, $options] = $table->added['import_vendor'];
		$this->assertSame(Types::STRING, $type);
		$this->assertFalse($options['notnull']);
		$this->assertSame(255, $options['length']);
		$this->assertArrayNotHasKey('default', $options);
	}

	public function testItDoesNothingWhenTheColumnIsThere(): void {
		$table = $this->table(['id', 'vendor', 'import_vendor']);

		$this->assertNull($this->run126($table));
		$this->assertSame([], $table->added);
	}

	public function testItDoesNothingWithoutTheTable(): void {
		$this->assertNull($this->run126(null));
	}

	/** Oracle's 30 characters with the prefix, and the project's 23 */
	public function testTheColumnNameIsShortEnough(): void {
		$this->assertLessThanOrEqual(23, strlen('import_vendor'));
		$this->assertStringContainsString("'import_vendor'", (string)file_get_contents(self::FILE));
	}

	/**
	 * Filled from the payee, in one statement, only where it is still NULL,
	 * the row has a payee and its import id is one a file import gives.
	 */
	public function testTheBackfillIsOneStatementOverTheRowsAFileImportStored(): void {
		$this->migration(3)->postSchemaChange($this->migrationOutput(), $this->schema($this->table(['vendor', 'import_id', 'import_vendor'])), []);

		$this->assertSame(1, $this->statements);
		$this->assertSame([
			['update', 'budget_transactions'],
			['set', ['import_vendor', "COALESCE(vendor, '')"]],
			['where', 'import_vendor IS NULL'],
			['andWhere', "(import_id LIKE 'hash\_%' OR import_id LIKE 'ofx\_fitid\_%')"],
		], $this->built);
		$this->assertSame(['Kept the payee of 3 imported transaction(s) for recognising them on a later import'], $this->info);
	}

	public function testItSaysNothingWhenNothingWasFilled(): void {
		$this->migration(0)->postSchemaChange($this->migrationOutput(), $this->schema($this->table(['vendor', 'import_id', 'import_vendor'])), []);

		$this->assertSame(1, $this->statements);
		$this->assertSame([], $this->info);
	}

	/** @return array<string, array{0: ?list<string>}> */
	public static function schemasItCannotFill(): array {
		return [
			'no table' => [null],
			'no import_vendor' => [['vendor', 'import_id']],
			'no vendor' => [['import_id', 'import_vendor']],
			'no import_id' => [['vendor', 'import_vendor']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('schemasItCannotFill')]
	public function testTheBackfillNeedsEveryColumnItReads(?array $columns): void {
		$this->migration()->postSchemaChange($this->migrationOutput(), $this->schema($columns === null ? null : $this->table($columns)), []);

		$this->assertSame(0, $this->statements);
	}
}
