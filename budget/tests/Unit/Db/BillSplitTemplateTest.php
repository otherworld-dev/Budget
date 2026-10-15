<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Bill;
use PHPUnit\Framework\TestCase;

/**
 * A bill's split template naming a deleted category made every split fail
 * ("Category not found", only logged), so each payment from then on was
 * booked unsplit and uncategorised - the parts whose categories still
 * existed lost their share too.
 */
class BillSplitTemplateTest extends TestCase {
	private function billWith(array $parts): Bill {
		$bill = new Bill();
		$bill->setSplitTemplateArray($parts);
		return $bill;
	}

	public function testADeletedCategorysPartBecomesUncategorised(): void {
		$bill = $this->billWith([
			['categoryId' => 3, 'amount' => 30, 'description' => 'Food'],
			['categoryId' => 7, 'amount' => 20, 'description' => 'Repairs'],
		]);

		$this->assertTrue($bill->dropSplitTemplateCategory(7));

		$this->assertSame([
			['categoryId' => 3, 'amount' => 30, 'description' => 'Food'],
			['categoryId' => null, 'amount' => 20, 'description' => 'Repairs'],
		], $bill->getSplitTemplateArray());
	}

	public function testAnIdStoredAsTextIsRecognisedToo(): void {
		$bill = $this->billWith([['categoryId' => '7', 'amount' => 20], ['categoryId' => 3, 'amount' => 30]]);

		$this->assertTrue($bill->dropSplitTemplateCategory(7));
		$this->assertNull($bill->getSplitTemplateArray()[0]['categoryId']);
	}

	public function testATemplateWithoutTheCategoryIsLeftAlone(): void {
		$bill = $this->billWith([['categoryId' => 3, 'amount' => 30], ['categoryId' => 17, 'amount' => 20]]);
		$before = $bill->getSplitTemplate();

		$this->assertFalse($bill->dropSplitTemplateCategory(7));
		$this->assertSame($before, $bill->getSplitTemplate());
	}

	public function testNoTemplateIsNothingToDo(): void {
		$this->assertFalse((new Bill())->dropSplitTemplateCategory(7));
	}
}
