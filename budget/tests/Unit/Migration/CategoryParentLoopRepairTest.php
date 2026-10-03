<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000120Date20261004;
use PHPUnit\Framework\TestCase;

/**
 * A reorder before 3.0 could drop a category next to its own subcategory,
 * leaving a parent chain that loops. Each loop is broken once.
 */
class CategoryParentLoopRepairTest extends TestCase {

	public function testACategoryThatIsItsOwnParentMovesToTheTop(): void {
		$this->assertSame([5], Version001000120Date20261004::loopBreakIds([5 => 5]));
	}

	public function testALoopThroughAGrandchildIsBrokenAtItsLowestId(): void {
		// 5 was dropped next to its grandchild 7: 5 -> 6 -> 5, with 7 under 6
		$parents = [5 => 6, 6 => 5, 7 => 6];

		$this->assertSame([5], Version001000120Date20261004::loopBreakIds($parents));
	}

	public function testATreeWithoutLoopsIsLeftAlone(): void {
		$parents = [2 => 1, 3 => 1, 4 => 3, 9 => 404];

		$this->assertSame([], Version001000120Date20261004::loopBreakIds($parents));
	}

	public function testEachSeparateLoopIsBrokenOnce(): void {
		$parents = [10 => 11, 11 => 12, 12 => 10, 20 => 20, 30 => 31, 31 => 30, 40 => 30];

		$this->assertSame([10, 20, 30], Version001000120Date20261004::loopBreakIds($parents));
	}
}
