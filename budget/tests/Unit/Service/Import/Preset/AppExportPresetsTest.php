<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Preset;

use OCA\Budget\Service\Import\Preset\ActualBudgetPreset;
use OCA\Budget\Service\Import\Preset\FireflyIIIPreset;
use OCA\Budget\Service\Import\Preset\HeaderMappedPresetInterface;
use OCA\Budget\Service\Import\Preset\MintPreset;
use OCA\Budget\Service\Import\Preset\MonarchMoneyPreset;
use OCA\Budget\Service\Import\Preset\YnabPreset;
use PHPUnit\Framework\TestCase;

/**
 * Row-level behaviour of the app-export presets. The end-to-end import of
 * each app's fixture file lives in AppExportImportTest.
 */
class AppExportPresetsTest extends TestCase {
	/**
	 * @return array<string, array{0: HeaderMappedPresetInterface}>
	 */
	public static function presetProvider(): array {
		return [
			'firefly' => [new FireflyIIIPreset()],
			'ynab' => [new YnabPreset()],
			'actual' => [new ActualBudgetPreset()],
			'mint' => [new MintPreset()],
			'monarch' => [new MonarchMoneyPreset()],
		];
	}

	/**
	 * @dataProvider presetProvider
	 */
	public function testEveryPresetIsHeaderMappedWithAnAccountColumn(HeaderMappedPresetInterface $preset): void {
		$this->assertNull($preset->getExpectedHeaders(), 'Read by name, never by position');
		$this->assertNotEmpty($preset->getRequiredHeaders());
		$this->assertNotEmpty($preset->getOptions()['accountColumn']);
		$this->assertTrue($preset->getOptions()['autoCreateCategories']);
		// Every mapped column is one the file is required to have, or blank
		// optional text (notes), so the mapping can never read a missing column
		// for a required field
		$required = array_map('strtolower', $preset->getRequiredHeaders());
		$this->assertContains(strtolower($preset->getMapping()['date']), $required);
	}

	/**
	 * @dataProvider presetProvider
	 */
	public function testRowsAreStampedWithTheirSourceAndAFrozenIdentity(HeaderMappedPresetInterface $preset): void {
		$raw = array_fill_keys($preset->getRequiredHeaders(), '');
		$raw = array_merge($raw, ['Date' => '01/02/2024', 'date' => '2024-02-01T00:00:00+00:00', 'journal_id' => '7', 'type' => 'Withdrawal', 'source_type' => 'Asset account', 'destination_type' => 'Expense account']);
		$row = $preset->postProcessRow(['date' => '2024-02-01', 'amount' => 1.0, 'type' => 'debit', 'description' => 'x'], $raw);

		$this->assertNotNull($row);
		$this->assertSame($preset->getName(), $row['source']);
		$this->assertArrayHasKey('_hashDate', $row);
		$this->assertArrayHasKey('_hashDescription', $row);
		$this->assertArrayHasKey('_hashReference', $row);
	}

	// ===== Firefly III =====

	private function fireflyRow(array $overrides): array {
		return array_merge([
			'journal_id' => '12',
			'type' => 'Withdrawal',
			'amount' => "'-10.00",
			'description' => 'Lunch',
			'date' => '2024-03-01T00:00:00+01:00',
			'source_name' => 'Checking',
			'source_type' => 'Asset account',
			'destination_name' => 'Cafe',
			'destination_type' => 'Expense account',
			'currency_code' => 'EUR',
			'category' => '',
			'tags' => '',
			'notes' => '',
		], $overrides);
	}

	public function testFireflyWithdrawalLeavesTheSourceAccount(): void {
		$preset = new FireflyIIIPreset();
		$rows = $preset->expandRow($this->fireflyRow([]));
		$this->assertCount(1, $rows);

		$row = $preset->postProcessRow(['amount' => 10.0, 'type' => 'debit', 'date' => '2024-02-29'], $rows[0]);
		$this->assertSame('Checking', $row['_accountName']);
		$this->assertSame('debit', $row['type']);
		$this->assertSame('Cafe', $row['vendor']);
		$this->assertSame('EUR', $row['_currency']);
		$this->assertSame('2024-03-01', $row['date'], 'The calendar date as written, not shifted to the server timezone');
		$this->assertSame('firefly:12', $row['_hashReference']);
	}

	public function testFireflyDepositLandsInTheDestinationAccount(): void {
		$preset = new FireflyIIIPreset();
		$raw = $this->fireflyRow([
			'type' => 'Deposit', 'amount' => '99.00',
			'source_name' => 'Employer', 'source_type' => 'Revenue account',
			'destination_name' => 'Checking', 'destination_type' => 'Asset account',
		]);
		$row = $preset->postProcessRow(['amount' => 99.0, 'type' => 'credit'], $preset->expandRow($raw)[0]);

		$this->assertSame('Checking', $row['_accountName']);
		$this->assertSame('credit', $row['type']);
		$this->assertSame('Employer', $row['vendor']);
	}

