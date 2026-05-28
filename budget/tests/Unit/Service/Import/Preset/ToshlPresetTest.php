<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Preset;

use OCA\Budget\Service\Import\Preset\ToshlPreset;
use PHPUnit\Framework\TestCase;

class ToshlPresetTest extends TestCase {
	private ToshlPreset $preset;

	protected function setUp(): void {
		$this->preset = new ToshlPreset();
	}

	// ===== Basic Getters =====

	public function testGetIdReturnsToshl(): void {
		$this->assertSame('toshl', $this->preset->getId());
	}

	public function testGetNameReturnsToshlFinance(): void {
		$this->assertSame('Toshl Finance', $this->preset->getName());
	}

	public function testGetDescriptionReturnsExpectedString(): void {
		$this->assertSame(
			'Import expenses, income, and categories from Toshl Finance CSV export',
			$this->preset->getDescription()
		);
	}

	// ===== Mapping =====

	public function testGetMappingReturnsExpectedKeys(): void {
		$mapping = $this->preset->getMapping();
		$this->assertSame('Date', $mapping['date']);
		$this->assertSame('Description', $mapping['description']);
		$this->assertSame('Expense', $mapping['expenseColumn']);
		$this->assertSame('Income', $mapping['incomeColumn']);
	}

	public function testGetMappingHasExactlyFourKeys(): void {
		$mapping = $this->preset->getMapping();
		$this->assertCount(4, $mapping);
	}

	// ===== Format Options =====

	public function testGetDateFormatHintReturnsDotSeparated(): void {
		$this->assertSame('d.m.y', $this->preset->getDateFormatHint());
	}

	public function testGetDelimiterReturnsComma(): void {
		$this->assertSame(',', $this->preset->getDelimiter());
	}

	// ===== Options =====

	public function testGetOptionsHasExpectedKeys(): void {
		$options = $this->preset->getOptions();
		$expectedKeys = ['autoCreateCategories', 'categoryColumn', 'tagColumn', 'accountColumn', 'transferMarker'];
		foreach ($expectedKeys as $key) {
			$this->assertArrayHasKey($key, $options, "Options should contain key '$key'");
		}
	}

	public function testGetOptionsAutoCreateCategoriesIsTrue(): void {
		$options = $this->preset->getOptions();
		$this->assertTrue($options['autoCreateCategories']);
	}

	public function testGetOptionsTransferMarkerIsTransaction(): void {
		$options = $this->preset->getOptions();
		$this->assertSame('transaction', $options['transferMarker']);
	}

	// ===== Expected Headers =====

	public function testGetExpectedHeadersReturnsCorrectColumns(): void {
		$headers = $this->preset->getExpectedHeaders();
		$this->assertIsArray($headers);
		$expected = [
			'Date', 'Account', 'Category', 'Tags', 'Expense',
			'Income', 'Currency', 'In Main Currency', 'Main Currency', 'Description',
		];
		$this->assertSame($expected, $headers);
	}

	public function testGetExpectedHeadersCountIsTen(): void {
		$headers = $this->preset->getExpectedHeaders();
		$this->assertCount(10, $headers);
	}

	// ===== postProcessRow: Normal Row =====

