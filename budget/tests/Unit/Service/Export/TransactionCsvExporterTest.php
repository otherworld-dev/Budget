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
        $this->assertCount(11, $row);
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
