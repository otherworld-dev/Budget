<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Parser\OfxParser;
use OCA\Budget\Service\Parser\QifParser;
use PHPUnit\Framework\TestCase;

class ParserFactoryTest extends TestCase {
    private ParserFactory $factory;

    protected function setUp(): void {
        $this->factory = new ParserFactory();
    }

    // ===== detectFormat =====

    public function testDetectFormatCsv(): void {
        $this->assertEquals('csv', $this->factory->detectFormat('data.csv'));
    }

    public function testDetectFormatCsvUpperCase(): void {
        $this->assertEquals('csv', $this->factory->detectFormat('DATA.CSV'));
    }

    public function testDetectFormatTxt(): void {
        $this->assertEquals('csv', $this->factory->detectFormat('data.txt'));
    }

    public function testDetectFormatOfx(): void {
        $this->assertEquals('ofx', $this->factory->detectFormat('bank.ofx'));
    }

    public function testDetectFormatQif(): void {
        $this->assertEquals('qif', $this->factory->detectFormat('export.qif'));
    }

    public function testDetectFormatUnknownDefaultsCsv(): void {
        $this->assertEquals('csv', $this->factory->detectFormat('data.xlsx'));
    }

    // ===== getOfxParser / getQifParser =====

    public function testGetOfxParserReturnsSameInstance(): void {
        $parser1 = $this->factory->getOfxParser();
        $parser2 = $this->factory->getOfxParser();

        $this->assertInstanceOf(OfxParser::class, $parser1);
        $this->assertSame($parser1, $parser2);
    }

    public function testGetQifParserReturnsSameInstance(): void {
        $parser1 = $this->factory->getQifParser();
        $parser2 = $this->factory->getQifParser();

        $this->assertInstanceOf(QifParser::class, $parser1);
        $this->assertSame($parser1, $parser2);
    }

    // ===== parse CSV =====

    public function testParseCsvBasic(): void {
        $csv = "Date,Amount,Description\n2026-01-01,100.00,Groceries\n2026-01-02,50.00,Gas\n";

        $result = $this->factory->parse($csv, 'csv');

        $this->assertCount(2, $result);
        $this->assertEquals('2026-01-01', $result[0]['Date']);
        $this->assertEquals('100.00', $result[0]['Amount']);
        $this->assertEquals('Groceries', $result[0]['Description']);
    }

    public function testParseCsvWithLimit(): void {
        $csv = "Date,Amount\n2026-01-01,10\n2026-01-02,20\n2026-01-03,30\n";

        $result = $this->factory->parse($csv, 'csv', 2);

        $this->assertCount(2, $result);
    }

    public function testParseCsvWithSemicolonDelimiter(): void {
        $csv = "Date;Amount;Description\n2026-01-01;100.00;Groceries\n";

        $result = $this->factory->parse($csv, 'csv', null, ';');

        $this->assertCount(1, $result);
        $this->assertEquals('100.00', $result[0]['Amount']);
    }

    public function testParseCsvStripsUtf8Bom(): void {
        $csv = "\xEF\xBB\xBFDate,Amount\n2026-01-01,100\n";

        $result = $this->factory->parse($csv, 'csv');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('Date', $result[0]);
    }

    public function testParseCsvSkipsMetadataPreamble(): void {
        // Swiss-style export with metadata before actual CSV
        $csv = "Bank: UBS\nDate: 2026-01-01\n\nDate,Amount,Description\n2026-01-01,100.00,Groceries\n2026-01-02,50.00,Gas\n";

        $result = $this->factory->parse($csv, 'csv');

        $this->assertCount(2, $result);
        $this->assertEquals('Groceries', $result[0]['Description']);
    }

    public function testParseCsvEmptyContentReturnsEmpty(): void {
        $result = $this->factory->parse('', 'csv');

        $this->assertEmpty($result);
    }

    public function testParseCsvOnlyHeadersReturnsEmpty(): void {
        $csv = "Date,Amount,Description\n";

        $result = $this->factory->parse($csv, 'csv');

        $this->assertEmpty($result);
    }

    // ===== stripBom =====

    public function testStripBomRemovesUtf8Bom(): void {
        $content = "\xEF\xBB\xBFHello World";
        $this->assertEquals('Hello World', $this->factory->stripBom($content));
    }

    public function testStripBomNoOpWithoutBom(): void {
        $content = 'Hello World';
        $this->assertEquals('Hello World', $this->factory->stripBom($content));
    }

    public function testStripBomEmptyString(): void {
        $this->assertEquals('', $this->factory->stripBom(''));
    }

    // ===== parse CSV with BOM + quoted values (DKB format) =====

