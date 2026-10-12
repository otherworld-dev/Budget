<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

/**
 * Turns a saved CSV template's column mapping into the column indexes the
 * importer reads, for one file (#425).
 *
 * A template stores the header a column had when it was saved, or the
 * column's position when there was no header to store. On the import page
 * the browser matches those against the file in front of it
 * (ImportModule.js: sanitizeHeaders, resolveTemplateColumn); a background
 * import has no browser, so this is the same matching on the server.
 */
final class TemplateColumnResolver {
	private const COLUMN_FIELDS = [
		'date', 'amount', 'incomeColumn', 'expenseColumn', 'description',
		'notes', 'type', 'vendor', 'reference', 'category', 'account', 'currency',
	];

	private const AMOUNT_FIELDS = ['amount', 'incomeColumn', 'expenseColumn'];

	/** Columns of a file a template can refer to */
	private const MAX_COLUMNS = 1000;

	/**
	 * @param array<string, mixed> $templateMapping The template's stored mapping
	 * @param array<array-key, string> $headers The file's header row (blank cells for a file without one)
	 * @param list<string> $columnLabelFormats "Column: {number}" in each language a
	 *                                         template may have been saved in
	 * @return array{mapping: array<string, mixed>, missing: list<string>}
	 *                                                                     mapping: field => column index (as a string) or a list of them;
	 *                                                                     missing: what the template calls each required column the file lacks
	 */
	public function resolve(array $templateMapping, array $headers, array $columnLabelFormats = ['Column: {number}']): array {
		// A statement has a handful of columns; one line of separators can
		// claim hundreds of thousands. Nothing past the limit is ever mapped
		$headers = array_slice(array_values($headers), 0, self::MAX_COLUMNS);
		$labelSets = array_map(
			fn (string $format): array => $this->labels($headers, $format),
			$columnLabelFormats
		);
		$columnCount = count($headers);

		$mapping = [];
		$missing = [];
		foreach (self::COLUMN_FIELDS as $field) {
			$stored = $templateMapping[$field] ?? null;
			if ($stored === null || $stored === '' || $stored === []) {
				continue;
			}
			$required = $field === 'date' || $field === 'description' || in_array($field, self::AMOUNT_FIELDS, true);

			if (is_array($stored)) {
				$indexes = [];
				foreach ($stored as $value) {
					$index = $this->resolveOne($value, $labelSets, $columnCount);
					if ($index !== null && !in_array($index, $indexes, true)) {
						$indexes[] = $index;
					}
				}
				if ($indexes !== []) {
					$mapping[$field] = count($indexes) === 1 ? $indexes[0] : $indexes;
				} elseif ($required) {
					$missing[] = implode(', ', array_map(fn ($value): string => $this->describe($value), $stored));
				}
				continue;
			}

			$index = $this->resolveOne($stored, $labelSets, $columnCount);
			if ($index !== null) {
				$mapping[$field] = $index;
			} elseif ($required) {
				$missing[] = $this->describe($stored);
			}
		}

		foreach (['date', 'description'] as $field) {
			if (!isset($mapping[$field]) && !array_key_exists($field, $templateMapping)) {
				$missing[] = $field;
			}
		}
		$templateHasAmount = array_intersect(self::AMOUNT_FIELDS, array_keys(array_filter(
			$templateMapping,
			static fn ($value): bool => $value !== null && $value !== '' && $value !== []
		))) !== [];
		if (!$templateHasAmount) {
			$missing[] = 'amount';
		}

		return ['mapping' => $mapping, 'missing' => $missing];
	}

	/**
	 * The label each column has on the import page: its header, trimmed; a
	 * blank or repeated header gets "Column: N", with " (2)" and so on if
	 * that is taken too.
	 *
	 * @param list<string> $headers
	 * @return list<string>
	 */
	private function labels(array $headers, string $format): array {
		$labels = [];
		$seen = [];
		foreach ($headers as $i => $header) {
			$candidate = trim((string)$header);
			if ($candidate === '' || isset($seen[$candidate])) {
				$base = str_replace('{number}', (string)($i + 1), $format);
				$candidate = $base;
				for ($n = 2; isset($seen[$candidate]); $n++) {
					$candidate = $base . ' (' . $n . ')';
				}
			}
			$seen[$candidate] = true;
			$labels[] = $candidate;
		}
		return $labels;
	}

	/**
	 * @param list<list<string>> $labelSets
	 */
	private function resolveOne(mixed $value, array $labelSets, int $columnCount): ?string {
		if (is_int($value)) {
			return $value >= 0 && $value < $columnCount ? (string)$value : null;
		}
		if (!is_string($value) || $value === '') {
			return null;
		}
		foreach ($labelSets as $labels) {
			$index = array_search($value, $labels, true);
			if ($index !== false) {
				return (string)$index;
			}
		}
		// Templates saved before headers were stored by name
		if (preg_match('/^Column (\d+)$/', $value, $match) === 1) {
			$index = (int)$match[1] - 1;
			return $index >= 0 && $index < $columnCount ? (string)$index : null;
		}
		return null;
	}

	/** What a stored value is called when it cannot be found: a header as it is, a position as "Column: N" */
	private function describe(mixed $value): string {
		return is_int($value) ? 'Column: ' . ($value + 1) : (string)$value;
	}
}
