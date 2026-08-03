<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

/**
 * Turns the raw text of a till receipt into draft-transaction fields.
 *
 * This is the whole "AI" when the provider is Nextcloud's image2text:ocr
 * task type, which returns plain text rather than structure — and it is
 * deliberately heuristic, not clever: a receipt is a merchant name near the
 * top, lines with a price at the end, and a total near the bottom. Anything
 * the heuristics cannot find is returned as null and becomes a field the
 * user fills in by hand, which is exactly what they did before this feature
 * existed. Wrong guesses are the thing to avoid; missing guesses are fine.
 */
class ReceiptParser {
    /**
     * Lines that end in an amount but are not purchases. Checked against the
     * text BEFORE the amount, so "TOTAL 12.30" is caught but a genuine item
     * called "Total Cereal 3.99" on its own line mostly survives (the word
     * must make up the whole prefix).
     */
    private const NON_ITEM_PREFIXES = [
        'total', 'subtotal', 'sub-total', 'sub total', 'amount due', 'balance due',
        'balance', 'to pay', 'grand total', 'tax', 'vat', 'gst', 'hst', 'tip',
        'service', 'cash', 'card', 'credit', 'debit', 'visa', 'mastercard', 'amex',
        'change', 'change due', 'tendered', 'payment', 'paid', 'rounding', 'discount',
        'savings', 'loyalty', 'points',
    ];

    /** Words that mark the line carrying the printed total. */
    private const TOTAL_MARKERS = [
        'grand total', 'amount due', 'balance due', 'total due', 'to pay', 'total',
    ];

