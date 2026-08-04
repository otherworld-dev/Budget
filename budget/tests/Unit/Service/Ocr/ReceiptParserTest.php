<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Ocr;

use OCA\Budget\Service\Ocr\ReceiptParser;
use PHPUnit\Framework\TestCase;

/**
 * The parser's contract is asymmetric on purpose: a missed field is fine
 * (the user types it), a wrong field is not. Tests therefore lean on
 * "returns null" as a correct answer.
 */
class ReceiptParserTest extends TestCase {
	private ReceiptParser $parser;

	protected function setUp(): void {
		$this->parser = new ReceiptParser();
	}

	public function testParsesAnOrdinaryReceipt(): void {
		$result = $this->parser->parse(implode("\n", [
			'TESCO EXPRESS',
			'123 High Street',
			'VAT No: GB123456789',
			'2026-08-01 14:32',
			'Milk 2L            1.65',
			'Bread              1.10',
			'2x Coffee 250ml    7.00',
			'SUBTOTAL           9.75',
			'TOTAL              9.75',
			'CARD               9.75',
			'CHANGE             0.00',
		]));

		$this->assertSame('Tesco Express', $result['merchant']);
		$this->assertSame('2026-08-01', $result['date']);
		$this->assertSame('9.75', $result['total']);
		$this->assertSame([
			['description' => 'Milk 2L', 'amount' => '1.65'],
			['description' => 'Bread', 'amount' => '1.10'],
			['description' => '2x Coffee 250ml', 'amount' => '7.00'],
		], $result['lineItems']);
	}

	public function testEmptyTextParsesToNothing(): void {
		$result = $this->parser->parse('');

		$this->assertNull($result['merchant']);
		$this->assertNull($result['date']);
		$this->assertNull($result['total']);
		$this->assertSame([], $result['lineItems']);
	}

	// ── merchant ────────────────────────────────────────────────────

	public function testMerchantSkipsNumberHeavyHeaderLines(): void {
		$result = $this->parser->parse("0044 121 496 0000\nStore #42\nCORNER SHOP LTD\n");

		$this->assertSame('Corner Shop Ltd', $result['merchant']);
	}

	public function testMixedCaseMerchantIsKeptAsPrinted(): void {
		$result = $this->parser->parse("Waitrose & Partners\nTOTAL 5.00");

		$this->assertSame('Waitrose & Partners', $result['merchant']);
	}

	// ── dates ───────────────────────────────────────────────────────

	public function testReadsEuropeanDatesDayFirst(): void {
		$this->assertSame('2026-02-01', $this->parser->parse('01/02/2026')['date']);
		$this->assertSame('2026-02-01', $this->parser->parse('01.02.26')['date']);
	}

	public function testReadsAnUnambiguousMonthFirstDate(): void {
		// 12/31 can only be month-first; the receipt's word is taken for it.
		$this->assertSame('2026-12-31', $this->parser->parse('12/31/2026')['date']);
	}

	public function testImpossibleDatesAreDroppedNotGuessed(): void {
		$this->assertNull($this->parser->parse('Printed 33/13/2026')['date']);
		$this->assertNull($this->parser->parse('Est. 01/02/1987')['date']);
	}

	public function testDottedTimesAreNotDates(): void {
		// A POS timestamp "12.30.15" once swapped itself into 2015-12-30.
		// Dotted forms are strictly day-first: an impossible one is a time.
		$result = $this->parser->parse("SHOP\nServed 12.30.15\n01/08/2026\nTOTAL 2.00");

		$this->assertSame('2026-08-01', $result['date']);
	}

	// ── totals ──────────────────────────────────────────────────────

	public function testTheLastTotalMarkerWins(): void {
		// "TOTAL" mid-receipt is a subtotal; the bottom one is the real thing.
		$result = $this->parser->parse("TOTAL 10.00\nMore items 2.50\nTOTAL 12.50");

		$this->assertSame('12.50', $result['total']);
	}

	public function testASubtotalLineIsNotTheTotal(): void {
		// "subtotal" ends in "total" — it must not satisfy the marker, and
		// with no real marker the paid amount wins as the largest.
		$result = $this->parser->parse("SHOP\nWidget 45.00\nSubtotal 45.00\nVAT 2.50\nBalance 47.50");

		$this->assertSame('47.50', $result['total']);
	}

	public function testAmountDueCountsAsATotalMarker(): void {
		$this->assertSame('18.20', $this->parser->parse("Items 20.00\nAMOUNT DUE 18.20")['total']);
	}

	public function testWithoutAMarkerTheLargestAmountIsTheGuess(): void {
		$result = $this->parser->parse("Thing 3.00\nOther 12.00\nSmall 1.50");

		$this->assertSame('12.00', $result['total']);
	}

	public function testEuropeanDecimalCommaAmounts(): void {
		$this->assertSame('1234.56', $this->parser->parse('TOTAL 1.234,56')['total']);
		$this->assertSame('12.34', $this->parser->parse('TOTAL 12,34')['total']);
		$this->assertSame('1234.56', $this->parser->parse('TOTAL 1 234,56')['total']);
	}

