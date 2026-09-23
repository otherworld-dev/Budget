<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Preset;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\TagSet;
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

		$categoryService = $this->createMock(CategoryService::class);
		$categoryService->method('findOrCreate')->willReturnCallback(
			fn (string $userId, string $name, string $type) => $this->findOrCreateCategory($name, $type, null)
		);
		$categoryService->method('findOrCreateSubcategory')->willReturnCallback(
			fn (string $userId, string $name, string $type, int $parentId) => $this->findOrCreateCategory($name, $type, $parentId)
		);

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

		$this->service = new ImportService(
			$appData,
			$transactionService,
			$this->createMock(TransactionMapper::class),
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

	private function findOrCreateCategory(string $name, string $type, ?int $parentId): Category {
		foreach ($this->categories as $category) {
			if ($category->getName() === $name && $category->getType() === $type && $category->getParentId() === $parentId) {
				return $category;
			}
		}
		$category = new Category();
		$category->setId(count($this->categories) + 1);
		$category->setName($name);
		$category->setType($type);
		$category->setParentId($parentId);
		$category->setCreatedAt(date('Y-m-d H:i:s'));
		$this->categories[$category->getId()] = $category;
		return $category;
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
		return $parent !== null ? $this->categories[$parent]->getName() . ' / ' . $category->getName() : $category->getName();
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
}
