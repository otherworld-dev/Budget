<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000126Date20261021;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 126 adds budget_transactions.import_vendor, the payee an import
 * stored (#399): nullable, as long as the payee, and nothing else.
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

	private function run126(?object $table): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => $name === 'budget_transactions' && $table !== null);
		$schema->method('getTable')->willReturnCallback(fn () => $table);
		return (new Version001000126Date20261021())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
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

	/** A schema change only: nothing in the table is rewritten */
	public function testItTouchesNoRows(): void {
		$source = (string)file_get_contents(self::FILE);
		$this->assertStringNotContainsString('postSchemaChange', $source);
		$this->assertStringNotContainsString('getQueryBuilder', $source);
	}
}
