<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Export;

use OCA\Budget\Enum\TransactionType;
use OCP\IL10N;

/**
 * Writes transaction rows as CSV for the "export what I'm looking at" download.
 *
 * Amounts are signed here rather than left as the stored magnitude: the whole
 * point of the export is that a treasurer can total the column in a spreadsheet,
 * and the direction otherwise lives only in the type column (#344).
 */
class TransactionCsvExporter {
    public function __construct(
        private IL10N $l,
    ) {
    }

    /**
     * @return string[]
     */
    public function headerRow(): array {
        return [
            $this->l->t('Date'),
            $this->l->t('Description'),
            $this->l->t('Vendor'),
            $this->l->t('Category'),
            $this->l->t('Account'),
            $this->l->t('Type'),
            $this->l->t('Amount'),
            $this->l->t('Currency'),
            $this->l->t('Reference'),
            $this->l->t('Notes'),
            $this->l->t('Status'),
            // Trailing so an existing spreadsheet template keeps its column
            // offsets; blank on every row that isn't a split share (#359).
            $this->l->t('Split of'),
        ];
    }

    /**
     * One CSV row for a transaction as findWithFilters() returns it.
     *
     * @param array<string, mixed> $transaction
     * @return string[]
     */
    public function dataRow(array $transaction): array {
        // A split transaction has no category of its own, so under a category
        // filter the row reports the share that put it in the list, and the
        // whole transaction goes in the trailing column (#359).
        $isShare = ($transaction['matchedSplitAmount'] ?? null) !== null;
        $category = $isShare
            ? (string)($transaction['matchedSplitCategoryName'] ?? '')
            : (string)($transaction['categoryName'] ?? '');

        return [
            (string)($transaction['date'] ?? ''),
            (string)($transaction['description'] ?? ''),
            (string)($transaction['vendor'] ?? ''),
            $category,
            (string)($transaction['accountName'] ?? ''),
            $this->typeLabel((string)($transaction['type'] ?? '')),
            $this->signedAmount($transaction),
            (string)($transaction['accountCurrency'] ?? ''),
            (string)($transaction['reference'] ?? ''),
            (string)($transaction['notes'] ?? ''),
            (string)($transaction['status'] ?? ''),
            $isShare ? $this->formatAmount(abs((float)($transaction['amount'] ?? 0)), $transaction) : '',
        ];
    }

    /**
     * Write the header and every row of each batch to an open stream.
     *
     * @param resource $handle
     * @param iterable<array<int, array<string, mixed>>> $batches
     */
    public function write($handle, iterable $batches): void {
        fputcsv($handle, $this->headerRow());

        foreach ($batches as $batch) {
            foreach ($batch as $transaction) {
                fputcsv($handle, $this->dataRow($transaction));
            }
        }
    }

    /**
     * The amount as it should be summed: negative for money out, positive for
     * money in. Transfers have no type of their own — they are two linked legs,
     * one of each — so signing every row also makes a transfer net to zero.
     *
     * @param array<string, mixed> $transaction
     */
    private function signedAmount(array $transaction): string {
        $share = $transaction['matchedSplitAmount'] ?? null;

        // A split share keeps its own sign: a receipt's discount line is a
        // negative part of a debit, and abs() would silently book it as spending.
        $amount = $share !== null ? (float)$share : abs((float)($transaction['amount'] ?? 0));

        return $this->formatAmount($amount, $transaction);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function formatAmount(float $amount, array $transaction): string {
        $type = TransactionType::tryFrom((string)($transaction['type'] ?? ''));

        // An unrecognised type would silently flip a sign, so leave it unsigned
        // rather than guess which way the money went.
        $sign = $type?->balanceMultiplier() ?? 1;

        return number_format($amount * $sign, 2, '.', '');
    }

    private function typeLabel(string $type): string {
        return match (TransactionType::tryFrom($type)) {
            TransactionType::CREDIT => $this->l->t('Income'),
            TransactionType::DEBIT => $this->l->t('Expense'),
            default => $type,
        };
    }
}