	public function testPostProcessRowAttachesCategoryMetadata(): void {
		$normalizedRow = ['amount' => 10.0, 'description' => 'Coffee'];
		$rawCsvRow = [
			'Category' => 'Food & Drink',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertNotNull($result);
		$this->assertSame('Food & Drink', $result['_categoryName']);
	}

	public function testPostProcessRowAttachesTagNames(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => 'lunch, work',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame(['lunch', 'work'], $result['_tagNames']);
	}

	public function testPostProcessRowAttachesAccountName(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => 'My Checking',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame('My Checking', $result['_accountName']);
	}

	public function testPostProcessRowSetsSourceToToshl(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame('Toshl', $result['source']);
	}

	// ===== postProcessRow: Transfer Rows =====

	public function testPostProcessRowReturnsNullForTransactionCategory(): void {
		$normalizedRow = ['amount' => 50.0];
		$rawCsvRow = [
			'Category' => 'transaction',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertNull($result, 'Transfer rows with category "transaction" should be skipped');
	}

	public function testPostProcessRowReturnsNullForTransactionCategoryUppercase(): void {
		$normalizedRow = ['amount' => 50.0];
		$rawCsvRow = [
			'Category' => 'Transaction',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertNull($result, 'Transfer detection should be case-insensitive');
	}

	// ===== postProcessRow: Currency Conversion =====

	public function testPostProcessRowUsesMainCurrencyWhenDifferent(): void {
		$normalizedRow = ['amount' => 100.0];
		$rawCsvRow = [
			'Category' => 'Travel',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'EUR',
			'Main Currency' => 'USD',
			'In Main Currency' => '1.234,56',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame('USD', $result['_currency']);
		// "1.234,56" -> remove '.' -> "1234,56" -> replace ',' with '.' -> "1234.56" -> abs = 1234.56
		$this->assertEqualsWithDelta(1234.56, $result['amount'], 0.001);
	}

	public function testPostProcessRowPreservesCurrencyWhenSame(): void {
		$normalizedRow = ['amount' => 25.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'EUR',
			'Main Currency' => 'EUR',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame('EUR', $result['_currency']);
		$this->assertSame(25.0, $result['amount'], 'Original amount should be preserved when currencies match');
	}

	public function testPostProcessRowFallsBackToTxCurrencyWhenMainCurrencyEmpty(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'GBP',
			'Main Currency' => '',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertSame('GBP', $result['_currency']);
	}

	public function testPostProcessRowKeepsOriginalWhenMainAmountIsZero(): void {
		$normalizedRow = ['amount' => 50.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'JPY',
			'Main Currency' => 'USD',
			'In Main Currency' => '0',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		// When main amount is '0', it falls through to set _currency = txCurrency
		$this->assertSame('JPY', $result['_currency']);
		$this->assertSame(50.0, $result['amount']);
	}

	// ===== postProcessRow: Empty Metadata =====

	public function testPostProcessRowOmitsCategoryWhenEmpty(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => '',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertArrayNotHasKey('_categoryName', $result);
	}

	public function testPostProcessRowOmitsTagsWhenEmpty(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertArrayNotHasKey('_tagNames', $result);
	}

	public function testPostProcessRowOmitsAccountWhenEmpty(): void {
		$normalizedRow = ['amount' => 10.0];
		$rawCsvRow = [
			'Category' => 'Food',
			'Tags' => '',
			'Account' => '',
			'Currency' => 'USD',
			'Main Currency' => 'USD',
			'In Main Currency' => '',
		];

		$result = $this->preset->postProcessRow($normalizedRow, $rawCsvRow);
		$this->assertArrayNotHasKey('_accountName', $result);
	}

	// ===== inferAccountType: Exact Matches =====

	/**
	 * @dataProvider exactMatchProvider
	 */
	public function testInferAccountTypeExactMatch(string $input, string $expected): void {
		$this->assertSame($expected, $this->preset->inferAccountType($input));
	}

	public static function exactMatchProvider(): array {
		return [
			'cash' => ['cash', 'cash'],
			'checking' => ['checking', 'checking'],
			'savings' => ['savings', 'savings'],
			'investment' => ['investment', 'investment'],
			'credit card' => ['credit card', 'credit_card'],
			'credit' => ['credit', 'credit_card'],
			'loan' => ['loan', 'loan'],
			'mortgage' => ['mortgage', 'mortgage'],
			'crypto' => ['crypto', 'cryptocurrency'],
			'bitcoin' => ['bitcoin', 'cryptocurrency'],
			'line of credit' => ['line of credit', 'line_of_credit'],
		];
	}

	public function testInferAccountTypeIsCaseInsensitive(): void {
		$this->assertSame('cash', $this->preset->inferAccountType('CASH'));
		$this->assertSame('credit_card', $this->preset->inferAccountType('Credit Card'));
		$this->assertSame('savings', $this->preset->inferAccountType('SAVINGS'));
	}

	// ===== inferAccountType: Partial Matches =====

	public function testInferAccountTypePartialMatchContainsKeyword(): void {
		$this->assertSame('savings', $this->preset->inferAccountType('My Savings Account'));
		$this->assertSame('checking', $this->preset->inferAccountType('Primary Checking'));
		$this->assertSame('credit_card', $this->preset->inferAccountType('Visa Credit Card'));
		$this->assertSame('cryptocurrency', $this->preset->inferAccountType('Bitcoin Wallet'));
		$this->assertSame('cash', $this->preset->inferAccountType('Petty Cash'));
	}

	// ===== inferAccountType: Unknown Defaults =====

	public function testInferAccountTypeReturnsCheckingForUnknown(): void {
		$this->assertSame('checking', $this->preset->inferAccountType('My Bank'));
		$this->assertSame('checking', $this->preset->inferAccountType('Main Account'));
		$this->assertSame('checking', $this->preset->inferAccountType('Wallet'));
	}

	public function testInferAccountTypeTrimsWhitespace(): void {
		$this->assertSame('cash', $this->preset->inferAccountType('  cash  '));
	}
}
