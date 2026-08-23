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

	public function testMapRowTypeColumnOverridesUnsignedAmount(): void {
		// #333: Nextcloud Tables exports carry an explicit "Expense"/"Income"
		// column and write every amount unsigned. Going by the sign alone
		// booked whole files as income.
		$row = ['2026-07-28', '18.40', 'Expense', 'Zigaretten'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(18.40, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowTypeColumnIncomeStaysCredit(): void {
		$row = ['2026-07-28', '2500.00', 'Income', 'Lohn'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowTypeColumnTolerantOfCaseAndPunctuation(): void {
		// Headers and values from real exports arrive padded: " DR. ", "Debit:"
		$row = ['2026-07-28', '42.00', ' DR. ', 'Fuel'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowTypeColumnWinsOverAmountSign(): void {
		// An explicit type column is the user's mapping; honour it even when
		// the amount also carries a sign.
		$row = ['2026-07-28', '-60.00', 'Income', 'Refund'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertEqualsWithDelta(60.00, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowUnrecognizedTypeValueFallsBackToSign(): void {
		$row = ['2026-07-28', '-75.00', 'SEPA-Lastschrift', 'Strom'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('debit', $result['type']);
	}

	public function testMapRowEmptyTypeValueFallsBackToSign(): void {
		// Half-filled exports leave the type blank on some rows (#333)
		$row = ['2026-07-29', '12.00', '', 'No type given'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('credit', $result['type']);
	}

	public function testMapRowDualColumnsIgnoreTypeColumn(): void {
		// Income/expense columns already state the direction unambiguously
		$row = ['2026-07-28', '', '75.00', 'Income', 'Electric bill'];
		$mapping = [
			'date' => 0,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
			'type' => 3,
			'description' => 4,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

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

	// ── parseAmount: signs written the way exports actually write them (#339) ──

	public function testParseAmountTrailingMinusIsDebit(): void {
		// A trailing minus was not just dropped: it also pushed the decimal
		// comma out of the last three characters, so "91,29-" parsed as
		// nine thousand one hundred and twenty nine, as income.
		$row = ['2024-01-01', '91,29-', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(91.29, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountTrailingMinusUsFormat(): void {
		$row = ['2024-01-01', '1,234.56-', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountTrailingMinusAfterCurrency(): void {
		$row = ['2024-01-01', '1.234,56 EUR-', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountUnicodeMinusIsDebit(): void {
		// U+2212 MINUS SIGN, not a hyphen. Stripped with the currency symbols.
		$row = ['2024-01-01', "\u{2212}91,29", 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(91.29, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountEnDashIsDebit(): void {
		$row = ['2024-01-01', "\u{2013}1.234,56", 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountParenthesesAreDebit(): void {
		// The accounting convention, and what a good few US/UK exports write.
		$row = ['2024-01-01', '(1,234.56)', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountParenthesesWithCurrencySymbol(): void {
		$row = ['2024-01-01', '($42.50)', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountUnbalancedParenthesisIsNotNegated(): void {
		// A stray bracket is a typo, not a sign.
		$row = ['2024-01-01', '(42.50', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testParseAmountDoubleNegativeStaysNegative(): void {
		// "(-42.50)" is one negative written twice over, not a positive.
		$row = ['2024-01-01', '(-42.50)', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame('debit', $result['type']);
	}

	public function testParseAmountBareDashIsZeroNotNegative(): void {
		// Exports write "-" for "nothing here"; it is a placeholder, not a sign.
		$row = ['2024-01-01', '-', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(0.0, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testParseAmountLeadingPlusStaysCredit(): void {
		$row = ['2024-01-01', '+1.234,56', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testParseAmountTrailingPlusStaysCredit(): void {
		$row = ['2024-01-01', '1.234,56+', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testParseAmountTypeColumnStillWinsOverTrailingMinus(): void {
		// #333's rule is unchanged: an explicit type column decides.
		$row = ['2024-01-01', '91,29-', 'Income', 'Test'];
		$mapping = ['date' => 0, 'amount' => 1, 'type' => 2, 'description' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(91.29, $result['amount'], 0.001);
		$this->assertSame('credit', $result['type']);
	}

	public function testParseAmountDualColumnParenthesesAreNotZero(): void {
		// The dual-column path skips a column that parses to zero; a
		// parenthesised value must not be mistaken for one.
		$row = ['2024-01-01', '', '(75.00)', 'Test'];
		$mapping = [
			'date' => 0,
			'incomeColumn' => 1,
			'expenseColumn' => 2,
			'description' => 3,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$this->assertEqualsWithDelta(75.00, $result['amount'], 0.001);
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

	// ── mapOfxTransaction column mapping (#338) ─────────────────────

	// #338: the user's column mapping was dropped on the floor for OFX/QIF,
	// so "description = Memo" did nothing. Banks that put the real payee in
	// <MEMO> could not be imported usefully.
	public function testMapOfxTransactionHonoursDescriptionMapping(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POINT OF SALE PURCHASE',
			'memo' => 'SOBEYS #4471 HALIFAX NS',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => 'memo']);

		$this->assertSame('SOBEYS #4471 HALIFAX NS', $result['description']);
	}

	public function testMapOfxTransactionHonoursVendorAndReferenceMapping(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'checkNumber' => '000812',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, [
			'vendor' => 'memo',
			'reference' => 'checkNumber',
		]);

		$this->assertSame('SOBEYS #4471', $result['vendor']);
		$this->assertSame('000812', $result['reference']);
	}

	// An empty mapping must be byte-identical to the no-argument call, or
	// every existing import silently changes shape.
	public function testMapOfxTransactionEmptyMappingMatchesLegacyOutput(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'reference' => 'REF9',
			'id' => 'FIT1',
		];

		$this->assertSame(
			$this->normalizer->mapOfxTransaction($txn),
			$this->normalizer->mapOfxTransaction($txn, [])
		);
	}

	// QIF rows run through this same mapper but carry different keys, and the
	// QIF column list advertises names the parser never emits. An unresolvable
	// source column must fall back to the default, never blank the field.
	public function testMapOfxTransactionUnknownSourceColumnFallsBackToDefault(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'GROCERY STORE',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => 'payee']);

		$this->assertSame('GROCERY STORE', $result['description']);
	}

	// A row whose chosen source is empty falls back rather than importing blank.
	public function testMapOfxTransactionMappedSourceEmptyFallsBackToDefault(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'GROCERY STORE',
			'memo' => '   ',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => 'memo']);

		$this->assertSame('GROCERY STORE', $result['description']);
	}

	// ── mapRowToTransaction notes mapping (#340) ────────────────────

	// The whole point of #340: a CSV column pointed at Notes has to reach the
	// stored transaction. Nothing covered the CSV side of this.
	public function testMapRowKeepsMappedNotesColumn(): void {
		$row = ['2026-08-04', '-12.34', 'Coffee shop', 'Verwendungszweck: Kartenzahlung'];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'notes' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Verwendungszweck: Kartenzahlung', $result['notes']);
		$this->assertSame('Coffee shop', $result['description']);
	}

	public function testMapRowTrimsMappedTextColumns(): void {
		$row = ['2026-08-04', '-12.34', ' Coffee shop ', "  padded note \n", '  ACME  ', ' REF-9 '];
		$mapping = [
			'date' => 0, 'amount' => 1, 'description' => 2,
			'notes' => 3, 'vendor' => 4, 'reference' => 5,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Coffee shop', $result['description']);
		$this->assertSame('padded note', $result['notes']);
		$this->assertSame('ACME', $result['vendor']);
		$this->assertSame('REF-9', $result['reference']);
	}

	// A mapped column whose cell is blank must store null, like every other
	// creation path, rather than an empty string.
	public function testMapRowBlankMappedTextColumnBecomesNull(): void {
		$row = ['2026-08-04', '-12.34', 'Coffee shop', '   ', '', ''];
		$mapping = [
			'date' => 0, 'amount' => 1, 'description' => 2,
			'notes' => 3, 'vendor' => 4, 'reference' => 5,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertNull($result['notes']);
		$this->assertNull($result['vendor']);
		$this->assertNull($result['reference']);
	}

	// #340: the CSV path never clamped. Over-long notes imported fine and then
	// failed ValidationService on the first edit, leaving an uneditable row;
	// over-long vendor/reference blew up the insert on MySQL/Postgres.
	public function testMapRowClampsOverlongTextToColumnWidths(): void {
		$row = [
			'2026-08-04',
			'-12.34',
			str_repeat('d', 1500),
			str_repeat('n', 2500),
			str_repeat('v', 400),
			str_repeat('r', 150),
		];
		$mapping = [
			'date' => 0, 'amount' => 1, 'description' => 2,
			'notes' => 3, 'vendor' => 4, 'reference' => 5,
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame(1000, mb_strlen($result['description']));
		$this->assertSame(2000, mb_strlen($result['notes']));
		$this->assertSame(255, mb_strlen($result['vendor']));
		$this->assertSame(100, mb_strlen($result['reference']));
	}

	// ── import-ID stability across the #340 cleaning ────────────────

	// The CSV import ID is md5(date.amount.description.reference). Trimming a
	// padded reference column would re-key every row, so a user re-importing
	// their statement after upgrading would get a second copy of all of it.
	public function testMapRowImportIdIgnoresTheNewTrimming(): void {
		$row = ['2026-08-04', '-12.34', 'Coffee shop', '  REF-9  '];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'reference' => 3];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$legacy = 'hash_' . md5('2026-08-04' . 12.34 . 'Coffee shop' . '  REF-9  ');

		$this->assertSame('REF-9', $result['reference']);
		$this->assertSame($legacy, $this->normalizer->generateImportId('f', 0, $result));
	}

	// Same guarantee for a description long enough to be truncated.
	public function testMapRowImportIdIgnoresTheNewDescriptionClamp(): void {
		$description = str_repeat('d', 1500);
		$row = ['2026-08-04', '-12.34', $description];
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);
		$legacy = 'hash_' . md5('2026-08-04' . 12.34 . $description . '');

		$this->assertSame(1000, mb_strlen($result['description']));
		$this->assertSame($legacy, $this->normalizer->generateImportId('f', 0, $result));
	}

	// An unmapped reference must keep hashing as the empty string, and a
	// mapped-but-blank one must not start hashing as null.
	public function testMapRowImportIdUnchangedForUnmappedAndBlankReference(): void {
		$mapping = ['date' => 0, 'amount' => 1, 'description' => 2, 'reference' => 3];
		$legacy = 'hash_' . md5('2026-08-04' . 12.34 . 'Coffee shop' . '');

		$unmapped = $this->normalizer->mapRowToTransaction(
			['2026-08-04', '-12.34', 'Coffee shop'],
			['date' => 0, 'amount' => 1, 'description' => 2]
		);
		$blank = $this->normalizer->mapRowToTransaction(['2026-08-04', '-12.34', 'Coffee shop', ''], $mapping);

		$this->assertSame($legacy, $this->normalizer->generateImportId('f', 0, $unmapped));
		$this->assertSame($legacy, $this->normalizer->generateImportId('f', 0, $blank));
	}

	// OFX rows reach generateImportId through ofxImportIdentity(), which
	// carries no _hash* keys — that path must be untouched.
	public function testGenerateImportIdStillHashesPlainDescriptionAndReference(): void {
		$identity = [
			'date' => '2026-08-04',
			'amount' => 12.34,
			'description' => 'Coffee shop',
			'reference' => 'REF-9',
			'id' => null,
		];

		$this->assertSame(
			'hash_' . md5('2026-08-04' . 12.34 . 'Coffee shop' . 'REF-9'),
			$this->normalizer->generateImportId('f', 0, $identity)
		);
	}

	// ── clampTransactionText (#340) ─────────────────────────────────

	// ImportService calls this again just before the insert, because an import
	// rule's "append to notes" action runs after the mapping clamp.
	public function testClampTransactionTextTruncatesEveryTextField(): void {
		$result = $this->normalizer->clampTransactionText([
			'description' => str_repeat('d', 1200),
			'notes' => str_repeat('n', 3000),
			'vendor' => str_repeat('v', 300),
			'reference' => str_repeat('r', 120),
			'amount' => 12.34,
		]);

		$this->assertSame(1000, mb_strlen($result['description']));
		$this->assertSame(2000, mb_strlen($result['notes']));
		$this->assertSame(255, mb_strlen($result['vendor']));
		$this->assertSame(100, mb_strlen($result['reference']));
		$this->assertSame(12.34, $result['amount']);
	}

	public function testClampTransactionTextLeavesNullAndShortValuesAlone(): void {
		$result = $this->normalizer->clampTransactionText([
			'description' => 'Coffee shop',
			'notes' => null,
			'vendor' => 'ACME',
		]);

		$this->assertSame('Coffee shop', $result['description']);
		$this->assertNull($result['notes']);
		$this->assertSame('ACME', $result['vendor']);
		$this->assertArrayNotHasKey('reference', $result);
	}

	// ── mapOfxTransaction memo persistence (#338) ───────────────────

	// #338: <MEMO> was parsed, shown in the preview, then discarded — create()
	// is handed $transaction['notes'], which nothing ever populated.
	public function testMapOfxTransactionKeepsMemoAsNotes(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POINT OF SALE PURCHASE',
			'memo' => 'SOBEYS #4471 HALIFAX NS',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertSame('SOBEYS #4471 HALIFAX NS', $result['notes']);
	}

	public function testMapOfxTransactionNotesNullWhenNoMemo(): void {
		$txn = ['date' => '2026-07-03', 'rawAmount' => -42.17, 'description' => 'X', 'id' => 'F'];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertNull($result['notes']);
	}

	// Storing the same string in both fields is never useful.
	public function testMapOfxTransactionDoesNotRepeatMemoInNotesWhenItIsTheDescription(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POINT OF SALE PURCHASE',
			'memo' => 'SOBEYS #4471 HALIFAX NS',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => 'memo']);

		$this->assertSame('SOBEYS #4471 HALIFAX NS', $result['description']);
		$this->assertNull($result['notes']);
	}

	public function testMapOfxTransactionHonoursNotesMapping(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'transactionType' => 'POS',
			'id' => 'FIT1',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['notes' => 'transactionType']);

		$this->assertSame('POS', $result['notes']);
	}

	// ── mapOfxTransaction blank NAME fallback (#338) ────────────────

	// OfxParser writes '' (not null) when <NAME> is absent, so the old
	// `?? $txn['name']` chain could never fire and the row imported blank.
	public function testMapOfxTransactionFallsBackToMemoWhenNameIsEmpty(): void {
		$txn = [
			'date' => '2026-07-05',
			'rawAmount' => -118.40,
			'description' => '',
			'memo' => 'NOVA SCOTIA POWER BILL PMT',
			'id' => 'FIT2',
		];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertSame('NOVA SCOTIA POWER BILL PMT', $result['description']);
		$this->assertSame('NOVA SCOTIA POWER BILL PMT', $result['vendor']);
		$this->assertNull($result['notes']);
	}

	public function testMapOfxTransactionStillBlankWhenNeitherNameNorMemo(): void {
		$txn = ['date' => '2026-07-05', 'rawAmount' => -10.0, 'description' => '', 'id' => 'F'];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertSame('', $result['description']);
	}

	// ── mapOfxTransaction length clamping (#338) ────────────────────

	// vendor is VARCHAR(255) and reference VARCHAR(100); routing a long MEMO
	// into either would throw on insert and lose the row behind a per-row error.
	public function testMapOfxTransactionClampsVendorAndReferenceToColumnWidths(): void {
		$long = str_repeat('A', 400);
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -1.0,
			'description' => 'X',
			'memo' => $long,
			'id' => 'F',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, [
			'vendor' => 'memo',
			'reference' => 'memo',
		]);

		$this->assertSame(255, mb_strlen($result['vendor']));
		$this->assertSame(100, mb_strlen($result['reference']));
	}

	public function testMapOfxTransactionClampsNotesToValidationLimit(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -1.0,
			'description' => 'X',
			'memo' => str_repeat('B', 2500),
			'id' => 'F',
		];

		$result = $this->normalizer->mapOfxTransaction($txn);

		$this->assertSame(2000, mb_strlen($result['notes']));
	}

	// ── ofxImportIdentity (#338 dedup safety) ───────────────────────

	// The import id is derived from description + reference. If the user's
	// mapping fed that derivation, re-importing a statement after changing the
	// mapping would re-key every row and import the whole file a second time.
	public function testOfxImportIdentityIsUnaffectedByMapping(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'reference' => 'REF9',
		];

		$identity = $this->normalizer->ofxImportIdentity($txn);

		$this->assertSame('POS PURCHASE', $identity['description']);
		$this->assertSame('REF9', $identity['reference']);
	}

	// The blank-NAME fallback must not leak into the identity either.
	public function testOfxImportIdentityKeepsEmptyDescriptionWhenNameAbsent(): void {
		$txn = ['date' => '2026-07-05', 'rawAmount' => -118.40, 'description' => '', 'memo' => 'POWER BILL'];

		$identity = $this->normalizer->ofxImportIdentity($txn);

		$this->assertSame('', $identity['description']);
	}

	public function testImportIdIsIdenticalAcrossEveryDescriptionMapping(): void {
		// No FITID, so generateImportId takes the content-hash branch — the
		// only branch a mapping could disturb.
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'reference' => 'REF9',
		];

		$baseline = $this->normalizer->generateImportId('f', 0, $this->normalizer->ofxImportIdentity($txn));

		foreach ([[], ['description' => 'memo'], ['description' => 'description'], ['notes' => 'memo']] as $mapping) {
			$this->normalizer->mapOfxTransaction($txn, $mapping);
			$this->assertSame(
				$baseline,
				$this->normalizer->generateImportId('f', 0, $this->normalizer->ofxImportIdentity($txn)),
				'import id changed under mapping ' . json_encode($mapping)
			);
		}
	}

	// The identity must reproduce today's ids exactly, not merely be stable.
	public function testOfxImportIdentityReproducesLegacyImportId(): void {
		$txn = [
			'date' => '2026-07-03',
			'rawAmount' => -42.17,
			'description' => 'POS PURCHASE',
			'memo' => 'SOBEYS #4471',
			'reference' => 'REF9',
		];

		$legacy = 'hash_' . md5('2026-07-03' . 42.17 . 'POS PURCHASE' . 'REF9');

		$this->assertSame(
			$legacy,
			$this->normalizer->generateImportId('f', 0, $this->normalizer->ofxImportIdentity($txn))
		);
	}

	public function testOfxImportIdentityPrefersFitId(): void {
		$txn = ['date' => '2026-07-03', 'rawAmount' => -42.17, 'description' => 'X', 'id' => 'FIT1'];

		$this->assertSame(
			'ofx_fitid_FIT1',
			$this->normalizer->generateImportId('f', 0, $this->normalizer->ofxImportIdentity($txn))
		);
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

	// ── Multi-Column Concatenation ──────────────────────────────────

	public function testMapRowConcatenatesMultipleColumnsWithComma(): void {
		$row = [
			'date' => '2026-08-11',
			'amount' => '100.00',
			'merchant' => 'Supermarket',
			'details' => 'Weekly Groceries',
		];
		$mapping = [
			'date' => 'date',
			'amount' => 'amount',
			'description' => ['merchant', 'details'],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Supermarket, Weekly Groceries', $result['description']);
	}

	public function testMapRowConcatenatesMultipleTextColumnsSkipsEmptyValues(): void {
		$row = [
			'date' => '2026-08-11',
			'amount' => '50.00',
			'shop' => 'Coffee Shop',
			'location' => '   ',
			'branch' => 'Downtown Branch',
		];
		$mapping = [
			'date' => 'date',
			'amount' => 'amount',
			'description' => ['shop', 'location', 'branch'],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Coffee Shop, Downtown Branch', $result['description']);
	}

	public function testMapRowConcatenatesNotesVendorReference(): void {
		$row = [
			'date' => '2026-08-11',
			'amount' => '75.00',
			'description' => 'Main Store',
			'note_1' => 'Note Line 1',
			'note_2' => 'Note Line 2',
			'vendor_1' => 'Vendor Name',
			'vendor_2' => 'Vendor Code',
			'reference_1' => 'Ref Part 1',
			'reference_2' => 'Ref Part 2',
		];
		$mapping = [
			'date' => 'date',
			'amount' => 'amount',
			'description' => 'description',
			'notes' => ['note_1', 'note_2'],
			'vendor' => ['vendor_1', 'vendor_2'],
			'reference' => ['reference_1', 'reference_2'],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Note Line 1, Note Line 2', $result['notes']);
		$this->assertSame('Vendor Name, Vendor Code', $result['vendor']);
		$this->assertSame('Ref Part 1, Ref Part 2', $result['reference']);
	}

	public function testMapRowIgnoresArrayMappingForNonTextField(): void {
		$row = ['2026-08-11', '40.00', 'Transaction', 'Income', 'Expense'];
		$mapping = [
			'date' => 0,
			'amount' => 1,
			'description' => 2,
			'type' => [3, 4],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('credit', $result['type']);
		// The list was dropped, not read as a mapped-but-blank type column.
		$this->assertArrayNotHasKey('_typeUnresolved', $result);
	}

	// A list on a field that is read straight off the row (category, account,
	// currency, income/expense) must be ignored, not throw on the array offset.
	public function testMapRowIgnoresArrayMappingForDirectlyReadFields(): void {
		$row = ['date' => '2026-08-11', 'amount' => '40.00', 'desc' => 'Shop', 'cat' => 'Food', 'acc' => 'Main'];
		$mapping = [
			'date' => 'date',
			'amount' => 'amount',
			'description' => 'desc',
			'category' => ['cat'],
			'account' => ['acc'],
			'currency' => ['cur'],
			'incomeColumn' => ['amount'],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Shop', $result['description']);
		$this->assertArrayNotHasKey('_categoryName', $result);
		$this->assertArrayNotHasKey('_accountName', $result);
		$this->assertArrayNotHasKey('_currency', $result);
	}

	// The checklist sends every selection as a list, one column included. A
	// one-column list has to map through the scalar path so the #340 hash
	// freeze still sees the raw cell and the import id does not change.
	public function testMapRowSingleColumnListKeepsLegacyImportId(): void {
		$row = ['date' => '2026-08-04', 'amt' => '-12.34', 'desc' => 'Coffee shop', 'ref' => '  CHQ 1042'];
		$scalar = ['date' => 'date', 'amount' => 'amt', 'description' => 'desc', 'reference' => 'ref'];
		$list = ['date' => 'date', 'amount' => 'amt', 'description' => ['desc'], 'reference' => ['ref']];

		$fromScalar = $this->normalizer->mapRowToTransaction($row, $scalar);
		$fromList = $this->normalizer->mapRowToTransaction($row, $list);

		$this->assertSame('CHQ 1042', $fromList['reference']);
		$this->assertSame(
			$this->normalizer->generateImportId('f', 0, $fromScalar),
			$this->normalizer->generateImportId('f', 0, $fromList)
		);
		$this->assertSame('hash_' . md5('2026-08-04' . 12.34 . 'Coffee shop' . '  CHQ 1042'), $this->normalizer->generateImportId('f', 0, $fromList));
	}

	// Same for a whitespace-only cell: scalar path hashes the raw '   '.
	public function testMapRowSingleColumnListKeepsLegacyImportIdForBlankCell(): void {
		$row = ['date' => '2026-08-04', 'amt' => '-12.34', 'desc' => 'Coffee shop', 'ref' => '   '];
		$scalar = ['date' => 'date', 'amount' => 'amt', 'description' => 'desc', 'reference' => 'ref'];
		$list = ['date' => 'date', 'amount' => 'amt', 'description' => 'desc', 'reference' => ['ref']];

		$this->assertSame(
			$this->normalizer->generateImportId('f', 0, $this->normalizer->mapRowToTransaction($row, $scalar)),
			$this->normalizer->generateImportId('f', 0, $this->normalizer->mapRowToTransaction($row, $list))
		);
	}

	// Joined in the order the columns appear in the file, whatever order they
	// were ticked in, so the content hash does not depend on click order.
	public function testMapRowJoinsColumnsInFileOrderRegardlessOfMappingOrder(): void {
		$row = ['date' => '2026-08-11', 'amount' => '100.00', 'merchant' => 'Supermarket', 'details' => 'Weekly Groceries'];
		$mapping = ['date' => 'date', 'amount' => 'amount', 'description' => ['details', 'merchant']];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Supermarket, Weekly Groceries', $result['description']);
		$this->assertSame(
			$this->normalizer->generateImportId('f', 0, $this->normalizer->mapRowToTransaction($row, ['date' => 'date', 'amount' => 'amount', 'description' => ['merchant', 'details']])),
			$this->normalizer->generateImportId('f', 0, $result)
		);
	}

	public function testMapRowJoinedColumnsDeduplicatedAndTrimmed(): void {
		$row = ['date' => '2026-08-11', 'amount' => '100.00', 'merchant' => 'Supermarket', 'details' => 'Weekly Groceries'];
		$mapping = ['date' => 'date', 'amount' => 'amount', 'description' => [' merchant', 'details', 'merchant ', '', null, false]];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Supermarket, Weekly Groceries', $result['description']);
	}

	public function testMapRowAllJoinedColumnsBlankLeavesFieldUnset(): void {
		$row = ['date' => '2026-08-11', 'amount' => '100.00', 'desc' => 'Shop', 'a' => '', 'b' => '  '];
		$mapping = ['date' => 'date', 'amount' => 'amount', 'description' => 'desc', 'notes' => ['a', 'b']];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertArrayNotHasKey('notes', $result);
	}

	// ── normalizeMapping ─────────────────────────────────────────────

	public function testNormalizeMappingShapes(): void {
		$this->assertSame(
			['date' => 'Date', 'description' => 'Memo', 'notes' => ['A', 'B'], 'skipFirstRow' => true],
			TransactionNormalizer::normalizeMapping([
				'date' => 'Date',
				'description' => ['Memo'],
				'notes' => [' A', 'B ', 'A', '', null, ['nested'], false],
				'vendor' => ['', '  '],
				'type' => ['T1', 'T2'],
				'category' => ['Cat'],
				'skipFirstRow' => true,
			])
		);
	}

	// ── mapOfxTransaction with joined columns ────────────────────────

	public function testMapOfxTransactionJoinsColumnsInSourceOrder(): void {
		$txn = [
			'date' => '2024-03-15',
			'rawAmount' => -42.50,
			'description' => 'COFFEE SHOP',
			'name' => 'Cafe Ltd',
			'memo' => 'Card 1234',
			'id' => 'FITID456',
		];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => ['memo', 'name'], 'notes' => ['memo', 'missing']]);

		$this->assertSame('Cafe Ltd, Card 1234', $result['description']);
		$this->assertSame('Card 1234', $result['notes']);
	}

	public function testMapOfxTransactionJoinedColumnsAllMissingFallsBackToDefaults(): void {
		$txn = ['date' => '2024-03-15', 'rawAmount' => -42.50, 'description' => 'COFFEE SHOP', 'memo' => '  ', 'id' => 'F1'];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => ['nope', 'memo'], 'notes' => ['memo', 'nope']]);

		$this->assertSame('COFFEE SHOP', $result['description']);
		$this->assertNull($result['notes']);
	}

	public function testMapOfxTransactionJoinedNotesEqualToDescriptionAreDropped(): void {
		$txn = ['date' => '2024-03-15', 'rawAmount' => -1, 'name' => 'A', 'memo' => 'B', 'id' => 'F1'];

		$result = $this->normalizer->mapOfxTransaction($txn, ['description' => ['name', 'memo'], 'notes' => ['name', 'memo']]);

		$this->assertSame('A, B', $result['description']);
		$this->assertNull($result['notes']);
	}

	public function testMapRowArrayMappingWithSingleColumn(): void {
		$row = [
			'date' => '2026-08-11',
			'amount' => '25.00',
			'payee' => 'Single Payee',
		];
		$mapping = [
			'date' => 'date',
			'amount' => 'amount',
			'description' => ['payee'],
		];

		$result = $this->normalizer->mapRowToTransaction($row, $mapping);

		$this->assertSame('Single Payee', $result['description']);
	}
}
