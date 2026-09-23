<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCA\Budget\Service\NetWorthService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\SampleDataService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\SharedExpenseService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use PHPUnit\Framework\TestCase;

class SampleDataServiceTest extends TestCase {
    private SettingService $settings;
    private AccountService $accounts;
    private CategoryService $categories;
    private TransactionService $transactions;
    private ManualExchangeRateService $rates;
    private FactoryResetService $factoryReset;
    private GoalsService $goals;

    /** @var array<string, string> the user's settings */
    private array $settingValues = [];
    /** @var Account[] accounts the user already has */
    private array $existingAccounts = [];
    /** @var array<int, array{name: string, currency: string}> */
    private array $createdAccounts = [];
    /** @var array<int, array> transaction create() arguments */
    private array $createdTransactions = [];
    /** @var Category[] */
    private array $categoryList = [];

    protected function setUp(): void {
        $this->settings = $this->createMock(SettingService::class);
        $this->settings->method('get')->willReturnCallback(fn(string $u, string $k) => $this->settingValues[$k] ?? null);
        $this->settings->method('set')->willReturnCallback(function (string $u, string $k, string $v): Setting {
            $this->settingValues[$k] = $v;
            return new Setting();
        });
        $this->settings->method('getAll')->willReturnCallback(fn() => $this->settingValues);

        $this->accounts = $this->createMock(AccountService::class);
        $this->accounts->method('findAll')->willReturnCallback(fn() => $this->existingAccounts);
        $this->accounts->method('create')->willReturnCallback(function (string $u, string $name, string $type, float $balance, string $currency): Account {
            $account = new Account();
            $account->setId(count($this->createdAccounts) + 1);
            $this->createdAccounts[] = ['name' => $name, 'currency' => $currency];
            return $account;
        });

        $this->categories = $this->createMock(CategoryService::class);
        $this->categories->method('findAll')->willReturnCallback(fn() => $this->categoryList);

        $this->transactions = $this->createMock(TransactionService::class);
        $this->transactions->method('create')->willReturnCallback(function (...$args): Transaction {
            $tx = new Transaction();
            $tx->setId(count($this->createdTransactions) + 1);
            $this->createdTransactions[] = $args;
            return $tx;
        });

        $this->goals = $this->createMock(GoalsService::class);
        $this->goals->method('create')->willReturnCallback(function (): SavingsGoal {
            $goal = new SavingsGoal();
            $goal->setId(7);
            return $goal;
        });

        $this->rates = $this->createMock(ManualExchangeRateService::class);
        $this->factoryReset = $this->createMock(FactoryResetService::class);
    }

    private function service(string $today = '2026-09-23'): SampleDataService {
        return new class(
            $today,
            $this->settings,
            $this->accounts,
            $this->categories,
            $this->transactions,
            $this->createMock(TransactionSplitService::class),
            $this->createMock(TagSetService::class),
            $this->createMock(TransactionTagService::class),
            $this->createMock(BillService::class),
            $this->createMock(RecurringIncomeService::class),
            $this->goals,
            $this->createMock(PensionService::class),
            $this->createMock(AssetService::class),
            $this->createMock(NetWorthService::class),
            $this->rates,
            $this->createMock(SharedExpenseService::class),
            $this->factoryReset,
        ) extends SampleDataService {
            public function __construct(private string $today, ...$deps) {
                parent::__construct(...$deps);
            }

            protected function getToday(): string {
                return $this->today;
            }
        };
    }

    private function category(int $id, string $name, ?float $budget = null, ?int $parentId = null): Category {
        $c = new Category();
        $c->setId($id);
        $c->setName($name);
        $c->setType('expense');
        $c->setParentId($parentId);
        $c->setBudgetAmount($budget);
        return $c;
    }

    private const DEFINITIONS = [
        ['name' => 'Food', 'type' => 'expense', 'budgetPercent' => 15, 'children' => [
            ['name' => 'Groceries', 'budgetPercent' => 10],
        ]],
        ['name' => 'Housing', 'type' => 'expense', 'budgetPercent' => 30, 'children' => [
            ['name' => 'Rent/Mortgage', 'budgetPercent' => 25],
        ]],
        ['name' => 'Savings', 'type' => 'expense', 'budgetPercent' => 5],
    ];

    public function testRefusesWhenTheUserAlreadyHasAnAccount(): void {
        $this->existingAccounts = [new Account()];
        $this->accounts->expects($this->never())->method('create');
        $this->transactions->expects($this->never())->method('create');

        $this->expectException(ValidationException::class);
        $this->service()->loadForUser('alice');
    }

    public function testLoadsASingleCurrencyDatasetInTheUsersBaseCurrency(): void {
        $this->settingValues = ['default_currency' => 'eur'];
        $this->rates->expects($this->never())->method('setRate');

        $counts = $this->service()->loadForUser('alice');

        $this->assertSame('1', $this->settingValues[SampleDataService::SETTING_KEY]);
        // The user's own currency setting is left as it was
        $this->assertSame('eur', $this->settingValues['default_currency']);
        $this->assertCount(4, $this->createdAccounts);
        $this->assertSame(['EUR'], array_values(array_unique(array_column($this->createdAccounts, 'currency'))));
        $this->assertSame(4, $counts['accounts']);
        $this->assertSame(count($this->createdTransactions), $counts['transactions']);
        $this->assertGreaterThan(20, $counts['transactions']);
    }

