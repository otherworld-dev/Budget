<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Parser;

use OCA\Budget\Service\Parser\CamtParser;
use PHPUnit\Framework\TestCase;

class CamtParserTest extends TestCase {
    private CamtParser $parser;

    protected function setUp(): void {
        $this->parser = new CamtParser();
    }

    /** The anonymised WIR Bank camt.053.001.08 statement from #350. */
    private function wirSample(): string {
        return file_get_contents(__DIR__ . '/fixtures/camt053-wir-sample.xml');
    }

    /**
     * A richer synthetic camt.053.001.04 statement: remittance text,
     * counterparties, a batch entry with per-transaction details, a repeated
     * bank reference, and a DtTm booking date.
     */
    private function richSample(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04">
  <BkToCstmrStmt>
    <GrpHdr><MsgId>M1</MsgId><CreDtTm>2026-08-01T08:00:00+02:00</CreDtTm></GrpHdr>
    <Stmt>
      <Id>STMT-7</Id>
      <FrToDt><FrDtTm>2026-07-01T00:00:00</FrDtTm><ToDtTm>2026-07-31T23:59:59</ToDtTm></FrToDt>
      <Acct>
        <Id><IBAN>DE02120300000000202051</IBAN></Id>
        <Ccy>EUR</Ccy>
        <Nm>Girokonto</Nm>
        <Svcr><FinInstnId><BICFI>BYLADEM1001</BICFI><Nm>Test Bank</Nm></FinInstnId></Svcr>
      </Acct>
      <Bal>
        <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>DBIT</CdtDbtInd>
        <Dt><Dt>2026-07-01</Dt></Dt>
      </Bal>
      <Bal>
        <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="EUR">2450.50</Amt><CdtDbtInd>CRDT</CdtDbtInd>
        <Dt><Dt>2026-07-31</Dt></Dt>
      </Bal>
      <Ntry>
        <Amt Ccy="EUR">3000.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><DtTm>2026-07-25T10:15:00+02:00</DtTm></BookgDt>
        <ValDt><Dt>2026-07-25</Dt></ValDt>
        <AcctSvcrRef>REF-SALARY-1</AcctSvcrRef>
        <BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>SALA</SubFmlyCd></Fmly></Domn></BkTxCd>
        <NtryDtls><TxDtls>
          <Refs><EndToEndId>E2E-SAL-0725</EndToEndId></Refs>
          <RltdPties><Dbtr><Nm>ACME GmbH</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Gehalt Juli 2026</Ustrd><Ustrd>Personalnr 4711</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
        <AddtlNtryInf>SEPA Gutschrift</AddtlNtryInf>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">42.10</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><Dt>2026-07-26</Dt></BookgDt>
        <AcctSvcrRef>REF-SHARED</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Cdtr><Nm>Supermarkt Nord</Nm></Cdtr></RltdPties>
        </TxDtls></NtryDtls>
        <AddtlNtryInf>Kartenzahlung</AddtlNtryInf>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">19.99</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><Dt>2026-07-27</Dt></BookgDt>
        <AcctSvcrRef>REF-SHARED</AcctSvcrRef>
        <AddtlNtryInf>Lastschrift Streaming</AddtlNtryInf>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">150.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><Dt>2026-07-28</Dt></BookgDt>
        <AcctSvcrRef>REF-BATCH</AcctSvcrRef>
        <NtryDtls>
          <Btch><NbOfTxs>2</NbOfTxs><TtlAmt Ccy="EUR">150.00</TtlAmt></Btch>
          <TxDtls>
            <Refs><EndToEndId>E2E-B1</EndToEndId></Refs>
            <Amt Ccy="EUR">100.00</Amt>
            <RltdPties><Cdtr><Nm>Vermieter</Nm></Cdtr></RltdPties>
            <RmtInf><Ustrd>Miete</Ustrd></RmtInf>
          </TxDtls>
          <TxDtls>
            <Refs><EndToEndId>E2E-B2</EndToEndId></Refs>
            <Amt Ccy="EUR">50.00</Amt>
            <RltdPties><Cdtr><Nm>Stadtwerke</Nm></Cdtr></RltdPties>
            <RmtInf><Ustrd>Strom</Ustrd></RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
    }

    // ── account & balances ──────────────────────────────────────────

    public function testParsesAccountFromWirSample(): void {
        $result = $this->parser->parse($this->wirSample());

        $this->assertCount(1, $result['accounts']);
        $account = $result['accounts'][0];
        $this->assertSame('CH21212121212121', $account['accountId']);
        $this->assertSame('WIRBCHBBXXX', $account['bankId']);
        $this->assertSame('WIR Bank', $account['bankName']);
        $this->assertSame('CHF', $account['currency']);
        $this->assertSame('checking', $account['type']);
        $this->assertEqualsWithDelta(3343495.03, $account['ledgerBalance'], 0.001);
        $this->assertSame('2026-07-30', $account['balanceDate']);
        $this->assertEqualsWithDelta(2343243461.4, $account['openingBalance'], 0.001);
    }

    public function testDebitBalanceIsNegative(): void {
        $account = $this->parser->parse($this->richSample())['accounts'][0];

        $this->assertEqualsWithDelta(-100.0, $account['openingBalance'], 0.001);
        $this->assertEqualsWithDelta(2450.5, $account['ledgerBalance'], 0.001);
        $this->assertSame('Girokonto', $account['name']);
    }

    // ── entries ─────────────────────────────────────────────────────

