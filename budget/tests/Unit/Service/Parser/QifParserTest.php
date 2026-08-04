<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Parser;

use OCA\Budget\Service\Parser\QifParser;
use PHPUnit\Framework\TestCase;

class QifParserTest extends TestCase {
    private QifParser $parser;

    protected function setUp(): void {
        $this->parser = new QifParser();
    }

    /**
     * Sample QIF content with bank transactions.
     */
    private function getSampleBankQif(): string {
        return <<<'QIF'
!Type:Bank
D12/30/2025
T-134.39
PBARCLAYS PRTNR FIN
MMonthly payment
N1001
LBills:Utilities
^
D12/30/2025
T49.27
PEBAY COMMERCE UK L
MEbay sale refund
LIncome:Sales
^
D12/30/2025
T1.80
PTransfer from savings
L[Savings Account]
^
QIF;
    }

    /**
     * Sample QIF with credit card transactions.
     */
    private function getSampleCreditCardQif(): string {
        return <<<'QIF'
!Type:CCard
D12/15/2025
T-25.99
PAmazon.co.uk
MBook purchase
LShopping:Books
C*
^
D12/20/2025
T-150.00
PJohn Lewis
MChristmas gifts
LShopping:Gifts
Cx
^
QIF;
    }

    /**
     * Sample QIF with split transaction.
     */
    private function getSampleSplitQif(): string {
        return <<<'QIF'
!Type:Bank
D12/25/2025
T-200.00
PTesco
MGrocery shopping
SFood:Groceries
EWeekly shop
$-150.00
SHousehold:Cleaning
ECleaning supplies
$-50.00
^
QIF;
    }

    public function testParseReturnsAccountsArray(): void {
        $result = $this->parser->parse($this->getSampleBankQif());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('accounts', $result);
        $this->assertIsArray($result['accounts']);
    }

    public function testParseBankTransactions(): void {
        $result = $this->parser->parse($this->getSampleBankQif());

        $this->assertCount(1, $result['accounts']);
        $account = $result['accounts'][0];

        $this->assertEquals('bank', $account['type']);
        $this->assertCount(3, $account['transactions']);

        // First transaction - debit
        $debit = $account['transactions'][0];
        $this->assertEquals('2025-12-30', $debit['date']);
        $this->assertEquals(134.39, $debit['amount']);
        $this->assertEquals('debit', $debit['type']);
        $this->assertEquals('BARCLAYS PRTNR FIN', $debit['description']);
        $this->assertEquals('Monthly payment', $debit['memo']);
        $this->assertEquals('1001', $debit['reference']);
        $this->assertEquals('Bills', $debit['category']['name']);
        $this->assertEquals('Utilities', $debit['category']['subcategory']);

        // Second transaction - credit
        $credit = $account['transactions'][1];
        $this->assertEquals(49.27, $credit['amount']);
        $this->assertEquals('credit', $credit['type']);
        $this->assertEquals('EBAY COMMERCE UK L', $credit['description']);
    }

    public function testParseCreditCardTransactions(): void {
        $result = $this->parser->parse($this->getSampleCreditCardQif());

        $this->assertCount(1, $result['accounts']);
        $account = $result['accounts'][0];

        $this->assertEquals('credit_card', $account['type']);
        $this->assertCount(2, $account['transactions']);

        // Check cleared status
        $this->assertEquals('reconciled', $account['transactions'][0]['cleared']);
        $this->assertEquals('cleared', $account['transactions'][1]['cleared']);
    }

    public function testParseTransferCategory(): void {
        $result = $this->parser->parse($this->getSampleBankQif());

        $transfer = $result['accounts'][0]['transactions'][2];
        $this->assertTrue($transfer['category']['isTransfer']);
        $this->assertEquals('Savings Account', $transfer['category']['transferAccount']);
    }

    public function testParseSplitTransaction(): void {
        $result = $this->parser->parse($this->getSampleSplitQif());

        $transaction = $result['accounts'][0]['transactions'][0];
        $this->assertEquals(200.00, $transaction['amount']);
        $this->assertEquals('debit', $transaction['type']);
        $this->assertEquals('Tesco', $transaction['description']);

        $this->assertArrayHasKey('splits', $transaction);
        $this->assertCount(2, $transaction['splits']);

        // First split
        $this->assertEquals('Food', $transaction['splits'][0]['category']['name']);
        $this->assertEquals('Groceries', $transaction['splits'][0]['category']['subcategory']);
        $this->assertEquals(-150.00, $transaction['splits'][0]['amount']);

        // Second split
        $this->assertEquals('Household', $transaction['splits'][1]['category']['name']);
        $this->assertEquals(-50.00, $transaction['splits'][1]['amount']);
    }