	public function testThousandsGroupingWithoutDecimals(): void {
		$this->assertSame('1234.00', $this->parser->parse('TOTAL 1,234.00')['total']);
	}

	public function testAdjacentNumbersAreNotMergedIntoOneAmount(): void {
		// A quantity column next to the price once produced 10-100x amounts
		// ("Coffee 2 2.50" -> 22.50). The LAST number on the line is the price.
		$result = $this->parser->parse("CORNER SHOP\nCoffee 2 2.50\nTea 1 1.80\nTOTAL 4.30");

		$this->assertSame('4.30', $result['total']);
		$this->assertSame([
			['description' => 'Coffee 2', 'amount' => '2.50'],
			['description' => 'Tea 1', 'amount' => '1.80'],
		], $result['lineItems']);
	}

	public function testADateBeforeAnAmountDoesNotInflateTheTotal(): void {
		// "01/02/2026 12.50" once tokenised as "2026 12.50" -> total 202612.50.
		$result = $this->parser->parse("SHOP\n01/02/2026 12.50\nWidget 4.00\nSumme 16.50");

		$this->assertSame('2026-02-01', $result['date']);
		$this->assertSame('16.50', $result['total']);
	}

	public function testDottedDatesAndVersionsAreNotAmounts(): void {
		// "31.12.26" is a date (and parses as one); it must never be 3112.26.
		$result = $this->parser->parse("REWE MARKT\nDatum: 31.12.26\nMilch 1,19\nBrot 2,49\nSumme 3,68");

		$this->assertSame('2026-12-31', $result['date']);
		$this->assertSame('3.68', $result['total'], 'the largest-amount fallback must not pick a date');
		$this->assertSame([
			['description' => 'Milch', 'amount' => '1.19'],
			['description' => 'Brot', 'amount' => '2.49'],
		], $result['lineItems']);
	}

	public function testThreeDecimalDigitsAreAmbiguousAndRejected(): void {
		// "1.499" is a per-litre price as often as a thousands group; either
		// reading is wrong half the time, so neither is made.
		$result = $this->parser->parse("PETROL STATION\nPRICE/LTR 1.499\nFuel 68.46\nTOTAL 68.46");

		$this->assertSame('68.46', $result['total']);
		$this->assertSame([['description' => 'Fuel', 'amount' => '68.46']], $result['lineItems']);

		// Same for a half-kilo weight on its own line.
		$weighed = $this->parser->parse("GROCER\nBananas 0.500\nApples 2.10\nTOTAL 2.60");
		$this->assertSame([['description' => 'Apples', 'amount' => '2.10']], $weighed['lineItems']);
	}

	public function testAmountsLongerThanADecimalColumnAreRejected(): void {
		// DECIMAL(15,2) holds 13 integer digits; an OCR-misread card number
		// must not become a total.
		$this->assertSame('2.00', $this->parser->parse("SHOP\nThing 2.00\nRef 12345678901234.00")['lineItems'][0]['amount']);
		$this->assertNull($this->parser->parse('TOTAL 12345678901234.00')['total']);
	}

	// ── line items ──────────────────────────────────────────────────

	public function testBookkeepingLinesAreNotItems(): void {
		$result = $this->parser->parse(implode("\n", [
			'SHOP',
			'Apples 2.00',
			'VAT 0.33',
			'Subtotal 2.00',
			'Cash 5.00',
			'Change due 3.00',
			'Loyalty points 2.50',
		]));

		$this->assertSame([['description' => 'Apples', 'amount' => '2.00']], $result['lineItems']);
	}

	public function testNamesEndingInXKeepTheirX(): void {
		// trim('x') once turned "Weetabix" into "Weetabi"; only a standalone
		// trailing quantity marker (" x") is stripped.
		$result = $this->parser->parse("SHOP\nWeetabix 3.49\nCoffee x 2.50\nTOTAL 5.99");

		$this->assertSame([
			['description' => 'Weetabix', 'amount' => '3.49'],
			['description' => 'Coffee', 'amount' => '2.50'],
		], $result['lineItems']);
	}

	public function testAWallOfPricesProducesNoItemsAtAll(): void {
		$lines = ['SHOP'];
		for ($i = 1; $i <= 51; $i++) {
			$lines[] = sprintf('Item %d 1.%02d', $i, $i % 100);
		}

		// Past a sane basket size the structure is noise: trust nothing.
		$this->assertSame([], $this->parser->parse(implode("\n", $lines))['lineItems']);
	}

	public function testBareIntegersAreQuantitiesNotPrices(): void {
		// "x2" style quantity columns must not be read as £2 items.
		$result = $this->parser->parse("SHOP\nApples 2\nBananas 3");

		$this->assertSame([], $result['lineItems']);
	}

	public function testNegativeAmountsAreNotItems(): void {
		$result = $this->parser->parse("SHOP\nApples 2.00\nDiscount -0.50");

		$this->assertSame([['description' => 'Apples', 'amount' => '2.00']], $result['lineItems']);
	}

	public function testThePriceIsTheLastAmountOnTheLine(): void {
		$result = $this->parser->parse("SHOP\n2x Cola 330ml 2.40");

		$this->assertSame([['description' => '2x Cola 330ml', 'amount' => '2.40']], $result['lineItems']);
	}
}