	public function testFireflyTransferExpandsIntoOneSidePerAccount(): void {
		$preset = new FireflyIIIPreset();
		$raw = $this->fireflyRow([
			'type' => 'Transfer', 'amount' => '250.00',
			'destination_name' => 'Savings', 'destination_type' => 'Asset account',
			'category' => 'Ignored', 'tags' => 'x',
		]);
		$rows = $preset->expandRow($raw);
		$this->assertCount(2, $rows);

		$out = $preset->postProcessRow(['amount' => 250.0], $rows[0]);
		$in = $preset->postProcessRow(['amount' => 250.0], $rows[1]);
		$this->assertSame(['Checking', 'debit', 'Savings'], [$out['_accountName'], $out['type'], $out['_transferPeer']]);
		$this->assertSame(['Savings', 'credit', 'Checking'], [$in['_accountName'], $in['type'], $in['_transferPeer']]);
		$this->assertTrue($out['_transfer']);
		$this->assertArrayNotHasKey('_categoryName', $out);
		$this->assertArrayNotHasKey('vendor', $out);
		// Same identity on both sides: they are keyed per account
		$this->assertSame($out['_hashReference'], $in['_hashReference']);
	}

	public function testFireflyLoanPaymentIsATransferIntoTheLiability(): void {
		$preset = new FireflyIIIPreset();
		$raw = $this->fireflyRow(['destination_name' => 'Car loan', 'destination_type' => 'Loan']);
		$rows = $preset->expandRow($raw);
		$this->assertCount(2, $rows);

		$in = $preset->postProcessRow(['amount' => 10.0], $rows[1]);
		$this->assertSame('loan', $in['_accountType']);
		$this->assertSame('credit', $in['type']);
	}

	public function testFireflyNegativeOpeningBalanceIsADebit(): void {
		$preset = new FireflyIIIPreset();
		$raw = $this->fireflyRow([
			'type' => 'Opening balance', 'amount' => "'-80.00",
			'destination_name' => 'Initial balance for "Checking"', 'destination_type' => 'Initial balance account',
		]);
		$row = $preset->postProcessRow(['amount' => 80.0], $preset->expandRow($raw)[0]);

		$this->assertSame('Checking', $row['_accountName']);
		$this->assertSame('debit', $row['type']);
		$this->assertArrayNotHasKey('vendor', $row);
	}

	public function testFireflyIdentityIgnoresTheEditableDescription(): void {
		$preset = new FireflyIIIPreset();
		$a = $preset->postProcessRow(['amount' => 10.0], $this->fireflyRow(['description' => 'Lunch']));
		$b = $preset->postProcessRow(['amount' => 10.0], $this->fireflyRow(['description' => 'Lunch with Sam']));

		$this->assertSame(
			[$a['_hashDate'], $a['_hashDescription'], $a['_hashReference']],
			[$b['_hashDate'], $b['_hashDescription'], $b['_hashReference']]
		);
	}

	// ===== YNAB =====

	public function testYnabTransferPayeeNamesTheOtherAccount(): void {
		$preset = new YnabPreset();
		foreach (['Transfer : Savings', 'Transfer:Savings', 'transfer : Savings'] as $payee) {
			$row = $preset->postProcessRow([], ['Account' => 'Checking', 'Payee' => $payee, 'Date' => '01/01/2024']);
			$this->assertTrue($row['_transfer'], $payee);
			$this->assertSame('Savings', $row['_transferPeer'], $payee);
		}
		$this->assertArrayNotHasKey('_transfer', $preset->postProcessRow([], ['Account' => 'Checking', 'Payee' => 'Transferwise', 'Date' => '01/01/2024']));
	}

	public function testYnabReadsTheCombinedCategoryWhenTheSplitColumnsAreBlank(): void {
		$preset = new YnabPreset();
		$row = $preset->postProcessRow([], [
			'Account' => 'Checking', 'Payee' => 'Shop', 'Date' => '01/01/2024',
			'Category Group/Category' => 'Fun: Games', 'Category Group' => '', 'Category' => '',
		]);
		$this->assertSame('Games', $row['_categoryName']);
		$this->assertSame('Fun', $row['_categoryParent']);
	}

	public function testYnabIncomeHoldingCategoriesAreLeftUncategorized(): void {
		$preset = new YnabPreset();
		foreach ([
			['Category Group' => 'Inflow', 'Category' => 'Ready to Assign'],
			['Category Group' => 'Inflow', 'Category' => 'To be Budgeted'],
			['Master Category' => 'Income', 'Sub Category' => 'Available next month', 'Category' => 'Income:Available next month'],
		] as $cols) {
			$row = $preset->postProcessRow([], ['Account' => 'A', 'Payee' => 'P', 'Date' => '1/1/2024'] + $cols);
			$this->assertArrayNotHasKey('_categoryName', $row);
		}
	}

