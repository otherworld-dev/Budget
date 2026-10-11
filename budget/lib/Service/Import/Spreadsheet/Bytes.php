<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Little-endian reads from binary data, each bounds-checked: running past
 * the end is a malformed file, never a PHP warning.
 */
final class Bytes {
	public static function u8(string $data, int $offset): int {
		self::need($data, $offset, 1);
		return ord($data[$offset]);
	}

	public static function u16(string $data, int $offset): int {
		self::need($data, $offset, 2);
		return self::unpacked('v', $data, $offset);
	}

	public static function u32(string $data, int $offset): int {
		self::need($data, $offset, 4);
		return self::unpacked('V', $data, $offset);
	}

	public static function f64(string $data, int $offset): float {
		self::need($data, $offset, 8);
		$value = unpack('e', $data, $offset);
		return is_array($value) ? (float)$value[1] : 0.0;
	}

	/**
	 * @return list<int>
	 */
	public static function u32s(string $data, int $offset, int $count): array {
		if ($count === 0) {
			return [];
		}
		self::need($data, $offset, 4 * $count);
		$values = unpack('V' . $count, $data, $offset);
		return is_array($values) ? array_values(array_map('intval', $values)) : [];
	}

	public static function utf16(string $bytes): string {
		return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
	}

	/** BIFF's "compressed" strings: the low byte of each UTF-16 unit, which is Latin-1 */
	public static function latin1(string $bytes): string {
		return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
	}

	private static function unpacked(string $format, string $data, int $offset): int {
		$value = unpack($format, $data, $offset);
		return is_array($value) ? (int)$value[1] : 0;
	}

	private static function need(string $data, int $offset, int $length): void {
		if ($offset < 0 || $length < 0 || $offset + $length > strlen($data)) {
			throw new SpreadsheetReadError('Unexpected end of data');
		}
	}
}
