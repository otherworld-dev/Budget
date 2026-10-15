<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Bill;
use PHPUnit\Framework\TestCase;

/**
 * A deleted tag stayed in a bill's tag list and was linked to every payment
 * the bill booked afterwards: those rows read as untagged but fell out of a
 * tag-filtered report even with "Include untagged" ticked.
 */
class BillTagIdsTest extends TestCase {
	public function testADeletedTagLeavesTheList(): void {
		$bill = new Bill();
		$bill->setTagIdsArray([4, 9, 12]);

		$this->assertTrue($bill->dropTagIds([9]));
		$this->assertSame([4, 12], $bill->getTagIdsArray());
	}

	public function testTheLastTagLeavesNoList(): void {
		$bill = new Bill();
		$bill->setTagIdsArray([9]);

		$this->assertTrue($bill->dropTagIds([9]));
		$this->assertNull($bill->getTagIds());
	}

	public function testAnotherTagIsLeftAlone(): void {
		$bill = new Bill();
		$bill->setTagIdsArray([19, 90]);
		$before = $bill->getTagIds();

		$this->assertFalse($bill->dropTagIds([9]));
		$this->assertSame($before, $bill->getTagIds());
	}
}
