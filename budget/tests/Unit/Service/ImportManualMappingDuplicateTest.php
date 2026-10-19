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

	/*
	 * The file's columns: 0 date, 1 product, 2 category, 3 amount, 4 shop,
	 * 5 the bank's reference (and 6 the account, when it names one).
	 */

	/** The first import: the product as the description */
	private const BY_PRODUCT = ['date' => 0, 'description' => 1, 'amount' => 3, 'skipFirstRow' => true];
	/** The second: product and category joined, and the category in the notes */
	private const WITH_CATEGORY = ['date' => 0, 'description' => [1, 2], 'notes' => 2, 'amount' => 3, 'skipFirstRow' => true];
	/** His 11 Oct mapping: the category as the description, the product as payee and notes */
	private const CATEGORY_FIRST = ['date' => 0, 'description' => 2, 'vendor' => 1, 'notes' => 1, 'amount' => 3, 'skipFirstRow' => true];
	/** The category alone as the description, as most of his imports since February */
	private const BY_CATEGORY = ['date' => 0, 'description' => 2, 'amount' => 3, 'skipFirstRow' => true];
	/** A statement with a payee column */
	private const WITH_SHOP = ['date' => 0, 'description' => 1, 'vendor' => 4, 'amount' => 3, 'skipFirstRow' => true];
	private const WITH_SHOP_AND_CATEGORY = ['date' => 0, 'description' => [1, 2], 'vendor' => 4, 'amount' => 3, 'skipFirstRow' => true];
	/** A statement with the bank's own reference for each line */
	private const WITH_REFERENCE = ['date' => 0, 'description' => 1, 'reference' => 5, 'amount' => 3, 'skipFirstRow' => true];
	private const WITH_REFERENCE_AND_CATEGORY = ['date' => 0, 'description' => [1, 2], 'reference' => 5, 'amount' => 3, 'skipFirstRow' => true];

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
	private int $nextId = 1;

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
				$id = $this->nextId++;
				$this->ledger[$id] = compact('accountId', 'date', 'description', 'amount', 'type', 'vendor', 'reference', 'notes', 'importId');
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
						'description' => $row['description'], 'vendor' => $row['vendor'], 'reference' => $row['reference'],
						'notes' => $row['notes'], 'import_id' => $row['importId'],
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

	/** @param array<int, list<string>> $rows Date, product, category, amount and, if any, shop and reference */
	private function csv(array $rows): string {
		$lines = ['Datum,Produkt,Kategorie,Betrag,Laden,Referenz' . ($this->accountColumn ? ',Konto' : '')];
		foreach ($rows as $row) {
			$row = array_pad($row, 6, '');
			if ($this->accountColumn) {
				$row[] = 'Lohnkonto';
			}
			$lines[] = implode(',', array_map(fn (string $cell) => '"' . $cell . '"', $row));
		}
		return implode("\n", $lines) . "\n";
	}

	/** @return array{0: array<string, mixed>, 1: ?int} The mapping and the account chosen for the import */
	private function destination(array $mapping): array {
		return $this->accountColumn ? [$mapping + ['account' => 6], null] : [$mapping, self::ACCOUNT];
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
	private function store(string $date, string $description, float $amount, ?string $importId, ?string $vendor = null, ?string $notes = null): void {
		$this->ledger[$this->nextId++] = [
			'accountId' => self::ACCOUNT, 'date' => $date, 'description' => $description, 'amount' => $amount,
			'type' => 'debit', 'vendor' => $vendor, 'reference' => null, 'notes' => $notes, 'importId' => $importId,
		];
	}

	private function balance(): float {
		$sum = 0.0;
		foreach ($this->ledger as $row) {
			$sum += $row['type'] === 'credit' ? $row['amount'] : -$row['amount'];
		}
		return round($sum, 2);
	}

	/** @return string[] */
	private function descriptions(): array {
		return array_values(array_column($this->ledger, 'description'));
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

	/**
	 * His whole evening: the 11 Oct rows already on the books (the category
	 * as the description), the 25-line file imported by product, 17 of those
	 * rows deleted by hand, then the same file again with the category
	 * joined. The 8 rows he kept are recognised and the 17 come back.
	 */
	public function testHisWholeSequenceAddsBackOnlyTheRowsHeDeleted(): void {
		$this->import(self::oct11Rows(), self::CATEGORY_FIRST);
		$this->assertCount(11, $this->ledger);
		$this->assertSame(25, $this->import(self::oct12File(), self::BY_PRODUCT)['imported']);
		// He deleted file lines 3-7 and 9-20 (rows 1503-1507 and 1509-1520)
		$batch = array_slice(array_keys($this->ledger), 11, 25);
		foreach ([2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19] as $line) {
			unset($this->ledger[$batch[$line]]);
		}
		$this->assertCount(11 + 8, $this->ledger);

		$preview = $this->preview(self::oct12File(), self::WITH_CATEGORY);
		$again = $this->import(self::oct12File(), self::WITH_CATEGORY);

		$this->assertSame(8, $preview['duplicates']);
		$this->assertSame(17, $again['imported']);
		$this->assertSame(8, $again['skipped']);
		$this->assertCount(11 + 25, $this->ledger);
	}

	public static function rowOrders(): array {
		return ['new row first' => [true], 'old row first' => [false]];
	}

	/**
	 * A line of the stored receipt, and another purchase on that day at that
	 * price that only the file has, under another mapping.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>, 3: list<string>, 4: string, 5: string}>
	 */
	private static function otherPurchases(): array {
		return [
			'another product of the brand' => [
				self::BY_PRODUCT, ['03.10.2026', 'Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', 'Modellbau / Renovation', '-11.13'],
				self::WITH_CATEGORY, ['03.10.2026', 'Wolfcraft Drahtbürste Messing 3tlg', 'Modellbau / Renovation', '-11.13'],
				'Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', 'Wolfcraft Drahtbürste Messing 3tlg, Modellbau / Renovation',
			],
			'another product from the same shop' => [
				self::WITH_SHOP, ['12.10.2026', 'Brot', 'Lebensmittel', '-2.50', 'Coop'],
				self::WITH_SHOP_AND_CATEGORY, ['12.10.2026', 'Eier', 'Lebensmittel', '-2.50', 'Coop'],
				'Brot', 'Eier, Lebensmittel',
			],
			// The stored row's description is only a category, its product the payee
			'another product of the category he stored as the description' => [
				self::CATEGORY_FIRST, ['06.10.2026', 'Schöni Sauerkraut gekocht 500G', 'Lebensmittel', '-3.40'],
				self::WITH_CATEGORY, ['06.10.2026', 'Kambly Bretzeli 100g', 'Lebensmittel', '-3.40'],
				'Lebensmittel', 'Kambly Bretzeli 100g, Lebensmittel',
			],
			'a description holding the stored one' => [
				self::BY_PRODUCT, ['14.10.2026', 'COOP', 'Lebensmittel', '-4.50'],
				self::WITH_CATEGORY, ['14.10.2026', 'COOP PRONTO BAHNHOF', 'Lebensmittel', '-4.50'],
				'COOP', 'COOP PRONTO BAHNHOF, Lebensmittel',
			],
		];
	}

	public static function otherPurchasesInEitherOrder(): array {
		$sets = [];
		foreach (self::otherPurchases() as $name => $case) {
			$sets[$name . ', new row first'] = [...$case, true];
			$sets[$name . ', old row first'] = [...$case, false];
		}
		return $sets;
	}

	/**
	 * A genuinely different purchase on the same day at the same price, in a
	 * re-import with another mapping, still imports, listed before or after
	 * the line already imported, and the stored row stays the old line's:
	 * the new purchase is not skipped in its place (Review Focus 4).
	 */
	#[DataProvider('otherPurchasesInEitherOrder')]
	public function testDifferentProductSameDaySameAmountStillImports(array $firstMapping, array $old, array $secondMapping, array $other, string $stored, string $added, bool $newFirst): void {
		$this->import([$old], $firstMapping);
		$this->assertSame([$stored], $this->descriptions());
		$rows = $newFirst ? [$other, $old] : [$old, $other];

		$preview = $this->preview($rows, $secondMapping);
		$again = $this->import($rows, $secondMapping);

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame([$added], self::newRows($preview));
		$this->assertSame(1, $again['imported']);
		$this->assertSame([$stored, $added], $this->descriptions());
	}

	public static function onlyTheNewLine(): array {
		$sets = [];
		foreach (self::otherPurchases() as $name => [$firstMapping, $old, $secondMapping, $other]) {
			$sets['another mapping, ' . $name] = [$firstMapping, $old, $secondMapping, $other];
		}
		return $sets + [
			'the same mapping, another order from the same payee' => [
				self::WITH_SHOP, ['14.10.2026', 'AMAZON MKTPLACE ORDER 111-555', 'Online', '-9.99', 'Amazon'],
				self::WITH_SHOP, ['14.10.2026', 'AMAZON MKTPLACE ORDER 222-777', 'Online', '-9.99', 'Amazon'],
			],
			'the same mapping, a description holding the stored one' => [
				self::BY_PRODUCT, ['14.10.2026', 'COOP', 'Lebensmittel', '-4.50'],
				self::BY_PRODUCT, ['14.10.2026', 'COOP PRONTO BAHNHOF', 'Lebensmittel', '-4.50'],
			],
			'the same mapping, a description the stored one holds' => [
				self::BY_PRODUCT, ['14.10.2026', 'COOP PRONTO BAHNHOF', 'Lebensmittel', '-4.50'],
				self::BY_PRODUCT, ['14.10.2026', 'COOP', 'Lebensmittel', '-4.50'],
			],
			'the same mapping, another of the bank\'s references' => [
				self::WITH_REFERENCE, ['14.10.2026', 'Kaffee', 'Lebensmittel', '-4.20', 'Café Zentral', 'R-1001'],
				self::WITH_REFERENCE, ['14.10.2026', 'Kaffee', 'Lebensmittel', '-4.20', 'Café Zentral', 'R-1002'],
			],
		];
	}

	/**
	 * A later file holding only a new purchase on a day and at a price the
	 * account already has, under the same mapping (a statement for the next
	 * days, the import folder) or another one. It imports, as it did before
	 * any of this: nothing in the stored row says it was this line.
	 */
	#[DataProvider('onlyTheNewLine')]
	public function testALaterFileWithOnlyTheNewPurchaseImportsIt(array $firstMapping, array $old, array $secondMapping, array $other): void {
		$this->import([$old], $firstMapping);

		$preview = $this->preview([$other], $secondMapping);
		$again = $this->import([$other], $secondMapping);

		$this->assertSame(0, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	/**
	 * A row stored with only its category as the description says no more
	 * than that. A line of that category on that day at that price is taken
	 * for it only when this mapping puts the category in the line's texts,
	 * never through a cell this mapping leaves out.
	 */
	public function testACategoryAloneIsComparedOnlyWhenTheMappingReadsIt(): void {
		$this->import([['13.10.2026', 'Milch', 'Lebensmittel', '-1.65']], self::BY_CATEGORY);
		$bretzeli = [['13.10.2026', 'Kambly Bretzeli 100g', 'Lebensmittel', '-1.65']];

		$this->assertSame(0, $this->preview($bretzeli, self::BY_PRODUCT)['duplicates']);
		$this->assertSame(1, $this->preview($bretzeli, self::WITH_CATEGORY)['duplicates']);
	}

	/** The bank's reference does not stop the same line being recognised under another mapping */
	public function testTheSameReferenceUnderAnotherMappingIsRecognised(): void {
		$coffee = ['14.10.2026', 'Kaffee', 'Lebensmittel', '-4.20', 'Café Zentral', 'R-1001'];
		$this->import([$coffee], self::WITH_REFERENCE);

		$preview = $this->preview([$coffee], self::WITH_REFERENCE_AND_CATEGORY);
		$again = $this->import([$coffee], self::WITH_REFERENCE_AND_CATEGORY);

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame(0, $again['imported']);
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
		$this->assertSame(['Parisienne Jaune B-PT', 'Parisienne Jaune B-PT, Zigaretten'], $this->descriptions());
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

	/**
	 * Two packs on one receipt, imported by product, then with the category
	 * joined (the first pack recognised, the second stored as new), then by
	 * product again. The first pack's line is its stored row by import id
	 * and takes no other, so the second pack's line is the row stored the
	 * second time: nothing goes in a third time.
	 */
	public function testALineItsOwnImportIdRecognisesTakesNoOtherStoredRow(): void {
		$pack = ['15.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'];
		$this->import([$pack], self::BY_PRODUCT);
		$this->assertSame(1, $this->import([$pack, $pack], self::WITH_CATEGORY)['imported']);
		$this->assertSame(['Parisienne Jaune B-PT', 'Parisienne Jaune B-PT, Zigaretten'], $this->descriptions());

		$preview = $this->preview([$pack, $pack], self::BY_PRODUCT);
		$again = $this->import([$pack, $pack], self::BY_PRODUCT);

		$this->assertSame(2, $preview['duplicates']);
		$this->assertSame(0, $again['imported']);
		$this->assertCount(2, $this->ledger);
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

	/** His rows of 11 Oct dated 3 Oct: date, product, category, amount */
	private static function oct11Rows(): array {
		return [
			['03.10.2026', 'Bier', 'Alkohol', '-13.80'],
			['03.10.2026', 'Werners Mini Markt Magenbrot 120G', 'Lebensmittel', '-3.95'],
			['03.10.2026', 'Werners Markt Magenbrot 250G', 'Lebensmittel', '-4.70'],
			['03.10.2026', 'Coop IPS Vollmilch UHT 3.5% 1L', 'Lebensmittel', '-1.65'],
			['03.10.2026', 'Mazot Raclette IPS classic Scheiben 400g', 'Lebensmittel', '-10.95'],
			['03.10.2026', 'Naturaplan Bio Bratspeck ca.110g', 'Lebensmittel', '-4.25'],
			['03.10.2026', 'Prix Garantie Knoblauch St', 'Lebensmittel', '-0.50'],
			['03.10.2026', 'Bio Kartoffeln grün 1KG CH', 'Lebensmittel', '-2.85'],
			['03.10.2026', 'Chirat Balsamico Zwiebeln 240g', 'Lebensmittel', '-4.50'],
			['03.10.2026', 'Kühne Gewürzgurken185G', 'Lebensmittel', '-2.60'],
			['03.10.2026', 'Alentejo Herdade Peso Reserva Jg 75CL', 'Alkohol', '-12.25'],
		];
	}

	/** His file of 12 Oct, all 25 lines in its own order (rows 1543-1567 of his backup) */
	private static function oct12File(): array {
		return [
			['12.10.2026', 'Wassertank Zubehör', 'Diverses', '-30.00'],
			['12.10.2026', 'Thermometer für Wasserkanister', 'Diverses', '-20.90'],
			['12.10.2026', 'Coop IPS Vollmilch UHT 3.5% 1L', 'Lebensmittel', '-1.65'],
			['12.10.2026', 'Emmi Crisp Truffes 150G', 'Lebensmittel', '-3.45'],
			['12.10.2026', 'Prix Garantie Feinkristall-Zucker 1KG', 'Lebensmittel', '-1.50'],
			['12.10.2026', 'familia c.m. plus Waldbeeren 600G', 'Lebensmittel', '-15.90'],
			['12.10.2026', 'Heineken Dose 6x50CL', 'Alkohol', '-13.80'],
			['12.10.2026', 'Yara Bus', 'Diverses', '-5.00'],
			['12.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'],
			['12.10.2026', 'Parisienne Jaune B-PT', 'Zigaretten', '-9.20'],
			['12.10.2026', 'Werners Nidletäfeli 150G', 'Lebensmittel', '-4.95'],
			['12.10.2026', 'Buitoni Pizzateig eckig 2x570g', 'Lebensmittel', '-6.90'],
			['12.10.2026', 'Galbani Mozzarella Grattugiata 150g', 'Lebensmittel', '-3.70'],
			['12.10.2026', 'Bio Pizza-Mozzarella gerieben 200g', 'Lebensmittel', '-3.80'],
			['12.10.2026', 'Naturafarm Hinterschinken 160G', 'Lebensmittel', '-5.10'],
			['12.10.2026', 'ÜNIQUE Peperoni offen 528', 'Lebensmittel', '-0.45'],
			['12.10.2026', 'Bio Champignons braun offen PLU 165', 'Lebensmittel', '-1.45'],
			['12.10.2026', 'Findus Rahmspinat TK 240g', 'Lebensmittel', '-3.30'],
			['12.10.2026', 'Mutti Pizzasauce Aromatica 210G', 'Lebensmittel', '-1.95'],
			['12.10.2026', 'Oecoplan Papiertasche D', 'Haushalt', '-0.40'],
			...self::OCT_3,
		];
	}
}
