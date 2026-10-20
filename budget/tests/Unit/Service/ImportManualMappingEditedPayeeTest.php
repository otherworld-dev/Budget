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
 * A CSV imported again with another manual mapping after the user edited a
 * stored row's payee by hand (#399, round 6).
 *
 * The browser check of round 5: receipt5r.csv imported with the product and
 * the category joined, the Dremel row's payee set to "Dremel" in the edit
 * dialog, then the same file imported by product. The Wolfcraft line was
 * flagged, the Dremel line went in a second time: its payee was neither one
 * of the line's cells nor anything the user's rules give it.
 *
 * Runs the real parser, normalizer, duplicate detector and rule applicator
 * against an in-memory ledger.
 */
class ImportManualMappingEditedPayeeTest extends TestCase {
	private const FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.csv';
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
	/** The same without the notes */
	private const PRODUCT_AS_PAYEE = ['date' => 0, 'description' => 3, 'vendor' => 1, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP = ['date' => 0, 'description' => 1, 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	/** Product, category and shop joined into the description, no payee */
	private const ALL_IN_DESCRIPTION = ['date' => 0, 'description' => [1, 3, 4], 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_REFERENCE = ['date' => 0, 'description' => 1, 'reference' => 5, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_REFERENCE_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'reference' => 5, 'amount' => 2, 'skipFirstRow' => true];

	/** The browser run's receipt5r.csv */
	private const WOLFCRAFT = ['2026-10-06', 'Wolfcraft Drahtbürste Messing 3tlg', '-9.95', 'Modellbau / Renovation'];
	private const DREMEL = ['2026-10-06', 'Dremel Trennscheibe 426', '-12.40', 'Modellbau / Renovation'];
	/** Another Dremel product on that day at that price */
	private const OTHER_DREMEL = ['2026-10-06', 'Dremel Schleifstein 952', '-12.40', 'Modellbau / Renovation'];

	private const STARBUCKS = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Starbucks'];
	private const COSTA = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Costa'];

	/** A coffee under the bank's reference, the shop in no column */
	private const COFFEE = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', '', 'R-1001'];
	private const NEXT_COFFEE = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', '', 'R-1002'];

	private ImportService $service;
	private string $fileContent = '';

	/** @var ImportRule[] The user's import rules */
	private array $rules = [];
	/** How often the import read the user's rules */
	private int $rulesLoaded = 0;

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
		$ruleMapper->method('findActive')->willReturnCallback(function () {
			$this->rulesLoaded++;
			return $this->rules;
		});
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
		$this->fileContent = $this->csv($rows);
		return $this->service->processImport('user1', self::FILE_ID, $mapping, self::ACCOUNT, null, true, $applyRules, ',', null);
	}

	/** The import screen's preview, which lists the duplicates it flags */
	private function preview(array $rows, array $mapping, bool $applyRules = true): array {
		$this->fileContent = $this->csv($rows);
		return $this->service->previewImport('user1', self::FILE_ID, $mapping, self::ACCOUNT, null, false, ',', null, null, $applyRules);
	}

	/** @return int[] The rows of the file the preview flags as duplicates */
	private static function flagged(array $preview): array {
		return array_values(array_column(array_filter($preview['transactions'], fn (array $row) => $row['isDuplicate']), 'rowIndex'));
	}

	/** The edit dialog: a stored row's payee set by hand, nothing else touched */
	private function editPayee(int $id, ?string $payee): void {
		$this->ledger[$id]['vendor'] = $payee;
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

	/**
	 * The browser run's case and its variations: the first import read every
	 * text cell of the line, so the row's import id says which line it came
	 * from, whatever its payee says now.
	 */
	public static function editedPayees(): array {
		$sets = [];
		$payees = [
			// A word of the product, as the browser run typed it
			'a payee from the product' => 'Dremel',
			// Nothing on the line says it
			'a payee no cell holds' => 'Hornbach',
		];
		$mappings = [
			'by product' => self::BY_PRODUCT,
			'his 11 Oct mapping' => self::CATEGORY_FIRST,
			'the product as the payee' => self::PRODUCT_AS_PAYEE,
			'his 20:45 mapping' => self::CATEGORY_IN_NOTES,
		];
		foreach ($payees as $what => $payee) {
			foreach ($mappings as $how => $mapping) {
				foreach (['rules applied' => true, 'rules not applied' => false] as $rules => $applyRules) {
					$sets[$what . ', ' . $how . ', ' . $rules] = [$payee, self::WITH_CATEGORY, $mapping, $applyRules];
				}
			}
		}
		// His 20:45 mapping the first time, the category in the notes too
		$sets['a payee no cell holds, his 20:45 mapping first'] = ['Hornbach', self::CATEGORY_IN_NOTES, self::BY_PRODUCT, true];
		return $sets;
	}

	/** The browser run's bug: the row whose payee was edited is flagged like the line next to it */
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

	/** A payee cleared by hand, and one edited on a row whose description was edited too */
	public function testOtherHandEditsOfTheRowAreRecognisedToo(): void {
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->import($file, self::WITH_CATEGORY);
		$this->ledger[1]['vendor'] = 'Wolfcraft';
		$this->ledger[2]['description'] = 'Trennscheibe für die Garage';
		$this->editPayee(2, 'Hornbach');

		$this->assertSame([0, 1], self::flagged($this->preview($file, self::BY_PRODUCT)));
		$this->assertSame([0, 1], self::flagged($this->preview($file, self::CATEGORY_FIRST)));
		$this->assertSame(0, $this->import($file, self::BY_PRODUCT)['imported']);
	}

	/**
	 * His 11 Oct mapping the first time: the product went into the payee and
	 * the notes. The payee edited, the notes still hold the product as the
	 * line's cell reads, so they say which line it was.
	 */
	public function testTheNotesSayWhichLineARowWithOnlyItsCategoryCameFrom(): void {
		$file = [self::WOLFCRAFT, self::DREMEL];
		$this->import($file, self::CATEGORY_FIRST);
		$this->editPayee(2, 'Dremel');

		$this->assertSame([0, 1], self::flagged($this->preview($file, self::WITH_CATEGORY)));
		$this->assertSame(0, $this->import($file, self::WITH_CATEGORY)['imported']);
		$this->assertCount(2, $this->ledger);
	}

	/** The bank's reference is one of the cells the id was read from */
	public function testARowWithTheBanksReferenceIsRecognised(): void {
		$this->import([self::COFFEE], self::WITH_REFERENCE_AND_CATEGORY);
		$this->editPayee(1, 'Café Zentral');

		$this->assertSame(1, $this->preview([self::COFFEE], self::WITH_REFERENCE)['duplicates']);
		$this->assertSame(0, $this->import([self::COFFEE], self::WITH_REFERENCE)['imported']);
	}

	/**
	 * Lines a row with an edited payee is not, on that day at that price:
	 * each is a purchase of its own and imports.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>, 3: list<string>}>
	 */
	public static function newLines(): array {
		return [
			'another product of the brand' => [self::WITH_CATEGORY, self::DREMEL, self::BY_PRODUCT, self::OTHER_DREMEL],
			'another product of the brand, his 11 Oct mapping' => [self::WITH_CATEGORY, self::DREMEL, self::CATEGORY_FIRST, self::OTHER_DREMEL],
			'another of the bank\'s references' => [self::WITH_REFERENCE_AND_CATEGORY, self::COFFEE, self::WITH_REFERENCE, self::NEXT_COFFEE],
			// The shop's column is none the row's import id was read from
			'another shop\'s coffee, the shop read as the payee' => [self::WITH_SHOP, self::STARBUCKS, self::WITH_SHOP_AND_CATEGORY, self::COSTA],
			'another shop\'s coffee, the shop read again' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::WITH_SHOP, self::COSTA],
			// (Left out by both imports, the shop is in neither import id, so
			// the two coffees carry the same one, whatever the payees say.)
			'another shop\'s coffee, the shop left out' => [self::WITH_SHOP, self::STARBUCKS, self::WITH_CATEGORY, self::COSTA],
			'another shop\'s coffee, the shop joined into the description' => [self::WITH_SHOP_AND_CATEGORY, self::STARBUCKS, self::ALL_IN_DESCRIPTION, self::COSTA],
			// Here the shop is in the import id, which Costa's line does not give
			'another shop\'s coffee, the shop in the stored description' => [self::ALL_IN_DESCRIPTION, self::STARBUCKS, self::WITH_SHOP, self::COSTA],
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

	/** With Starbucks' coffee stored by every text cell, only its own line is recognised */
	public function testOnlyTheStoredShopsCoffeeIsRecognised(): void {
		$this->import([self::STARBUCKS], self::ALL_IN_DESCRIPTION);
		$this->editPayee(1, 'Starbucks Zürich');

		$this->assertSame([1], self::flagged($this->preview([self::COSTA, self::STARBUCKS], self::WITH_SHOP)));
		$this->assertSame(1, $this->import([self::COSTA, self::STARBUCKS], self::WITH_SHOP)['imported']);
		$this->assertSame('Costa', $this->ledger[2]['vendor']);
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

	/**
	 * The limit, kept on purpose: when the import that stored the row left a
	 * text column out, another line could have given the same import id with
	 * something else in that column, its shop say (Costa's coffee above), and
	 * only the payee could have told the two apart. Once it is edited by hand
	 * it cannot, so the line imports, as it did before.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function rowsThatLeftAColumnOut(): array {
		return [
			'the category left out' => [self::BY_PRODUCT, self::WITH_CATEGORY],
			'only the category as the description, the product as the payee' => [self::PRODUCT_AS_PAYEE, self::WITH_CATEGORY],
		];
	}

	#[DataProvider('rowsThatLeftAColumnOut')]
	public function testARowWhoseImportLeftAColumnOutCannotSayWhichLineItWas(array $firstMapping, array $secondMapping): void {
		$this->import([self::DREMEL], $firstMapping);
		$this->editPayee(1, 'Hornbach');

		$this->assertSame(0, $this->preview([self::DREMEL], $secondMapping)['duplicates']);
	}

	/** The rules are still read once for a preview and once for an import */
	public function testTheRulesAreReadOncePerImport(): void {
		$this->rules = [self::wolfcraftRule()];
		$this->import([self::WOLFCRAFT, self::DREMEL], self::WITH_CATEGORY);
		$this->editPayee(2, 'Hornbach');

		foreach ([true, false] as $applyRules) {
			$this->rulesLoaded = 0;
			$this->assertSame(2, $this->preview([self::WOLFCRAFT, self::DREMEL], self::BY_PRODUCT, $applyRules)['duplicates']);
			$this->assertLessThanOrEqual(1, $this->rulesLoaded);
		}
	}
}
