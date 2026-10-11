<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads an OpenDocument spreadsheet (.ods): every table, cells as text.
 *
 * ODS pads a sheet with repeated empty cells and rows (a million rows is
 * normal), so an empty repeat only moves the position on and is never
 * written out.
 */
final class OdsReader {
	private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function read(ZipPackage $zip): array {
		$reader = Xml::reader($zip->read('content.xml'));
		$document = new \DOMDocument();
		$sheets = [];
		$builder = null;
		$row = 0;
		$rowRepeat = 1;
		$column = 0;
		/** @var array<int, string> $cells */
		$cells = [];

		$ok = $reader->read();
		while ($ok) {
			if ($reader->namespaceURI === self::TABLE) {
				$start = $reader->nodeType === \XMLReader::ELEMENT;
				$end = $reader->nodeType === \XMLReader::END_ELEMENT || ($start && $reader->isEmptyElement);
				switch ($reader->localName) {
					case 'table':
						if ($start) {
							$builder = new SheetBuilder($reader->getAttributeNs('name', self::TABLE) ?? '');
							$row = 0;
						}
						if ($end && $builder !== null) {
							$sheets[] = $builder->build();
							$builder = null;
						}
						break;
					case 'table-row':
						if ($start) {
							$rowRepeat = max(1, (int)($reader->getAttributeNs('number-rows-repeated', self::TABLE) ?? 1));
							$column = 0;
							$cells = [];
						}
						if ($end) {
							if ($builder !== null && $cells !== []) {
								// Identical filled rows repeated: written out, up to the limit
								for ($i = 0, $times = min($rowRepeat, SheetBuilder::MAX_ROWS + 1); $i < $times; $i++) {
									foreach ($cells as $index => $text) {
										$builder->add($row + $i, $index, $text);
									}
								}
							}
							$row += $rowRepeat;
						}
						break;
					case 'table-cell':
					case 'covered-table-cell':
						if ($start) {
							$repeat = max(1, (int)($reader->getAttributeNs('number-columns-repeated', self::TABLE) ?? 1));
							$text = $this->cellText($reader, $document);
							if ($text !== '') {
								for ($i = 0; $i < $repeat && $column + $i < SheetBuilder::MAX_COLUMNS; $i++) {
									$cells[$column + $i] = $text;
								}
							}
							$column += $repeat;
							$ok = $reader->next();
							continue 2;
						}
						break;
				}
			}
			$ok = $reader->read();
		}
		return $sheets;
	}

	private function cellText(\XMLReader $reader, \DOMDocument $document): string {
		switch ($reader->getAttributeNs('value-type', self::OFFICE)) {
			case 'float':
			case 'currency':
			case 'percentage':
				$value = $reader->getAttributeNs('value', self::OFFICE);
				return $value !== null && is_numeric($value) ? CellFormat::numberText((float)$value) : '';
			case 'date':
				return CellFormat::isoText($reader->getAttributeNs('date-value', self::OFFICE) ?? '');
			case 'time':
				$value = $reader->getAttributeNs('time-value', self::OFFICE) ?? '';
				return preg_match('/^PT(\d+)H(\d+)M(\d+)/', $value, $match) === 1
					? sprintf('%02d:%02d:%02d', (int)$match[1], (int)$match[2], (int)$match[3])
					: '';
			case 'boolean':
				return $reader->getAttributeNs('boolean-value', self::OFFICE) === 'true' ? 'TRUE' : 'FALSE';
		}
		if ($reader->isEmptyElement) {
			return '';
		}
		$node = $reader->expand($document);
		if (!$node instanceof \DOMElement) {
			return '';
		}
		// The cell's own paragraphs; a comment (office:annotation) has its own
		$parts = [];
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === self::TEXT && $child->localName === 'p') {
				$text = trim($child->textContent);
				if ($text !== '') {
					$parts[] = $text;
				}
			}
		}
		return implode(' ', $parts);
	}
}