    public function testParseCsvBomWithQuotedSemicolonDelimited(): void {
        // DKB exports: UTF-8 BOM + quoted values + semicolons + 2-digit years
        $csv = "\xEF\xBB\xBF\"Buchungsdatum\";\"Wertstellung\";\"Status\";\"Zahlungspflichtige*r\";\"Zahlungsempfänger*in\";\"Verwendungszweck\";\"Umsatztyp\";\"Betrag (€)\"\n"
             . "\"25.03.26\";\"25.03.26\";\"Gebucht\";\"Max Mustermann\";\"Lidl\";\"VISA Debitkartenumsatz\";\"Ausgang\";\"-57,68\"\n"
             . "\"24.03.26\";\"24.03.26\";\"Gebucht\";\"Max Mustermann\";\"REWE\";\"VISA Debitkartenumsatz\";\"Ausgang\";\"-23,45\"\n";

        $result = $this->factory->parse($csv, 'csv', null, ';');

        $this->assertCount(2, $result);
        // Headers should be clean (no BOM, no quotes)
        $this->assertArrayHasKey('Buchungsdatum', $result[0]);
        $this->assertArrayHasKey('Betrag (€)', $result[0]);
        $this->assertArrayHasKey('Zahlungsempfänger*in', $result[0]);
        // Values should be clean
        $this->assertEquals('25.03.26', $result[0]['Buchungsdatum']);
        $this->assertEquals('-57,68', $result[0]['Betrag (€)']);
        $this->assertEquals('Lidl', $result[0]['Zahlungsempfänger*in']);
    }

    public function testParseCsvBomWithQuotedValuesHeadersMatchColumnNames(): void {
        // Verify that headers from parse() match what buildUploadResponse would produce
        // after BOM stripping (the actual bug was a mismatch between these two)
        $csv = "\xEF\xBB\xBF\"Date\";\"Amount\";\"Description\"\n\"2026-01-01\";\"100.00\";\"Groceries\"\n";

        $result = $this->factory->parse($csv, 'csv', null, ';');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('Date', $result[0]);
        $this->assertEquals('100.00', $result[0]['Amount']);
    }

    // ===== detectDataWidth =====

    public function testDetectDataWidthSkipsPreambleRows(): void {
        // DKB-style CSV with metadata preamble (2 columns) before data (11 columns)
        $lines = [
            '"1. Girokonto";"DE000000000000006543"',
            '"Zeitraum:";"25.03.2026 - 25.03.2026"',
            '"Kontostand vom 25.03.2026:";"1.807,02 €"',
            '',
            '"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz"',
            '"25.03.26";"25.03.26";"Gebucht";"ISSUER";"Lidl";"VISA Debitkartenumsatz";"Ausgang";"DE000000000000000000";"-57,68";"";""',
        ];

        $width = $this->factory->detectDataWidth($lines, ';');

        // Should detect 11 columns (header + data), not 2 (preamble)
        $this->assertEquals(11, $width);
    }

    public function testParseCsvDkbFullFormatWithPreamble(): void {
        // Full DKB export: BOM + preamble metadata + quoted semicolon-delimited data
        $csv = "\xEF\xBB\xBF\"1. Girokonto\";\"DE000000000000006543\"\n"
             . "\"Zeitraum:\";\"25.03.2026 - 25.03.2026\"\n"
             . "\"Kontostand vom 25.03.2026:\";\"1.807,02 €\"\n"
             . "\n"
             . "\"Buchungsdatum\";\"Wertstellung\";\"Status\";\"Zahlungspflichtige*r\";\"Zahlungsempfänger*in\";\"Verwendungszweck\";\"Umsatztyp\";\"IBAN\";\"Betrag (€)\";\"Gläubiger-ID\";\"Mandatsreferenz\"\n"
             . "\"25.03.26\";\"25.03.26\";\"Gebucht\";\"ISSUER\";\"Lidl\";\"VISA Debitkartenumsatz\";\"Ausgang\";\"DE000000000000000000\";\"-57,68\";\"\";\"\"\n";

        $result = $this->factory->parse($csv, 'csv', null, ';');

        $this->assertCount(1, $result);
        // Should use actual data headers, not preamble
        $this->assertArrayHasKey('Buchungsdatum', $result[0]);
        $this->assertArrayHasKey('Betrag (€)', $result[0]);
        $this->assertEquals('25.03.26', $result[0]['Buchungsdatum']);
        $this->assertEquals('Lidl', $result[0]['Zahlungsempfänger*in']);
        $this->assertEquals('-57,68', $result[0]['Betrag (€)']);
    }

    // ===== parse unsupported format =====

    public function testParseUnsupportedFormatThrows(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported format');

        $this->factory->parse('data', 'xml');
    }

    // ===== countRows =====

    public function testCountRowsCsv(): void {
        $csv = "Date,Amount\n2026-01-01,10\n2026-01-02,20\n2026-01-03,30\n";

        $count = $this->factory->countRows($csv, 'csv');

        $this->assertEquals(3, $count);
    }

    public function testCountRowsCsvEmptyContent(): void {
        $count = $this->factory->countRows('', 'csv');

        $this->assertEquals(0, $count);
    }

    public function testCountRowsCsvHeaderOnly(): void {
        $csv = "Date,Amount,Description\n";

        $count = $this->factory->countRows($csv, 'csv');

        $this->assertEquals(0, $count);
    }

    // ===== getSupportedFormats =====

    public function testGetSupportedFormats(): void {
        $formats = $this->factory->getSupportedFormats();

        $this->assertEquals(['csv', 'ofx', 'qif'], $formats);
    }

    // ===== parseFull =====

    public function testParseFullCsvReturnsDefaultStructure(): void {
        $csv = "Date,Amount\n2026-01-01,100\n";

        $result = $this->factory->parseFull($csv, 'csv');

        $this->assertArrayHasKey('accounts', $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertEmpty($result['accounts']);
        $this->assertCount(1, $result['transactions']);
    }
}
