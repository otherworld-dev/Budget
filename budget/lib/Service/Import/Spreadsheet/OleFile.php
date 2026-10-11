<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads streams out of an OLE2 compound file (the container of a binary
 * .xls, and of a password-protected .xlsx).
 *
 * Streams are looked up by name across the whole directory rather than by
 * walking the storage tree: the ones read here (Workbook, Book,
 * EncryptionInfo) always sit at the root. Every sector number is checked and
 * every chain is followed at most once per sector, so a malformed file is
 * refused instead of looping or reading out of range.
 */
final class OleFile {
	public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	private const MAX_REGULAR_SECTOR = 0xFFFFFFFA;
	private const END_OF_CHAIN = 0xFFFFFFFE;
	private const MINI_SECTOR_SIZE = 64;

	private int $sectorSize;
	private int $miniCutoff;
	/** @var list<int> */
	private array $fat;
	/** @var list<int> */
	private array $miniFat = [];
	private string $miniStream = '';
	/** @var array<string, array{start: int, size: int}> */
	private array $streams = [];

	public function __construct(
		private string $data,
	) {
		if (strlen($data) < 512 || !str_starts_with($data, self::SIGNATURE)) {
			throw new SpreadsheetReadError('Not an OLE2 file');
		}
		$sectorShift = Bytes::u16($data, 30);
		if (($sectorShift !== 9 && $sectorShift !== 12) || Bytes::u16($data, 32) !== 6) {
			throw new SpreadsheetReadError('Unsupported OLE2 sector size');
		}
		$this->sectorSize = 1 << $sectorShift;
		$this->miniCutoff = Bytes::u32($data, 56);
		$this->fat = $this->readFat(Bytes::u32($data, 44), Bytes::u32($data, 68), Bytes::u32($data, 72));
		$this->readDirectory(Bytes::u32($data, 48), Bytes::u32($data, 60));
	}

	public function hasStream(string $name): bool {
		return isset($this->streams[mb_strtolower($name)]);
	}

	public function stream(string $name): string {
		$entry = $this->streams[mb_strtolower($name)] ?? throw new SpreadsheetReadError('No stream ' . $name);
		if ($entry['size'] === 0) {
			return '';
		}
		if ($entry['size'] > strlen($this->data)) {
			throw new SpreadsheetReadError('Stream larger than the file');
		}
		if ($entry['size'] < $this->miniCutoff) {
			$bytes = '';
			foreach ($this->chain($entry['start'], $this->miniFat) as $sector) {
				$offset = $sector * self::MINI_SECTOR_SIZE;
				if ($offset >= strlen($this->miniStream)) {
					throw new SpreadsheetReadError('Mini sector out of range');
				}
				$bytes .= substr($this->miniStream, $offset, self::MINI_SECTOR_SIZE);
			}
		} else {
			$bytes = $this->readChain($entry['start']);
		}
		if (strlen($bytes) < $entry['size']) {
			throw new SpreadsheetReadError('Stream truncated');
		}
		return substr($bytes, 0, $entry['size']);
	}

	/**
	 * @return list<int>
	 */
	private function readFat(int $fatSectors, int $difatStart, int $difatSectors): array {
		// Each FAT sector is copied into the FAT, so a header listing more of
		// them than the file holds, or one sector many times, could make a
		// small file build a FAT of gigabytes
		if ($fatSectors > intdiv(strlen($this->data), $this->sectorSize)) {
			throw new SpreadsheetReadError('More FAT sectors than the file holds');
		}
		$sectors = array_filter(Bytes::u32s($this->data, 76, 109), static fn (int $s): bool => $s <= self::MAX_REGULAR_SECTOR);
		$perSector = intdiv($this->sectorSize, 4) - 1;
		$seen = [];
		for ($next = $difatStart; $next <= self::MAX_REGULAR_SECTOR && count($seen) < $difatSectors && count($sectors) < $fatSectors;) {
			if (isset($seen[$next])) {
				throw new SpreadsheetReadError('DIFAT loops');
			}
			$seen[$next] = true;
			$entries = Bytes::u32s($this->sector($next), 0, $perSector + 1);
			$next = (int)array_pop($entries);
			foreach ($entries as $sector) {
				if ($sector <= self::MAX_REGULAR_SECTOR) {
					$sectors[] = $sector;
				}
			}
		}

		$sectors = array_slice(array_values($sectors), 0, $fatSectors);
		if (count(array_unique($sectors)) !== count($sectors)) {
			throw new SpreadsheetReadError('A FAT sector is listed twice');
		}
		$fat = '';
		foreach ($sectors as $sector) {
			$fat .= $this->sector($sector);
		}
		return Bytes::u32s($fat, 0, intdiv(strlen($fat), 4));
	}

	private function readDirectory(int $directoryStart, int $miniFatStart): void {
		$directory = $this->readChain($directoryStart);
		$root = null;
		for ($offset = 0; $offset + 128 <= strlen($directory); $offset += 128) {
			$nameLength = Bytes::u16($directory, $offset + 64);
			$type = Bytes::u8($directory, $offset + 66);
			if ($nameLength < 2 || $nameLength > 64 || ($type !== 2 && $type !== 5)) {
				continue;
			}
			$entry = [
				'start' => Bytes::u32($directory, $offset + 116),
				'size' => Bytes::u32($directory, $offset + 120),
			];
			if ($type === 5) {
				$root = $entry;
			} else {
				$name = mb_strtolower(Bytes::utf16(substr($directory, $offset, $nameLength - 2)));
				$this->streams[$name] ??= $entry;
			}
		}
		if ($root === null) {
			throw new SpreadsheetReadError('No root entry');
		}
		if ($miniFatStart <= self::MAX_REGULAR_SECTOR) {
			$miniFat = $this->readChain($miniFatStart);
			$this->miniFat = Bytes::u32s($miniFat, 0, intdiv(strlen($miniFat), 4));
			$this->miniStream = $root['size'] > 0 ? substr($this->readChain($root['start']), 0, $root['size']) : '';
		}
	}

	private function readChain(int $start): string {
		$bytes = '';
		foreach ($this->chain($start, $this->fat) as $sector) {
			$bytes .= $this->sector($sector);
		}
		return $bytes;
	}

	/**
	 * @param list<int> $table The FAT or the mini FAT
	 * @return list<int>
	 */
	private function chain(int $start, array $table): array {
		$chain = [];
		$seen = [];
		for ($sector = $start; $sector !== self::END_OF_CHAIN;) {
			if ($sector > self::MAX_REGULAR_SECTOR || !isset($table[$sector]) || isset($seen[$sector])) {
				throw new SpreadsheetReadError('Broken sector chain');
			}
			$seen[$sector] = true;
			$chain[] = $sector;
			$sector = $table[$sector];
		}
		return $chain;
	}

	private function sector(int $sector): string {
		$offset = ($sector + 1) * $this->sectorSize;
		if ($offset >= strlen($this->data)) {
			throw new SpreadsheetReadError('Sector out of range');
		}
		// Some writers cut the last sector short; what is there is used
		return substr($this->data, $offset, $this->sectorSize);
	}
}
