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
