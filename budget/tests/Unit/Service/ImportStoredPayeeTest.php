<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\Import\CriteriaEvaluator;
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
 * The payee as the import stored it (#399, round 7).
 *
 * The browser check of round 5 (check S6d): receipt5r.csv imported with the
 * product and the category joined, the Dremel row's payee set to "Dremel" in
 * the edit dialog, then the same file imported by product. The line went in
 * a second time: from the file alone a payee typed by hand cannot be told
 * apart from one read out of a column this line does not have. An import now
 * keeps the payee it stored (import_vendor), which an edit never touches, and
 * a re-import under another mapping compares the line with that.
 *
 * Runs the real parser, normalizer, duplicate detector and rule applicator
 * against an in-memory ledger.
 */
class ImportStoredPayeeTest extends TestCase {
	private const FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.csv';
	private const QIF_FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.qif';
	private const ACCOUNT = 1;

	/*
	 * The file's columns, as in the browser run's receipt (Date, Product,
	 * Amount, Category), then a shop and the bank's reference: 0 date,
	 * 1 product, 2 amount, 3 category, 4 shop, 5 reference.
	 */

	private const BY_PRODUCT = ['date' => 0, 'description' => 1, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_CATEGORY = ['date' => 0, 'description' => [1, 3], 'amount' => 2, 'skipFirstRow' => true];
	/** The reporter's own 12 Oct 20:45 mapping: joined, and the category in the notes */
	private const CATEGORY_IN_NOTES = ['date' => 0, 'description' => [1, 3], 'notes' => 3, 'amount' => 2, 'skipFirstRow' => true];
	/** The reporter's own 11 Oct mapping: the category as the description, the product as the payee and in the notes */
	private const CATEGORY_FIRST = ['date' => 0, 'description' => 3, 'vendor' => 1, 'notes' => 1, 'amount' => 2, 'skipFirstRow' => true];
	/** The product read as the payee only, the category as the description */
	private const PRODUCT_AS_PAYEE = ['date' => 0, 'description' => 3, 'vendor' => 1, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP = ['date' => 0, 'description' => 1, 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	/** Product, category and shop joined into the description, no payee */
	private const ALL_IN_DESCRIPTION = ['date' => 0, 'description' => [1, 3, 4], 'amount' => 2, 'skipFirstRow' => true];

	/** The browser run's receipt5r.csv */
	private const WOLFCRAFT = ['2026-10-06', 'Wolfcraft Drahtbürste Messing 3tlg', '-9.95', 'Modellbau / Renovation'];
	private const DREMEL = ['2026-10-06', 'Dremel Trennscheibe 426', '-12.40', 'Modellbau / Renovation'];
	/** Another Dremel product on that day at that price */
	private const OTHER_DREMEL = ['2026-10-06', 'Dremel Schleifstein 952', '-12.40', 'Modellbau / Renovation'];

	private const STARBUCKS = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Starbucks'];
	private const COSTA = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Costa'];
	/** The same coffee with the shop's cell blank */
	private const NO_SHOP = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', ''];

	private ImportService $service;
	private string $fileContent = '';

	/** @var ImportRule[] The user's import rules */
	private array $rules = [];

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
			function (string $userId, int $accountId, string $date, string $description, float $amount, string $type, ?int $categoryId = null, ?string $vendor = null, ?string $reference = null, ?string $notes = null, ?string $importId = null, ?int $billId = null, ?string $status = null, bool $excludedFromForecast = false, bool $deferBalanceUpdate = false, ?string $importVendor = null) {
				if ($importId !== null && in_array($importId, $this->importIds($accountId), true)) {
					throw new \Exception('Transaction with this import ID already exists');
				}
				$id = $this->nextId++;
				$this->ledger[$id] = compact('accountId', 'date', 'description', 'amount', 'type', 'vendor', 'reference', 'notes', 'importId', 'importVendor');
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
		// As TransactionMapper::findImportComparables() reads the rows
		$transactionMapper->method('findImportComparables')->willReturnCallback(function (int $accountId, string $from, string $to) {
			$rows = [];
			foreach ($this->ledger as $id => $row) {
				if ($row['accountId'] === $accountId && $row['date'] >= $from && $row['date'] <= $to) {
					$rows[] = [
						'id' => $id, 'date' => $row['date'], 'amount' => (string)$row['amount'], 'type' => $row['type'],
						'description' => $row['description'], 'vendor' => $row['vendor'], 'reference' => $row['reference'],
						'notes' => $row['notes'], 'import_id' => $row['importId'], 'import_vendor' => $row['importVendor'],
					];
				}
			}
			return $rows;
		});
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
		$account->setName('Sparkonto');
		$account->setType('savings');
		$account->setCurrency('CHF');
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('find')->willReturnCallback(fn (int $id) => $id === self::ACCOUNT ? $account : throw new \Exception('no account ' . $id));
		$accountMapper->method('findByName')->willReturnCallback(fn (string $userId, string $name) => $name === 'Sparkonto' ? $account : null);

		$ruleMapper = $this->createMock(ImportRuleMapper::class);
		$ruleMapper->method('findActive')->willReturnCallback(fn () => $this->rules);
		$ruleMapper->method('findActiveByIds')->willReturn([]);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedImportRuleIds')->willReturn([]);
		$shares->method('canAccess')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);

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
			new ImportRuleApplicator($ruleMapper, new CriteriaEvaluator($logger), $shares, $logger),
			new PresetRegistry(),
			$this->createMock(CategoryService::class),
			$this->createMock(TagSetService::class),
			$this->createMock(TransactionTagService::class),
			$this->createMock(ImportAccountLinkService::class),
			$this->createMock(BillService::class),
			$this->createMock(SettingService::class),
			$l,
			$logger
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

	/** @param array<int, list<string>> $rows Date, product, amount, category and, if any, shop and reference */
	private function csv(array $rows): string {
		$lines = ['Date,Product,Amount,Category,Shop,Reference'];
		foreach ($rows as $row) {
			$lines[] = implode(',', array_map(fn (string $cell) => '"' . $cell . '"', array_pad($row, 6, '')));
		}
		return implode("\n", $lines) . "\n";
	}

	private function import(array $rows, array $mapping, bool $applyRules = true): array {
		return $this->importRaw($this->csv($rows), $mapping, $applyRules);
	}

	private function preview(array $rows, array $mapping, bool $applyRules = true): array {
		return $this->previewRaw($this->csv($rows), $mapping, $applyRules);
	}

	private function importRaw(string $content, array $mapping, bool $applyRules = true, ?string $presetId = null): array {
		$this->fileContent = $content;
		return $this->service->processImport('user1', self::FILE_ID, $mapping, self::ACCOUNT, null, true, $applyRules, ',', $presetId);
	}

	/** The import screen's preview, which lists the duplicates it flags */
	private function previewRaw(string $content, array $mapping, bool $applyRules = true, ?string $presetId = null): array {
		$this->fileContent = $content;
		return $this->service->previewImport('user1', self::FILE_ID, $mapping, self::ACCOUNT, null, false, ',', $presetId, null, $applyRules);
	}

	/** @return int[] The rows of the file the preview flags as duplicates */
	private static function flagged(array $preview): array {
		return array_values(array_column(array_filter($preview['transactions'], fn (array $row) => $row['isDuplicate']), 'rowIndex'));
	}

	/** The edit dialog: a stored row's payee set by hand, nothing else touched */
	private function editPayee(int $id, ?string $payee): void {
		$this->ledger[$id]['vendor'] = $payee;
	}

	/** A row as one imported before the update stored it: no import_vendor */
	private function storedBeforeTheUpdate(int $id): void {
		$this->ledger[$id]['importVendor'] = null;
	}

	private static function wolfcraftRule(): ImportRule {
		$rule = new ImportRule();
		$rule->setId(1);
		$rule->setUserId('user1');
		$rule->setName('Wolfcraft payee');
		$rule->setPriority(20);
		$rule->setActive(true);
		$rule->setApplyOnImport(true);
		$rule->setSchemaVersion(2);
		$rule->setStopProcessing(false);
		$rule->setCriteria((string)json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
			['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => 'Wolfcraft', 'negate' => false],
		]]]));
		$rule->setActions((string)json_encode(['version' => 2, 'stopProcessing' => false, 'actions' => [
			['type' => 'set_vendor', 'value' => 'Wolfcraft', 'behavior' => 'always', 'priority' => 50],
		]]));
		return $rule;
	}

	// ===== what an import stores =====

	public function testAnImportStoresThePayeeItWroteAfterTheRules(): void {
		$this->rules = [self::wolfcraftRule()];
		$this->import([self::WOLFCRAFT, self::DREMEL, self::STARBUCKS], self::WITH_SHOP);

		$this->assertSame('Wolfcraft', $this->ledger[1]['vendor']);
		$this->assertSame('Wolfcraft', $this->ledger[1]['importVendor'], 'The payee a rule gave it');
		$this->assertNull($this->ledger[2]['vendor']);
		$this->assertSame('', $this->ledger[2]['importVendor'], 'Imported with no payee, which is not the same as not known');
		$this->assertSame('Starbucks', $this->ledger[3]['importVendor'], 'The shop column');
	}

	public function testAQifImportStoresThePayeeItWrote(): void {
		$this->fileContent = "!Type:Bank\nD10/14/2026\nT-4.20\nPStarbucks\nMKaffee\n^\n";
		$result = $this->service->processImport('user1', self::QIF_FILE_ID, [], null, ['Account 1' => self::ACCOUNT], true, true, ',', null);

		$this->assertSame(1, $result['imported']);
		$row = $this->ledger[1];
		$this->assertNotNull($row['importVendor']);
		$this->assertSame((string)$row['vendor'], $row['importVendor']);
	}

	// ===== the browser run's bug =====

	/**
	 * The row whose payee was edited is flagged like the line next to it,
	 * whatever the payee typed, whichever mapping reads the file again, with
	 * the rules applied or not.
	 */
	public static function editedPayees(): array {
		$sets = [];
		$payees = [
			// A word of the product, as the browser run typed it
			'Dremel' => 'Dremel',
			// Nothing on the line says it
			'Hornbach' => 'Hornbach',
		];
		$mappings = [
			'by product' => self::BY_PRODUCT,
			'his 11 Oct mapping' => self::CATEGORY_FIRST,
			'the product as the payee only' => self::PRODUCT_AS_PAYEE,
			'his 20:45 mapping' => self::CATEGORY_IN_NOTES,
		];
		foreach ($payees as $what => $payee) {
			foreach ($mappings as $how => $mapping) {
				foreach (['rules applied' => true, 'rules not applied' => false] as $rules => $applyRules) {
					$sets[$what . ', ' . $how . ', ' . $rules] = [$payee, self::WITH_CATEGORY, $mapping, $applyRules];
				}
			}
		}
		// The other way round: the category left out the first time
		foreach (['rules applied' => true, 'rules not applied' => false] as $rules => $applyRules) {
			$sets['Hornbach, by product first, category added, ' . $rules] = ['Hornbach', self::BY_PRODUCT, self::WITH_CATEGORY, $applyRules];
			$sets['Dremel, his 11 Oct mapping first, by product, ' . $rules] = ['Dremel', self::CATEGORY_FIRST, self::BY_PRODUCT, $applyRules];
		}
		return $sets;
	}

	#[DataProvider('editedPayees')]
	public function testARowWhosePayeeWasEditedByHandIsRecognisedUnderAnotherMapping(string $payee, array $firstMapping, array $secondMapping, bool $applyRules): void {
		$this->rules = [self::wolfcraftRule()];
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->assertSame(2, $this->import($file, $firstMapping)['imported']);
		$this->editPayee(2, $payee);

		$preview = $this->preview($file, $secondMapping, $applyRules);
		$again = $this->import($file, $secondMapping, $applyRules);

		$this->assertSame([0, 1], self::flagged($preview));
		$this->assertSame(0, $again['imported']);
		$this->assertSame(2, $again['skipped']);
		$this->assertCount(2, $this->ledger);
	}

	/** A payee a rule set, then changed by hand */
	public function testAPayeeARuleSetThenEditedByHandIsRecognised(): void {
		$this->rules = [self::wolfcraftRule()];
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->import($file, self::WITH_CATEGORY);
		$this->editPayee(1, 'Hornbach');
		$this->editPayee(2, 'Dremel');

		foreach ([true, false] as $applyRules) {
			$this->assertSame([0, 1], self::flagged($this->preview($file, self::BY_PRODUCT, $applyRules)));
		}
		$this->assertSame(0, $this->import($file, self::BY_PRODUCT, false)['imported']);
	}

	/** A payee read from the shop's column, then edited, then a mapping that joins the category */
	public function testAPayeeFromAColumnThenEditedByHandIsRecognised(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP);
		$this->editPayee(1, 'Starbucks Zürich');

		$this->assertSame([0], self::flagged($this->preview([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)));
		$this->assertSame(0, $this->import([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)['imported']);
	}

	/** Cleared by hand: the payee the import stored still says which line it was */
	public function testAPayeeClearedByHandIsComparedAsImported(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY);
		$this->editPayee(1, null);

		$this->assertSame([1], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP)));
		$this->assertSame(1, $this->import([self::COSTA, self::STARBUCKS], self::WITH_SHOP)['imported']);
		$this->assertSame('Costa', $this->ledger[2]['vendor']);
	}

	// ===== lines that are not the stored row =====

	/**
	 * Each of these is a purchase of its own on that day at that price, and
	 * imports, the stored row's payee edited or not.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>, 3: list<string>}>
	 */
	public static function newLines(): array {
		return [
			'another product of the brand' => [self::WITH_CATEGORY, self::DREMEL, self::BY_PRODUCT, self::OTHER_DREMEL],
			'another product of the brand, his 11 Oct mapping' => [self::WITH_CATEGORY, self::DREMEL, self::CATEGORY_FIRST, self::OTHER_DREMEL],
			'another shop\'s coffee, the shop read as the payee' => [self::WITH_SHOP, self::STARBUCKS, self::WITH_SHOP_AND_CATEGORY, self::COSTA],
			'another shop\'s coffee, the shop read again' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::WITH_SHOP, self::COSTA],
			'another shop\'s coffee, the shop left out' => [self::WITH_SHOP, self::STARBUCKS, self::WITH_CATEGORY, self::COSTA],
			'another shop\'s coffee, the shop joined into the description' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::ALL_IN_DESCRIPTION, self::COSTA],
			'another shop\'s coffee, the shop in the stored description' => [self::ALL_IN_DESCRIPTION, self::STARBUCKS, self::WITH_SHOP, self::COSTA],
			// The reviewer's P1: a later export with the shop's cell blank
			'the coffee with the shop left blank' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::BY_PRODUCT, self::NO_SHOP],
			// The reviewer's P5: Costa against a Starbucks row by product
			'another shop\'s coffee, by product' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::BY_PRODUCT, self::COSTA],
		];
	}

	#[DataProvider('newLines')]
	public function testANewLineIsNotTakenForARowWhosePayeeWasEdited(array $firstMapping, array $old, array $secondMapping, array $new): void {
		$this->import([$old], $firstMapping);
		$this->editPayee(1, 'Mein Laden');

		$preview = $this->preview([$new], $secondMapping);
		$again = $this->import([$new], $secondMapping);

		$this->assertSame(0, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	/** One purchase stored and edited, two identical ones in the file: the second imports */
	public function testASecondIdenticalPurchaseStillImports(): void {
		$this->import([self::DREMEL], self::WITH_CATEGORY);
		$this->editPayee(1, 'Dremel');

		$preview = $this->preview([self::DREMEL, self::DREMEL], self::BY_PRODUCT);
		$again = $this->import([self::DREMEL, self::DREMEL], self::BY_PRODUCT);

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	// ===== the review's probes (round 6) =====

	/**
	 * Each probe gives what the stored row gave before its payee was edited:
	 * the edit changes nothing, so a line the row cannot be is still not
	 * taken for it.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>, 4: string, 5: int}>
	 */
	public static function probes(): array {
		$shop = "Date,Product,Amount,Category,Shop\n";
		$memo = "Date,Type,Description,Amount,Memo\n";
		$currency = "Date,Product,Amount,Category,Currency\n";
		$kind = "Date,Payee,Amount,Category,Kind\n";
		return [
			// A later export with the shop's cell blank
			'P1' => [$shop . "2026-10-14,Kaffee,-4.20,Lebensmittel,Starbucks\n", self::WITH_SHOP_AND_CATEGORY,
				$shop . "2026-10-14,Kaffee,-4.20,Lebensmittel,\n", self::BY_PRODUCT, 'Starbucks Zurich', 0],
			// The payee read from a memo, a later line with the memo blank
			'P1b' => [$memo . "2026-10-14,FPO,TRANSFER,-50.00,Rent share\n", ['date' => 0, 'description' => [1, 2], 'vendor' => 4, 'amount' => 3, 'skipFirstRow' => true],
				$memo . "2026-10-14,FPO,TRANSFER,-50.00,\n", ['date' => 0, 'description' => 2, 'notes' => 1, 'amount' => 3, 'skipFirstRow' => true], 'Miete', 0],
			// A later export without the shop's column at all
			'P4' => [$shop . "2026-10-14,Kaffee,-4.20,Lebensmittel,Starbucks\n", self::WITH_SHOP_AND_CATEGORY,
				"Date,Product,Amount,Category\n2026-10-14,Kaffee,-4.20,Lebensmittel\n", self::BY_PRODUCT, 'Starbucks Zurich', 0],
			// Another currency, in a column neither import read (the
			// stored row has no payee, so the row gives what it gave
			// unedited: it cannot tell the two lines apart)
			'P2, currency not mapped' => [$currency . "2026-10-14,Hotel,-100.00,Travel,EUR\n", self::WITH_CATEGORY,
				$currency . "2026-10-14,Hotel,-100.00,Travel,USD\n", self::BY_PRODUCT, 'Hilton', 1],
			'P2, currency mapped' => [$currency . "2026-10-14,Hotel,-100.00,Travel,EUR\n", self::WITH_CATEGORY,
				$currency . "2026-10-14,Hotel,-100.00,Travel,USD\n", ['date' => 0, 'description' => 1, 'amount' => 2, 'currency' => 4, 'skipFirstRow' => true], 'Hilton', 1],
			// Another kind of payment, the same: the first import read no kind
			'P3, kind not mapped' => [$kind . "2026-10-14,British Gas,-50.00,Bills,DD\n", self::WITH_CATEGORY,
				$kind . "2026-10-14,British Gas,-50.00,Bills,SO\n", self::BY_PRODUCT, 'Gas', 1],
			'P3, kind as the type' => [$kind . "2026-10-14,British Gas,-50.00,Bills,DD\n", self::WITH_CATEGORY,
				$kind . "2026-10-14,British Gas,-50.00,Bills,SO\n", ['date' => 0, 'description' => 1, 'type' => 4, 'amount' => 2, 'skipFirstRow' => true], 'Gas', 1],
		];
	}

	#[DataProvider('probes')]
	public function testAProbeGivesWhatTheRowGaveBeforeItsPayeeWasEdited(string $first, array $firstMapping, string $second, array $secondMapping, string $payee, int $unedited): void {
		$this->importRaw($first, $firstMapping);
		$before = $this->previewRaw($second, $secondMapping)['duplicates'];
		$this->editPayee(1, $payee);
		$after = $this->previewRaw($second, $secondMapping)['duplicates'];

		$this->assertSame($unedited, $before, 'What the unedited row gives (as before this change)');
		$this->assertSame($before, $after, 'The edit changes nothing');
	}

	/** The reviewer's P5: notes typed by hand say nothing either */
	public function testACostaLineIsNotTakenForAStarbucksRowWithCostaTypedIntoItsNotes(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY);
		$this->editPayee(1, 'Starbucks Zurich');
		$this->ledger[1]['notes'] = 'Costa';

		$this->assertSame(0, $this->preview([self::COSTA], self::BY_PRODUCT)['duplicates']);
		$this->assertSame(0, $this->preview([self::COSTA], self::WITH_SHOP)['duplicates']);
	}

	/**
	 * The reviewer's Q1: the stored Starbucks row stands for the Starbucks
	 * line, never the one with the shop left blank, in either order, edited
	 * or not.
	 */
	public function testOnlyTheStarbucksLineIsTakenForTheStarbucksRow(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY);
		$this->assertSame([0], self::flagged($this->preview([self::STARBUCKS, self::NO_SHOP], self::BY_PRODUCT)));
		$this->assertSame([1], self::flagged($this->preview([self::NO_SHOP, self::STARBUCKS], self::BY_PRODUCT)));

		$this->editPayee(1, 'Starbucks Zurich');
		$this->assertSame([0], self::flagged($this->preview([self::STARBUCKS, self::NO_SHOP], self::BY_PRODUCT)));
		$this->assertSame([1], self::flagged($this->preview([self::NO_SHOP, self::STARBUCKS], self::BY_PRODUCT)));

		$again = $this->import([self::NO_SHOP, self::STARBUCKS], self::BY_PRODUCT);
		$this->assertSame(1, $again['imported']);
		$this->assertNull($this->ledger[2]['vendor'], 'The line with no shop is the one that went in');
	}

	// ===== several lines, one stored row (review of round 7, M2) =====

	/**
	 * A row imported with no payee, then given the shop by hand: of two
	 * lines the stored row could each be, the one the typed payee names is
	 * the one taken, wherever it is in the file, and the other comes in.
	 */
	public function testOfTwoLinesTheOneTheTypedPayeeNamesIsTakenForTheRow(): void {
		$this->import([self::STARBUCKS], self::BY_PRODUCT);
		$this->assertSame('', $this->ledger[1]['importVendor']);
		$this->editPayee(1, 'Starbucks');

		$this->assertSame([1], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)));
		$this->assertSame([0], self::flagged($this->preview([self::STARBUCKS, self::COSTA], self::WITH_SHOP_AND_CATEGORY)));

		$again = $this->import([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY);
		$this->assertSame(1, $again['imported']);
		$this->assertSame('Costa', $this->ledger[2]['vendor']);
	}

	/** With a stored row for each, both lines are still recognised */
	public function testEveryLineIsStillRecognisedWhenThereIsARowForEach(): void {
		$this->import([self::STARBUCKS, self::STARBUCKS], self::BY_PRODUCT);
		$this->editPayee(1, 'Starbucks');

		$this->assertSame([0, 1], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)));
		$this->assertSame(0, $this->import([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)['imported']);
	}

	/** A typed payee no line names changes nothing: the first line is taken, as before */
	public function testATypedPayeeNoLineNamesLeavesThePairingAsItWas(): void {
		$this->import([self::STARBUCKS], self::BY_PRODUCT);
		$this->editPayee(1, 'Hornbach');

		$this->assertSame([0], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)));
	}

	// ===== rows imported before the update =====

	/** No stored payee: the row is compared by its payee now, as before, so the browser run's case still doubles */
	public function testARowImportedBeforeTheUpdateIsComparedByItsPayeeNow(): void {
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->import($file, self::WITH_CATEGORY);
		$this->storedBeforeTheUpdate(1);
		$this->storedBeforeTheUpdate(2);
		$this->editPayee(2, 'Dremel');

		$this->assertSame([0], self::flagged($this->preview($file, self::BY_PRODUCT)));
		$this->assertSame(1, $this->import($file, self::BY_PRODUCT)['imported']);
	}

	/** Unedited, it is recognised as it was before the update */
	public function testAnUneditedRowImportedBeforeTheUpdateIsRecognisedAsBefore(): void {
		$this->rules = [self::wolfcraftRule()];
		$file = [self::WOLFCRAFT, self::DREMEL, self::STARBUCKS];
		$this->import($file, self::WITH_SHOP_AND_CATEGORY);
		foreach (array_keys($this->ledger) as $id) {
			$this->storedBeforeTheUpdate($id);
		}

		$this->assertSame([0, 1, 2], self::flagged($this->preview($file, self::WITH_SHOP)));
		$this->assertSame([0, 1], self::flagged($this->preview([self::WOLFCRAFT, self::DREMEL, self::COSTA], self::WITH_SHOP)));
	}

	// ===== rows imported before the update, after migration 126 filled them in =====

	/**
	 * What Version001000126Date20261021::postSchemaChange() does to a row an
	 * import stored before the update (ImportVendorBackfillTest runs the
	 * statement itself): a file import's row keeps its payee, '' for none,
	 * any other row stays NULL.
	 */
	private function backfilled(int $id): void {
		$row = $this->ledger[$id];
		$fromAFile = false;
		foreach (\OCA\Budget\Migration\Version001000126Date20261021::FILE_IMPORT_ID_PREFIXES as $prefix) {
			$fromAFile = $fromAFile || str_starts_with((string)$row['importId'], $prefix);
		}
		$this->ledger[$id]['importVendor'] = $row['importVendor'] ?? ($fromAFile ? ($row['vendor'] ?? '') : null);
	}

	private function importedBeforeTheUpdateThenMigrated(array $rows, array $mapping): void {
		$this->import($rows, $mapping);
		foreach (array_keys($this->ledger) as $id) {
			$this->storedBeforeTheUpdate($id);
			$this->backfilled($id);
		}
	}

	/** A payee read from a column, edited after the update: followed */
	public function testAnOldRowsPayeeEditedAfterTheUpdateIsFollowed(): void {
		$this->importedBeforeTheUpdateThenMigrated([self::STARBUCKS], self::WITH_SHOP);
		$this->assertSame('Starbucks', $this->ledger[1]['importVendor']);
		$this->editPayee(1, 'Starbucks Zürich');

		$this->assertSame([1], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)));
		$again = $this->import([self::COSTA, self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY);
		$this->assertSame(1, $again['imported']);
		$this->assertSame('Costa', $this->ledger[2]['vendor']);
	}

	/** A payee a rule set, edited after the update: followed, rules on or off */
	public function testAnOldRowsRulePayeeEditedAfterTheUpdateIsFollowed(): void {
		$this->rules = [self::wolfcraftRule()];
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->importedBeforeTheUpdateThenMigrated($file, self::WITH_CATEGORY);
		$this->editPayee(1, 'Hornbach');

		foreach ([true, false] as $applyRules) {
			$this->assertSame([0, 1], self::flagged($this->preview($file, self::BY_PRODUCT, $applyRules)));
		}
	}

	/**
	 * The browser run's Dremel row, imported with no payee: the migration
	 * records that as '', so a payee typed on it after the update is
	 * followed, whichever mapping reads the file again.
	 */
	public function testAnOldRowImportedWithNoPayeeThenGivenOneAfterTheUpdateIsFollowed(): void {
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->importedBeforeTheUpdateThenMigrated($file, self::WITH_CATEGORY);
		$this->assertSame('', $this->ledger[2]['importVendor']);
		$this->editPayee(2, 'Dremel');

		foreach ([self::BY_PRODUCT, self::CATEGORY_FIRST, self::PRODUCT_AS_PAYEE] as $mapping) {
			$this->assertSame([0, 1], self::flagged($this->preview($file, $mapping)));
		}
		$this->assertSame(0, $this->import($file, self::BY_PRODUCT)['imported']);
	}

	/** A payee edited before the update is what the migration keeps: no change from before */
	public function testAPayeeEditedBeforeTheUpdateIsNotFollowed(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP);
		$this->storedBeforeTheUpdate(1);
		$this->editPayee(1, 'Starbucks Zürich');
		$this->backfilled(1);

		$this->assertSame('Starbucks Zürich', $this->ledger[1]['importVendor']);
		$this->assertSame(0, $this->preview([self::STARBUCKS], self::WITH_SHOP_AND_CATEGORY)['duplicates']);
	}

	/** A row the migration leaves alone is not filled: typed in, bank sync */
	public function testTheMigrationLeavesOtherRowsUnknown(): void {
		$this->import([self::STARBUCKS], self::WITH_SHOP);
		$this->storedBeforeTheUpdate(1);
		$this->ledger[1]['importId'] = 'gocardless:abc';
		$this->backfilled(1);

		$this->assertNull($this->ledger[1]['importVendor']);
	}

	// ===== an app's export after a manual mapping of it (R5-4) =====

	private const YNAB_HEADER = "\"Account\",\"Flag\",\"Date\",\"Payee\",\"Category Group/Category\",\"Category Group\",\"Category\",\"Memo\",\"Outflow\",\"Inflow\",\"Cleared\"\n";
	/** The memo as the description, the payee as the payee */
	private const YNAB_MANUAL = ['date' => 2, 'description' => 7, 'vendor' => 3, 'expenseColumn' => 8, 'incomeColumn' => 9, 'skipFirstRow' => true];

	private static function ynabRow(string $payee, string $memo): string {
		return "\"Sparkonto\",\"\",\"10/14/2026\",\"{$payee}\",\"\",\"\",\"\",\"{$memo}\",\"4.20\",\"0.00\",\"Cleared\"\n";
	}

	/** The preset compares its row with the payee the manual mapping stored, not one typed since */
	public function testAPresetComparesTheStoredRowsPayeeAsImported(): void {
		$this->importRaw(self::YNAB_HEADER . self::ynabRow('Corner Cafe', 'Kaffeebohnen'), self::YNAB_MANUAL);
		$this->assertSame('Corner Cafe', $this->ledger[1]['importVendor']);
		$this->editPayee(1, 'Café am Eck');

		$content = self::YNAB_HEADER . self::ynabRow('Book Shop', 'Kaffeebohnen') . self::ynabRow('Corner Cafe', 'Kaffeebohnen');
		$this->assertSame([1], self::flagged($this->previewRaw($content, [], true, 'ynab')));
	}

	/**
	 * The same for an app's export: a manual mapping stored the memo with no
	 * payee and the payee was typed in since; of the two rows of the export
	 * the stored text fits, the one the typed payee names is taken for it.
	 */
	public function testAPresetTakesTheRowTheTypedPayeeNamesFirst(): void {
		$manual = ['date' => 2, 'description' => 7, 'expenseColumn' => 8, 'incomeColumn' => 9, 'skipFirstRow' => true];
		$this->importRaw(self::YNAB_HEADER . self::ynabRow('Corner Cafe', 'Cafe'), $manual);
		$this->assertSame('', $this->ledger[1]['importVendor']);
		$this->editPayee(1, 'Corner Cafe');

		$content = self::YNAB_HEADER . self::ynabRow('Cafe Nero', 'x') . self::ynabRow('Corner Cafe', 'y');
		$this->assertSame([1], self::flagged($this->previewRaw($content, [], true, 'ynab')));
		$again = $this->importRaw($content, [], true, 'ynab');
		$this->assertSame(1, $again['imported']);
		$this->assertSame('Cafe Nero', $this->ledger[2]['description']);
	}

	/** A row stored before the update is compared by its payee now, as before */
	public function testAPresetComparesARowImportedBeforeTheUpdateByItsPayeeNow(): void {
		$this->importRaw(self::YNAB_HEADER . self::ynabRow('Corner Cafe', 'Kaffeebohnen'), self::YNAB_MANUAL);
		$this->storedBeforeTheUpdate(1);

		$this->assertSame(1, $this->previewRaw(self::YNAB_HEADER . self::ynabRow('Corner Cafe', 'Kaffeebohnen'), [], true, 'ynab')['duplicates']);
		$this->editPayee(1, 'Café am Eck');
		$this->assertSame(0, $this->previewRaw(self::YNAB_HEADER . self::ynabRow('Corner Cafe', 'Kaffeebohnen'), [], true, 'ynab')['duplicates']);
	}
}
