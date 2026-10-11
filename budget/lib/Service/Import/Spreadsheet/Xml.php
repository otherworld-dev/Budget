<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Streaming XML reading for the spreadsheet readers. Never loads anything
 * from the network, and external entities are left unexpanded (PHP 8's
 * default; asserted in XlsxReaderTest).
 */
final class Xml {
	public static function reader(string $xml): \XMLReader {
		$reader = new \XMLReader();
		if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
			throw new SpreadsheetReadError('Unreadable XML');
		}
		return $reader;
	}

	/**
	 * An attribute of the current element by local name, whatever its
	 * namespace prefix (transitional and strict xlsx use different ones).
	 */
	public static function attr(\XMLReader $reader, string $localName): ?string {
		if (!$reader->hasAttributes || !$reader->moveToFirstAttribute()) {
			return null;
		}
		$value = null;
		do {
			if ($reader->localName === $localName) {
				$value = $reader->value;
				break;
			}
		} while ($reader->moveToNextAttribute());
		$reader->moveToElement();
		return $value;
	}
}
