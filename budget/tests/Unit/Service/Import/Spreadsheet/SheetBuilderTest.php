<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\SheetBuilder;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
use PHPUnit\Framework\TestCase;

class SheetBuilderTest extends TestCase {
	public function testRowsInOrderWithGapsFilledAndEmptyRowsLeftOut(): void {
		$builder = new SheetBuilder('Movimenti');
		$builder->add(5, 2, 'c');
		$builder->add(0, 0, 'a');
		$builder->add(5, 0, 'b');
		$builder->add(3, 1, '');
		$this->assertSame(['name' => 'Movimenti', 'rows' => [['a'], ['b', '', 'c']]], $builder->build());
	}

	public function testCellsBeyondTheColumnLimitAreIgnored(): void {
		$builder = new SheetBuilder('S');
		$builder->add(0, 0, 'a');
		$builder->add(0, SheetBuilder::MAX_COLUMNS, 'far');
		$builder->add(0, 16383, 'farther');
		$this->assertSame([['a']], $builder->build()['rows']);
	}

	public function testTooManyRows(): void {
		$builder = new SheetBuilder('S');
		for ($row = 0; $row < SheetBuilder::MAX_ROWS; $row++) {
			$builder->add($row, 0, 'x');
		}
		$builder->add(0, 1, 'same row is fine');
		$this->expectException(SpreadsheetTooLargeError::class);
		$builder->add(SheetBuilder::MAX_ROWS, 0, 'one too many');
	}

	public function testTooManyCells(): void {
		$builder = new SheetBuilder('S');
		$perRow = intdiv(SheetBuilder::MAX_CELLS, SheetBuilder::MAX_ROWS);
		for ($row = 0; $row < SheetBuilder::MAX_ROWS; $row++) {
			for ($column = 0; $column < $perRow; $column++) {
				$builder->add($row, $column, 'x');
			}
		}
		$this->expectException(SpreadsheetTooLargeError::class);
		$builder->add(0, $perRow, 'one too many');
	}

	public function testTooMuchText(): void {
		// One string in many cells: shared in memory, but the CSV would hold every copy
		$builder = new SheetBuilder('S');
		$megabyte = str_repeat('x', 1024 * 1024);
		$this->expectException(SpreadsheetTooLargeError::class);
		for ($row = 0; $row <= intdiv(SheetBuilder::MAX_TEXT_BYTES, strlen($megabyte)); $row++) {
			$builder->add($row, 0, $megabyte);
		}
	}
}
