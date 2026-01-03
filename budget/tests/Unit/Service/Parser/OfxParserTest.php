<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Parser;

use OCA\Budget\Service\Parser\OfxParser;
use PHPUnit\Framework\TestCase;

class OfxParserTest extends TestCase {
    private OfxParser $parser;

    protected function setUp(): void {
        $this->parser = new OfxParser();
    }

    /**
     * Sample OFX content matching real-world bank exports (SGML format).
     */
    private function getSampleOfxContent(): string {
        return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE
<OFX>
    <SIGNONMSGSRSV1>
        <SONRS>
            <STATUS>
                <CODE>0</CODE>
                <SEVERITY>INFO</SEVERITY>
            </STATUS>
            <DTSERVER>20251231212913</DTSERVER>
            <LANGUAGE>ENG</LANGUAGE>
        </SONRS>
    </SIGNONMSGSRSV1>
    <BANKMSGSRSV1>
        <STMTTRNRS>
            <TRNUID>1</TRNUID>
            <STATUS>
                <CODE>0</CODE>
                <SEVERITY>INFO</SEVERITY>
            </STATUS>
            <STMTRS>
                <CURDEF>GBP</CURDEF>
                <BANKACCTFROM>
                    <BANKID>541002</BANKID>
                    <ACCTID>89020944</ACCTID>
                    <ACCTTYPE>CHECKING</ACCTTYPE>
                </BANKACCTFROM>
                <BANKTRANLIST>
                    <DTSTART>20251230</DTSTART>
                    <DTEND>20251230</DTEND>
                    <STMTTRN>
                        <TRNTYPE>CREDIT</TRNTYPE>
                        <DTPOSTED>20251230</DTPOSTED>
                        <TRNAMT>49.27</TRNAMT>
                        <FITID>202512300001</FITID>
                        <NAME>EBAY COMMERCE UK L</NAME>
                        <MEMO>P.7252976115</MEMO>
                    </STMTTRN>
                    <STMTTRN>
                        <TRNTYPE>DIRECTDEBIT</TRNTYPE>
                        <DTPOSTED>20251230</DTPOSTED>
                        <TRNAMT>-134.39</TRNAMT>
                        <FITID>202512300003</FITID>
                        <NAME>BARCLAYS PRTNR FIN</NAME>
                    </STMTTRN>
                </BANKTRANLIST>
                <LEDGERBAL>
                    <BALAMT>27.79</BALAMT>
                    <DTASOF>20251230</DTASOF>
                </LEDGERBAL>
                <AVAILBAL>
                    <BALAMT>0.00</BALAMT>
                    <DTASOF>20251230</DTASOF>
                </AVAILBAL>
            </STMTRS>
        </STMTTRNRS>
    </BANKMSGSRSV1>
    <CREDITCARDMSGSRSV1>
        <CCSTMTTRNRS>
            <TRNUID>2</TRNUID>
            <STATUS>
                <CODE>0</CODE>
                <SEVERITY>INFO</SEVERITY>
            </STATUS>
            <CCSTMTRS>
                <CURDEF>GBP</CURDEF>
                <CCACCTFROM>
                    <ACCTID>552213******8589</ACCTID>
                </CCACCTFROM>
                <BANKTRANLIST>
                    <DTSTART>20251230</DTSTART>
                    <DTEND>20251231</DTEND>
                </BANKTRANLIST>
                <LEDGERBAL>
                    <BALAMT>0.00</BALAMT>
                    <DTASOF>20251231</DTASOF>
                </LEDGERBAL>
            </CCSTMTRS>
        </CCSTMTTRNRS>
    </CREDITCARDMSGSRSV1>
</OFX>
OFX;
    }

    public function testParseReturnsAccountsArray(): void {
        $result = $this->parser->parse($this->getSampleOfxContent());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('accounts', $result);
        $this->assertIsArray($result['accounts']);
    }

    public function testParseFindsBankAccount(): void {
        $result = $this->parser->parse($this->getSampleOfxContent());

        // Should find 1 bank account + 1 credit card account
        $this->assertCount(2, $result['accounts']);

        // First should be the bank account
        $bankAccount = $result['accounts'][0];
        $this->assertEquals('89020944', $bankAccount['accountId']);
        $this->assertEquals('541002', $bankAccount['bankId']);
        $this->assertEquals('checking', $bankAccount['type']);
        $this->assertEquals('GBP', $bankAccount['currency']);
    }

    public function testParseFindsCreditCardAccount(): void {
        $result = $this->parser->parse($this->getSampleOfxContent());

        // Second should be the credit card
        $ccAccount = $result['accounts'][1];
        $this->assertEquals('552213******8589', $ccAccount['accountId']);
        $this->assertNull($ccAccount['bankId']);
        $this->assertEquals('credit_card', $ccAccount['type']);
    }

    public function testParseExtractsBalances(): void {
        $result = $this->parser->parse($this->getSampleOfxContent());

        $bankAccount = $result['accounts'][0];
        $this->assertEquals(27.79, $bankAccount['ledgerBalance']);
        $this->assertEquals(0.00, $bankAccount['availableBalance']);
        $this->assertEquals('2025-12-30', $bankAccount['balanceDate']);
    }

