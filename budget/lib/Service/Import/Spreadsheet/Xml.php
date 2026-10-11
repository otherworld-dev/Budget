<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Streaming XML reading for the spreadsheet readers. Never loads anything
 * from the network.
 *
 * XML with a DOCTYPE is refused outright, in whatever encoding it is
 * written. Spreadsheet XML (OOXML, ODF) never declares one, and without it
 * there are no entities to expand, so neither external entities nor nested
 * entity expansion ("billion laughs") can reach libxml.
 */
final class Xml {
	public static function reader(string $xml): \XMLReader {
		self::refuseDoctype($xml);
		return self::open($xml);
	}

	/**
	 * The plain search catches the usual case cheaply. It cannot see a
	 * DOCTYPE written in another encoding (UTF-16, say), which libxml decodes
	 * all the same, so the parser is asked too: everything before the root
	 * element is read, and a DTD node refused. Entities are only expanded in
	 * content, after the root element starts, so none ever is.
	 */
	private static function refuseDoctype(string $xml): void {
		if (stripos($xml, '<!DOCTYPE') !== false) {
			throw new SpreadsheetReadError('XML with a DOCTYPE');
		}
		$probe = self::open($xml);
		try {
			while ($probe->read()) {
				if ($probe->nodeType === \XMLReader::DOC_TYPE) {
					throw new SpreadsheetReadError('XML with a DOCTYPE');
				}
				if ($probe->nodeType === \XMLReader::ELEMENT) {
					return;
				}
			}
		} finally {
			$probe->close();
		}
	}

	private static function open(string $xml): \XMLReader {
		// Errors are collected rather than raised as warnings, and complete()
		// looks at them once the part has been read
		libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = new \XMLReader();
		if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
			throw new SpreadsheetReadError('Unreadable XML');
		}
		return $reader;
	}

	/**
	 * Refuse a part whose reading stopped on an error. XMLReader::read()
	 * returns false for a broken document just as it does at the end of a
	 * good one, and a part cut short there is a statement silently missing
	 * its strings or its later rows.
	 */
	public static function complete(\XMLReader $reader): void {
		$error = libxml_get_last_error();
		$reader->close();
		if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
			throw new SpreadsheetReadError('Broken XML: ' . trim($error->message));
		}
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
