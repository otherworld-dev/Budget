<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\ImportAccountLinkService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionTagService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A CSV imported again with another manual mapping (#399).
 *
 * A manual mapping's import id is a hash of the description it mapped, so
 * when the reporter imported his receipt lines a second time with the
 * category joined to the product ("Yara Bus, Diverses"), every line came out
 * new and eight went in twice (104.85). The lines and ids here are his own.
 *
 * Runs the real parser, normalizer and duplicate detector against an
 * in-memory ledger.
 */
class ImportManualMappingDuplicateTest extends TestCase {
	private const FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.csv';
	private const ACCOUNT = 1;

	/** The first import: the product as the description */
	private const BY_PRODUCT = ['date' => 0, 'description' => 1, 'amount' => 3, 'skipFirstRow' => true];
	/** The second: product and category joined, and the category in the notes */
	private const WITH_CATEGORY = ['date' => 0, 'description' => [1, 2], 'notes' => 2, 'amount' => 3, 'skipFirstRow' => true];

	/** Date, product, category, amount */
	private const OCT_3 = [
		['03.10.2026', 'Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', 'Modellbau / Renovation', '-11.13'],
		['03.10.2026', 'Wolfcraft 1 Scheiben-drahtbürste Inox Sg', 'Modellbau / Renovation', '-13.50'],
		['03.10.2026', 'Dremel 952 Schleifstein', 'Modellbau / Renovation', '-10.20'],
		['03.10.2026', 'Dremel 8193 Schleifstein', 'Modellbau / Renovation', '-6.75'],
		['03.10.2026', 'Molto Holz-Feinspachtel weiss 400g', 'Modellbau / Renovation', '-7.37'],
	];
	private const OCT_12 = [
		['12.10.2026', 'Wassertank Zubehör', 'Diverses', '-30.00'],
		['12.10.2026', 'Thermometer für Wasserkanister', 'Diverses', '-20.90'],
		['12.10.2026', 'Yara Bus', 'Diverses', '-5.00'],
	];
	/** Lines only the second file has */
	private const OCT_12_NEW = [
		['12.10.2026', 'Coop IPS Vollmilch UHT 3.5% 1L', 'Lebensmittel', '-1.65'],
		['12.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'],
		['12.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'],
	];

	private ImportService $service;
	private string $fileContent = '';
	/** Name the account in a column of the file instead of choosing it */
	private bool $accountColumn = false;

	/** @var array<int, array<string, mixed>> Stored transactions, by id */
	private array $ledger = [];

	protected function setUp(): void {
		$appData = $this->createMock(IAppData::class);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function () {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturn($this->fileContent);
			return $file;
		});
		$appData->method('getFolder')->willReturn($folder);

		$transactionService = $this->createMock(TransactionService::class);
		$transactionService->method('create')->willReturnCallback(
			function (string $userId, int $accountId, string $date, string $description, float $amount, string $type, ?int $categoryId = null, ?string $vendor = null, ?string $reference = null, ?string $notes = null, ?string $importId = null) {
				if ($importId !== null && in_array($importId, $this->importIds($accountId), true)) {
					throw new \Exception('Transaction with this import ID already exists');
				}
				$id = count($this->ledger) + 1;
				$this->ledger[$id] = compact('accountId', 'date', 'description', 'amount', 'type', 'vendor', 'notes', 'importId');
				$tx = new Transaction();
				$tx->setId($id);
				return $tx;
			}
		);
		$transactionService->method('existsByImportId')->willReturnCallback(
			fn (int $accountId, string $importId) => in_array($importId, $this->importIds($accountId), true)
		);

		$transactionMapper = $this->createMock(TransactionMapper::class);
		$transactionMapper->method('findImportIds')->willReturnCallback(fn (int $accountId) => $this->importIds($accountId));
		$transactionMapper->method('findImportComparables')->willReturnCallback(function (int $accountId, string $from, string $to) {
			$rows = [];
			foreach ($this->ledger as $id => $row) {
				if ($row['accountId'] === $accountId && $row['date'] >= $from && $row['date'] <= $to) {
					$rows[] = [
						'id' => $id, 'date' => $row['date'], 'amount' => (string)$row['amount'], 'type' => $row['type'],
						'description' => $row['description'], 'vendor' => $row['vendor'], 'notes' => $row['notes'],
						'import_id' => $row['importId'],
					];
				}
			}
			return $rows;
		});
		// The preview's direction check reads the account's history
		$transactionMapper->method('countByTypeForAccount')->willReturnCallback(function (int $accountId) {
			$counts = ['credit' => 0, 'debit' => 0];
			foreach ($this->ledger as $row) {
				if ($row['accountId'] === $accountId) {
					$counts[$row['type']]++;
				}
			}
			return $counts;
		});

		$account = new Account();
		$account->setId(self::ACCOUNT);
		$account->setName('Lohnkonto');
		$account->setType('checking');
		$account->setCurrency('CHF');
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('find')->willReturnCallback(fn (int $id) => $id === self::ACCOUNT ? $account : throw new \Exception('no account ' . $id));
		$accountMapper->method('findByName')->willReturnCallback(fn (string $userId, string $name) => $name === 'Lohnkonto' ? $account : null);

		$ruleApplicator = $this->createMock(ImportRuleApplicator::class);
		$ruleApplicator->method('applyRules')->willReturnArgument(1);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});

		$this->service = new ImportService(
			$appData,
			$transactionService,
			$transactionMapper,
			$accountMapper,
			$this->createMock(AccountService::class),
			$this->createMock(FileValidator::class),
			new ParserFactory(),
			new TransactionNormalizer(),
			new DuplicateDetector($transactionService, $transactionMapper),
			$ruleApplicator,
			new PresetRegistry(),
			$this->createMock(CategoryService::class),
			$this->createMock(TagSetService::class),
			$this->createMock(TransactionTagService::class),
			$this->createMock(ImportAccountLinkService::class),
			$this->createMock(BillService::class),
			$this->createMock(SettingService::class),
			$l,
			$this->createMock(LoggerInterface::class)
		);
	}

	/** @return string[] */
	private function importIds(int $accountId): array {
		$ids = [];
		foreach ($this->ledger as $row) {
			if ($row['accountId'] === $accountId && $row['importId'] !== null) {
				$ids[] = $row['importId'];
			}
		}
		return $ids;
	}

	/** @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows */
	private function csv(array $rows): string {
		$lines = ['Datum,Produkt,Kategorie,Betrag' . ($this->accountColumn ? ',Konto' : '')];
		foreach ($rows as $row) {
			if ($this->accountColumn) {
				$row[] = 'Lohnkonto';
			}
			$lines[] = implode(',', array_map(fn (string $cell) => '"' . $cell . '"', $row));
		}
		return implode("\n", $lines) . "\n";
	}

	/** @return array{0: array<string, mixed>, 1: ?int} The mapping and the account chosen for the import */
	private function destination(array $mapping): array {
		return $this->accountColumn ? [$mapping + ['account' => 4], null] : [$mapping, self::ACCOUNT];
	}

	private function import(array $rows, array $mapping, bool $skipDuplicates = true): array {
		$this->fileContent = $this->csv($rows);
		[$mapping, $accountId] = $this->destination($mapping);
		return $this->service->processImport('user1', self::FILE_ID, $mapping, $accountId, null, $skipDuplicates, true, ',', null);
	}

	/** The import screen's preview, which lists the duplicates it flags */
	private function preview(array $rows, array $mapping): array {
		$this->fileContent = $this->csv($rows);
		[$mapping, $accountId] = $this->destination($mapping);
		return $this->service->previewImport('user1', self::FILE_ID, $mapping, $accountId, null, false, ',', null);
	}

	/** A row that reached the account some other way than through an import of this file */
	private function store(string $date, string $description, float $amount, ?string $importId): void {
		$this->ledger[count($this->ledger) + 1] = [
			'accountId' => self::ACCOUNT, 'date' => $date, 'description' => $description, 'amount' => $amount,
			'type' => 'debit', 'vendor' => null, 'notes' => null, 'importId' => $importId,
		];
	}

	private function balance(): float {
		$sum = 0.0;
		foreach ($this->ledger as $row) {
			$sum += $row['type'] === 'credit' ? $row['amount'] : -$row['amount'];
		}
		return round($sum, 2);
	}

	/** @return string[] Descriptions of the previewed rows not flagged as duplicates */
	private static function newRows(array $preview): array {
		return array_values(array_column(array_filter($preview['transactions'], fn (array $row) => !$row['isDuplicate']), 'description'));
	}

	private static function firstFile(): array {
		return array_merge(self::OCT_12, self::OCT_3);
	}

	/** The second file of 12 Oct, in its own order: the doubled lines and three new ones */
	private static function secondFile(): array {
		return [self::OCT_12[0], self::OCT_12[1], self::OCT_12_NEW[0], self::OCT_12[2], self::OCT_12_NEW[1], self::OCT_12_NEW[2], ...self::OCT_3];
	}

	public static function destinations(): array {
		return ['account chosen for the import' => [false], 'account column' => [true]];
	}

	#[DataProvider('destinations')]
	public function testThePreviewFlagsTheLinesAnotherMappingImported(bool $accountColumn): void {
		$this->accountColumn = $accountColumn;
		$this->assertSame(8, $this->import(self::firstFile(), self::BY_PRODUCT)['imported']);
		// His own ids: the fixture is the file he imported
		$this->assertSame('hash_ad01e59cc8d3977ac501f15ff7e0546e', $this->ledger[4]['importId']);
		$this->assertSame('hash_483d293530608d7baa2a47bd2aa8f0b3', $this->ledger[1]['importId']);

		$preview = $this->preview(self::secondFile(), self::WITH_CATEGORY);

		$this->assertSame(8, $preview['duplicates']);
		$this->assertSame([
			'Coop IPS Vollmilch UHT 3.5% 1L, Lebensmittel',
			'Parisienne Jaune B-PT, Zigaretten',
			'Parisienne Jaune B-PT, Zigaretten',
		], self::newRows($preview));
	}

	#[DataProvider('destinations')]
	public function testTheReimportAddsOnlyTheNewLines(bool $accountColumn): void {
		$this->accountColumn = $accountColumn;
		$this->import(self::firstFile(), self::BY_PRODUCT);
		$storedIds = array_column($this->ledger, 'importId');

		$again = $this->import(self::secondFile(), self::WITH_CATEGORY);

		$this->assertSame([], $again['errors']);
		$this->assertSame(3, $again['imported']);
		$this->assertSame(8, $again['skipped']);
		$added = array_slice($this->ledger, 8);
		$this->assertSame([
			'Coop IPS Vollmilch UHT 3.5% 1L, Lebensmittel',
			'Parisienne Jaune B-PT, Zigaretten',
			'Parisienne Jaune B-PT, Zigaretten',
		], array_column($added, 'description'));
		$this->assertEqualsWithDelta(-104.85 - 1.65 - 9.20 - 9.20, $this->balance(), 0.001);
		// Import ids are not touched: the stored lines keep theirs, the new
		// ones get the ids his import gave them
		$this->assertSame($storedIds, array_column(array_slice($this->ledger, 0, 8), 'importId'));
		$this->assertSame(
			['hash_e2f37a1cc0d7792b9aa611a71b1b881f', 'hash_8fa903716174b7aaffef820bcb4fd273', 'hash_8fa903716174b7aaffef820bcb4fd273_occ2'],
			array_column($added, 'importId')
		);
	}

	public static function rowOrders(): array {
		return ['new row first' => [true], 'old row first' => [false]];
	}

	/**
	 * Another product of the same brand, on the same receipt at the same
	 * price, is a purchase of its own, listed before or after the one
	 * already imported.
	 */
	#[DataProvider('rowOrders')]
	public function testDifferentProductSameDaySameAmountStillImports(bool $newFirst): void {
		$this->import([self::OCT_3[0]], self::BY_PRODUCT);
		$other = ['03.10.2026', 'Wolfcraft Drahtbürste Messing 3tlg', 'Modellbau / Renovation', '-11.13'];
		$rows = $newFirst ? [$other, self::OCT_3[0]] : [self::OCT_3[0], $other];

		$preview = $this->preview($rows, self::WITH_CATEGORY);
		$again = $this->import($rows, self::WITH_CATEGORY);

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame(['Wolfcraft Drahtbürste Messing 3tlg, Modellbau / Renovation'], self::newRows($preview));
		$this->assertSame(1, $again['imported']);
		$this->assertSame(
			['Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', 'Wolfcraft Drahtbürste Messing 3tlg, Modellbau / Renovation'],
			array_column($this->ledger, 'description')
		);
	}

	/**
	 * One pack imported earlier and two in the file, mapped the other way:
	 * the stored row stands for one of them, so the second pack imports.
	 */
	public function testEachStoredRowStandsForOneRowOfTheFile(): void {
		$pack = ['15.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'];
		$this->import([$pack], self::BY_PRODUCT);

		$again = $this->import([$pack, $pack], self::WITH_CATEGORY);

		$this->assertSame(1, $again['imported']);
		$this->assertSame(['Parisienne Jaune B-PT', 'Parisienne Jaune B-PT, Zigaretten'], array_column($this->ledger, 'description'));
	}

	/**
	 * The same mapping, and the file now lists a second pack: that row's id
	 * is the first one's with _occ2, and the stored row, which carries the
	 * first pack's id, is that row's. Taken for the second pack, it hid it
	 * (V2-2).
	 */
	#[DataProvider('destinations')]
	public function testASecondIdenticalPurchaseIsNotTakenForTheFirst(bool $accountColumn): void {
		$this->accountColumn = $accountColumn;
		$pack = ['15.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'];
		$this->import([$pack], self::BY_PRODUCT);

		$preview = $this->preview([$pack, $pack], self::BY_PRODUCT);
		$again = $this->import([$pack, $pack], self::BY_PRODUCT);

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertSame(
			['hash_adc398d013d262ca320a07367ad3caa2', 'hash_adc398d013d262ca320a07367ad3caa2_occ2'],
			array_column($this->ledger, 'importId')
		);
	}

	public static function rowsFromElsewhere(): array {
		return ['typed in by hand' => [null], 'read from an OFX statement' => ['ofx_fitid_20261003-0042']];
	}

	/**
	 * Only a row an earlier import stored under a content hash stands for
	 * a line of the file. A row typed in by hand or read from an OFX
	 * statement never hid a CSV line, and still does not.
	 */
	#[DataProvider('rowsFromElsewhere')]
	public function testARowNoImportHashedNeverHidesALine(?string $importId): void {
		$this->store('2026-10-03', 'Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', 11.13, $importId);

		$preview = $this->preview([self::OCT_3[0]], self::WITH_CATEGORY);
		$again = $this->import([self::OCT_3[0]], self::WITH_CATEGORY);

		$this->assertSame(0, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	/**
	 * The same file and mapping twice is still recognised by its import ids,
	 * even where the text no longer matches: a line renamed after the first
	 * import, and a second identical pack (_occ2).
	 */
	public function testTheSameFileAndMappingTwiceIsSkippedByImportId(): void {
		$file = array_merge(self::OCT_12, self::OCT_3, self::OCT_12_NEW);
		$this->import($file, self::BY_PRODUCT);
		$this->assertCount(11, $this->ledger);
		$this->ledger[1]['description'] = 'Wohnmobil Ersatzteil';

		$preview = $this->preview($file, self::BY_PRODUCT);
		$again = $this->import($file, self::BY_PRODUCT);

		$this->assertSame(11, $preview['duplicates']);
		$this->assertSame(0, $again['imported']);
		$this->assertSame(11, $again['skipped']);
		$this->assertCount(11, $this->ledger);
	}

	/** "Import flagged duplicates too" still imports every line */
	public function testRecognisedLinesCanStillBeImportedOnPurpose(): void {
		$this->import(self::firstFile(), self::BY_PRODUCT);

		$again = $this->import(self::secondFile(), self::WITH_CATEGORY, false);

		$this->assertSame(11, $again['imported']);
		$this->assertCount(19, $this->ledger);
	}
}
