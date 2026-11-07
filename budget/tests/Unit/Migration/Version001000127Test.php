<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000127Date20261027;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 127 adds budget_bam.balance_reconciled_at: when bank sync last
 * matched the mapped account's balance to the bank's. Nullable, as every
 * existing mapping is still to be matched.
 */
class Version001000127Test extends TestCase {
	private const FILE = __DIR__ . '/../../../lib/Migration/Version001000127Date20261027.php';

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

	private function run127(?object $table): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => $name === 'budget_bam' && $table !== null);
		$schema->method('getTable')->willReturnCallback(fn () => $table);

		return (new Version001000127Date20261027())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testItAddsANullableDateTimeColumn(): void {
		$table = $this->table(['id', 'last_balance']);

		$this->assertNotNull($this->run127($table));

		$this->assertSame(['balance_reconciled_at'], array_keys($table->added));
		[$type, $options] = $table->added['balance_reconciled_at'];
		$this->assertSame(Types::DATETIME, $type);
		$this->assertFalse($options['notnull']);
		$this->assertArrayNotHasKey('default', $options);
	}

	public function testItDoesNothingWhenTheColumnIsThere(): void {
		$table = $this->table(['id', 'balance_reconciled_at']);

		$this->assertNull($this->run127($table));
		$this->assertSame([], $table->added);
	}

	public function testItDoesNothingWithoutTheTable(): void {
		$this->assertNull($this->run127(null));
	}

	/** Oracle's 30 characters with the prefix, and the project's 23 */
	public function testTheColumnNameIsShortEnough(): void {
		$this->assertLessThanOrEqual(23, strlen('balance_reconciled_at'));
		$this->assertStringContainsString("'balance_reconciled_at'", (string)file_get_contents(self::FILE));
	}
}