	// ===== Actual Budget =====

	public function testActualDropsASplitTotalRow(): void {
		$preset = new ActualBudgetPreset();
		$total = ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => 'P', 'Notes' => '(SPLIT INTO 2)', 'Category' => '', 'Amount' => '0', 'Split_Amount' => '-10.00'];
		$this->assertSame([], $preset->expandRow($total));
		$this->assertNull($preset->postProcessRow([], $total));

		$part = ['Split_Amount' => '0', 'Amount' => '-4.00'] + $total;
		$this->assertCount(1, $preset->expandRow($part));
		// An old export has no Split_Amount column at all
		$old = $total;
		unset($old['Split_Amount']);
		$this->assertCount(1, $preset->expandRow($old));
	}

	public function testActualUncategorizedRowIsATransferCandidate(): void {
		$preset = new ActualBudgetPreset();
		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => 'Transfer: Savings', 'Notes' => '', 'Category' => '', 'Amount' => '5']);
		$this->assertSame('Savings', $row['_transferPeer']);

		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => '', 'Notes' => '', 'Category' => '', 'Amount' => '5']);
		$this->assertSame('', $row['_transferPeer'], 'Blank payee: the other account is unknown');

		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => 'Shop', 'Notes' => '', 'Category' => 'Food', 'Category_Group' => 'Living', 'Amount' => '5']);
		$this->assertArrayNotHasKey('_transfer', $row);
		$this->assertSame(['Food', 'Living'], [$row['_categoryName'], $row['_categoryParent']]);
	}

	// ===== Mint / Monarch =====

	public function testMintTransferCategoriesMarkATransferWithAnUnknownPeer(): void {
		$preset = new MintPreset();
		foreach (['Transfer', 'Credit Card Payment', 'transfer'] as $category) {
			$row = $preset->postProcessRow([], ['Account Name' => 'A', 'Date' => '1/1/2023', 'Category' => $category, 'Labels' => 'x']);
			$this->assertTrue($row['_transfer']);
			$this->assertSame('', $row['_transferPeer']);
			$this->assertArrayNotHasKey('_tagNames', $row);
		}
	}

	public function testMintIdentityUsesTheBanksOwnText(): void {
		$preset = new MintPreset();
		$a = $preset->postProcessRow([], ['Date' => '1/1/2023', 'Description' => 'Coffee', 'Original Description' => 'SQ *CAFE 123']);
		$b = $preset->postProcessRow([], ['Date' => '1/1/2023', 'Description' => 'Renamed in Mint', 'Original Description' => 'SQ *CAFE 123']);
		$this->assertSame('SQ *CAFE 123', $a['_hashDescription']);
		$this->assertSame($a['_hashDescription'], $b['_hashDescription']);
	}

	public function testMonarchTransferCategories(): void {
		$preset = new MonarchMoneyPreset();
		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Category' => 'Credit Card Payment', 'Merchant' => 'Amex']);
		$this->assertTrue($row['_transfer']);

		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Category' => 'Groceries', 'Merchant' => 'Shop', 'Tags' => 'a, b,a']);
		$this->assertSame('Groceries', $row['_categoryName']);
		$this->assertSame(['a', 'b'], $row['_tagNames']);
	}

	// ===== Shared =====

	/**
	 * @dataProvider accountTypeProvider
	 */
	public function testInferAccountType(string $name, string $expected): void {
		$this->assertSame($expected, (new MintPreset())->inferAccountType($name));
	}

	public static function accountTypeProvider(): array {
		return [
			['Everyday Checking', 'checking'],
			['Rainy Day Savings', 'savings'],
			['Cash ISA', 'savings'],
			['Visa Signature', 'credit_card'],
			['Amex Gold Card (...9876)', 'credit_card'],
			['HELOC Line of Credit', 'line_of_credit'],
			['Home Mortgage', 'mortgage'],
			['Student Loan', 'loan'],
			['Wallet cash', 'cash'],
			['Brokerage', 'investment'],
			['Joint', 'checking'],
			// Whole words only: "Cashback" is not a cash account
			['Cashback Rewards', 'checking'],
		];
	}

	public function testFormulaGuardIsRemovedFromText(): void {
		$preset = new ActualBudgetPreset();
		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => "'-Minus", 'Notes' => "'=x", 'Category' => 'C', 'Amount' => '1']);
		$this->assertSame('-Minus', $row['description']);
		$this->assertSame('=x', $row['notes']);

		// An apostrophe that is not a guard stays
		$row = $preset->postProcessRow([], ['Account' => 'A', 'Date' => '2024-01-01', 'Payee' => "'Tis the season", 'Notes' => '', 'Category' => 'C', 'Amount' => '1']);
		$this->assertSame("'Tis the season", $row['description']);
	}
}