    public function testParseToTransactionListFlattensData(): void {
        $transactions = $this->parser->parseToTransactionList($this->getSampleBankQif());

        $this->assertCount(3, $transactions);

        // Each transaction should have account metadata
        $first = $transactions[0];
        $this->assertArrayHasKey('_account', $first);
        $this->assertEquals('bank', $first['_account']['type']);
    }

    public function testParseToTransactionListRespectsLimit(): void {
        $transactions = $this->parser->parseToTransactionList($this->getSampleBankQif(), 2);

        $this->assertCount(2, $transactions);
    }

    public function testParseDateFormats(): void {
        // Test various date formats
        $testCases = [
            "!Type:Bank\nD12/30/2025\nT100\nPTest\n^" => '2025-12-30',
            "!Type:Bank\nD1/5/2025\nT100\nPTest\n^" => '2025-01-05',
            "!Type:Bank\nD12/30/25\nT100\nPTest\n^" => '2025-12-30',
            "!Type:Bank\nD12/30'25\nT100\nPTest\n^" => '2025-12-30', // Apostrophe format
            "!Type:Bank\nD30/12/2025\nT100\nPTest\n^" => '2025-12-30', // UK format
        ];

        foreach ($testCases as $qif => $expectedDate) {
            $result = $this->parser->parse($qif);
            $this->assertEquals(
                $expectedDate,
                $result['accounts'][0]['transactions'][0]['date'],
                "Failed for input: $qif"
            );
        }
    }

    public function testParseAmountFormats(): void {
        // Test various amount formats
        $testCases = [
            "!Type:Bank\nD1/1/2025\nT1,234.56\nPTest\n^" => 1234.56,
            "!Type:Bank\nD1/1/2025\nT-1,234.56\nPTest\n^" => 1234.56, // Absolute
            "!Type:Bank\nD1/1/2025\nT$100.00\nPTest\n^" => 100.00,
            "!Type:Bank\nD1/1/2025\nT100\nPTest\n^" => 100.00,
        ];

        foreach ($testCases as $qif => $expectedAmount) {
            $result = $this->parser->parse($qif);
            $this->assertEquals(
                $expectedAmount,
                $result['accounts'][0]['transactions'][0]['amount'],
                "Failed for input: $qif"
            );
        }
    }

    public function testParseMultipleAccountTypes(): void {
        $qif = <<<'QIF'
!Type:Bank
D1/1/2025
T100
PBank deposit
^
!Type:CCard
D1/2/2025
T-50
PCard purchase
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(2, $result['accounts']);
        $this->assertEquals('bank', $result['accounts'][0]['type']);
        $this->assertEquals('credit_card', $result['accounts'][1]['type']);
    }

    public function testParseWithoutHeader(): void {
        // QIF without explicit header should default to bank
        $qif = "D1/1/2025\nT100\nPTest payment\n^";

        $result = $this->parser->parse($qif);

        $this->assertCount(1, $result['accounts']);
        $this->assertEquals('bank', $result['accounts'][0]['type']);
        $this->assertCount(1, $result['accounts'][0]['transactions']);
    }

    public function testParseHandlesMissingEndMarker(): void {
        // Transaction without trailing ^
        $qif = "!Type:Bank\nD1/1/2025\nT100\nPTest payment";

        $result = $this->parser->parse($qif);

        $this->assertCount(1, $result['accounts'][0]['transactions']);
    }

    public function testParseCategoryWithClass(): void {
        $qif = "!Type:Bank\nD1/1/2025\nT-100\nPTest\nLBills:Utilities/Home\n^";

        $result = $this->parser->parse($qif);
        $category = $result['accounts'][0]['transactions'][0]['category'];

        $this->assertEquals('Bills', $category['name']);
        $this->assertEquals('Utilities', $category['subcategory']);
        $this->assertEquals('Home', $category['class']);
    }

