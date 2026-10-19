<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Db\DanglingLinks;
use OCA\Budget\Db\LegacyIncomeRows;
use OCA\Budget\Migration\Version001000125Date20261019;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration 125 clears up two things older versions left (#399): incomes
 * 2.54.0 left due on a receipt it had already booked, and references to
 * bills and transactions that no longer exist (DanglingLinksTest).
 */
class Version001000125Test extends TestCase {
	/** @var string[] lines written to the migration output */
	private array $info = [];

	private function migrationOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->info[] = $message;
		});
		return $output;
	}

	/**
	 * @param string[] $tables the tables the schema has, each with every column
	 */
	private function schema(array $tables): \Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => in_array($name, $tables, true));
		$schema->method('getTable')->willReturnCallback(function () {
			return new class {
				public function hasColumn(string $name): bool {
					return true;
				}
			};
		});
		return static fn () => $schema;
	}

	private const TABLES = ['budget_recurring_income', 'budget_transactions', 'budget_bills'];

	private function stuckIncome(int $id): array {
		return [
			'incomeId' => $id, 'userId' => 'terje', 'name' => 'Gutschrift SBB Abo Leon', 'accountId' => 1,
			'transactionId' => 1293, 'amount' => 100.0, 'lastReceivedDate' => '2026-09-28',
			'nextExpectedDate' => '2026-09-29', 'settledNextExpectedDate' => '2026-10-29',
		];
	}

	public function testItSettlesEveryUsersStuckIncomeThenClearsDanglingLinks(): void {
		$legacy = $this->createMock(LegacyIncomeRows::class);
		$links = $this->createMock(DanglingLinks::class);
		$calls = [];
		$legacy->expects($this->once())->method('stuckEarlyReceipts')
			->with(null)
			->willReturnCallback(function () use (&$calls) {
				$calls[] = 'find';
				return [$this->stuckIncome(9), $this->stuckIncome(10)];
			});
		$legacy->expects($this->exactly(2))->method('settle')
			->willReturnCallback(function (array $stuck) use (&$calls) {
				$calls[] = 'settle ' . $stuck['incomeId'];
				// The second moved on in the meantime
				return $stuck['incomeId'] === 9;
			});
		$links->expects($this->once())->method('clear')
			->willReturnCallback(function () use (&$calls) {
				$calls[] = 'links';
				return 3;
			});

		(new Version001000125Date20261019($legacy, $links))->postSchemaChange($this->migrationOutput(), $this->schema(self::TABLES), []);

		$this->assertSame(['find', 'settle 9', 'settle 10', 'links'], $calls);
		$this->assertSame([
			'Moved 1 recurring income(s) received early on to their next expected date',
			'Cleared 3 reference(s) to bills or transactions that no longer exist',
		], $this->info);
	}

	public function testNothingToDoSaysNothing(): void {
		$legacy = $this->createMock(LegacyIncomeRows::class);
		$legacy->method('stuckEarlyReceipts')->willReturn([]);
		$links = $this->createMock(DanglingLinks::class);
		$links->method('clear')->willReturn(0);

		(new Version001000125Date20261019($legacy, $links))->postSchemaChange($this->migrationOutput(), $this->schema(self::TABLES), []);

		$this->assertSame([], $this->info);
	}

	public function testMissingTablesAreLeftAlone(): void {
		$legacy = $this->createMock(LegacyIncomeRows::class);
		$legacy->expects($this->never())->method('stuckEarlyReceipts');
		$links = $this->createMock(DanglingLinks::class);
		$links->expects($this->never())->method('clear');

		(new Version001000125Date20261019($legacy, $links))->postSchemaChange($this->migrationOutput(), $this->schema([]), []);

		$this->assertSame([], $this->info);
	}
}
