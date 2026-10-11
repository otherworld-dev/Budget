<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetReadError;
use OCA\Budget\Service\Import\Spreadsheet\ZipPackage;
use PHPUnit\Framework\TestCase;

class ZipPackageTest extends TestCase {
	public function testTheTotalReadFromOneFileIsCapped(): void {
		// Parts of zeros compress to almost nothing, so a small upload can
		// hold many of them, each under the per-part cap
		$path = tempnam(sys_get_temp_dir(), 'zip');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$part = str_repeat("\0", 40 * 1024 * 1024);
		foreach (['a', 'b', 'c'] as $name) {
			$zip->addFromString($name, $part);
		}
		$zip->close();
		unset($part);

		$package = new ZipPackage($path);
		try {
			$package->read('a');
			$package->read('b');
			$this->expectException(SpreadsheetReadError::class);
			$package->read('c');
		} finally {
			$package->close();
			unlink($path);
		}
	}
}