    public function testParsesWirEntry(): void {
        $txns = $this->parser->parse($this->wirSample())['accounts'][0]['transactions'];

        $this->assertCount(1, $txns);
        $t = $txns[0];
        $this->assertSame('2026-07-01', $t['date']);
        $this->assertEqualsWithDelta(11.0, $t['amount'], 0.001);
        $this->assertSame('debit', $t['type']);
        $this->assertEqualsWithDelta(-11.0, $t['rawAmount'], 0.001);
        $this->assertSame('Comment', $t['description']);
        $this->assertSame('AlphaNumeric', $t['id']);
        $this->assertSame('PMNT/ICDT/OTHR', $t['transactionType']);
    }

    public function testRemittanceTextAndCounterpartyWin(): void {
        $txns = $this->parser->parse($this->richSample())['accounts'][0]['transactions'];
        $salary = $txns[0];

        $this->assertSame('credit', $salary['type']);
        $this->assertSame('2026-07-25', $salary['date']); // from DtTm
        $this->assertSame('Gehalt Juli 2026 Personalnr 4711', $salary['description']);
        $this->assertSame('ACME GmbH', $salary['name']);
        $this->assertSame('E2E-SAL-0725', $salary['reference']);
        $this->assertSame('REF-SALARY-1', $salary['id']);
        $this->assertSame('SEPA Gutschrift', $salary['memo']);
    }

    public function testCounterpartyUsedWhenNoRemittanceText(): void {
        $txns = $this->parser->parse($this->richSample())['accounts'][0]['transactions'];
        $card = $txns[1];

        $this->assertSame('Supermarkt Nord', $card['description']);
        $this->assertSame('Supermarkt Nord', $card['name']);
        $this->assertSame('Kartenzahlung', $card['memo']);
    }

    public function testAdditionalInfoIsLastResortDescription(): void {
        $txns = $this->parser->parse($this->richSample())['accounts'][0]['transactions'];
        $streaming = $txns[2];

        $this->assertSame('Lastschrift Streaming', $streaming['description']);
        $this->assertNull($streaming['memo']); // not repeated verbatim
    }

    public function testRepeatedBankReferenceIsNotUsedAsId(): void {
        // Two entries share AcctSvcrRef REF-SHARED: trusting it would collapse
        // them into one transaction on import. Both must fall back to hashing.
        $txns = $this->parser->parse($this->richSample())['accounts'][0]['transactions'];

        $this->assertNull($txns[1]['id']);
        $this->assertNull($txns[2]['id']);
        $this->assertSame('REF-SALARY-1', $txns[0]['id']);
    }

    public function testBatchEntryExpandsToItsTransactionDetails(): void {
        $txns = $this->parser->parse($this->richSample())['accounts'][0]['transactions'];

        $this->assertCount(5, $txns); // 3 single entries + 2 batch parts
        [$rent, $power] = [$txns[3], $txns[4]];
        $this->assertEqualsWithDelta(100.0, $rent['amount'], 0.001);
        $this->assertSame('debit', $rent['type']);
        $this->assertSame('Miete', $rent['description']);
        $this->assertSame('Vermieter', $rent['name']);
        $this->assertSame('REF-BATCH/E2E-B1', $rent['id']);
        $this->assertEqualsWithDelta(50.0, $power['amount'], 0.001);
        $this->assertSame('Strom', $power['description']);
        $this->assertSame('REF-BATCH/E2E-B2', $power['id']);
    }

    // ── flattening, other roots, robustness ─────────────────────────

    public function testParseToTransactionListCarriesAccountMetadata(): void {
        $list = $this->parser->parseToTransactionList($this->richSample());

        $this->assertCount(5, $list);
        $this->assertSame('DE02120300000000202051', $list[0]['_account']['accountId']);
        $this->assertSame('EUR', $list[0]['_account']['currency']);
        $this->assertEqualsWithDelta(2450.5, $list[0]['_balances']['ledger'], 0.001);

        $this->assertCount(2, $this->parser->parseToTransactionList($this->richSample(), 2));
    }

    public function testParsesCamt052AccountReport(): void {
        $xml = str_replace(
            ['camt.053.001.04', 'BkToCstmrStmt', '<Stmt>', '</Stmt>'],
            ['camt.052.001.04', 'BkToCstmrAcctRpt', '<Rpt>', '</Rpt>'],
            $this->richSample()
        );

        $accounts = $this->parser->parse($xml)['accounts'];

        $this->assertCount(1, $accounts);
        $this->assertCount(5, $accounts[0]['transactions']);
    }

    public function testRejectsNonCamtXml(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('<?xml version="1.0"?><note><to>Tove</to></note>');
    }

    public function testRejectsMalformedXml(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('<Document><BkToCstmrStmt><Stmt>');
    }

    public function testExternalEntitiesAreNotExpanded(): void {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///etc/hostname">]>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>
<Acct><Id><IBAN>XX00</IBAN></Id><Ccy>EUR</Ccy></Acct>
<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-01-01</Dt></BookgDt>
<AddtlNtryInf>&xxe;</AddtlNtryInf></Ntry>
</Stmt></BkToCstmrStmt></Document>
XML;
        try {
            $result = $this->parser->parse($xml);
            $desc = $result['accounts'][0]['transactions'][0]['description'] ?? '';
            $this->assertSame('', trim($desc), 'external entity must not be substituted');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1); // refusing the document outright is fine too
        }
    }
}