    public function testFallsBackToTheAppDefaultCurrencyWhenNoneIsSet(): void {
        $this->service()->loadForUser('alice');

        $this->assertSame(['GBP'], array_values(array_unique(array_column($this->createdAccounts, 'currency'))));
        $this->assertArrayNotHasKey('default_currency', $this->settingValues);
    }

    public function testDatesCoverTheLastThreeMonthsAndNeverPassToday(): void {
        $this->service('2026-09-02')->loadForUser('alice');

        $dates = array_map(fn(array $args) => $args[2], $this->createdTransactions);
        $this->assertSame('2026-06-01', min($dates));
        $this->assertSame('2026-09-02', max($dates));
        // Current-month rows dated after today are pulled back to today
        $this->assertContains('2026-09-01', $dates);
    }

    public function testShortMonthsClampTheDay(): void {
        // Three months before May is February: day 28 fits, 30 would not
        $this->service('2026-05-20')->loadForUser('alice');

        $dates = array_map(fn(array $args) => $args[2], $this->createdTransactions);
        foreach ($dates as $date) {
            $this->assertTrue(checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), $date);
        }
        $this->assertContains('2026-02-28', $dates);
    }

    public function testUsesCategoriesTheUserAlreadyHasAndBudgetsTheUnbudgetedOnes(): void {
        // The user ran the checklist's default-categories step first: the
        // tree exists without budgets, and one budget is their own
        $this->categoryList = [
            $this->category(40, 'Salary'),
            $this->category(50, 'Food'),
            $this->category(41, 'Groceries', null, 50),
            $this->category(51, 'Housing'),
            $this->category(42, 'Rent/Mortgage', 900.0, 51),
            $this->category(52, 'Savings'),
        ];
        $this->categories->method('getDefaultCategoryDefinitions')->willReturn(self::DEFINITIONS);
        $updates = [];
        $this->categories->method('update')->willReturnCallback(function (int $id, string $u, array $changes) use (&$updates) {
            $updates[$id] = $changes['budgetAmount'];
            return new Category();
        });

        $this->service()->loadForUser('alice');

        // Subcategory and childless parent get the suggestion; the user's
        // own 900 and the parents with budgeted children are left alone
        $this->assertSame([41 => 320.0, 52 => 160.0], $updates);
        $salaryRows = array_filter($this->createdTransactions, fn(array $a) => $a[3] === 'Monthly salary');
        $this->assertNotEmpty($salaryRows);
        foreach ($salaryRows as $args) {
            $this->assertSame(40, $args[6]);
        }
    }

    public function testNewParentsDropTheirBudgetSoIncomeIsNotBudgetedTwice(): void {
        $food = $this->category(50, 'Food', 480.0);
        $groceries = $this->category(41, 'Groceries', 320.0, 50);
        $savings = $this->category(52, 'Savings', 160.0);
        $this->categoryList = [$food, $groceries, $savings];
        $this->categories->method('createDefaultCategories')->willReturn([$food, $groceries, $savings]);
        $this->categories->method('getDefaultCategoryDefinitions')->willReturn(self::DEFINITIONS);
        $updates = [];
        $this->categories->method('update')->willReturnCallback(function (int $id, string $u, array $changes) use (&$updates) {
            $updates[$id] = $changes['budgetAmount'];
            return new Category();
        });

        $this->service()->loadForUser('alice');

        $this->assertSame([50 => null], $updates);
    }

    public function testTheDemoProfileStaysMultiCurrency(): void {
        $this->rates->expects($this->atLeastOnce())->method('setRate');

        $data = $this->service()->seedFullProfile('admin', 'USD');

        $this->assertSame('USD', $this->settingValues['default_currency']);
        $this->assertSame(['USD', 'EUR', 'GBP', 'BTC'], array_values(array_unique(array_column($this->createdAccounts, 'currency'))));
        $this->assertArrayHasKey('btc', $data['accountIds']);
        $this->assertSame(7, $data['holidayGoalId']);
    }

    public function testClearRefusesWithoutSampleData(): void {
        $this->factoryReset->expects($this->never())->method('executeFactoryReset');

        $this->expectException(ValidationException::class);
        $this->service()->clearForUser('alice');
    }

    public function testClearResetsEverythingButPutsTheUsersSettingsBack(): void {
        $this->settingValues = [
            SampleDataService::SETTING_KEY => '1',
            'default_currency' => 'EUR',
            'onboarding_state' => 'active',
        ];
        $this->factoryReset->expects($this->once())->method('executeFactoryReset')->with('alice')
            ->willReturnCallback(function (): array {
                $this->settingValues = [];
                return ['accounts' => 4];
            });

        $counts = $this->service()->clearForUser('alice');

        $this->assertSame(['accounts' => 4], $counts);
        $this->assertSame(['default_currency' => 'EUR', 'onboarding_state' => 'active'], $this->settingValues);
    }
}
