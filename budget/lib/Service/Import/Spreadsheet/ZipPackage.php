<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads parts out of a zip-based spreadsheet (xlsx, ods), each capped in
 * size so a small upload cannot unpack into gigabytes. Passing a cap is
 * SpreadsheetTooLargeError, so the user is told the file is too big rather
 * than unreadable.
 */
final class ZipPackage {
	public const MAX_PART_BYTES = 50 * 1024 * 1024;

	/** Everything read from one file together: a small upload can hold many highly compressed parts */
	public const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

	private const READ_CHUNK_BYTES = 1024 * 1024;

	private \ZipArchive $zip;
	private bool $open;
	private int $bytesRead = 0;

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
			throw new SpreadsheetTooLargeError('Zip part too large: ' . $name);
		}
		$stream = $this->zip->getStream($name);
		if ($stream === false) {
			throw new SpreadsheetReadError('Unreadable zip part ' . $name);
		}
		try {
			// The stated size can lie, so the read is capped as well. Read a
			// chunk at a time: before PHP 8.3, stream_get_contents() with a
			// length allocated all of it up front, 50 MB for every part.
			$data = '';
			while (!feof($stream) && strlen($data) <= self::MAX_PART_BYTES) {
				$chunk = fread($stream, self::READ_CHUNK_BYTES);
				if ($chunk === false) {
					throw new SpreadsheetReadError('Unreadable zip part ' . $name);
				}
				if ($chunk === '') {
					break;
				}
				$data .= $chunk;
			}
		} finally {
			fclose($stream);
		}
		if (strlen($data) > self::MAX_PART_BYTES) {
			throw new SpreadsheetTooLargeError('Zip part too large: ' . $name);
		}
		$this->bytesRead += strlen($data);
		if ($this->bytesRead > self::MAX_TOTAL_BYTES) {
			throw new SpreadsheetTooLargeError('More than ' . self::MAX_TOTAL_BYTES . ' bytes read from one zip file');
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
