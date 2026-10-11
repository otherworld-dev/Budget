<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * The shared string table (SST) of a BIFF8 workbook.
 *
 * A table longer than one record carries on in CONTINUE records. A string's
 * header never splits, so between strings the next record just follows on;
 * but characters can split, and then the next record starts with a fresh
 * flags byte saying whether the rest are one byte or two. Formatting runs
 * and phonetic data after the characters are skipped across records with no
 * flags byte.
 */
final class BiffStringTable {
	private int $segment = 0;
	private int $position = 0;

	/**
	 * @param list<string> $segments
	 */
	private function __construct(
		private array $segments,
	) {
	}

	/**
	 * @param list<string> $segments The SST record's data, then each following CONTINUE's
	 * @return list<string>
	 */
	public static function parse(array $segments): array {
		$table = new self($segments);
		$table->skip(4); // total use count
		$unique = $table->u32();
		$strings = [];
		for ($i = 0; $i < $unique && !$table->atEnd(); $i++) {
			$length = $table->u16();
			$flags = $table->u8();
			$runs = ($flags & 0x08) !== 0 ? $table->u16() : 0;
			$phonetic = ($flags & 0x04) !== 0 ? $table->u32() : 0;
			$strings[] = $table->characters($length, ($flags & 0x01) !== 0);
			$table->skip(4 * $runs + $phonetic);
		}
		return $strings;
	}

	private function atEnd(): bool {
		while ($this->segment < count($this->segments) && $this->position >= strlen($this->segments[$this->segment])) {
			$this->segment++;
			$this->position = 0;
		}
		return $this->segment >= count($this->segments);
	}

	private function take(int $count): string {
		$bytes = '';
		while ($count > 0) {
			if ($this->atEnd()) {
				throw new SpreadsheetReadError('Shared string table ends early');
			}
			$chunk = substr($this->segments[$this->segment], $this->position, $count);
			$bytes .= $chunk;
			$this->position += strlen($chunk);
			$count -= strlen($chunk);
		}
		return $bytes;
	}

	private function skip(int $count): void {
		while ($count > 0) {
			if ($this->atEnd()) {
				throw new SpreadsheetReadError('Shared string table ends early');
			}
			$step = min($count, strlen($this->segments[$this->segment]) - $this->position);
			$this->position += $step;
			$count -= $step;
		}
	}

	private function u8(): int {
		return Bytes::u8($this->take(1), 0);
	}

	private function u16(): int {
		return Bytes::u16($this->take(2), 0);
	}

	private function u32(): int {
		return Bytes::u32($this->take(4), 0);
	}

	private function characters(int $count, bool $wide): string {
		$text = '';
		while ($count > 0) {
			$segment = $this->segments[$this->segment] ?? null;
			if ($segment === null || $this->position >= strlen($segment)) {
				$this->segment++;
				$segment = $this->segments[$this->segment] ?? throw new SpreadsheetReadError('Shared string table ends early');
				$wide = (Bytes::u8($segment, 0) & 0x01) !== 0;
				$this->position = 1;
			}
			$available = strlen($segment) - $this->position;
			$take = min($count, $wide ? intdiv($available, 2) : $available);
			if ($take === 0) {
				throw new SpreadsheetReadError('Broken shared string');
			}
			$chunk = substr($segment, $this->position, $take * ($wide ? 2 : 1));
			$text .= $wide ? Bytes::utf16($chunk) : Bytes::latin1($chunk);
			$this->position += strlen($chunk);
			$count -= $take;
		}
		return $text;
	}
}
