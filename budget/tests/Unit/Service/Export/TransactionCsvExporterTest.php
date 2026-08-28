<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Export;

use OCA\Budget\Service\Export\TransactionCsvExporter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class TransactionCsvExporterTest extends TestCase {
    private TransactionCsvExporter $exporter;

    protected function setUp(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn(string $text, array $params = []) => vsprintf($text, $params));
        $this->exporter = new TransactionCsvExporter($l);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function transaction(array $overrides = []): array {
        return array_merge([
            'date' => '2026-01-15',
            'description' => 'Membership fees',
            'vendor' => 'Sports Club',
            'categoryName' => 'Subscriptions',
            'accountName' => 'Club Current Account',
            'accountCurrency' => 'EUR',
            'type' => 'credit',
            'amount' => 120.0,
            'reference' => 'REF-1',
            'notes' => 'Annual',
            'status' => 'cleared',
        ], $overrides);
    }

    private function csvFor(array $transactions): string {
        $handle = fopen('php://memory', 'w+');
        $this->exporter->write($handle, [$transactions]);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // ===== split transactions =====

    /**
     * A split carries no category of its own, so an export with no category
     * filter wrote an empty Category cell for it -- the one column that says
     * what the money was for, blank on exactly the transactions someone took
     * the trouble to itemise (#360).
     */
    public function testASplitNamesItsCategoriesRatherThanLeavingTheCellEmpty(): void {
        $row = $this->exporter->dataRow($this->transaction([
            'categoryName' => null,
            'isSplit' => true,
            'splitCategories' => [
                ['categoryId' => 2, 'categoryName' => 'Groceries', 'amount' => 12.40],
                ['categoryId' => 9, 'categoryName' => 'Household', 'amount' => 70.00],
            ],
        ]));

        $this->assertSame('Groceries / Household', $row[3]);
    }

    public function testASplitNamesACategoryOnceWhenTwoPartsShareIt(): void {
        $row = $this->exporter->dataRow($this->transaction([
            'categoryName' => null,
            'isSplit' => true,
            'splitCategories' => [
                ['categoryId' => 2, 'categoryName' => 'Groceries', 'amount' => 12.40],
                ['categoryId' => 2, 'categoryName' => 'Groceries', 'amount' => 5.00],
                ['categoryId' => 9, 'categoryName' => 'Household', 'amount' => 65.00],
            ],
        ]));

        $this->assertSame('Groceries / Household', $row[3]);
    }

    /** Under a category filter the row still reports the matched share (#359). */
    public function testAFilteredShareStillReportsTheMatchedCategory(): void {
        $row = $this->exporter->dataRow($this->transaction([
            'categoryName' => null,
            'isSplit' => true,
            'matchedSplitAmount' => 12.40,
            'matchedSplitCategoryName' => 'Groceries',
            'splitCategories' => [
                ['categoryId' => 2, 'categoryName' => 'Groceries', 'amount' => 12.40],
                ['categoryId' => 9, 'categoryName' => 'Household', 'amount' => 70.00],
            ],
        ]));

        $this->assertSame('Groceries', $row[3]);
    }

    /**
     * matchedSplitCategoryName resolves to '' when the matched category was
     * since deleted. The row is still a share (matchedSplitAmount is set), so
     * it must not fall through to the "list every part" fallback -- that
     * would mislabel the share with categories it never actually matched
     * (#360).
     */
    public function testAShareWithADeletedCategoryStaysBlankRatherThanListingAllParts(): void {
        $row = $this->exporter->dataRow($this->transaction([
            'categoryName' => null,
            'isSplit' => true,
            'matchedSplitAmount' => 12.40,
            'matchedSplitCategoryName' => '',
            'splitCategories' => [
                ['categoryId' => 2, 'categoryName' => 'Groceries', 'amount' => 12.40],
                ['categoryId' => 9, 'categoryName' => 'Household', 'amount' => 70.00],
            ],
        ]));

        $this->assertSame('', $row[3]);
    }

    public function testAnOrdinaryRowKeepsItsOwnCategory(): void {
        $row = $this->exporter->dataRow($this->transaction(['categoryName' => 'Fuel']));

        $this->assertSame('Fuel', $row[3]);
    }

    // ===== signing =====

    public function testExpensesAreNegativeAndIncomeIsPositive(): void {
        $expense = $this->exporter->dataRow($this->transaction(['type' => 'debit', 'amount' => 57.68]));
        $income = $this->exporter->dataRow($this->transaction(['type' => 'credit', 'amount' => 120.0]));

        $this->assertSame('-57.68', $expense[6]);
        $this->assertSame('120.00', $income[6]);
    }

    public function testStoredAmountIsNeverDoubleNegated(): void {
        // Amounts are stored as magnitudes, but a negative one must not flip back
        // to positive and turn an expense into income in the total.
        $row = $this->exporter->dataRow($this->transaction(['type' => 'debit', 'amount' => -57.68]));

        $this->assertSame('-57.68', $row[6]);
    }

    public function testUnknownTypeIsLeftUnsigned(): void {
        $row = $this->exporter->dataRow($this->transaction(['type' => 'wibble', 'amount' => 10.0]));

        $this->assertSame('10.00', $row[6]);
        $this->assertSame('wibble', $row[5]);
    }

    public function testAmountHasNoThousandsSeparator(): void {
        // A separator would split the value across two CSV cells.
        $row = $this->exporter->dataRow($this->transaction(['type' => 'credit', 'amount' => 1234567.5]));

        $this->assertSame('1234567.50', $row[6]);
    }

    // ===== labels =====

    public function testTypeIsLabelledInWordsNotDebitCredit(): void {
        $expense = $this->exporter->dataRow($this->transaction(['type' => 'debit']));
        $income = $this->exporter->dataRow($this->transaction(['type' => 'credit']));

        $this->assertSame('Expense', $expense[5]);
        $this->assertSame('Income', $income[5]);
    }

    // ===== CSV shape =====

    public function testWriteEmitsHeaderThenOneRowPerTransaction(): void {
        $csv = $this->csvFor([$this->transaction(), $this->transaction(['description' => 'Pitch hire'])]);
        $lines = array_values(array_filter(explode("\n", $csv), fn($l) => trim($l) !== ''));

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Date,Description,Vendor,Category,Account,Type,Amount', $lines[0]);
    }

    public function testWriteEmitsHeaderEvenWithNoTransactions(): void {
        $csv = $this->csvFor([]);

        $this->assertStringContainsString('Date,Description', $csv);
    }

    public function testSeparatorsAndQuotesInTextAreEscaped(): void {
        $csv = $this->csvFor([$this->transaction([
            'description' => 'Kit, boots and 12" socks',
            'notes' => "Line one\nLine two",
        ])]);

        $this->assertStringContainsString('"Kit, boots and 12"" socks"', $csv);
        $this->assertStringContainsString("\"Line one\nLine two\"", $csv);
    }

    public function testMissingFieldsBecomeEmptyCellsRatherThanErrors(): void {
        $row = $this->exporter->dataRow(['date' => '2026-01-15', 'type' => 'debit', 'amount' => 5.0]);

        $this->assertSame('2026-01-15', $row[0]);
        $this->assertSame('', $row[1]);
        $this->assertSame('', $row[3]);
        $this->assertSame('-5.00', $row[6]);
        $this->assertSame('', $row[11]);
        $this->assertCount(12, $row);
    }

    // ===== split shares (#359) =====

    public function testSplitShareIsExportedInsteadOfTheWholeTransaction(): void {
        // Filtering by category matches a split through its parts, so the row
        // has to report the part that matched or the column no longer totals
        // the category.
        $row = $this->exporter->dataRow($this->transaction([
            'type' => 'debit',
            'amount' => 82.40,
            'categoryName' => null,
            'matchedSplitAmount' => 12.40,
            'matchedSplitCategoryName' => 'Groceries',
        ]));

        $this->assertSame('Groceries', $row[3]);
        $this->assertSame('-12.40', $row[6]);
        $this->assertSame('-82.40', $row[11]);
    }

    public function testNegativeSplitPartKeepsItsSign(): void {
        // A receipt's discount line is a negative part of an expense; abs() would
        // book the saving as spending.
        $row = $this->exporter->dataRow($this->transaction([
            'type' => 'debit',
            'amount' => 82.40,
            'matchedSplitAmount' => -3.50,
            'matchedSplitCategoryName' => 'Savings',
        ]));

        $this->assertSame('3.50', $row[6]);
    }

    public function testZeroSplitShareIsExportedRatherThanTreatedAsAbsent(): void {
        $row = $this->exporter->dataRow($this->transaction([
            'type' => 'debit',
            'amount' => 82.40,
            'matchedSplitAmount' => 0.0,
            'matchedSplitCategoryName' => 'Groceries',
        ]));

        $this->assertSame('Groceries', $row[3]);
        $this->assertSame('0.00', $row[6]);
        $this->assertSame('-82.40', $row[11]);
    }

    public function testRowsWithoutASplitShareAreUnchanged(): void {
        $row = $this->exporter->dataRow($this->transaction(['type' => 'debit', 'amount' => 57.68]));

        $this->assertSame('Subscriptions', $row[3]);
        $this->assertSame('-57.68', $row[6]);
        $this->assertSame('', $row[11]);
    }

    public function testHeaderCarriesTheSplitColumnLast(): void {
        // Trailing, so an existing spreadsheet template keeps its offsets.
        $header = $this->exporter->headerRow();

        $this->assertCount(12, $header);
        $this->assertSame('Split of', $header[11]);
    }

    public function testWriteConsumesEveryBatch(): void {
        $handle = fopen('php://memory', 'w+');
        $batches = (function () {
            yield [$this->transaction()];
            yield [$this->transaction(), $this->transaction()];
        })();

        $this->exporter->write($handle, $batches);
        rewind($handle);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($handle)), fn($l) => trim($l) !== ''));
        fclose($handle);

        $this->assertCount(4, $lines);
    }
}
