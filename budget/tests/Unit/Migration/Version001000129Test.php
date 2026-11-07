<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000129Date20261107;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 129 adds budget_categories.show_in_calendar (#459): the
 * category's budget shows in the Bills Calendar. A nullable boolean, as
 * MigrationSchemaTest asks of every boolean column.
 */
class Version001000129Test extends TestCase {
	private const FILE = __DIR__ . '/../../../lib/Migration/Version001000129Date20261107.php';

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

	private function run129(?object $table): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => $name === 'budget_categories' && $table !== null);
		$schema->method('getTable')->willReturnCallback(fn () => $table);

		return (new Version001000129Date20261107())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testItAddsANullableBooleanColumn(): void {
		$table = $this->table(['id', 'budget_rollover']);

		$this->assertNotNull($this->run129($table));

		$this->assertSame(['show_in_calendar'], array_keys($table->added));
		[$type, $options] = $table->added['show_in_calendar'];
		$this->assertSame(Types::BOOLEAN, $type);
		$this->assertFalse($options['notnull']);
		$this->assertFalse($options['default']);
	}

	public function testItDoesNothingWhenTheColumnIsThere(): void {
		$table = $this->table(['id', 'show_in_calendar']);

		$this->assertNull($this->run129($table));
		$this->assertSame([], $table->added);
	}

	public function testItDoesNothingWithoutTheTable(): void {
		$this->assertNull($this->run129(null));
	}

	public function testTheColumnNameIsShortEnough(): void {
		$this->assertLessThanOrEqual(23, strlen('show_in_calendar'));
		$this->assertStringContainsString("'show_in_calendar'", (string)file_get_contents(self::FILE));
	}
}
