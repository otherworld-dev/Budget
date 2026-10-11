<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\CellFormat;
use PHPUnit\Framework\TestCase;

class CellFormatTest extends TestCase {
	public function testBuiltInDateIdsAreDates(): void {
		foreach ([14, 15, 17, 22, 45, 47] as $id) {
			$this->assertTrue(CellFormat::isDateFormat($id, null), "id $id");
		}
		foreach ([0, 1, 2, 4, 9, 10, 49] as $id) {
			$this->assertFalse(CellFormat::isDateFormat($id, null), "id $id");
		}
	}

	public function testACustomCodeDecidesOverItsId(): void {
		$this->assertTrue(CellFormat::isDateFormat(164, 'dd/mm/yyyy'));
		$this->assertFalse(CellFormat::isDateFormat(14, '0.00'));
	}

	public function testDateCodes(): void {
		foreach (['dd/mm/yyyy', 'yyyy-mm-dd', 'mmm d, yyyy', '[h]:mm', 'h:mm AM/PM', '[$-410]dd mmmm yyyy', 'dd/mm/yyyy;@'] as $code) {
			$this->assertTrue(CellFormat::isDateCode($code), $code);
		}
	}

	public function testNumberCodesThatLookLikeDates(): void {
		foreach (['General', '0.00', '#,##0.00', '[Red]0.00', '"day"0', '0.00\d', '#,##0.00 [$€-410]', '0.0E+00', '@', '#,##0_);(#,##0)'] as $code) {
			$this->assertFalse(CellFormat::isDateCode($code), $code);
		}
	}

	public function testNumberText(): void {
		$this->assertSame('0.3', CellFormat::numberText(0.1 + 0.2));
		$this->assertSame('-1234.5', CellFormat::numberText(-1234.5));
		$this->assertSame('2000', CellFormat::numberText(2000.0));
		$this->assertSame('0', CellFormat::numberText(-0.0));
		$this->assertSame('1234.567', CellFormat::numberText(1234.567));
	}

	public function testNumberTextNeverReadsAsGroupedThousands(): void {
		// TransactionNormalizer::groupsThousands reads "1.234" as 1234
		$this->assertSame('1.2340', CellFormat::numberText(1.234));
		$this->assertSame('-12.3450', CellFormat::numberText(-12.345));
	}

	public function testSerialDates(): void {
		$this->assertSame('2026-10-01', CellFormat::serialDateText(46296.0, false));
		$this->assertSame('1900-01-01', CellFormat::serialDateText(1.0, false));
		$this->assertSame('1900-02-28', CellFormat::serialDateText(59.0, false));
		$this->assertSame('1900-02-29', CellFormat::serialDateText(60.0, false));
		$this->assertSame('1900-03-01', CellFormat::serialDateText(61.0, false));
		$this->assertSame('2026-10-01', CellFormat::serialDateText(46296.0 - 1462, true));
	}

	public function testATimeOnADateIsDropped(): void {
		$this->assertSame('2026-10-01', CellFormat::serialDateText(46296.75, false));
		$this->assertSame('2026-10-02', CellFormat::serialDateText(46296.999999, false));
	}

	public function testATimeOnItsOwn(): void {
		$this->assertSame('18:00:00', CellFormat::serialDateText(0.75, false));
	}

	public function testIsoText(): void {
		$this->assertSame('2026-10-01', CellFormat::isoText('2026-10-01T00:00:00'));
		$this->assertSame('2026-10-01', CellFormat::isoText('2026-10-01'));
		$this->assertSame('14:30:00', CellFormat::isoText('T14:30:00'));
	}
}
