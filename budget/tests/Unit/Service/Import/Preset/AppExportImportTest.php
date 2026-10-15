<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Preset;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\ImportAccountLinkService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionTagService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Imports the hand-written export fixtures under tests/fixtures/import
 * through the real parser, normalizer and presets, against an in-memory
 * ledger, and imports each one a second time to prove the import IDs are
 * stable: the second run must insert nothing (#338).
 */
class AppExportImportTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/import/';

	private ImportService $service;
	private string $fileContent = '';

	/** @var array<int, Account> */
	private array $accounts = [];
	/** @var array<int, array<string, mixed>> Created transactions, by id */
	private array $ledger = [];
	/** @var array<int, Category> */
	private array $categories = [];
	/** @var array<int, array{0: int, 1: int}> */
	private array $links = [];
	/** @var array<int, int[]> Transaction id => tag ids */
	private array $transactionTags = [];
	/** @var array<int, string> Tag id => name */
	private array $tags = [];

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
				foreach ($this->ledger as $row) {
					if ($row['accountId'] === $accountId && $row['importId'] === $importId) {
						throw new \Exception('Transaction with this import ID already exists');
					}
				}
				$id = count($this->ledger) + 1;
				$this->ledger[$id] = compact('accountId', 'date', 'description', 'amount', 'type', 'categoryId', 'vendor', 'reference', 'notes', 'importId');
				$tx = new Transaction();
				$tx->setId($id);
				return $tx;
			}
		);
		$transactionService->method('existsByImportId')->willReturnCallback(fn (int $accountId, string $importId) => $this->hasImportId($accountId, $importId));
		$transactionService->method('findPotentialMatches')->willReturn([]);
		$transactionService->method('linkTransactions')->willReturnCallback(function (int $a, int $b) {
			foreach ($this->links as [$x, $y]) {
				if (in_array($a, [$x, $y], true) || in_array($b, [$x, $y], true)) {
					throw new \Exception('Transaction is already linked to another transaction');
				}
			}
			$this->links[] = [$a, $b];
			return [];
		});

		$duplicateDetector = $this->createMock(DuplicateDetector::class);
		$duplicateDetector->method('isDuplicateByImportId')->willReturnCallback(fn (int $accountId, string $importId) => $this->hasImportId($accountId, $importId));
		$duplicateDetector->method('isDuplicate')->willReturnCallback(fn (int $accountId, array $tx, ?string $importId = null) => $importId !== null && $this->hasImportId($accountId, $importId));

		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findByName')->willReturnCallback(function (string $userId, string $name) {
			foreach ($this->accounts as $account) {
				if ($account->getName() === $name) {
					return $account;
				}
			}
			return null;
		});
		$accountMapper->method('find')->willReturnCallback(fn (int $id) => $this->accounts[$id] ?? throw new \Exception('no account ' . $id));

		$accountService = $this->createMock(AccountService::class);
		$accountService->method('create')->willReturnCallback(function (string $userId, string $name, string $type, float $balance, string $currency) {
			$account = new Account();
			$account->setId(count($this->accounts) + 1);
			$account->setName($name);
			$account->setType($type);
			$account->setCurrency($currency);
			$this->accounts[$account->getId()] = $account;
			return $account;
		});

		// The real lookup rules over an in-memory category table
		$categoryMapper = $this->createMock(CategoryMapper::class);
		$categoryMapper->method('findByName')->willReturnCallback(function (string $userId, string $name, string $type, ?int $parentId = null) {
			foreach ($this->categories as $category) {
				if ($category->getName() === $name && $category->getType() === $type && $category->getParentId() === $parentId) {
					return $category;
				}
			}
			return null;
		});
		$categoryMapper->method('findAll')->willReturnCallback(fn () => array_values($this->categories));
		$categoryMapper->method('insert')->willReturnCallback(function (Category $category) {
			$category->setId(count($this->categories) + 1);
			$this->categories[$category->getId()] = $category;
			return $category;
		});

		$tagSetService = $this->createMock(TagSetService::class);
		$tagSetService->method('findByCategory')->willReturn([]);
		$tagSetService->method('create')->willReturnCallback(function (string $userId, int $categoryId, string $name) {
			$set = new TagSet();
			$set->setId(1000 + $categoryId);
			$set->setName($name);
			return $set;
		});
		$tagSetService->method('getTagSetWithTags')->willReturnCallback(function () {
			$set = new TagSet();
			$set->setTags([]);
			return $set;
		});
		$tagSetService->method('createTag')->willReturnCallback(function (int $tagSetId, string $userId, string $name) {
			$id = count($this->tags) + 1;
			$this->tags[$id] = $name;
			$tag = new \OCA\Budget\Db\Tag();
			$tag->setId($id);
			$tag->setName($name);
			return $tag;
		});
		$transactionTagService = $this->createMock(TransactionTagService::class);
		$transactionTagService->method('setTransactionTags')->willReturnCallback(function (int $txId, string $userId, array $tagIds) {
			$this->transactionTags[$txId] = $tagIds;
			return [];
		});
		$transactionTagService->method('getTransactionTags')->willReturn([]);

		$ruleApplicator = $this->createMock(ImportRuleApplicator::class);
		$ruleApplicator->method('applyRules')->willReturnArgument(1);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')->willReturn(null);

		$categoryService = new CategoryService(
			$categoryMapper,
			$this->createMock(TransactionMapper::class),
			$this->createMock(BudgetSnapshotMapper::class),
			$this->createMock(TagSetMapper::class),
			$this->createMock(TagMapper::class),
			$this->createMock(TransactionTagMapper::class),
			$l,
			$this->createMock(BudgetCarryoverService::class),
			$this->createMock(RecurringBudgetService::class)
		);

		// The rows a preset import compares a file against (R5-4)
		$transactionMapper = $this->createMock(TransactionMapper::class);
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

		$this->service = new ImportService(
			$appData,
			$transactionService,
			$transactionMapper,
			$accountMapper,
			$accountService,
			$this->createMock(FileValidator::class),
			new ParserFactory(),
			new TransactionNormalizer(),
			$duplicateDetector,
			$ruleApplicator,
			new PresetRegistry(),
			$categoryService,
			$tagSetService,
			$transactionTagService,
			$this->createMock(ImportAccountLinkService::class),
			$this->createMock(BillService::class),
			$settingService,
			$l,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function hasImportId(int $accountId, string $importId): bool {
		foreach ($this->ledger as $row) {
			if ($row['accountId'] === $accountId && $row['importId'] === $importId) {
				return true;
			}
		}
		return false;
	}

	/** A category the user already had before the import. */
	private function seedCategory(string $name, string $type, ?int $parentId = null): int {
		$category = new Category();
		$category->setId(count($this->categories) + 1);
		$category->setUserId('user1');
		$category->setName($name);
		$category->setType($type);
		$category->setParentId($parentId);
		$category->setCreatedAt('2026-01-01 00:00:00');
		$this->categories[$category->getId()] = $category;
		return $category->getId();
	}

	private function import(string $fixture, string $presetId): array {
		$this->fileContent = (string)file_get_contents(self::FIXTURES . $fixture);
		return $this->service->processImport('user1', 'import_user1_0123456789abcdef0123456789abcdef.csv', [], null, null, true, true, ',', $presetId);
	}

	private function preview(string $fixture, string $presetId): array {
		$this->fileContent = (string)file_get_contents(self::FIXTURES . $fixture);
		return $this->service->previewImport('user1', 'import_user1_0123456789abcdef0123456789abcdef.csv', [], null, null, true, ',', $presetId);
	}

	private function accountId(string $name): int {
		foreach ($this->accounts as $account) {
			if ($account->getName() === $name) {
				return $account->getId();
			}
		}
		$this->fail('No account named ' . $name);
	}

	/** Signed balance of an account from the in-memory ledger. */
	private function balance(string $name): float {
		$id = $this->accountId($name);
		$sum = 0.0;
		foreach ($this->ledger as $row) {
			if ($row['accountId'] === $id) {
				$sum += $row['type'] === 'credit' ? $row['amount'] : -$row['amount'];
			}
		}
		return round($sum, 2);
	}

	/** @return array<string, mixed> */
	private function rowWhere(string $description, ?string $account = null): array {
		foreach ($this->ledger as $id => $row) {
			if ($row['description'] === $description && ($account === null || $row['accountId'] === $this->accountId($account))) {
				return $row + ['id' => $id];
			}
		}
		$this->fail('No imported row described ' . $description);
	}

	private function categoryPath(?int $id): ?string {
		if ($id === null) {
			return null;
		}
		$category = $this->categories[$id];
		$parent = $category->getParentId();
		return $parent !== null ? $this->categoryPath($parent) . ' / ' . $category->getName() : $category->getName();
	}

	private function assertSecondImportAddsNothing(string $fixture, string $presetId): void {
		$count = count($this->ledger);
		$again = $this->import($fixture, $presetId);
		$this->assertSame(0, $again['imported'], 'Importing the same export twice must insert nothing the second time');
		$this->assertCount($count, $this->ledger);
		$this->assertSame([], $again['errors']);
	}

	// ===== Firefly III =====

	public function testFireflyImportRoutesEachJournalToTheOwnAccountSide(): void {
		$result = $this->import('firefly-iii-export.csv', 'firefly-iii');

		$this->assertSame([], $result['errors']);
		// 8 journals, the transfer written into both accounts
		$this->assertSame(9, $result['imported']);
		$this->assertSame(2, $result['accountsCreated']);
		$this->assertSame('EUR', $this->accounts[$this->accountId('Main account')]->getCurrency());
		$this->assertSame('savings', $this->accounts[$this->accountId('Savings account')]->getType());

		$this->assertEqualsWithDelta(3429.40, $this->balance('Main account'), 0.001);
		$this->assertEqualsWithDelta(501.25, $this->balance('Savings account'), 0.001);

		$shop = $this->rowWhere('Weekly shop');
		$this->assertSame('debit', $shop['type']);
		$this->assertSame(42.10, $shop['amount']);
		$this->assertSame('2024-01-05', $shop['date']);
		$this->assertSame('Supermarket', $shop['vendor']);
		$this->assertSame('Groceries', $this->categoryPath($shop['categoryId']));
		$this->assertStringContainsString('Bought extra', (string)$shop['notes']);
		$this->assertStringContainsString('for the party', (string)$shop['notes'], 'A note spanning two lines stays with its row');
		$this->assertSame(['food', 'weekly'], array_map(fn ($id) => $this->tags[$id], $this->transactionTags[$shop['id']]));

		$salary = $this->rowWhere('Salary January');
		$this->assertSame('credit', $salary['type']);
		$this->assertSame('Employer Ltd', $salary['vendor']);

		$opening = $this->rowWhere('Initial balance for "Main account"');
		$this->assertSame('credit', $opening['type']);
		$this->assertNull($opening['vendor'] ?? null, 'Firefly\'s bookkeeping account is not a payee');

		$voucher = $this->rowWhere('-5% coffee voucher');
		$this->assertSame(3.0, $voucher['amount']);
		$this->assertStringContainsString('Foreign amount: 3.30 USD', (string)$voucher['notes']);

		// Split parts arrive as their own rows
		$this->assertSame(20.0, $this->rowWhere('Paint')['amount']);
		$this->assertSame(5.5, $this->rowWhere('Brushes')['amount']);
	}

	public function testFireflyTransferIsTwoLinkedSides(): void {
		$result = $this->import('firefly-iii-export.csv', 'firefly-iii');

		$out = $this->rowWhere('Savings top-up', 'Main account');
		$in = $this->rowWhere('Savings top-up', 'Savings account');
		$this->assertSame('debit', $out['type']);
		$this->assertSame('credit', $in['type']);
		$this->assertNull($out['categoryId']);
		$this->assertSame([[$out['id'], $in['id']]], $this->links);
		$this->assertSame(1, $result['transfersLinked']);
	}

	public function testFireflyReimportAddsNothing(): void {
		$this->import('firefly-iii-export.csv', 'firefly-iii');
		$this->assertSecondImportAddsNothing('firefly-iii-export.csv', 'firefly-iii');
	}

	/**
	 * 100 EUR sent to a USD account arriving as 108 USD: the receiving side
	 * used to take the EUR figures, crediting the USD account 100 and, as
	 * its first row, creating it in EUR.
	 */
	public function testFireflyTransferAcrossCurrenciesUsesEachSidesOwnFigures(): void {
		$result = $this->import('firefly-iii-export-cross-currency.csv', 'firefly-iii');

		$this->assertSame([], $result['errors']);
		$this->assertSame('USD', $this->accounts[$this->accountId('Dollar account')]->getCurrency());
		$this->assertSame('EUR', $this->accounts[$this->accountId('Euro account')]->getCurrency());

		$out = $this->rowWhere('Move to dollars', 'Euro account');
		$in = $this->rowWhere('Move to dollars', 'Dollar account');
		$this->assertSame(100.0, $out['amount']);
		$this->assertSame('debit', $out['type']);
		$this->assertSame(108.0, $in['amount']);
		$this->assertSame('credit', $in['type']);
		$this->assertStringContainsString('Foreign amount: 108.00 USD', (string)$out['notes']);
		$this->assertStringContainsString('Foreign amount: 100.00 EUR', (string)$in['notes']);

		$this->assertEqualsWithDelta(900.0, $this->balance('Euro account'), 0.001);
		$this->assertEqualsWithDelta(108.0, $this->balance('Dollar account'), 0.001);

		// One journal: the two sides are linked though their amounts differ
		$this->assertSame([[$out['id'], $in['id']]], $this->links);
		$this->assertSame(1, $result['transfersLinked']);
	}

	public function testFireflyCrossCurrencyReimportAddsNothing(): void {
		$this->import('firefly-iii-export-cross-currency.csv', 'firefly-iii');
		$this->assertSecondImportAddsNothing('firefly-iii-export-cross-currency.csv', 'firefly-iii');
	}

	public function testFireflyReadsColumnsByNameWhateverTheirOrder(): void {
		$result = $this->import('firefly-iii-export-v6.0.csv', 'firefly-iii');

		$this->assertSame(2, $result['imported']);
		$this->assertSame('GBP', $this->accounts[$this->accountId('Current account')]->getCurrency());
		$this->assertEqualsWithDelta(1181.60, $this->balance('Current account'), 0.001);
		$this->assertSame('2023-06-02', $this->rowWhere('Bus pass')['date']);
	}

	// ===== YNAB =====

	public function testYnabImportUsesOutflowAndInflowAndCategoryGroups(): void {
		$result = $this->import('ynab-register.csv', 'ynab');

		$this->assertSame([], $result['errors']);
		$this->assertSame(10, $result['imported']);
		$this->assertEqualsWithDelta(1850.0 - 23.45 - 1200 - 300 - 12 - 4.5 + 2400 + 5, $this->balance('Checking'), 0.001);
		$this->assertEqualsWithDelta(4300.0, $this->balance('Savings'), 0.001);

		$rent = $this->rowWhere('Landlord');
		$this->assertSame('2024-01-15', $rent['date']);
		$this->assertSame(1200.0, $rent['amount']);
		$this->assertSame('debit', $rent['type']);
		$this->assertSame('Monthly Bills / Rent', $this->categoryPath($rent['categoryId']));
		$this->assertSame('January rent', $rent['notes']);

		// Ready to Assign is YNAB's income holding area, not a category
		$this->assertNull($this->rowWhere('Employer Inc')['categoryId']);
		$this->assertNull($this->rowWhere('Starting Balance', 'Checking')['categoryId']);

		// A refund reuses the spending category instead of creating an income twin
		$refund = $this->rowWhere('Corner Grocer', null);
		$groceries = array_filter($this->categories, fn ($c) => $c->getName() === 'Groceries');
		$this->assertCount(1, $groceries);
		$this->assertSame('Everyday Expenses / Groceries', $this->categoryPath($refund['categoryId']));
	}

	public function testYnabTransferSidesAreLinked(): void {
		$result = $this->import('ynab-register.csv', 'ynab');

		$out = $this->rowWhere('Transfer : Savings');
		$in = $this->rowWhere('Transfer : Checking');
		$this->assertSame([[$out['id'], $in['id']]], $this->links);
		$this->assertSame(1, $result['transfersLinked']);
	}

	public function testYnabReimportAddsNothing(): void {
		$this->import('ynab-register.csv', 'ynab');
		$this->assertSecondImportAddsNothing('ynab-register.csv', 'ynab');
	}

	public function testYnab4RegisterWithDayFirstDatesAndPounds(): void {
		$result = $this->import('ynab4-register.csv', 'ynab');

		$this->assertSame([], $result['errors']);
		$this->assertSame(5, $result['imported']);
		$garage = $this->rowWhere('Village Garage');
		$this->assertSame('2015-02-14', $garage['date']);
		$this->assertSame(54.85, $garage['amount']);
		$this->assertSame('104', $garage['reference']);
		$this->assertSame('Transport / Car Maintenance', $this->categoryPath($garage['categoryId']));
		$this->assertSame('2015-02-03', $this->rowWhere('Starting Balance')['date']);
		$this->assertNull($this->rowWhere('Starting Balance')['categoryId']);
		$this->assertSame('credit_card', $this->accounts[$this->accountId('Credit Card')]->getType());
		$this->assertEqualsWithDelta(0.0, $this->balance('Credit Card'), 0.001);
		$this->assertCount(1, $this->links);
	}

	public function testYnabTabSeparatedExportWithCommaDecimals(): void {
		$result = $this->import('ynab-register-tab.csv', 'ynab');

		$this->assertSame([], $result['errors']);
		$this->assertSame(2, $result['imported']);
		$this->assertSame(3.2, $this->rowWhere('Bäckerei Müller')['amount']);
		$this->assertSame('2024-03-02', $this->rowWhere('Bäckerei Müller')['date']);
		$this->assertSame(2345.67, $this->rowWhere('Arbeitgeber GmbH')['amount']);
	}

	// ===== Actual Budget =====

	public function testActualImportDropsSplitTotalsAndKeepsTheParts(): void {
		$result = $this->import('actual-budget-export.csv', 'actual-budget');

		$this->assertSame([], $result['errors']);
		$this->assertSame(8, $result['imported']);
		$this->assertSame(1, $result['skipped'], 'The split total row is skipped');
		$this->assertEqualsWithDelta(2150.75 - 36.20 - 19.40 - 8.50 - 250 - 4.10 + 3100, $this->balance('Everyday Checking'), 0.001);
		$this->assertEqualsWithDelta(250.0, $this->balance('Rainy Day Savings'), 0.001);
		$this->assertSame('savings', $this->accounts[$this->accountId('Rainy Day Savings')]->getType());

		$medicine = $this->rowWhere('Corner Pharmacy');
		$this->assertSame('Health / Medicine', $this->categoryPath($medicine['categoryId']));
		$this->assertSame(19.40, $medicine['amount']);

		$cafe = $this->rowWhere('-Minus Cafe');
		$this->assertSame('=SUM(A1) is not a formula', $cafe['notes'], 'The export\'s formula guard is removed');
		$this->assertSame('debit', $cafe['type']);
	}

	public function testActualTransferWithBlankPayeeIsLinked(): void {
		$result = $this->import('actual-budget-export.csv', 'actual-budget');

		$this->assertCount(1, $this->links);
		[$a, $b] = $this->links[0];
		$this->assertSame(250.0, $this->ledger[$a]['amount']);
		$this->assertNotSame($this->ledger[$a]['accountId'], $this->ledger[$b]['accountId']);
		$this->assertSame(1, $result['transfersLinked']);
	}

	public function testActualReimportAddsNothing(): void {
		$this->import('actual-budget-export.csv', 'actual-budget');
		$this->assertSecondImportAddsNothing('actual-budget-export.csv', 'actual-budget');
	}

	// ===== Mint =====

	public function testMintImportTakesDirectionFromTransactionType(): void {
		$result = $this->import('mint-transactions.csv', 'mint');

		$this->assertSame([], $result['errors']);
		$this->assertSame(6, $result['imported']);
		$this->assertSame('credit_card', $this->accounts[$this->accountId('Visa Signature')]->getType());
		$this->assertEqualsWithDelta(-4.75 + 4.75 - 19.99, $this->balance('Visa Signature'), 0.001);
		$this->assertEqualsWithDelta(-82.16 + 2450 - 4.75, $this->balance('Everyday Checking'), 0.001);

		$safeway = $this->rowWhere('Safeway');
		$this->assertSame('2023-01-13', $safeway['date']);
		$this->assertSame('Groceries', $this->categoryPath($safeway['categoryId']));
		$this->assertSame('split with roommate', $safeway['notes']);
		$this->assertSame(['Reimbursable'], array_map(fn ($id) => $this->tags[$id], $this->transactionTags[$safeway['id']]));

		$this->assertSame('credit', $this->rowWhere('Paycheck')['type']);
		$this->assertNull($this->rowWhere('Amazon')['categoryId'], 'Mint\'s "Uncategorized" is not a category');
	}

	public function testMintCardPaymentSidesAreLinked(): void {
		$this->import('mint-transactions.csv', 'mint');

		$out = $this->rowWhere('Chase Card Payment');
		$in = $this->rowWhere('Visa Payment');
		$this->assertSame([[$in['id'], $out['id']]], $this->links);
		$this->assertNull($out['categoryId']);
	}

	public function testMintReimportAddsNothing(): void {
		$this->import('mint-transactions.csv', 'mint');
		$this->assertSecondImportAddsNothing('mint-transactions.csv', 'mint');
	}

	// ===== Monarch Money =====

	public function testMonarchImportUsesTheSignedAmount(): void {
		$result = $this->import('monarch-transactions.csv', 'monarch-money');

		$this->assertSame([], $result['errors']);
		$this->assertSame(7, $result['imported']);
		$this->assertEqualsWithDelta(-64.12 - 118.40 + 3250 - 500, $this->balance('Joint Checking (...1234)'), 0.001);
		$this->assertEqualsWithDelta(500 - 412.30 - 412.30, $this->balance('Amex Gold Card (...9876)'), 0.001);
		$this->assertSame('credit_card', $this->accounts[$this->accountId('Amex Gold Card (...9876)')]->getType());

		$utilities = $this->rowWhere('City Utilities');
		$this->assertSame('2024-04-02', $utilities['date']);
		$this->assertSame('Gas & Electric', $this->categoryPath($utilities['categoryId']));
		$this->assertSame(['Household', 'Recurring'], array_map(fn ($id) => $this->tags[$id], $this->transactionTags[$utilities['id']]));
		$this->assertCount(1, $this->links);
	}

	public function testMonarchReimportAddsNothingEvenWithIdenticalRows(): void {
		$this->import('monarch-transactions.csv', 'monarch-money');
		$this->assertSecondImportAddsNothing('monarch-transactions.csv', 'monarch-money');
	}

	// ===== Shared =====

	public function testPreviewListsAccountsAndCategoriesToCreate(): void {
		$preview = $this->preview('ynab-register.csv', 'ynab');

		$this->assertSame(10, $preview['validTransactions']);
		$this->assertSame(['Checking', 'Savings'], array_column($preview['accountsToCreate'], 'name'));
		$this->assertContains('Monthly Bills / Rent', array_column($preview['categoriesToCreate'], 'name'));
		$this->assertSame([], $this->ledger, 'A preview writes nothing');
		$this->assertSame([], $this->accounts);
	}

	public function testAFileFromAnotherAppIsRefusedWithTheMissingColumns(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('does not look like a YNAB export. Columns missing: Memo, Outflow, Inflow');
		$this->import('actual-budget-export.csv', 'ynab');
	}

	// ===== A file imported earlier with a manual mapping (R5-4) =====

	private const YNAB_MANUAL = ['date' => 2, 'description' => 3, 'notes' => 7, 'expenseColumn' => 8, 'incomeColumn' => 9, 'account' => 0, 'skipFirstRow' => true];
	private const MINT_MANUAL = ['date' => 0, 'description' => 1, 'amount' => 3, 'type' => 4, 'account' => 6, 'skipFirstRow' => true];
	private const FILE_ID = 'import_user1_0123456789abcdef0123456789abcdef.csv';

	private function importManually(string $content, array $mapping): array {
		$this->fileContent = $content;
		return $this->service->processImport('user1', self::FILE_ID, $mapping, null, null, true, true, ',', null);
	}

	/**
	 * 2.54 could only import a YNAB or Mint export with a manual mapping. 3.0
	 * selects the app's preset for the same file, whose import ids are built
	 * differently, and every row was imported a second time.
	 */
	public function testAPresetRecognisesTheRowsAManualMappingImported(): void {
		$first = $this->importManually((string)file_get_contents(self::FIXTURES . 'ynab-register.csv'), self::YNAB_MANUAL);
		$this->assertSame(10, $first['imported']);
		$manualIds = array_column($this->ledger, 'importId');

		$this->fileContent = (string)file_get_contents(self::FIXTURES . 'ynab-register.csv');
		$preview = $this->service->previewImport('user1', self::FILE_ID, [], null, null, false, ',', 'ynab');
		$this->assertSame(10, $preview['duplicates']);
		$this->assertSame([true], array_values(array_unique(array_column($preview['transactions'], 'isDuplicate'))));

		$again = $this->import('ynab-register.csv', 'ynab');
		$this->assertSame(0, $again['imported']);
		$this->assertCount(10, $this->ledger);
		$this->assertSame($manualIds, array_column($this->ledger, 'importId'), 'Import ids are not touched');
	}

	public function testAMintPresetRecognisesTheRowsAManualMappingImported(): void {
		$this->importManually((string)file_get_contents(self::FIXTURES . 'mint-transactions.csv'), self::MINT_MANUAL);
		$this->assertCount(6, $this->ledger);

		$again = $this->import('mint-transactions.csv', 'mint');

		$this->assertSame(0, $again['imported']);
		$this->assertCount(6, $this->ledger);
	}

	public function testRecognisedRowsCanStillBeImportedOnPurpose(): void {
		$this->importManually((string)file_get_contents(self::FIXTURES . 'ynab-register.csv'), self::YNAB_MANUAL);

		$this->fileContent = (string)file_get_contents(self::FIXTURES . 'ynab-register.csv');
		$again = $this->service->processImport('user1', self::FILE_ID, [], null, null, false, true, ',', 'ynab');

		$this->assertSame(10, $again['imported']);
		$this->assertCount(20, $this->ledger);
	}

	public function testEachStoredRowStandsForOneRowOfTheFile(): void {
		// The earlier import held one of two identical purchases; the second
		// one is new and must still import
		$header = "\"Account\",\"Flag\",\"Date\",\"Payee\",\"Category Group/Category\",\"Category Group\",\"Category\",\"Memo\",\"Outflow\",\"Inflow\",\"Cleared\"\n";
		$coffee = "\"Checking\",\"\",\"01/03/2024\",\"Corner Cafe\",\"\",\"\",\"\",\"\",\"\$3.50\",\"\$0.00\",\"Cleared\"\n";
		$this->importManually($header . $coffee, self::YNAB_MANUAL);
		$this->assertCount(1, $this->ledger);

		$this->fileContent = $header . $coffee . $coffee;
		$preview = $this->service->previewImport('user1', self::FILE_ID, [], null, null, false, ',', 'ynab');
		$this->assertSame(1, $preview['duplicates']);

		$again = $this->service->processImport('user1', self::FILE_ID, [], null, null, true, true, ',', 'ynab');
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	public function testADifferentPurchaseOnTheSameDayIsNotADuplicate(): void {
		$header = "\"Account\",\"Flag\",\"Date\",\"Payee\",\"Category Group/Category\",\"Category Group\",\"Category\",\"Memo\",\"Outflow\",\"Inflow\",\"Cleared\"\n";
		$this->importManually($header . "\"Checking\",\"\",\"01/03/2024\",\"Corner Cafe\",\"\",\"\",\"\",\"\",\"\$3.50\",\"\$0.00\",\"Cleared\"\n", self::YNAB_MANUAL);

		$this->fileContent = $header . "\"Checking\",\"\",\"01/03/2024\",\"Book Shop\",\"\",\"\",\"\",\"\",\"\$3.50\",\"\$0.00\",\"Cleared\"\n";
		$again = $this->service->processImport('user1', self::FILE_ID, [], null, null, true, true, ',', 'ynab');

		$this->assertSame(1, $again['imported']);
	}

	// ===== a re-exported register (V2-2) =====

	private const MINT_HEADER = "\"Date\",\"Description\",\"Original Description\",\"Amount\",\"Transaction Type\",\"Category\",\"Account Name\",\"Labels\",\"Notes\"\n";

	private function mintRow(string $original): string {
		return "\"9/01/2026\",\"Netflix\",\"{$original}\",\"9.99\",\"debit\",\"Entertainment\",\"Card\",\"\",\"\"\n";
	}

	private function importText(string $content, string $presetId): array {
		$this->fileContent = $content;
		return $this->service->processImport('user1', self::FILE_ID, [], null, null, true, true, ',', $presetId);
	}

	/**
	 * A new charge listed above one already imported, same payee, day and
	 * amount: the new row took the stored row that belonged to the old one,
	 * so both were skipped and the new charge was never stored. Each stored
	 * row now goes to the file row that carries its import id first.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('rowOrders')]
	public function testANewRowNeverTakesTheStoredRowOfAnOldOne(bool $newFirst): void {
		$this->importText(self::MINT_HEADER . $this->mintRow('NETFLIX.COM 111'), 'mint');
		$this->assertCount(1, $this->ledger);

		$rows = [$this->mintRow('NETFLIX.COM 222'), $this->mintRow('NETFLIX.COM 111')];
		$content = self::MINT_HEADER . implode('', $newFirst ? $rows : array_reverse($rows));
		$this->fileContent = $content;
		$preview = $this->service->previewImport('user1', self::FILE_ID, [], null, null, false, ',', 'mint');
		$again = $this->importText($content, 'mint');

		$this->assertSame(1, $preview['duplicates']);
		$this->assertSame(1, $again['imported']);
		$this->assertCount(2, $this->ledger);
	}

	public static function rowOrders(): array {
		return ['new row first' => [true], 'old row first' => [false]];
	}

	/**
	 * Two payees with the same memo are two transactions: matching on the
	 * memo alone hid Spotify behind Netflix.
	 */
	public function testAMemoAloneDoesNotMakeTwoRowsTheSame(): void {
		$header = "\"Account\",\"Flag\",\"Date\",\"Payee\",\"Category Group/Category\",\"Category Group\",\"Category\",\"Memo\",\"Outflow\",\"Inflow\",\"Cleared\"\n";
		$row = fn (string $payee) => "\"Current\",\"\",\"09/01/2026\",\"{$payee}\",\"Bills: Subscriptions\",\"Bills\",\"Subscriptions\",\"Subscription\",\"£9.99\",\"£0.00\",\"Cleared\"\n";
		$this->importText($header . $row('Netflix'), 'ynab');

		$again = $this->importText($header . $row('Spotify') . $row('Netflix'), 'ynab');

		$this->assertSame(1, $again['imported']);
		$this->assertSame(['Netflix', 'Spotify'], array_column($this->ledger, 'description'));
	}

	public function testImportIdsDoNotDependOnTheMappingSentWithTheRequest(): void {
		$this->fileContent = (string)file_get_contents(self::FIXTURES . 'mint-transactions.csv');
		$this->service->processImport('user1', 'import_user1_0123456789abcdef0123456789abcdef.csv', [], null, null, true, true, ',', 'mint');
		$ids = array_column($this->ledger, 'importId');

		$this->ledger = [];
		$this->accounts = [];
		$this->links = [];
		$this->service->processImport('user1', 'import_user1_fedcba9876543210fedcba9876543210.csv', ['date' => 3, 'description' => [1, 2], 'amount' => 0], null, null, true, true, ';', 'mint');

		$this->assertSame($ids, array_column($this->ledger, 'importId'));
	}

	// ===== a path in the category column (#421) =====

	private const CATEGORY_MAPPING = ['date' => 0, 'description' => 1, 'amount' => 2, 'category' => 3, 'skipFirstRow' => true];

	/**
	 * A statement with a category column, imported into one account, the
	 * way a Skrooge export comes in.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows date, payee, amount, category
	 */
	private function importWithCategories(array $rows): array {
		$account = new Account();
		$account->setId(1);
		$account->setName('Current');
		$account->setType('checking');
		$account->setCurrency('EUR');
		$this->accounts[1] = $account;

		$lines = ['Date,Payee,Amount,Category'];
		foreach ($rows as $row) {
			$lines[] = implode(',', array_map(fn (string $cell) => '"' . $cell . '"', $row));
		}
		$this->fileContent = implode("\n", $lines) . "\n";
		return $this->service->processImport('user1', self::FILE_ID, self::CATEGORY_MAPPING, 1, null, true, true, ',', null);
	}

	/**
	 * Skrooge writes a subcategory as its whole path. The cell was taken as
	 * one name, so every row made a flat "Food > Groceries" category
	 * instead of using the Groceries under Food.
	 */
	public function testAPathInTheCategoryColumnFindsTheSubcategory(): void {
		$food = $this->seedCategory('Food', 'expense');
		$groceries = $this->seedCategory('Groceries', 'expense', $food);

		$result = $this->importWithCategories([['2026-09-01', 'Corner shop', '-12.50', 'Food > Groceries']]);

		$this->assertSame([], $result['errors']);
		$this->assertSame($groceries, $this->rowWhere('Corner shop')['categoryId']);
		$this->assertCount(2, $this->categories, 'Nothing is created');
		$this->assertSame(0, $result['categoriesCreated'] ?? 0);
	}

	public function testAPathCreatesTheLevelsThatAreMissing(): void {
		$this->seedCategory('Food', 'expense');

		$result = $this->importWithCategories([
			['2026-09-01', 'Market', '-3.20', 'Food > Groceries > Fruit'],
			['2026-09-02', 'Bakery', '-2.10', 'Food>Groceries>Bread'],
		]);

		$this->assertSame('Food / Groceries / Fruit', $this->categoryPath($this->rowWhere('Market')['categoryId']));
		$this->assertSame('Food / Groceries / Bread', $this->categoryPath($this->rowWhere('Bakery')['categoryId']));
		$this->assertCount(4, $this->categories, 'Food is reused, and Groceries is made once');
		$this->assertSame(3, $result['categoriesCreated']);
	}

	public function testANewSubcategoryTakesItsParentsType(): void {
		$this->seedCategory('Income', 'income');

		$this->importWithCategories([['2026-09-01', 'Payroll correction', '-40.00', 'Income > Corrections']]);

		$category = $this->categories[$this->rowWhere('Payroll correction')['categoryId']];
		$this->assertSame('Income / Corrections', $this->categoryPath($category->getId()));
		$this->assertSame('income', $category->getType());
	}

	/**
	 * A category an earlier import made from the whole cell keeps getting
	 * its rows, so a statement imported every month does not split its
	 * history between two categories.
	 */
	public function testACategoryNamedWithTheWholePathIsStillUsed(): void {
		$flat = $this->seedCategory('Food > Groceries', 'expense');

		$this->importWithCategories([['2026-09-01', 'Corner shop', '-12.50', 'Food > Groceries']]);

		$this->assertSame($flat, $this->rowWhere('Corner shop')['categoryId']);
		$this->assertCount(1, $this->categories);
	}

	/**
	 * A refund names the category the spending went to. Only an income
	 * category of that name was looked for, so each refund made an income
	 * twin of an expense category the user already had.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refundCategoryCells')]
	public function testARefundIsFiledUnderTheSpendingCategory(string $cell): void {
		$food = $this->seedCategory('Food', 'expense');
		$groceries = $this->seedCategory('Groceries', 'expense', $food);

		$this->importWithCategories([['2026-09-03', 'Refund', '4.20', $cell]]);

		$this->assertSame('credit', $this->rowWhere('Refund')['type']);
		$this->assertSame($groceries, $this->rowWhere('Refund')['categoryId']);
		$this->assertCount(2, $this->categories);
	}

	public static function refundCategoryCells(): array {
		return ['path' => ['Food > Groceries'], 'name only' => ['Groceries']];
	}
}