    /**
     * @return array{
     *   merchant: ?string,
     *   date: ?string,
     *   total: ?string,
     *   lineItems: list<array{description: string, amount: string}>,
     * }
     */
    public function parse(string $text): array {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            static fn (string $line) => $line !== ''
        ));

        return [
            'merchant' => $this->findMerchant($lines),
            'date' => $this->findDate($lines),
            'total' => $this->findTotal($lines),
            'lineItems' => $this->findLineItems($lines),
        ];
    }

    /**
     * The merchant is almost always the first line that looks like a name:
     * skip anything that is mostly digits (a phone number, a VAT id, a shop
     * number) and take what is left, title-cased when the receipt shouts.
     */
    private function findMerchant(array $lines): ?string {
        foreach (array_slice($lines, 0, 5) as $line) {
            $letters = preg_replace('/[^\p{L}]/u', '', $line) ?? '';
            if (mb_strlen($letters) < 3) {
                continue;
            }
            if (preg_match('/#\s*\d/', $line)) {
                // "Store #42", "Till #3" — an identifier, not a name.
                continue;
            }
            if ($this->parseAmount($this->amountToken($line) ?? '') !== null) {
                // A price this early is a line item on a headerless receipt.
                break;
            }

            $merchant = mb_substr($line, 0, 80);

            // ALL-CAPS receipt headers read badly in a form field.
            if ($merchant === mb_strtoupper($merchant) && preg_match('/\p{L}/u', $merchant)) {
                $merchant = mb_convert_case($merchant, MB_CASE_TITLE, 'UTF-8');
            }

            return $merchant;
        }

        return null;
    }

    /**
     * First recognisable date on the receipt, normalised to YYYY-MM-DD.
     * Ambiguous day/month order (01/02/2026) is read day-first — this app's
     * user base skews European, and the wrong guess is one click to fix in a
     * date picker. Dates that cannot be real (month 13, year 1970) are
     * dropped rather than guessed at.
     */
    private function findDate(array $lines): ?string {
        foreach ($lines as $line) {
            // ISO first: unambiguous, increasingly common on card terminals.
            if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $line, $m)) {
                if ($this->isRealDate((int)$m[1], (int)$m[2], (int)$m[3])) {
                    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
            }

            // 31/12/2026, 31.12.26, 31-12-2026 — day first.
            if (preg_match('~\b(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\b~', $line, $m)) {
                $year = (int)$m[3];
                if ($year < 100) {
                    $year += 2000;
                }
                $day = (int)$m[1];
                $month = (int)$m[2];
                // 12/31/2026 can only be month-first; accept the receipt's word for it.
                if ($month > 12 && $day <= 12) {
                    [$day, $month] = [$month, $day];
                }
                if ($this->isRealDate($year, $month, $day)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        }

        return null;
    }

    /**
     * The printed total. Marker lines win (last one, because "TOTAL" often
     * appears once mid-receipt for a subtotal and again at the bottom for
     * the real thing); with no marker at all, the largest amount on the
     * receipt is the best available guess.
     */
    private function findTotal(array $lines): ?string {
        $marked = null;
        $largest = null;

        foreach ($lines as $line) {
            $token = $this->amountToken($line);
            if ($token === null) {
                continue;
            }
            $amount = $this->parseAmount($token);
            if ($amount === null) {
                continue;
            }

            $prefix = mb_strtolower(trim(mb_substr($line, 0, mb_strpos($line, $token) ?: 0)));
            $prefix = trim($prefix, " \t:.-*");
            foreach (self::TOTAL_MARKERS as $marker) {
                if ($prefix !== '' && str_ends_with($prefix, $marker)) {
                    $marked = $amount;
                    break;
                }
            }

            if ($largest === null || (float)$amount > (float)$largest) {
                $largest = $amount;
            }
        }

        return $marked ?? $largest;
    }

    /**
     * Lines shaped like "<description> <amount>" that are not totals, tax,
     * or payment bookkeeping.
     *
     * @return list<array{description: string, amount: string}>
     */
    private function findLineItems(array $lines): array {
        $items = [];

        foreach ($lines as $line) {
            $token = $this->amountToken($line);
            if ($token === null) {
                continue;
            }
            $amount = $this->parseAmount($token);
            if ($amount === null || (float)$amount <= 0) {
                continue;
            }

            $description = trim(mb_substr($line, 0, mb_strpos($line, $token) ?: 0));
            $description = trim($description, " \t:.-*x");
            if ($description === '' || mb_strlen($description) < 2) {
                continue;
            }

            $lower = mb_strtolower($description);
            $isBookkeeping = false;
            foreach (self::NON_ITEM_PREFIXES as $prefix) {
                if ($lower === $prefix || str_ends_with($lower, ' ' . $prefix) || str_starts_with($lower, $prefix . ' ')) {
                    $isBookkeeping = true;
                    break;
                }
            }
            if ($isBookkeeping) {
                continue;
            }

            $items[] = ['description' => mb_substr($description, 0, 100), 'amount' => $amount];
        }

        // A "receipt" that is one wall of prices with no structure produces
        // dozens of junk items; past a sane basket size, trust nothing.
        return count($items) <= 50 ? $items : [];
    }

    /**
     * The last thing on a line that looks like a money amount, currency
     * symbols and sign stripped. Receipts right-align prices, so the last
     * numeric token is the price even when the description contains numbers
     * ("2x Coffee 250ml   7.00").
     */
    private function amountToken(string $line): ?string {
        if (!preg_match_all('/-?[\p{Sc}]?\s*\d[\d.,\s]*\d|\d/u', $line, $m)) {
            return null;
        }
        $token = trim(end($m[0]));

        return $token === '' ? null : $token;
    }

    /**
     * "1,234.56", "1.234,56", "12,34", "£12.34" → "1234.56" | "12.34".
     * A bare integer ("2") is rejected: on a receipt that is a quantity, not
     * a price, and guessing prices is how drafts go wrong.
     */
    private function parseAmount(string $token): ?string {
        $token = preg_replace('/[\p{Sc}\s]/u', '', $token) ?? '';
        if ($token === '' || $token === '-') {
            return null;
        }

        $negative = str_starts_with($token, '-');
        $token = ltrim($token, '-');

        $lastComma = strrpos($token, ',');
        $lastDot = strrpos($token, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both present: the later one is the decimal separator.
            $decimalPos = max($lastComma, $lastDot);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $pos = $lastComma !== false ? $lastComma : $lastDot;
            $digitsAfter = strlen($token) - $pos - 1;
            // "1.234" / "1,234" is a thousands group, not one-tenth of a cent.
            $decimalPos = ($digitsAfter === 3 && strlen($token) > 4) || $digitsAfter === 0 ? null : $pos;
            if ($digitsAfter === 3 && strlen($token) <= 4) {
                $decimalPos = null;
            }
        } else {
            return null; // Bare integer: quantity, not price.
        }

        $digits = preg_replace('/\D/', '', $token) ?? '';
        if ($digits === '' || strlen($digits) > 13) {
            return null;
        }

        if ($decimalPos === null) {
            $whole = $digits;
            $cents = '00';
        } else {
            $decimals = preg_replace('/\D/', '', substr($token, $decimalPos + 1)) ?? '';
            if (strlen($decimals) > 2) {
                return null; // Three-plus decimals is a weight or a unit price.
            }
            $whole = preg_replace('/\D/', '', substr($token, 0, $decimalPos)) ?? '0';
            $cents = str_pad($decimals, 2, '0');
        }

        $whole = ltrim($whole, '0');

        return ($negative ? '-' : '') . ($whole === '' ? '0' : $whole) . '.' . $cents;
    }

    private function isRealDate(int $year, int $month, int $day): bool {
        return $year >= 2000 && $year <= (int)date('Y') + 1 && checkdate($month, $day, $year);
    }
}
