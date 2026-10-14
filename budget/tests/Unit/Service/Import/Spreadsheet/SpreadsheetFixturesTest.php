<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetConverter;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exact CSV every spreadsheet fixture converts to, kept in
 * fixtures/spreadsheets/golden/<fixture>.csv.
 *
 * The text written for a cell is what a row's import id is made of when the
 * row has no id of its own (TransactionNormalizer::generateImportId), so
 * once statements have been imported from spreadsheets this output must
 * never change: a cell written differently re-keys the row, and importing
 * the same statement again inserts it a second time (#338). A change that
 * makes a golden file fail is a change to the import ids of every user who
 * imported a file like it.
 *
 * Every fixture needs a golden file or a place in REFUSED, so a new fixture
 * cannot slip in unpinned.
 */
class SpreadsheetFixturesTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	private const UNREADABLE = 'This spreadsheet could not be read. If it is password-protected, remove the password, or save it as CSV and import that.';

	private const TOO_BIG = 'This spreadsheet is too big to import in one go. Split it into smaller files and import them one at a time.';

	private const REFUSED = [
		'biff5.xls' => self::UNREADABLE,
		'encrypted.xlsx' => self::UNREADABLE,
		'filepass.xls' => self::UNREADABLE,
		'ods-spaces.ods' => self::TOO_BIG,
	];

	/** @return array<string, array{string}> */
	public static function fixtures(): array {
		$fixtures = [];
		foreach (glob(self::FIXTURES . '*') ?: [] as $path) {
			if (is_file($path) && !str_ends_with($path, '.md')) {
				$fixtures[basename($path)] = [basename($path)];
			}
		}
		return $fixtures;
	}

	private function converter(): SpreadsheetConverter {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => tempnam(sys_get_temp_dir(), 'conv'));
		return new SpreadsheetConverter($l, $temp);
	}

	#[DataProvider('fixtures')]
	public function testEveryFixtureConvertsToItsGoldenCsv(string $fixture): void {
		$bytes = file_get_contents(self::FIXTURES . $fixture);
		if (isset(self::REFUSED[$fixture])) {
			$this->expectException(ValidationException::class);
			$this->expectExceptionMessage(self::REFUSED[$fixture]);
			$this->converter()->toCsv($bytes);
			return;
		}
		$golden = self::FIXTURES . 'golden/' . $fixture . '.csv';
		$this->assertFileExists($golden, "$fixture has no golden CSV and is not listed as refused");
		$this->assertSame(file_get_contents($golden), $this->converter()->toCsv($bytes));
	}

	public function testEveryGoldenFileHasItsFixture(): void {
		foreach (glob(self::FIXTURES . 'golden/*.csv') ?: [] as $golden) {
			$this->assertFileExists(self::FIXTURES . basename($golden, '.csv'), basename($golden) . ' pins a fixture that is gone');
		}
		foreach (array_keys(self::REFUSED) as $fixture) {
			$this->assertFileExists(self::FIXTURES . $fixture);
		}
	}
}
