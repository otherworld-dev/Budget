<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\TransactionNormalizer;
use PHPUnit\Framework\TestCase;

class TransactionNormalizerTest extends TestCase {
	private TransactionNormalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new TransactionNormalizer();
	}

	// ── mapRowToTransaction ─────────────────────────────────────────

	public function testMapRowBasicCsvMapping(): void {
		$row = ['2024-03-15', '42.50', 'Coffee Shop', 'Latte'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'memo' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('2024-03-15', $result['date']);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
		$this->assertSame('Coffee Shop', $result['description']);
	}

	public function testMapRowNegativeAmountIsDebit(): void {
		$row = ['2024-03-15', '-25.00', 'Grocery'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(25.00, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowDualColumnIncome(): void {
		$row = ['2024-03-15', '1500.00', '', 'Salary'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(1500.00, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowDualColumnExpense(): void {
		$row = ['2024-03-15', '', '75.00', 'Electric bill'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(75.00, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowDualColumnZeroIncomeIgnored(): void {
		$row = ['2024-03-15', '0.00', '50.00', 'Purchase'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('debit', $result['type']);
		$this->assertEqualsWithDelta(50.00, $result['amount'], 0.001);
	}

	public function testMapRowDualColumnEuropeanZeroExpenseIgnored(): void {
		// Bug #95: European zero "0,00" was not recognized as zero,
		// causing it to overwrite the valid income amount
		$row = ['01.01.2026', '1,00', '0,00', 'Interest'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(1.00, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowDualColumnEuropeanZeroIncomeIgnored(): void {
		$row = ['02.01.2026', '0,00', '30,00', 'Shopping'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(30.00, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowDualColumnEuropeanThousandsIncome(): void {
		// "1.000,00" = 1000.00 in European format
		$row = ['02.01.2026', '1.000,00', '0,00', 'Wage'];
		$mapping = [
			'date' => 0,
			'description' => 3,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(1000.00, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowThrowsWhenNoDate(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Date is required');

		$row = ['', '42.50', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];
		$this->normalizer->mapRowToTransaction($row, $mapping);
	}

	public function testMapRowThrowsWhenNoAmount(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Amount is required');

		$row = ['2024-03-15', '', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];
		$this->normalizer->mapRowToTransaction($row, $mapping);
	}

	public function testMapRowSkipsBooleanMappingValues(): void {
		$row = ['2024-03-15', '10.00', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'hasHeader' => true];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertArrayNotHasKey('hasHeader', $result);
	}

	public function testMapRowSkipsNullMappingValues(): void {
		$row = ['2024-03-15', '10.00', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'vendor' => null];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		// vendor not set from null mapping, but description trim still works
		$this->assertSame('Test', $result['description']);
	}

	public function testMapRowTrimsDescription(): void {
		$row = ['2024-03-15', '10.00', '  Spaces Around  '];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertSame('Spaces Around', $result['description']);
	}

	public function testMapRowMissingDescriptionDefaultsToEmpty(): void {
		$row = ['2024-03-15', '10.00'];
		$mapping = ['date' => 0, 'amount' => 1];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertSame('', $result['description']);
	}

	// ── parseAmount (tested indirectly via mapRowToTransaction) ─────

	public function testParseAmountUSFormat(): void {
		$row = ['2024-01-01', '1,234.56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
	}

	public function testParseAmountEuropeanFormat(): void {
		$row = ['2024-01-01', '1.234,56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
	}

	public function testParseAmountEuropeanDecimalOnly(): void {
		$row = ['2024-01-01', '42,50', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
	}

	public function testParseAmountWithCurrencySymbol(): void {
		$row = ['2024-01-01', '$1,234.56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
	}

	public function testParseAmountEuroSymbol(): void {
		$row = ['2024-01-01', '€1.234,56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
	}

	public function testParseAmountPlainInteger(): void {
		$row = ['2024-01-01', '500', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(500.0, $result['amount'], 0.001);
	}

	public function testParseAmountMultipleThousandsPeriods(): void {
		// 1.000.000 → periods as thousands separators (multiple periods)
		$row = ['2024-01-01', '1.000.000', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1000000.0, $result['amount'], 0.001);
	}

	public function testParseAmountMultipleThousandsCommas(): void {
		// 1,000,000 → commas as thousands separators (multiple commas)
		$row = ['2024-01-01', '1,000,000', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1000000.0, $result['amount'], 0.001);
	}

	public function testParseAmountNegativeEuropean(): void {
		$row = ['2024-01-01', '-1.234,56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	// ── mapOfxTransaction ───────────────────────────────────────────

	public function testMapOfxTransactionCredit(): void {
		$txn = [
			'date' => '2024-03-15',
			'rawAmount' => 1500.00,
			'description' => 'PAYROLL',
			'memo' => 'March salary',
			'id' => 'FITID123',
		];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertSame('2024-03-15', $result['date']);
		$this->assertEqualsWithDelta(1500.00, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
		$this->assertSame('PAYROLL', $result['description']);
		$this->assertSame('March salary', $result['memo']);
		$this->assertSame('FITID123', $result['id']);
	}

	public function testMapOfxTransactionDebit(): void {
		$txn = [
			'date' => '2024-03-15',
			'rawAmount' => -42.50,
			'description' => 'COFFEE SHOP',
			'id' => 'FITID456',
		];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapOfxTransactionFallsBackToAmount(): void {
		$txn = ['amount' => -100.0, 'name' => 'Purchase'];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertEqualsWithDelta(100.0, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
		$this->assertSame('Purchase', $result['description']);
	}

	public function testMapOfxTransactionMissingFieldsDefault(): void {
		$result = $this->normalizer->mapOfxTransaction([]);

		$this->assertSame('', $result['date']);
		$this->assertEqualsWithDelta(0.0, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']); // 0 >= 0
		$this->assertSame('', $result['description']);
		$this->assertNull($result['memo']);
		$this->assertNull($result['reference']);
		$this->assertNull($result['id']);
	}

	// ── mapQifTransaction ───────────────────────────────────────────

	public function testMapQifTransactionCredit(): void {
		$txn = [
			'date' => '03/15/2024',
			'amount' => 500.0,
			'payee' => 'Employer Inc',
			'memo' => 'Paycheck',
			'number' => '1234',
			'category' => 'Income:Salary',
		];

		$result = $this->normalizer->mapQifTransaction($txn);

		$this->assertSame('2024-03-15', $result['date']);
		$this->assertEqualsWithDelta(500.0, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
		$this->assertSame('Employer Inc', $result['description']);
		$this->assertSame('Paycheck', $result['memo']);
		$this->assertSame('1234', $result['reference']);
		$this->assertSame('Income:Salary', $result['category']);
	}

	public function testMapQifTransactionDebit(): void {
		$txn = [
			'date' => '2024-03-15',
			'amount' => -75.00,
			'payee' => 'Grocery Store',
		];

		$result = $this->normalizer->mapQifTransaction($txn);

		$this->assertEqualsWithDelta(75.00, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapQifTransactionMissingFieldsDefault(): void {
		// mapQifTransaction calls normalizeDate on the date field, so empty date throws
		$this->expectException(\Exception::class);
		$this->normalizer->mapQifTransaction([]);
	}

	public function testMapQifTransactionMissingOptionalFields(): void {
		$txn = ['date' => '2024-01-01', 'amount' => 0];
		$result = $this->normalizer->mapQifTransaction($txn);

		$this->assertSame('', $result['description']);
		$this->assertNull($result['memo']);
		$this->assertNull($result['reference']);
		$this->assertSame('', $result['vendor']);
		$this->assertNull($result['category']);
	}

	// ── detectDateFormat ────────────────────────────────────────────

	public function testDetectDateFormatDDMMWhenDayAbove12(): void {
		// Day 25 is unambiguous: must be DD/MM/YYYY
		$dates = ['25/01/2024', '15/02/2024', '03/03/2024'];
		$this->normalizer->detectDateFormat($dates);

		$result = $this->normalizer->normalizeDate('05/06/2024');
		$this->assertSame('2024-06-05', $result); // DD/MM detected
	}

	public function testDetectDateFormatMMDDWhenMonthAbove12(): void {
		// These only parse as MM/DD/YYYY (month would be >12 in DD/MM)
		// Actually m/d/Y comes before d/m/Y in DATE_FORMATS, so for ambiguous
		// dates like 01/15/2024 where 15 > 12, MM/DD is the only valid parse
		$dates = ['01/15/2024', '02/20/2024', '03/25/2024'];
		$this->normalizer->detectDateFormat($dates);

		$result = $this->normalizer->normalizeDate('04/05/2024');
		$this->assertSame('2024-04-05', $result); // MM/DD detected
	}

	public function testDetectDateFormatSkipsAlreadyNormalized(): void {
		$dates = ['2024-01-01', '2024-02-15', '25/03/2024'];
		$this->normalizer->detectDateFormat($dates);

		// Only '25/03/2024' is a candidate, forces DD/MM
		$result = $this->normalizer->normalizeDate('05/06/2024');
		$this->assertSame('2024-06-05', $result);
	}

	public function testDetectDateFormatSkipsOfxFormat(): void {
		$dates = ['20240315', '20240401', '25/06/2024'];
		$this->normalizer->detectDateFormat($dates);

		$result = $this->normalizer->normalizeDate('05/07/2024');
		$this->assertSame('2024-07-05', $result);
	}

	public function testDetectDateFormatSkipsEmptyStrings(): void {
		$dates = ['', '  ', '25/03/2024'];
		$this->normalizer->detectDateFormat($dates);

		$result = $this->normalizer->normalizeDate('05/06/2024');
		$this->assertSame('2024-06-05', $result);
	}

	public function testDetectDateFormatNoOpWhenEmpty(): void {
		$this->normalizer->detectDateFormat([]);
		// No detected format → falls through to per-format trial
		// m/d/Y is tried before d/m/Y, so ambiguous dates default to US format
		$result = $this->normalizer->normalizeDate('01/02/2024');
		$this->assertSame('2024-01-02', $result); // MM/DD fallback
	}

	// ── resetDateFormat ─────────────────────────────────────────────

	public function testResetDateFormatClearsDetection(): void {
		// Force DD/MM detection
		$this->normalizer->detectDateFormat(['25/01/2024']);
		$this->normalizer->resetDateFormat();

		// Without detection, ambiguous date falls back to format trial (m/d/Y first)
		$result = $this->normalizer->normalizeDate('01/02/2024');
		$this->assertSame('2024-01-02', $result); // MM/DD fallback
	}

	// ── normalizeDate ───────────────────────────────────────────────

	public function testNormalizeDateAlreadyNormalized(): void {
		$this->assertSame('2024-03-15', $this->normalizer->normalizeDate('2024-03-15'));
	}

	public function testNormalizeDateOfxFormat(): void {
		$this->assertSame('2024-03-15', $this->normalizer->normalizeDate('20240315'));
	}

	public function testNormalizeDateOfxWithTime(): void {
		$this->assertSame('2024-03-15', $this->normalizer->normalizeDate('20240315120000'));
	}

	public function testNormalizeDateEuropeanDotFormat(): void {
		$this->assertSame('2024-03-15', $this->normalizer->normalizeDate('15.03.2024'));
	}

	public function testNormalizeDateTwoDigitYearEuropeanDot(): void {
		// DKB (Deutsche Kredit Bank) exports dates as DD.MM.YY
		$this->assertSame('2026-03-25', $this->normalizer->normalizeDate('25.03.26'));
	}

	public function testDetectDateFormatTwoDigitYearBatch(): void {
		// Batch detection should find d.m.y for 2-digit year dates
		$dates = ['25.03.26', '24.03.26', '15.01.26'];
		$this->normalizer->detectDateFormat($dates);

		$result = $this->normalizer->normalizeDate('01.06.26');
		$this->assertSame('2026-06-01', $result);
	}

	public function testMapRowDkbCsvFormat(): void {
		// Simulates a DKB bank export row (issue #100)
		$row = [
			'Buchungsdatum' => '25.03.26',
			'Zahlungsempfänger*in' => 'Lidl',
			'Verwendungszweck' => 'VISA Debitkartenumsatz vom 24.03.2026',
			'Betrag (€)' => '-57,68',
		];
		$mapping = [
			'date' => 'Buchungsdatum',
			'description' => 'Verwendungszweck',
			'amount' => 'Betrag (€)',
			'vendor' => 'Zahlungsempfänger*in',
		];

		$this->normalizer->detectDateFormat(['25.03.26', '25.03.26', '25.03.26']);
		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('2026-03-25', $result['date']);
		$this->assertEqualsWithDelta(57.68, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
		$this->assertSame('Lidl', $result['vendor']);
	}

	public function testMapRowDkbCsvFullFlowWithParsedData(): void {
		// End-to-end test: simulates how DKB data flows after ParserFactory
		// strips BOM and parses quoted CSV. Verifies column name mapping works
		// when headers are clean (no BOM/quote artifacts).
		$row = [
			'Buchungsdatum' => '24.03.26',
			'Wertstellung' => '24.03.26',
			'Status' => 'Gebucht',
			'Zahlungspflichtige*r' => 'Max Mustermann',
			'Zahlungsempfänger*in' => 'REWE',
			'Verwendungszweck' => 'VISA Debitkartenumsatz vom 23.03.2026',
			'Umsatztyp' => 'Ausgang',
			'Betrag (€)' => '-23,45',
		];
		$mapping = [
			'date' => 'Buchungsdatum',
			'description' => 'Verwendungszweck',
			'amount' => 'Betrag (€)',
			'vendor' => 'Zahlungsempfänger*in',
		];

		$this->normalizer->detectDateFormat(['24.03.26', '25.03.26']);
		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('2026-03-24', $result['date']);
		$this->assertEqualsWithDelta(23.45, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
		$this->assertSame('REWE', $result['vendor']);
	}

	public function testNormalizeDateTrimsWhitespace(): void {
		$this->assertSame('2024-03-15', $this->normalizer->normalizeDate('  2024-03-15  '));
	}

	public function testNormalizeDateThrowsOnInvalidDate(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid date format');
		$this->normalizer->normalizeDate('not-a-date');
	}

	// ── generateImportId ────────────────────────────────────────────

	public function testGenerateImportIdUsesOfxFitid(): void {
		$tx = ['id' => 'FITID12345', 'date' => '2024-03-15', 'amount' => '42.50'];
		$id = $this->normalizer->generateImportId('file1', 0, $tx);

		$this->assertSame('ofx_fitid_FITID12345', $id);
	}

	public function testGenerateImportIdUsesContentHash(): void {
		$tx = ['date' => '2024-03-15', 'amount' => '42.50', 'description' => 'Coffee'];
		$id = $this->normalizer->generateImportId('file1', 0, $tx);

		$this->assertStringStartsWith('hash_', $id);
		$this->assertSame(37, strlen($id)); // 'hash_' + 32 char md5
	}

	public function testGenerateImportIdSameContentSameHash(): void {
		$tx = ['date' => '2024-03-15', 'amount' => '42.50', 'description' => 'Coffee'];
		$id1 = $this->normalizer->generateImportId('file1', 0, $tx);
		$id2 = $this->normalizer->generateImportId('file2', 5, $tx);

		// Same content → same hash (fileId and index are intentionally ignored)
		$this->assertSame($id1, $id2);
	}

	public function testGenerateImportIdDifferentContentDifferentHash(): void {
		$tx1 = ['date' => '2024-03-15', 'amount' => '42.50', 'description' => 'Coffee'];
		$tx2 = ['date' => '2024-03-15', 'amount' => '42.50', 'description' => 'Tea'];

		$id1 = $this->normalizer->generateImportId('file1', 0, $tx1);
		$id2 = $this->normalizer->generateImportId('file1', 0, $tx2);

		$this->assertNotSame($id1, $id2);
	}

	// ── normalizeVendor ─────────────────────────────────────────────

	public function testNormalizeVendorNull(): void {
		$this->assertNull($this->normalizer->normalizeVendor(null));
	}

	public function testNormalizeVendorEmpty(): void {
		$this->assertNull($this->normalizer->normalizeVendor(''));
	}

	public function testNormalizeVendorTrimsAndCollapsesSpaces(): void {
		$this->assertSame('Coffee Shop', $this->normalizer->normalizeVendor('  Coffee   Shop  '));
	}

	public function testNormalizeVendorNoChange(): void {
		$this->assertSame('Normal Vendor', $this->normalizer->normalizeVendor('Normal Vendor'));
	}

	// ── normalizeDescription ────────────────────────────────────────

	public function testNormalizeDescriptionNull(): void {
		$this->assertSame('', $this->normalizer->normalizeDescription(null));
	}

	public function testNormalizeDescriptionTrimsAndCollapsesSpaces(): void {
		$this->assertSame('Purchase at store', $this->normalizer->normalizeDescription('  Purchase   at   store  '));
	}

	public function testNormalizeDescriptionEmpty(): void {
		$this->assertSame('', $this->normalizer->normalizeDescription(''));
	}
}