    public function testParseExtractsTransactions(): void {
        $result = $this->parser->parse($this->getSampleOfxContent());

        $transactions = $result['accounts'][0]['transactions'];
        $this->assertCount(2, $transactions);

        // First transaction - credit
        $credit = $transactions[0];
        $this->assertEquals('202512300001', $credit['id']);
        $this->assertEquals('2025-12-30', $credit['date']);
        $this->assertEquals(49.27, $credit['amount']);
        $this->assertEquals('credit', $credit['type']);
        $this->assertEquals('EBAY COMMERCE UK L', $credit['description']);
        $this->assertEquals('P.7252976115', $credit['memo']);
        $this->assertEquals('CREDIT', $credit['transactionType']);

        // Second transaction - debit
        $debit = $transactions[1];
        $this->assertEquals('202512300003', $debit['id']);
        $this->assertEquals(134.39, $debit['amount']);
        $this->assertEquals('debit', $debit['type']);
        $this->assertEquals('BARCLAYS PRTNR FIN', $debit['description']);
        $this->assertNull($debit['memo']);
    }

    public function testParseToTransactionListFlattensData(): void {
        $transactions = $this->parser->parseToTransactionList($this->getSampleOfxContent());

        $this->assertCount(2, $transactions);

        // Each transaction should have account metadata
        $first = $transactions[0];
        $this->assertArrayHasKey('_account', $first);
        $this->assertArrayHasKey('_balances', $first);
        $this->assertEquals('89020944', $first['_account']['accountId']);
        $this->assertEquals(27.79, $first['_balances']['ledger']);
    }

    public function testParseToTransactionListRespectsLimit(): void {
        $transactions = $this->parser->parseToTransactionList($this->getSampleOfxContent(), 1);

        $this->assertCount(1, $transactions);
    }

    public function testParseDateFormats(): void {
        // Test YYYYMMDD format
        $ofx = '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
            <CURDEF>USD</CURDEF>
            <BANKACCTFROM><ACCTID>123</ACCTID></BANKACCTFROM>
            <BANKTRANLIST>
                <STMTTRN>
                    <DTPOSTED>20251225</DTPOSTED>
                    <TRNAMT>100.00</TRNAMT>
                    <FITID>1</FITID>
                </STMTTRN>
            </BANKTRANLIST>
        </STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

        $result = $this->parser->parse($ofx);
        $this->assertEquals('2025-12-25', $result['accounts'][0]['transactions'][0]['date']);
    }

    public function testParseHandlesEmptyTransactionList(): void {
        $ofx = '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
            <CURDEF>GBP</CURDEF>
            <BANKACCTFROM>
                <BANKID>123456</BANKID>
                <ACCTID>789</ACCTID>
                <ACCTTYPE>SAVINGS</ACCTTYPE>
            </BANKACCTFROM>
            <BANKTRANLIST>
                <DTSTART>20251230</DTSTART>
                <DTEND>20251231</DTEND>
            </BANKTRANLIST>
        </STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

        $result = $this->parser->parse($ofx);

        $this->assertCount(1, $result['accounts']);
        $this->assertEquals('789', $result['accounts'][0]['accountId']);
        $this->assertCount(0, $result['accounts'][0]['transactions']);
    }

    public function testParseHandlesNegativeAmounts(): void {
        $ofx = '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
            <CURDEF>USD</CURDEF>
            <BANKACCTFROM><ACCTID>123</ACCTID></BANKACCTFROM>
            <BANKTRANLIST>
                <STMTTRN>
                    <DTPOSTED>20251230</DTPOSTED>
                    <TRNAMT>-50.00</TRNAMT>
                    <FITID>1</FITID>
                </STMTTRN>
            </BANKTRANLIST>
        </STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

        $result = $this->parser->parse($ofx);
        $txn = $result['accounts'][0]['transactions'][0];

        $this->assertEquals(50.00, $txn['amount']); // Absolute value
        $this->assertEquals(-50.00, $txn['rawAmount']); // Signed value
        $this->assertEquals('debit', $txn['type']);
    }

    public function testParseMultipleBankAccounts(): void {
        $ofx = '<OFX><BANKMSGSRSV1>
            <STMTTRNRS><STMTRS>
                <CURDEF>GBP</CURDEF>
                <BANKACCTFROM><ACCTID>111</ACCTID></BANKACCTFROM>
                <BANKTRANLIST></BANKTRANLIST>
            </STMTRS></STMTTRNRS>
            <STMTTRNRS><STMTRS>
                <CURDEF>GBP</CURDEF>
                <BANKACCTFROM><ACCTID>222</ACCTID></BANKACCTFROM>
                <BANKTRANLIST></BANKTRANLIST>
            </STMTRS></STMTTRNRS>
        </BANKMSGSRSV1></OFX>';

        $result = $this->parser->parse($ofx);

        $this->assertCount(2, $result['accounts']);
        $this->assertEquals('111', $result['accounts'][0]['accountId']);
        $this->assertEquals('222', $result['accounts'][1]['accountId']);
    }
}
