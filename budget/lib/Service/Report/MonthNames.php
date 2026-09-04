<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCP\IL10N;

/**
 * Month names in the user's language for generated documents — the PDF and
 * CSV exports and the scheduled report email. PHP's date('M') only speaks
 * English, and the browser already translates these twelve pairs, so the
 * same msgids are reused here rather than adding new ones (#377).
 */
final class MonthNames {
    /**
     * Abbreviated name: Jan … Dec. An out-of-range month comes back as its number.
     */
    public static function short(IL10N $l, int $month): string {
        return match ($month) {
            1 => $l->t('Jan'),
            2 => $l->t('Feb'),
            3 => $l->t('Mar'),
            4 => $l->t('Apr'),
            5 => $l->t('May'),
            6 => $l->t('Jun'),
            7 => $l->t('Jul'),
            8 => $l->t('Aug'),
            9 => $l->t('Sep'),
            10 => $l->t('Oct'),
            11 => $l->t('Nov'),
            12 => $l->t('Dec'),
            default => (string) $month,
        };
    }

    /**
     * Full name: January … December. An out-of-range month comes back as its number.
     */
    public static function long(IL10N $l, int $month): string {
        return match ($month) {
            1 => $l->t('January'),
            2 => $l->t('February'),
            3 => $l->t('March'),
            4 => $l->t('April'),
            5 => $l->t('May'),
            6 => $l->t('June'),
            7 => $l->t('July'),
            8 => $l->t('August'),
            9 => $l->t('September'),
            10 => $l->t('October'),
            11 => $l->t('November'),
            12 => $l->t('December'),
            default => (string) $month,
        };
    }

    /**
     * "Jan 2026" — or "Jan 26" with $twoDigitYear — for a YYYY-MM string.
     * Anything that is not one is returned untouched.
     */
    public static function shortWithYear(IL10N $l, string $yearMonth, bool $twoDigitYear = false): string {
        if (!preg_match('/^(\d{4})-(\d{2})/', $yearMonth, $m)) {
            return $yearMonth;
        }
        $year = $twoDigitYear ? substr($m[1], 2) : $m[1];
        return self::short($l, (int) $m[2]) . ' ' . $year;
    }
}
