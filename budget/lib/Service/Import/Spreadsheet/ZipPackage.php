<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads parts out of a zip-based spreadsheet (xlsx, ods), each capped in
 * size so a small upload cannot unpack into gigabytes.
 */
final class ZipPackage {
	public const MAX_PART_BYTES = 50 * 1024 * 1024;

	private \ZipArchive $zip;
	private bool $open;

	public function __construct(string $path) {
		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			throw new SpreadsheetReadError('Not a readable zip file');
		}
		$this->zip = $zip;
		$this->open = true;
	}

	public function has(string $name): bool {
		return $this->zip->locateName($name) !== false;
	}

	public function read(string $name): string {
		$stat = $this->zip->statName($name);
		if ($stat === false) {
			throw new SpreadsheetReadError('Missing zip part ' . $name);
		}
		if ($stat['size'] > self::MAX_PART_BYTES) {
			throw new SpreadsheetReadError('Zip part too large: ' . $name);
		}
		$stream = $this->zip->getStream($name);
		if ($stream === false) {
			throw new SpreadsheetReadError('Unreadable zip part ' . $name);
		}
		try {
			// The stated size can lie, so the read is capped as well
			$data = stream_get_contents($stream, self::MAX_PART_BYTES + 1);
		} finally {
			fclose($stream);
		}
		if ($data === false || strlen($data) > self::MAX_PART_BYTES) {
			throw new SpreadsheetReadError('Zip part too large: ' . $name);
		}
		return $data;
	}

	public function close(): void {
		if ($this->open) {
			$this->zip->close();
			$this->open = false;
		}
	}

	public function __destruct() {
		$this->close();
	}
}
