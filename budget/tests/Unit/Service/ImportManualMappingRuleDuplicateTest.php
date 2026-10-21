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
 * A CSV imported again with another manual mapping, when one of the user's
 * import rules (or a hand edit) changed the row the first import stored
 * (#399, round 2).
 *
 * The browser check of the first round: with a rule "Description contains
 * Wolfcraft: set the payee to Wolfcraft", a receipt imported by product and
 * then again with the category joined to the product went in twice, because
 * the stored row's payee was not one of the line's cells. The same held for
 * a rule that set or rewrote the description or the reference.
 *
 * Runs the real parser, normalizer, duplicate detector and rule applicator
 * (with real criteria) against an in-memory ledger.
 */
class ImportManualMappingRuleDuplicateTest extends TestCase {
	private const FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.csv';
	private const ACCOUNT = 1;

	/*
	 * The file's columns, as in the browser run's receipt (Date, Product,
	 * Amount, Category), then a shop and the bank's reference: 0 date,
	 * 1 product, 2 amount, 3 category, 4 shop, 5 reference.
	 */

	/** The browser run's first import: the product as the description */
	private const BY_PRODUCT = ['date' => 0, 'description' => 1, 'amount' => 2, 'skipFirstRow' => true];
	/** Its second: product and category joined */
	private const WITH_CATEGORY = ['date' => 0, 'description' => [1, 3], 'amount' => 2, 'skipFirstRow' => true];
	/** The reporter's own 12 Oct 20:45 mapping: joined, and the category in the notes */
	private const CATEGORY_IN_NOTES = ['date' => 0, 'description' => [1, 3], 'notes' => 3, 'amount' => 2, 'skipFirstRow' => true];
	/** Three columns joined into the description */
	private const WITH_CATEGORY_AND_SHOP = ['date' => 0, 'description' => [1, 3, 4], 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP = ['date' => 0, 'description' => 1, 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_SHOP_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'vendor' => 4, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_REFERENCE = ['date' => 0, 'description' => 1, 'reference' => 5, 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_REFERENCE_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'reference' => 5, 'amount' => 2, 'skipFirstRow' => true];
	/** The shop and the bank's reference joined into the reference */
	private const WITH_TWO_COLUMN_REFERENCE = ['date' => 0, 'description' => 1, 'reference' => [4, 5], 'amount' => 2, 'skipFirstRow' => true];
	private const WITH_TWO_COLUMN_REFERENCE_AND_CATEGORY = ['date' => 0, 'description' => [1, 3], 'reference' => [4, 5], 'amount' => 2, 'skipFirstRow' => true];
	/** The reporter's own 11 Oct mapping: the category as the description, the product as the payee and in the notes */
	private const CATEGORY_FIRST = ['date' => 0, 'description' => 3, 'vendor' => 1, 'notes' => 1, 'amount' => 2, 'skipFirstRow' => true];
	/** The same without the notes */
	private const PRODUCT_AS_PAYEE = ['date' => 0, 'description' => 3, 'vendor' => 1, 'amount' => 2, 'skipFirstRow' => true];

	/** The browser run's receipt5.csv */
	private const WOLFCRAFT = ['2026-10-05', 'Wolfcraft Drahtbürste Messing 3tlg', '-9.95', 'Modellbau / Renovation'];
	private const DREMEL = ['2026-10-05', 'Dremel Trennscheibe 426', '-12.40', 'Modellbau / Renovation'];

	private const AMAZON_ORDER = ['2026-10-14', 'AMZN Mktp DE*2X4F5G6H7', '-9.99', 'Online'];
	/** Another order of that day at that price */
	private const AMAZON_NEXT_ORDER = ['2026-10-14', 'AMZN Mktp DE*9Z8Y7X6W5', '-9.99', 'Online'];

	private const STARBUCKS = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Starbucks'];
	private const COSTA = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Costa'];

	private const BROT = ['2026-10-12', 'Brot', '-2.50', 'Lebensmittel', 'Coop'];
	private const EIER = ['2026-10-12', 'Eier', '-2.50', 'Lebensmittel', 'Coop'];

	private const COFFEE = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Café Zentral', 'R-1001'];
	/** A second coffee there, under the bank's next reference */
	private const NEXT_COFFEE = ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Café Zentral', 'R-1002'];

	private ImportService $service;
	private string $fileContent = '';

	/** @var ImportRule[] The user's import rules */
	private array $rules = [];
	/** How often the import read the user's rules */
	private int $rulesLoaded = 0;

	/** @var array<int, array<string, mixed>> Stored transactions, by id */
	private array $ledger = [];
	private int $nextId = 1;
	private static int $ruleIds = 0;

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

	/**
	 * A rule as the rule editor saves it: one condition, one action, and the
	 * rules after it still run.
	 */
	private static function rule(string $field, string $matchType, string $pattern, string $action, string $value, string $behavior = 'always'): ImportRule {
		$rule = new ImportRule();
		$rule->setId(++self::$ruleIds);
		$rule->setUserId('user1');
		$rule->setName($action . ' ' . $value);
		$rule->setPriority(20);
		$rule->setActive(true);
		$rule->setApplyOnImport(true);
		$rule->setSchemaVersion(2);
		$rule->setStopProcessing(false);
		$rule->setCriteria((string)json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
			['type' => 'condition', 'field' => $field, 'matchType' => $matchType, 'pattern' => $pattern, 'negate' => false],
		]]]));
		$rule->setActions((string)json_encode(['version' => 2, 'stopProcessing' => false, 'actions' => [
			['type' => $action, 'value' => $value, 'behavior' => $behavior, 'priority' => 50],
		]]));
		return $rule;
	}

	/** @return int[] The rows of the file the preview flags as duplicates */
	private static function flagged(array $preview): array {
		return array_values(array_column(array_filter($preview['transactions'], fn (array $row) => $row['isDuplicate']), 'rowIndex'));
	}

	/** @return array<string, mixed> A stored row, by id (the first import's rows come first) */
	private function storedRow(int $id): array {
		return $this->ledger[$id];
	}

	/**
	 * Rules that give a row a payee that is not one of its cells, each with
	 * the mapping of the first import and of the second.
	 *
	 * @return array<string, array{0: list<ImportRule>, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<list<string>>, 4: string}>
	 */
	public static function payeeRules(): array {
		$wolfcraft = self::rule('description', 'contains', 'Wolfcraft', 'set_vendor', 'Wolfcraft');
		return [
			// The browser run: the second import added the category
			'description contains a word, category added' => [[$wolfcraft], self::BY_PRODUCT, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft'],
			'description contains a word, his own 20:45 mapping' => [[$wolfcraft], self::BY_PRODUCT, self::CATEGORY_IN_NOTES, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft'],
			'description contains a word, category left out' => [[$wolfcraft], self::WITH_CATEGORY, self::BY_PRODUCT, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft'],
			// His 11 Oct mapping the second time: the product is read as the payee
			'description contains a word, his 11 Oct mapping' => [[$wolfcraft], self::BY_PRODUCT, self::CATEGORY_FIRST, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft'],
			'description contains a word, the product as the payee' => [[$wolfcraft], self::BY_PRODUCT, self::PRODUCT_AS_PAYEE, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft'],
			// His 11 Oct mapping the first time, the product it read as the payee tidied
			'a tidied product payee, his 11 Oct mapping first' => [
				[self::rule('vendor', 'contains', 'Wolfcraft', 'set_vendor', 'Wolfcraft')],
				self::CATEGORY_FIRST, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft',
			],
			// Only the first import's description equals it
			'the whole description' => [
				[self::rule('description', 'equals', 'Wolfcraft Drahtbürste Messing 3tlg', 'set_vendor', 'Wolfcraft')],
				self::BY_PRODUCT, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], 'Wolfcraft',
			],
			// The line's texts hold no word of it
			'a payee none of the line\'s texts hold' => [
				[self::rule('description', 'contains', 'Drahtbürste', 'set_vendor', 'Hornbach')],
				self::BY_PRODUCT, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], 'Hornbach',
			],
			// The first import had no payee column, the second maps the shop
			'only while the payee is empty, shop column added' => [
				[self::rule('description', 'contains', 'Wolfcraft', 'set_vendor', 'Wolfcraft', 'if_empty')],
				self::BY_PRODUCT, self::WITH_SHOP_AND_CATEGORY, [[...self::WOLFCRAFT, 'Hornbach'], [...self::DREMEL, 'Hornbach']], 'Wolfcraft',
			],
			// The rule tidies the payee column the first import read, and the
			// product holds its pattern too: read as a payee, the product gives
			// the stored payee, and this import reads the product. (A pattern
			// only the column holds: testAPayeeARuleTidiedIsComparedAsTheColumnItTidied.)
			'a payee rule the product matches too, payee column left out' => [
				[self::rule('vendor', 'contains', 'WOLFCRAFT', 'set_vendor', 'Wolfcraft')],
				self::WITH_SHOP, self::WITH_CATEGORY, [[...self::WOLFCRAFT, 'WOLFCRAFT-CH 0815'], self::DREMEL], 'Wolfcraft',
			],
			// The notes the first import mapped, which the second leaves out
			'the notes' => [
				[self::rule('notes', 'contains', 'Modellbau', 'set_vendor', 'Baumarkt')],
				self::CATEGORY_IN_NOTES, self::BY_PRODUCT, [self::WOLFCRAFT, self::DREMEL], 'Baumarkt',
			],
		];
	}

	public static function payeeRulesWithAndWithoutRulesOnTheReimport(): array {
		$sets = [];
		foreach (self::payeeRules() as $name => $case) {
			$sets[$name . ', rules applied again'] = [...$case, true];
			$sets[$name . ', rules not applied again'] = [...$case, false];
		}
		return $sets;
	}

	/**
	 * The browser run's bug: a row whose payee one of the user's rules set is
	 * recognised under another mapping, as the line next to it is, whether or
	 * not the import applies the rules again. Its payee is what the rules
	 * give the line as the first import read it.
	 */
	#[DataProvider('payeeRulesWithAndWithoutRulesOnTheReimport')]
	public function testARowWhosePayeeARuleSetIsRecognisedUnderAnotherMapping(array $rules, array $firstMapping, array $secondMapping, array $file, string $payee, bool $applyRulesAgain): void {
		$this->rules = $rules;
		$this->assertSame(2, $this->import($file, $firstMapping)['imported']);
		$this->assertSame($payee, $this->storedRow(1)['vendor']);

		$preview = $this->preview($file, $secondMapping, $applyRulesAgain);
		$again = $this->import($file, $secondMapping, $applyRulesAgain);

		$this->assertSame(2, $preview['duplicates']);
		$this->assertSame([0, 1], self::flagged($preview));
		$this->assertSame(0, $again['imported']);
		$this->assertSame(2, $again['skipped']);
		$this->assertCount(2, $this->ledger);
	}

	/** The rules are read once for a preview and once for an import, however many rows need them */
	public function testTheRulesAreReadOncePerImport(): void {
		$this->rules = [self::rule('notes', 'contains', 'Modellbau', 'set_vendor', 'Baumarkt')];
		$this->import([self::WOLFCRAFT, self::DREMEL], self::CATEGORY_IN_NOTES);
		$this->assertSame(['Baumarkt', 'Baumarkt'], array_column($this->ledger, 'vendor'));

		foreach ([true, false] as $applyRulesAgain) {
			$this->rulesLoaded = 0;
			$this->assertSame(2, $this->preview([self::WOLFCRAFT, self::DREMEL], self::BY_PRODUCT, $applyRulesAgain)['duplicates']);
			$this->assertSame(1, $this->rulesLoaded);
			$this->rulesLoaded = 0;
			$this->assertSame(2, $this->import([self::WOLFCRAFT, self::DREMEL], self::BY_PRODUCT, $applyRulesAgain)['skipped']);
			$this->assertSame(1, $this->rulesLoaded);
		}
	}

	public static function payeeMappings(): array {
		$named = self::rule('vendor', 'contains', 'Starbucks', 'set_vendor', 'Starbucks Coffee');
		return [
			'category added' => [[], self::WITH_SHOP, self::WITH_SHOP_AND_CATEGORY],
			'category left out' => [[], self::WITH_SHOP_AND_CATEGORY, self::WITH_SHOP],
			'a rule naming the payee, category added' => [[$named], self::WITH_SHOP, self::WITH_SHOP_AND_CATEGORY],
			'a rule naming the payee, category left out' => [[$named], self::WITH_SHOP_AND_CATEGORY, self::WITH_SHOP],
		];
	}

	/**
	 * Another payee's coffee on that day at that price, under mappings that
	 * both read the payee: it is not the stored coffee, whatever the user's
	 * rules call the stored coffee's payee.
	 */
	#[DataProvider('payeeMappings')]
	public function testACoffeeFromAnotherPayeeIsNotTheStoredOne(array $rules, array $firstMapping, array $secondMapping): void {
		$this->rules = $rules;
		$this->import([self::STARBUCKS], $firstMapping);

		$preview = $this->preview([self::COSTA], $secondMapping);
		$again = $this->import([self::COSTA], $secondMapping);

		$this->assertSame(0, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	public static function payeeMappingsInEitherOrder(): array {
		$sets = [];
		foreach (self::payeeMappings() as $name => $case) {
			$sets[$name . ', the other payee first'] = [...$case, true];
			$sets[$name . ', the stored payee first'] = [...$case, false];
		}
		return $sets;
	}

	/** With both coffees in the file, only the stored payee's is recognised */
	#[DataProvider('payeeMappingsInEitherOrder')]
	public function testOnlyTheStoredPayeesCoffeeIsRecognised(array $rules, array $firstMapping, array $secondMapping, bool $costaFirst): void {
		$this->rules = $rules;
		$this->import([self::STARBUCKS], $firstMapping);
		$rows = $costaFirst ? [self::COSTA, self::STARBUCKS] : [self::STARBUCKS, self::COSTA];

		$preview = $this->preview($rows, $secondMapping);
		$again = $this->import($rows, $secondMapping);

		$this->assertSame([$costaFirst ? 1 : 0], self::flagged($preview));
		$this->assertSame(1, $again['imported']);
		$this->assertSame(1, $again['skipped']);
		$this->assertSame('Costa', $this->storedRow(2)['vendor']);
	}

	/** Another product of the brand on that day at that price is not taken for the stored one because the rule gives both the brand as payee */
	public function testAnotherProductOfTheBrandStillImports(): void {
		$this->rules = [self::rule('description', 'contains', 'Wolfcraft', 'set_vendor', 'Wolfcraft')];
		$this->import([self::WOLFCRAFT], self::BY_PRODUCT);
		$other = [['2026-10-05', 'Wolfcraft Stahldrahtbürsten-3tlgSet 2.35', '-9.95', 'Modellbau / Renovation']];

		$this->assertSame(0, $this->preview($other, self::WITH_CATEGORY)['duplicates']);
		$this->assertSame(1, $this->import($other, self::WITH_CATEGORY)['imported']);
	}

	public static function tidiedPayeeColumns(): array {
		return [
			'the column read again, category added' => [self::WITH_SHOP, self::WITH_SHOP_AND_CATEGORY, [0, 1]],
			'the column read again, category left out' => [self::WITH_SHOP_AND_CATEGORY, self::WITH_SHOP, [0, 1]],
			// The stored payee is in nothing this import reads, and the line's
			// description holds more than the stored one
			'the column left out, category added' => [self::WITH_SHOP, self::WITH_CATEGORY, []],
		];
	}

	/**
	 * A payee column the first import read and a rule tidied is compared as
	 * that column, so the rule changes nothing: the tidied line is recognised
	 * exactly when the line next to it, whose payee no rule touched, is.
	 */
	#[DataProvider('tidiedPayeeColumns')]
	public function testAPayeeARuleTidiedIsComparedAsTheColumnItTidied(array $firstMapping, array $secondMapping, array $recognised): void {
		$this->rules = [self::rule('vendor', 'contains', 'WOLFCRAFT-CH', 'set_vendor', 'Wolfcraft')];
		$file = [[...self::WOLFCRAFT, 'WOLFCRAFT-CH 0815'], [...self::DREMEL, 'DREMEL-CH 77']];
		$this->import($file, $firstMapping);
		$this->assertSame(['Wolfcraft', 'DREMEL-CH 77'], array_column($this->ledger, 'vendor'));

		$this->assertSame($recognised, self::flagged($this->preview($file, $secondMapping)));
		$this->assertSame(2 - count($recognised), $this->import($file, $secondMapping)['imported']);
	}

	/**
	 * Rules that changed what the first import read into the description or
	 * the reference, with the description and reference they left on the
	 * first line's row.
	 *
	 * @return array<string, array{0: list<ImportRule>, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<list<string>>, 4: array{0: string, 1: ?string}}>
	 */
	public static function textRules(): array {
		$rewrite = self::rule('description', 'contains', 'Drahtbürste', 'replace_text', '');
		$rewrite->setActions((string)json_encode(['version' => 2, 'actions' => [
			['type' => 'replace_text', 'field' => 'description', 'find' => 'Drahtbürste', 'replace' => 'Bürste'],
		]]));
		$tidyAndLabel = self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon');
		$tidyAndLabel->setActions((string)json_encode(['version' => 2, 'actions' => [
			['type' => 'set_description', 'value' => 'Amazon', 'behavior' => 'always'],
			['type' => 'set_reference', 'value' => 'Online', 'behavior' => 'always'],
		]]));
		return [
			// A tidy-up of the bank's text, as many users write
			'a description tidied up' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::BY_PRODUCT, self::WITH_CATEGORY, [self::AMAZON_ORDER], ['Amazon', null],
			],
			// The id hashes the description trimmed
			'a padded description tidied up' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::BY_PRODUCT, self::WITH_CATEGORY, [['2026-10-14', '  AMZN Mktp DE*2X4F5G6H7 ', '-9.99', 'Online']], ['Amazon', null],
			],
			'a description of two columns tidied up' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::WITH_CATEGORY, self::BY_PRODUCT, [self::AMAZON_ORDER], ['Amazon', null],
			],
			'a description of three columns tidied up' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::WITH_CATEGORY_AND_SHOP, self::WITH_CATEGORY, [[...self::AMAZON_ORDER, 'Amazon EU']], ['Amazon', null],
			],
			'a description named after the payee' => [
				[self::rule('vendor', 'equals', 'Coop', 'set_description', 'Einkauf Coop')],
				self::WITH_SHOP, self::WITH_SHOP_AND_CATEGORY, [self::BROT], ['Einkauf Coop', null],
			],
			'a description rewritten in place' => [
				[$rewrite], self::BY_PRODUCT, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], ['Wolfcraft Bürste Messing 3tlg', null],
			],
			'a reference set' => [
				[self::rule('description', 'contains', 'Wolfcraft', 'set_reference', 'Baumarkt-Beleg')],
				self::BY_PRODUCT, self::WITH_CATEGORY, [self::WOLFCRAFT, self::DREMEL], ['Wolfcraft Drahtbürste Messing 3tlg', 'Baumarkt-Beleg'],
			],
			'the bank\'s reference replaced' => [
				[self::rule('description', 'equals', 'Kaffee', 'set_reference', 'Kaffeekasse')],
				self::WITH_REFERENCE, self::WITH_REFERENCE_AND_CATEGORY, [self::COFFEE], ['Kaffee', 'Kaffeekasse'],
			],
			// The id hashes the reference as the cell reads, padding and all (#340)
			'the bank\'s padded reference replaced' => [
				[self::rule('description', 'equals', 'Kaffee', 'set_reference', 'Kaffeekasse')],
				self::WITH_REFERENCE, self::WITH_REFERENCE_AND_CATEGORY,
				[['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'Café Zentral', ' R-1001 ']], ['Kaffee', 'Kaffeekasse'],
			],
			// A reference no rule touched is the one the id hashes, as its cell reads
			'a description tidied up, the bank\'s reference kept' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::WITH_REFERENCE, self::WITH_REFERENCE_AND_CATEGORY, [[...self::AMAZON_ORDER, 'Amazon EU', 'R-1001']], ['Amazon', 'R-1001'],
			],
			'a description tidied up, the bank\'s padded reference kept' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::WITH_REFERENCE, self::WITH_REFERENCE_AND_CATEGORY, [[...self::AMAZON_ORDER, 'Amazon EU', ' R-1001 ']], ['Amazon', 'R-1001'],
			],
			'a description tidied up, a reference of two columns kept' => [
				[self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')],
				self::WITH_TWO_COLUMN_REFERENCE, self::WITH_TWO_COLUMN_REFERENCE_AND_CATEGORY,
				[[...self::AMAZON_ORDER, 'Amazon EU', 'R-1001']], ['Amazon', 'Amazon EU, R-1001'],
			],
			// The reference the rule set reads as one of the line's cells, but the id hashed none
			'a description tidied up, a reference set to what a cell holds' => [
				[$tidyAndLabel], self::BY_PRODUCT, self::WITH_CATEGORY, [self::AMAZON_ORDER], ['Amazon', 'Online'],
			],
		];
	}

	/**
	 * A row whose description or reference a rule changed is recognised under
	 * another mapping: its import id still holds the texts the first import
	 * read from the line, before any rule ran.
	 */
	#[DataProvider('textRules')]
	public function testARowWhoseTextARuleChangedIsRecognisedUnderAnotherMapping(array $rules, array $firstMapping, array $secondMapping, array $file, array $storedTexts): void {
		$this->rules = $rules;
		$this->assertSame(count($file), $this->import($file, $firstMapping)['imported']);
		$this->assertSame($storedTexts, [$this->storedRow(1)['description'], $this->storedRow(1)['reference']]);

		$preview = $this->preview($file, $secondMapping);
		$again = $this->import($file, $secondMapping);

		$this->assertSame(count($file), $preview['duplicates']);
		$this->assertSame(0, $again['imported']);
		$this->assertCount(count($file), $this->ledger);
	}

	/** A description edited by hand after the import, the same way */
	public function testARowWhoseDescriptionWasEditedByHandIsRecognised(): void {
		$this->import([self::WOLFCRAFT, self::DREMEL], self::BY_PRODUCT);
		$this->ledger[1]['description'] = 'Drahtbürste für die Garage';

		$this->assertSame(2, $this->preview([self::WOLFCRAFT, self::DREMEL], self::WITH_CATEGORY)['duplicates']);
		$this->assertSame(0, $this->import([self::WOLFCRAFT, self::DREMEL], self::WITH_CATEGORY)['imported']);
	}

	/** Two identical orders a rule tidied: the second's id carries _occ2, and both are recognised */
	public function testTwoIdenticalLinesARuleChangedAreBothRecognised(): void {
		$this->rules = [self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')];
		$this->import([self::AMAZON_ORDER, self::AMAZON_ORDER], self::BY_PRODUCT);
		$this->assertStringEndsWith('_occ2', (string)$this->storedRow(2)['importId']);

		$this->assertSame(2, $this->preview([self::AMAZON_ORDER, self::AMAZON_ORDER], self::WITH_CATEGORY)['duplicates']);
		$this->assertSame(0, $this->import([self::AMAZON_ORDER, self::AMAZON_ORDER], self::WITH_CATEGORY)['imported']);
	}

	/**
	 * A genuinely new line that the same rule rewrites the same way, on that
	 * day at that price, in a later file under the same mapping or another.
	 *
	 * @return array<string, array{0: list<ImportRule>, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>, 4: list<string>}>
	 */
	public static function newLinesARuleMakesAlike(): array {
		$amazon = self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon');
		$coop = self::rule('vendor', 'equals', 'Coop', 'set_description', 'Einkauf Coop');
		$kaffeekasse = self::rule('description', 'equals', 'Kaffee', 'set_reference', 'Kaffeekasse');
		$sets = [];
		foreach (['the same mapping' => false, 'another mapping' => true] as $how => $another) {
			$sets[$how . ', another order the rule tidies'] = [[$amazon], self::BY_PRODUCT, self::AMAZON_ORDER, $another ? self::WITH_CATEGORY : self::BY_PRODUCT, self::AMAZON_NEXT_ORDER];
			$sets[$how . ', another product from the shop the rule names'] = [[$coop], self::WITH_SHOP, self::BROT, $another ? self::WITH_SHOP_AND_CATEGORY : self::WITH_SHOP, self::EIER];
			$sets[$how . ', another coffee whose reference the rule replaced'] = [[$kaffeekasse], self::WITH_REFERENCE, self::COFFEE, $another ? self::WITH_REFERENCE_AND_CATEGORY : self::WITH_REFERENCE, self::NEXT_COFFEE];
		}
		// The stored row's description is only the rule's: the line it came from had none
		$sets['the same mapping, a line that had no description'] = [
			[self::rule('vendor', 'equals', 'Coop', 'set_description', 'Einkauf', 'if_empty')],
			self::WITH_SHOP, ['2026-10-12', '', '-2.50', 'Lebensmittel', 'Coop'], self::WITH_SHOP, self::EIER,
		];
		// A payee the rule filled in while it was empty, and this import reads
		// another payee where the stored row's description has none
		$sets['another mapping, another payee\'s coffee where the rule filled one in'] = [
			[self::rule('description', 'contains', 'Kaffee', 'set_vendor', 'Starbucks', 'if_empty')],
			self::WITH_CATEGORY, self::STARBUCKS, self::WITH_SHOP, self::COSTA,
		];
		// The rule makes every branch of the chain one payee, and this import
		// leaves the payee column out
		$sets['another mapping, a coffee at another branch the rule names alike'] = [
			[self::rule('vendor', 'contains', 'STARBUCKS', 'set_vendor', 'Starbucks')],
			self::WITH_SHOP, ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'STARBUCKS 123'],
			self::WITH_CATEGORY, ['2026-10-14', 'Kaffee', '-4.20', 'Lebensmittel', 'STARBUCKS 456'],
		];
		return $sets;
	}

	/**
	 * Such a line imports, as it does without the rule: the rule's text says
	 * nothing about which line it came from, so a stored row is never taken
	 * for a line on the strength of it. Under the same mapping this is the
	 * next statement or receipt, and nothing in it was imported before.
	 */
	#[DataProvider('newLinesARuleMakesAlike')]
	public function testANewLineARuleMakesAlikeStillImports(array $rules, array $firstMapping, array $old, array $secondMapping, array $new): void {
		$this->rules = $rules;
		$this->import([$old], $firstMapping);
		$this->assertCount(1, $this->ledger);

		$preview = $this->preview([$new], $secondMapping);
		$again = $this->import([$new], $secondMapping);

		$this->assertSame(0, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	public static function amazonOrders(): array {
		return ['the new order first' => [true], 'the stored order first' => [false]];
	}

	/** With both orders in the file under another mapping, the stored order's line is the one recognised */
	#[DataProvider('amazonOrders')]
	public function testTheStoredOrderIsTheOneRecognised(bool $newFirst): void {
		$this->rules = [self::rule('description', 'contains', 'AMZN', 'set_description', 'Amazon')];
		$this->import([self::AMAZON_ORDER], self::BY_PRODUCT);
		$rows = $newFirst ? [self::AMAZON_NEXT_ORDER, self::AMAZON_ORDER] : [self::AMAZON_ORDER, self::AMAZON_NEXT_ORDER];

		$preview = $this->preview($rows, self::WITH_CATEGORY);
		$again = $this->import($rows, self::WITH_CATEGORY);

		$this->assertSame([$newFirst ? 1 : 0], self::flagged($preview));
		$this->assertSame(1, $again['imported']);
		$this->assertSame(
			'hash_' . md5('2026-10-14' . '9.99' . 'AMZN Mktp DE*9Z8Y7X6W5, Online'),
			$this->storedRow(2)['importId']
		);
	}

	/**
	 * Stored rows that another line on that day at that price could have
	 * given just as well, with the payee the rules give.
	 *
	 * @return array<string, array{0: list<ImportRule>, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>, 4: list<string>}>
	 */
	public static function storedRowsThatCannotTellLinesApart(): array {
		return [
			// Costa's line, imported without its shop, gets Starbucks from the rule
			'a payee a rule fills in while it is empty' => [
				[self::rule('description', 'contains', 'Kaffee', 'set_vendor', 'Starbucks', 'if_empty')],
				self::WITH_SHOP, self::STARBUCKS, self::WITH_SHOP_AND_CATEGORY, self::COSTA,
			],
			// Every product of the brand, imported his 11 Oct way, gets the stored row's texts
			'the category as the description, a payee the rules give the whole brand' => [
				[self::rule('vendor', 'contains', 'Wolfcraft', 'set_vendor', 'Wolfcraft')],
				self::CATEGORY_FIRST, self::WOLFCRAFT, self::WITH_CATEGORY, ['2026-10-05', 'Wolfcraft Stahlbürste', '-9.95', 'Modellbau / Renovation'],
			],
		];
	}

	/**
	 * The limit of recognising a row through the user's rules, kept on
	 * purpose: a stored row that another line could have given just as well
	 * says no more than that. Such a line is taken for it once this mapping
	 * reads the texts the two share, as a line is for a row stored with no
	 * payee, or with only its category as the description, without any rule.
	 * Under the mapping that stored the row the two lines carry one import id
	 * anyway.
	 */
	#[DataProvider('storedRowsThatCannotTellLinesApart')]
	public function testALineTheStoredRowCannotTellFromItsOwnIsTakenForIt(array $rules, array $firstMapping, array $old, array $secondMapping, array $new): void {
		$this->rules = $rules;
		$this->import([$old], $firstMapping);

		$this->assertSame(1, $this->preview([$new], $secondMapping)['duplicates']);
	}
}