    public function testTransactionHasUniqueId(): void {
        $result = $this->parser->parse($this->getSampleBankQif());

        foreach ($result['accounts'][0]['transactions'] as $transaction) {
            $this->assertArrayHasKey('id', $transaction);
            $this->assertStringStartsWith('qif_', $transaction['id']);
        }

        // IDs should be unique
        $ids = array_map(fn($t) => $t['id'], $result['accounts'][0]['transactions']);
        $this->assertEquals(count($ids), count(array_unique($ids)));
    }

    public function testParseEmptyContent(): void {
        $result = $this->parser->parse('');

        $this->assertCount(0, $result['accounts']);
    }

    public function testParseInvestmentFields(): void {
        $qif = <<<'QIF'
!Type:Invst
D1/1/2025
NBuy
YAAPL
I150.50
Q10
T1505.00
O9.99
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertEquals('investment', $result['accounts'][0]['type']);
        $transaction = $result['accounts'][0]['transactions'][0];

        $this->assertEquals('AAPL', $transaction['security']);
        $this->assertEquals(150.50, $transaction['price']);
        $this->assertEquals(10.0, $transaction['quantity']);
        $this->assertEquals(9.99, $transaction['commission']);
    }

    // ===== account definition blocks =====

    // Every account came back with name === null because case 'N' appeared
    // twice in the field switch and PHP takes the first arm, so the account
    // name was only ever read as a transaction's check number.
    public function testParseReadsAccountNameFromAccountBlock(): void {
        $qif = <<<'QIF'
!Account
NMy Checking
TBank
DEveryday account
^
!Type:Bank
D1/2/2025
T-10.00
PShop
N1001
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(1, $result['accounts']);
        $this->assertEquals('My Checking', $result['accounts'][0]['name']);
        $this->assertEquals('Everyday account', $result['accounts'][0]['description']);
    }

    // The !Account record's own N/T/D lines used to fall through the
    // transaction switch, so the header itself was emitted as a transaction
    // dated "Everyday account".
    public function testParseDoesNotTurnAccountBlockIntoATransaction(): void {
        $qif = <<<'QIF'
!Account
NMy Checking
TBank
DEveryday account
^
!Type:Bank
D1/2/2025
T-10.00
PShop
^
QIF;

        $result = $this->parser->parse($qif);

        $transactions = $result['accounts'][0]['transactions'];
        $this->assertCount(1, $transactions);
        $this->assertEquals('2025-01-02', $transactions[0]['date']);
        $this->assertEquals('Shop', $transactions[0]['description']);
    }

    // A transaction's own N line is still its check number.
    public function testParseStillReadsCheckNumberOutsideAccountBlock(): void {
        $qif = <<<'QIF'
!Type:Bank
D1/2/2025
T-10.00
PShop
N1001
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertEquals('1001', $result['accounts'][0]['transactions'][0]['reference']);
    }

    public function testParseReadsAccountTypeFromAccountBlock(): void {
        $qif = <<<'QIF'
!Account
NVisa
TCCard
^
!Type:CCard
D1/2/2025
T-10.00
PShop
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertEquals('Visa', $result['accounts'][0]['name']);
        $this->assertEquals('credit_card', $result['accounts'][0]['type']);
    }

    // ===== non-transaction sections =====

    // parseAccountType defaulted anything it did not recognise to 'bank', so a
    // real Quicken export's leading category list became a fake account whose
    // "transactions" were the category names.
    public function testParseIgnoresCategoryListSection(): void {
        $qif = <<<'QIF'
!Type:Cat
NGroceries
DFood shopping
E
^
NSalary
DPay
I
^
!Type:Bank
D1/2/2025
T-10.00
PShop A
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(1, $result['accounts']);
        $this->assertEquals('bank', $result['accounts'][0]['type']);
        $this->assertCount(1, $result['accounts'][0]['transactions']);
        $this->assertEquals('Shop A', $result['accounts'][0]['transactions'][0]['description']);
    }

    public function testParseIgnoresMemorizedAndClassSections(): void {
        $qif = <<<'QIF'
!Type:Class
NHoliday
^
!Type:Memorized
PSome Payee
T-5.00
^
!Type:Bank
D1/2/2025
T-10.00
PShop A
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(1, $result['accounts']);
        $this->assertCount(1, $result['accounts'][0]['transactions']);
    }

