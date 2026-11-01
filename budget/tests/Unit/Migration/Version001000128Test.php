<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000128Date20261027;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 128 adds budget_bam.last_balance_date: the day the bank reported
 * the mapping's last balance for. Nullable, as existing mappings and
 * providers without a balance date have none.
 */
class Version001000128Test extends TestCase {
	private const FILE = __DIR__ . '/../../../lib/Migration/Version001000128Date20261027.php';

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

	private function run128(?object $table): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => $name === 'budget_bam' && $table !== null);
		$schema->method('getTable')->willReturnCallback(fn () => $table);

		return (new Version001000128Date20261027())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testItAddsANullableDateColumn(): void {
		$table = $this->table(['id', 'last_balance']);

		$this->assertNotNull($this->run128($table));

		$this->assertSame(['last_balance_date'], array_keys($table->added));
		[$type, $options] = $table->added['last_balance_date'];
		$this->assertSame(Types::DATE, $type);
		$this->assertFalse($options['notnull']);
		$this->assertArrayNotHasKey('default', $options);
	}

	public function testItDoesNothingWhenTheColumnIsThere(): void {
		$table = $this->table(['id', 'last_balance_date']);

		$this->assertNull($this->run128($table));
		$this->assertSame([], $table->added);
	}

	public function testItDoesNothingWithoutTheTable(): void {
		$this->assertNull($this->run128(null));
	}

	/** Oracle's 30 characters with the prefix, and the project's 23 */
	public function testTheColumnNameIsShortEnough(): void {
		$this->assertLessThanOrEqual(23, strlen('last_balance_date'));
		$this->assertStringContainsString("'last_balance_date'", (string)file_get_contents(self::FILE));
	}
}
