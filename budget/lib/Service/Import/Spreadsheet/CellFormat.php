<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * How a spreadsheet cell's value is written as text.
 *
 * Cells are written as their values, not as the spreadsheet displays them. A
 * displayed amount follows the cell's number format (it may round, or group
 * thousands), and Excel's default date format follows the locale of whoever
 * opens the file, so neither could be read back reliably.
 */
final class CellFormat {
	/** Built-in number format ids that show a date or time */
	private const DATE_FORMAT_IDS = [
		14, 15, 16, 17, 18, 19, 20, 21, 22,
		27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
		45, 46, 47,
		50, 51, 52, 53, 54, 55, 56, 57, 58,
	];

	/**
	 * Whether a number format shows a date or time. A format code, when the
	 * file gives one, decides; otherwise the built-in id does.
	 */
	public static function isDateFormat(int $id, ?string $code): bool {
		if ($code !== null && $code !== '') {
			return self::isDateCode($code);
		}
		return in_array($id, self::DATE_FORMAT_IDS, true);
	}

	/**
	 * Whether a format code shows a date or time: it uses a date or time
	 * token once quoted text, escaped characters, padding, colours, locales
	 * and conditions are taken out. Elapsed-time brackets ([h], [mm]) stay.
	 */
	public static function isDateCode(string $code): bool {
		$code = preg_replace('/"[^"]*"/', '', $code) ?? '';
		$code = preg_replace('/\\\\.|_.|\*./', '', $code) ?? '';
		$code = preg_replace('/\[(?![hms]+\])[^\]]*\]/i', '', $code) ?? '';
		$code = str_ireplace('general', '', $code);
		return preg_match('/[dmyhs]/i', $code) === 1;
	}

	/**
	 * A number as text, rounded to the 15 significant digits Excel shows, so
	 * arithmetic noise (0.30000000000000004) is written as the bank's
	 * spreadsheet displays it.
	 *
	 * The importer reads a lone separator followed by exactly three digits,
	 * with one to three digits before it, as grouping thousands ("1.234" is
	 * 1234; see TransactionNormalizer::groupsThousands). A value written here
	 * never groups thousands, so a trailing zero keeps 1.234 from being read
	 * as 1234.
	 */
	public static function numberText(float $value): string {
		if (!is_finite($value)) {
			return '';
		}
		if ($value == 0.0) {
			return '0';
		}
		$magnitude = (int)floor(log10(abs($value)));
		$text = (string)round($value, 14 - $magnitude);
		return preg_match('/^-?[1-9]\d{0,2}\.\d{3}$/', $text) === 1 ? $text . '0' : $text;
	}

	/**
	 * A date serial (days since the workbook's epoch) as Y-m-d. A transaction
	 * has no time, so a time on a date is dropped; a serial below one (a time
	 * on its own) is written as H:i:s.
	 *
	 * In the 1900 system serial 1 is 1900-01-01 and serial 60 is
	 * 1900-02-29, a day that never existed which Excel keeps for Lotus 1-2-3
	 * compatibility. In the 1904 system serial 0 is 1904-01-01.
	 */
	public static function serialDateText(float $serial, bool $date1904): string {
		if ($serial < 0) {
			return self::numberText($serial);
		}
		$days = (int)floor($serial);
		$seconds = (int)round(($serial - $days) * 86400);
		if ($seconds >= 86400) {
			$days++;
			$seconds -= 86400;
		}
		if ($days === 0 && !$date1904) {
			return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
		}
		if ($date1904) {
			$epoch = '1904-01-01';
			$offset = $days;
		} else {
			if ($days === 60) {
				return '1900-02-29';
			}
			$epoch = '1900-01-01';
			$offset = $days < 60 ? $days - 1 : $days - 2;
		}
		return (new \DateTimeImmutable($epoch, new \DateTimeZone('UTC')))
			->modify('+' . $offset . ' days')
			->format('Y-m-d');
	}

	/**
	 * An ISO 8601 date or time as text: the date part of a date, or the time
	 * of a time on its own.
	 */
	public static function isoText(string $value): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match) === 1) {
			return $match[0];
		}
		if (preg_match('/(?:^|T)(\d{2}:\d{2}:\d{2})/', $value, $match) === 1) {
			return $match[1];
		}
		return trim($value);
	}
}