    // ===== account identity =====

    // ImportService's multi-account loop reads $sourceAccount['accountId'] for
    // both formats. QIF never emitted that key, so every account was skipped
    // and a QIF import silently imported nothing at all.
    public function testParseGivesEveryAccountAnIdentity(): void {
        $result = $this->parser->parse($this->getSampleBankQif());

        $this->assertArrayHasKey('accountId', $result['accounts'][0]);
        $this->assertNotSame('', $result['accounts'][0]['accountId']);
    }

    public function testParseIdentityUsesTheAccountName(): void {
        $qif = <<<'QIF'
!Account
NMy Checking
TBank
^
!Type:Bank
D1/2/2025
T-10.00
PShop
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertEquals('My Checking', $result['accounts'][0]['accountId']);
    }

    // Two unnamed sections must not collapse onto one routing key, or the user
    // can only ever route both to the same destination account.
    public function testParseGivesUnnamedAccountsDistinctIdentities(): void {
        $qif = <<<'QIF'
!Type:Bank
D1/2/2025
T-10.00
PShop
^
!Type:CCard
D1/3/2025
T-20.00
POther
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(2, $result['accounts']);
        $this->assertNotEquals(
            $result['accounts'][0]['accountId'],
            $result['accounts'][1]['accountId']
        );
    }

    public function testParseMultiAccountAutoSwitchExport(): void {
        $qif = <<<'QIF'
!Option:AutoSwitch
!Account
NChecking
TBank
^
NVisa
TCCard
^
!Clear:AutoSwitch
!Account
NChecking
TBank
^
!Type:Bank
D1/2/2025
T-10.00
PShop A
^
!Account
NVisa
TCCard
^
!Type:CCard
D1/3/2025
T-20.00
PShop B
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(2, $result['accounts']);
        $this->assertEquals('Checking', $result['accounts'][0]['name']);
        $this->assertEquals('Visa', $result['accounts'][1]['name']);
        $this->assertCount(1, $result['accounts'][0]['transactions']);
        $this->assertCount(1, $result['accounts'][1]['transactions']);
        $this->assertEquals('Shop A', $result['accounts'][0]['transactions'][0]['description']);
        $this->assertEquals('Shop B', $result['accounts'][1]['transactions'][0]['description']);
    }

    // ===== date validation =====

    // Reachable only now that QIF imports at all: an impossible date used to
    // be handed straight to the insert.
    // Quicken pads date components to a fixed width, which matches none of the
    // formats below and used to be handed to the date column verbatim.
    public function testParseHandlesQuickenSpacePaddedDates(): void {
        $qif = <<<'QIF'
!Type:Bank
D12/ 4'98
T-20.00
PPadded with apostrophe year
^
D 8/ 6' 3
T-30.00
PPadded everywhere
^
QIF;

        $result = $this->parser->parse($qif);
        $transactions = $result['accounts'][0]['transactions'];

        $this->assertCount(2, $transactions);
        $this->assertEquals('1998-12-04', $transactions[0]['date']);
        $this->assertEquals('2003-08-06', $transactions[1]['date']);
    }

    // Better to drop the row than to write a non-date into a date column.
    public function testParseDropsRowWithUnparseableDate(): void {
        $qif = <<<'QIF'
!Type:Bank
Dnot a date at all
T-20.00
PBroken
^
D1/2/2025
T-30.00
PFine
^
QIF;

        $result = $this->parser->parse($qif);
        $transactions = $result['accounts'][0]['transactions'];

        $this->assertCount(1, $transactions);
        $this->assertEquals('2025-01-02', $transactions[0]['date']);
    }

    // Textual dates still parse - the padding fix must not break them.
    public function testParseStillHandlesTextualDates(): void {
        $qif = <<<'QIF'
!Type:Bank
DJan 15 2025
T-20.00
PTextual
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertEquals('2025-01-15', $result['accounts'][0]['transactions'][0]['date']);
    }

    public function testParseRejectsImpossibleDate(): void {
        $qif = <<<'QIF'
!Type:Bank
D13/45/2025
T-10.00
PShop
^
QIF;

        $result = $this->parser->parse($qif);

        $this->assertCount(0, $result['accounts'][0]['transactions'] ?? []);
    }
}
