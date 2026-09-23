<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Exception\ValidationException;

/**
 * Sample data: a realistic dataset for one user.
 *
 * Two callers share the generation here. `occ budget:seed-demo` builds the
 * full multi-currency demo (and shares it between two users, which stays in
 * the command), and "Try with sample data" on the first-run checklist seeds
 * the signed-in user's own account with a single-currency version of it.
 *
 * Dates are relative to today — the data covers the last three months and
 * this one — so the dashboard, budget and reports all have something to show
 * the moment it lands. Nothing is dated after today.
 *
 * A user who loaded sample data this way carries the SETTING_KEY flag, and
 * clearForUser() removes it all through the factory reset, keeping the
 * user's own settings.
 */
class SampleDataService {

    /** User setting marking that the sample data is what the user is looking at */
    public const SETTING_KEY = 'sample_data_loaded';

    /** Monthly income the default category budgets are sized from */
    private const OWNER_MONTHLY_INCOME = 3600.0;

    public function __construct(
        private SettingService $settingService,
        private AccountService $accountService,
        private CategoryService $categoryService,
        private TransactionService $transactionService,
        private TransactionSplitService $splitService,
        private TagSetService $tagSetService,
        private TransactionTagService $transactionTagService,
        private BillService $billService,
        private RecurringIncomeService $incomeService,
        private GoalsService $goalsService,
        private PensionService $pensionService,
        private AssetService $assetService,
        private NetWorthService $netWorthService,
        private ManualExchangeRateService $manualRateService,
        private SharedExpenseService $sharedExpenseService,
        private FactoryResetService $factoryResetService,
    ) {
    }

    // ==========================================================
    // "Try with sample data" (one user, no sharing)
    // ==========================================================

    /**
     * Whether the user already has anything the sample data could collide
     * with: an account (and with it any transaction) of their own.
     */
    public function hasData(string $userId): bool {
        return !empty($this->accountService->findAll($userId));
    }

    public function isLoaded(string $userId): bool {
        return $this->settingService->get($userId, self::SETTING_KEY) === '1';
    }

    /**
     * Seed the sample data into the user's own, empty budget, in their base
     * currency, and flag it as sample data.
     *
     * @return array{accounts: int, transactions: int, categories: int}
     * @throws ValidationException when the user already has accounts
     */
    public function loadForUser(string $userId): array {
        if ($this->hasData($userId)) {
            throw new ValidationException('Sample data can only be added to an empty budget.');
        }

        // The currency every other part of the app falls back to when unset
        $base = strtoupper($this->settingService->get($userId, 'default_currency') ?? 'GBP');
        $counts = ['accounts' => 0, 'transactions' => 0, 'categories' => 0];
        // Flagged first: should seeding fail halfway, what did land is still
        // marked as sample data, and "Clear sample data" can take it away
        $this->settingService->set($userId, self::SETTING_KEY, '1');
        $this->seedFullProfile($userId, $base, false, null, $counts);

        return $counts;
    }

    /**
     * Remove the sample data: everything the user has in Budget goes, through
     * the factory reset, except their settings, which are put back (without
     * the sample flag) so their currency and preferences survive.
     *
     * @return array<string, int> the factory reset's deleted counts
     * @throws ValidationException when no sample data is loaded
     */
    public function clearForUser(string $userId): array {
        if (!$this->isLoaded($userId)) {
            throw new ValidationException('No sample data is loaded.');
        }

        $settings = $this->settingService->getAll($userId);
        unset($settings[self::SETTING_KEY]);

        $counts = $this->factoryResetService->executeFactoryReset($userId);

        foreach ($settings as $key => $value) {
            $this->settingService->set($userId, (string) $key, (string) $value);
        }

        return $counts;
    }

    // ==========================================================
    // Full profile (the demo owner)
    // ==========================================================

    /**
     * Seed the full demo profile for one user.
     *
     * @param bool $multiCurrency true: USD/EUR/GBP/BTC accounts with manual
     *        rates, as `occ budget:seed-demo` has always built; false: every
     *        account in $base, no crypto wallet and no manual rates
     * @param callable(string):void|null $log progress lines
     * @param array<string, int>|null $counts filled with what was created
     * @return array{accountIds: array<string,int>, categoryIds: array<string,int>, holidayGoalId: int}
     */
    public function seedFullProfile(string $u, string $base, bool $multiCurrency = true, ?callable $log = null, ?array &$counts = null): array {
        $log ??= static function (string $line): void {
        };

        if ($multiCurrency) {
            $this->settingService->set($u, 'default_currency', $base);
            $this->seedExchangeRates($log, $u, $base);
        }

        // --- Accounts ---
        $acct = [
            'checking' => $this->accountService->create($u, 'Main Checking', 'checking', 0.0, $multiCurrency ? 'USD' : $base, 'Globex Bank')->getId(),
            'savings' => $this->accountService->create($u, $multiCurrency ? 'Euro Savings' : 'Savings Account', 'savings', 0.0, $multiCurrency ? 'EUR' : $base, 'Banque Centrale')->getId(),
            'uk' => $this->accountService->create($u, $multiCurrency ? 'UK Current Account' : 'Everyday Account', 'checking', 0.0, $multiCurrency ? 'GBP' : $base, 'Stark Bank')->getId(),
            'card' => $this->accountService->create($u, 'Rewards Credit Card', 'credit_card', 0.0, $multiCurrency ? 'USD' : $base, 'Globex Bank')->getId(),
        ];
        if ($multiCurrency) {
            $acct['btc'] = $this->accountService->create($u, 'Bitcoin Wallet', 'cryptocurrency', 0.0, 'BTC', 'Coinbase')->getId();
            $log('  · 5 accounts (USD/EUR/GBP/BTC)');
        } else {
            $log('  · 4 accounts (' . $base . ')');
        }

        // --- Categories + budgets (full default tree) ---
        $cat = $this->seedCategories($u, self::OWNER_MONTHLY_INCOME);
        $log('  · ' . count($cat) . ' categories with budgets');

        // --- Transactions ---
        // Row: [acctKey, monthOffset, day, description, signedAmount, categoryName|null, vendor|null, ref|null, note|null, key|null]
        // monthOffset 0 is the current month, -3 three months back.
        $rows = [
            // Main Checking
            ['checking', -3, 1, 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-1', null, null],
            ['checking', -3, 3, 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-1', null, null],
            ['checking', -3, 5, 'Weekly groceries', -92.40, 'Groceries', 'Greenleaf Market', null, null, null],
            ['checking', -3, 8, 'Electricity and gas', -138.75, 'Utilities', 'PowerCo', null, null, null],
            ['checking', -3, 15, 'Transfer to savings', -500.00, null, null, 'TRF-1', 'Transfer out', 'xferOut'],
            ['checking', -3, 22, 'Freelance project', 650.00, 'Freelance', 'Initech', 'INV-204', null, null],
            ['checking', -2, 1, 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-2', null, null],
            ['checking', -2, 3, 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-2', null, null],
            ['checking', -2, 6, 'Grocery run (bulk)', -120.00, 'Groceries', 'Costco', null, null, 'splitTx'],
            ['checking', -2, 9, 'Monthly transit pass', -60.00, 'Public Transit', 'Metro Transit', null, null, null],
            ['checking', -2, 18, 'Cinema tickets', -27.00, 'Movies/Shows', 'Odeon', null, null, null],
            ['checking', -2, 28, 'Credit card payment', -250.00, null, null, 'PMT-2', 'Payment to card', 'payOut'],
            ['checking', -1, 1, 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-3', null, null],
            ['checking', -1, 3, 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-3', null, null],
            ['checking', -1, 7, 'Weekly groceries', -88.90, 'Groceries', 'Costco', null, null, 'tagTx'],
            ['checking', -1, 18, 'Dinner with Sam', -90.00, 'Dining Out', 'Sakura Sushi', null, 'Split 50/50 with Sam', 'shareTx'],
            ['checking', 0, 1, 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-4', null, null],
            ['checking', 0, 3, 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-4', null, null],
            ['checking', 0, 5, 'Weekly groceries', -79.55, 'Groceries', 'Greenleaf Market', null, null, null],

            // Savings
            ['savings', -3, 15, 'Transfer in from checking', $multiCurrency ? 460.00 : 500.00, null, null, 'TRF-1', 'Transfer in', 'xferIn'],
            ['savings', -3, 28, 'Monthly interest', 3.18, 'Other Income', 'Banque Centrale', null, null, null],
            ['savings', -2, 28, 'Monthly interest', 3.41, 'Other Income', 'Banque Centrale', null, null, null],
            ['savings', -1, 28, 'Monthly interest', 3.74, 'Other Income', 'Banque Centrale', null, null, null],

            // Second current account
            ['uk', -3, 14, 'Clothing', -64.99, 'Clothing', 'Marks & Spencer', null, null, null],
            ['uk', -2, 19, 'Train tickets', -48.20, 'Public Transit', 'National Rail', null, null, null],
            ['uk', -1, 23, 'Bookshop', -31.45, 'Movies/Shows', 'Waterstones', null, null, null],

            // Rewards Credit Card
            ['card', -3, 11, 'Online order', -89.99, 'Electronics', 'Amazon', null, null, null],
            ['card', -3, 16, 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', -2, 16, 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', -2, 27, 'Prescription', -58.00, 'Prescriptions', 'WellCare Pharmacy', null, null, null],
            ['card', -2, 28, 'Card payment received', 250.00, null, null, 'PMT-2', 'Payment from checking', 'payIn'],
            ['card', -1, 10, 'New laptop', -220.00, 'Electronics', 'Currys PC World', null, 'Saving towards this', 'laptopTx'],
            ['card', -1, 16, 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', -1, 28, 'Concert tickets', -120.00, 'Movies/Shows', 'TicketHub', null, null, null],
        ];
        if ($multiCurrency) {
            $rows[] = ['btc', -3, 18, 'Bought Bitcoin', 0.01500000, 'Investment', 'Coinbase', null, 'DCA purchase', null];
            $rows[] = ['btc', -2, 18, 'Bought Bitcoin', 0.01200000, 'Investment', 'Coinbase', null, 'DCA purchase', null];
            $rows[] = ['btc', -1, 12, 'Sold Bitcoin', -0.00500000, 'Investment', 'Coinbase', null, 'Took some profit', null];
        }

        $tx = [];
        foreach ($rows as $r) {
            [$ak, $offset, $day, $desc, $amt, $catName, $vendor, $ref, $note, $key] = $r;
            $type = $amt < 0 ? 'debit' : 'credit';
            $catId = ($catName !== null && isset($cat[$catName])) ? $cat[$catName] : null;
            $created = $this->transactionService->create(
                $u, $acct[$ak], $this->relativeDate($offset, $day), $desc, abs($amt), $type, $catId, $vendor, $ref, $note
            );
            if ($key !== null) {
                $tx[$key] = $created;
            }
        }
        $log('  · ' . count($rows) . ' transactions');

        // --- Transfers (link the two legs) ---
        try {
            $this->transactionService->linkTransactions($tx['payOut']->getId(), $tx['payIn']->getId(), $u);
        } catch (\Throwable $e) {
            $log('    (skip card payment transfer link: ' . $e->getMessage() . ')');
        }
        try {
            $this->transactionService->linkTransactions($tx['xferOut']->getId(), $tx['xferIn']->getId(), $u);
        } catch (\Throwable $e) {
            $log('    (skip savings transfer link: ' . $e->getMessage() . ')');
        }
        $log('  · 2 transfers linked');

        // --- Split a transaction ---
        try {
            $this->splitService->splitTransaction($tx['splitTx']->getId(), $u, [
                ['categoryId' => $cat['Groceries'] ?? null, 'amount' => 80.00, 'description' => 'Food'],
                ['categoryId' => $cat['Home Goods'] ?? ($cat['Shopping'] ?? null), 'amount' => 40.00, 'description' => 'Household'],
            ]);
            $log('  · 1 split transaction');
        } catch (\Throwable $e) {
            $log('    (skip split: ' . $e->getMessage() . ')');
        }

        // --- Tags: a category tag set + a global tag ---
        $laptopTagId = null;
        try {
            $tagSet = $this->tagSetService->create($u, $cat['Groceries'] ?? throw new \RuntimeException('no Groceries category'), 'Store', 'Where the shop happened');
            $costco = $this->tagSetService->createTag($tagSet->getId(), $u, 'Costco');
            $this->tagSetService->createTag($tagSet->getId(), $u, 'Whole Foods');
            $this->transactionTagService->setTransactionTags($tx['tagTx']->getId(), $u, [$costco->getId()]);

            $laptopTag = $this->tagSetService->createGlobalTag($u, 'Laptop Fund');
            $laptopTagId = $laptopTag->getId();
            $this->transactionTagService->setTransactionTags($tx['laptopTx']->getId(), $u, [$laptopTagId]);
            $log('  · tag set + global tag applied');
        } catch (\Throwable $e) {
            $log('    (skip tags: ' . $e->getMessage() . ')');
        }

        // --- Bills ---
        $this->billService->create($u, 'Apartment Rent', 1450.0, 'monthly', 3, null, $cat['Rent/Mortgage'] ?? null, $acct['checking'], 'rent', 'Apartment rent', null, 3);
        $this->billService->create($u, 'Netflix', 12.99, 'monthly', 16, null, $cat['Streaming Services'] ?? null, $acct['card'], 'netflix', 'Streaming subscription', null, 3);
        $this->billService->create($u, 'Home Internet', 45.0, 'monthly', 20, null, $cat['Utilities'] ?? null, $acct['checking'], 'internet', 'Broadband', null, 3);
        $log('  · 3 bills');

        // --- Recurring income ---
        $this->incomeService->create($u, 'Salary', 3200.0, 'monthly', 1, null, $cat['Salary'] ?? null, $acct['checking'], 'Globex', 'salary');
        $log('  · 1 recurring income');

        // --- Savings goals (one tag-linked, one the demo shares) ---
        $this->goalsService->create($u, 'Emergency Fund', 10000.0, null, 3500.0, '3 months of expenses', null, null, $acct['savings'], '#22c55e');
        $holiday = $this->goalsService->create($u, 'Group Holiday', 2000.0, null, 750.0, 'Shared trip with Sam', $this->relativeDate(4, 1, false), null, null, '#3b82f6');
        if ($laptopTagId !== null) {
            $this->goalsService->create($u, 'New Laptop', 1500.0, null, 0.0, 'Auto-tracked via Laptop Fund tag', null, $laptopTagId, null, '#a855f7');
        }
        $log('  · ' . ($laptopTagId !== null ? 3 : 2) . ' savings goals');

        // --- Pension ---
        try {
            $pension = $this->pensionService->create($u, 'Workplace Pension', 'workplace', 'Aviva', $base, 42000.0, 350.0, 0.06, 67, null, null);
            $this->pensionService->createContribution($pension->getId(), $u, 350.0, $this->relativeDate(-2, 28), 'Monthly contribution');
            $this->pensionService->createContribution($pension->getId(), $u, 350.0, $this->relativeDate(-1, 28), 'Monthly contribution');
            $this->pensionService->createSnapshot($pension->getId(), $u, 43200.0, $this->relativeDate(-1, 28));
            $log('  · 1 pension (+contributions, snapshot)');
        } catch (\Throwable $e) {
            $log('    (skip pension: ' . $e->getMessage() . ')');
        }

        // --- Assets ---
        try {
            $this->assetService->create($u, 'Family Home', 'real_estate', 'Primary residence', $base, 320000.0, 280000.0, '2019-06-01', 0.04);
            $this->assetService->create($u, 'Car', 'vehicle', 'Daily driver', $base, 18000.0, 26000.0, '2022-03-15', -0.12);
            $log('  · 2 assets');
        } catch (\Throwable $e) {
            $log('    (skip assets: ' . $e->getMessage() . ')');
        }

        // --- Net worth history ---
        foreach ([$this->relativeDate(-3, 28), $this->relativeDate(-2, 28), $this->relativeDate(-1, 28), $this->getToday()] as $d) {
            try {
                $this->netWorthService->createSnapshot($u, NetWorthSnapshot::SOURCE_AUTO, $d);
            } catch (\Throwable $e) {
                // best effort
            }
        }
        $log('  · net-worth snapshots');

        // --- Shared expenses (split with a contact, then settle) ---
        try {
            $contact = $this->sharedExpenseService->createContact($u, 'Sam Chen', null, null);
            $this->sharedExpenseService->splitFiftyFifty($u, $tx['shareTx']->getId(), $contact->getId(), 'Dinner split 50/50');
            $this->sharedExpenseService->recordSettlement($u, $contact->getId(), 45.0, $this->relativeDate(-1, 25), 'Sam repaid half of dinner', $multiCurrency ? 'USD' : $base);
            $log('  · shared expense (split + settlement)');
        } catch (\Throwable $e) {
            $log('    (skip shared expense: ' . $e->getMessage() . ')');
        }

        if ($counts !== null) {
            $counts['accounts'] = count($acct);
            $counts['transactions'] = count($rows);
            $counts['categories'] = count($cat);
        }

        return [
            'accountIds' => $acct,
            'categoryIds' => $cat,
            'holidayGoalId' => $holiday->getId(),
        ];
    }

    // ==========================================================
    // Light profile (the demo's second user)
    // ==========================================================

    /**
     * @param callable(string):void|null $log
     */
    public function seedLightProfile(string $u, string $base, ?callable $log = null): void {
        $log ??= static function (string $line): void {
        };
        $this->settingService->set($u, 'default_currency', $base);

        $checking = $this->accountService->create($u, 'Sam Checking', 'checking', 0.0, 'USD', 'Wayne Bank')->getId();
        $travel = $this->accountService->create($u, 'Sam Travel Card', 'checking', 0.0, 'EUR', 'Wayne Bank')->getId();

        $cat = $this->seedCategories($u, 2750.0);

        $rows = [
            [$checking, -3, 1, 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, -3, 4, 'Rent share', -725.00, 'Rent/Mortgage', 'Citywide Lettings'],
            [$checking, -3, 13, 'Gym membership', -39.00, 'Doctor Visits', 'FlexFit'],
            [$checking, -2, 1, 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, -2, 4, 'Rent share', -725.00, 'Rent/Mortgage', 'Citywide Lettings'],
            [$checking, -2, 9, 'Weekly groceries', -83.15, 'Groceries', 'Greenleaf Market'],
            [$checking, -1, 1, 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, -1, 25, 'Repaid by Alex', 45.00, 'Other Income', 'Alex Rivera'],
            [$travel, -2, 21, 'Flights for trip', -310.00, 'Other Income', 'SkyHigh Air'],
            [$travel, -1, 2, 'Car hire', -95.50, 'Public Transit', 'EuroCar'],
        ];
        foreach ($rows as [$aid, $offset, $day, $desc, $amt, $catName, $vendor]) {
            $type = $amt < 0 ? 'debit' : 'credit';
            $catId = $cat[$catName] ?? null;
            $this->transactionService->create($u, $aid, $this->relativeDate($offset, $day), $desc, abs($amt), $type, $catId, $vendor);
        }

        $this->goalsService->create($u, 'New Bike', 800.0, null, 120.0, null, $this->relativeDate(2, 1, false), null, null, '#f59e0b');
        $log('  · 2 accounts, ' . count($rows) . ' transactions, 1 goal');
    }

    // ==========================================================
    // Helpers
    // ==========================================================

    /**
     * Create the default category tree (sized budgets on what it creates) and
     * return name => id over the user's whole tree, so categories the user
     * already made — from the checklist's default-categories step, say — are
     * used rather than left out. Existing default categories with no budget
     * get the suggested one too.
     *
     * @return array<string, int>
     */
    private function seedCategories(string $u, float $monthlyIncome): array {
        $this->categoryService->createDefaultCategories($u, $monthlyIncome);

        $byName = [];
        $entities = [];
        foreach ($this->categoryService->findAll($u) as $category) {
            $byName[$category->getName()] ??= $category->getId();
            $entities[$category->getName()] ??= $category;
        }

        foreach ($this->categoryService->getDefaultCategoryDefinitions() as $parent) {
            foreach (array_merge([$parent], $parent['children'] ?? []) as $definition) {
                $category = $entities[$definition['name']] ?? null;
                if ($category === null || !isset($definition['budgetPercent']) || $category->getBudgetAmount() !== null) {
                    continue;
                }
                try {
                    $this->categoryService->update($category->getId(), $u, [
                        'budgetAmount' => round($monthlyIncome * $definition['budgetPercent'] / 100, 2),
                    ]);
                } catch (\Throwable $e) {
                    // best effort: a category without a budget is still usable
                }
            }
        }

        return $byName;
    }

    /**
     * A date $monthOffset months from this one, on $day (clamped to the
     * month's length). With $notAfterToday, a date that would fall later
     * than today becomes today, so the sample never holds future-dated rows.
     */
    private function relativeDate(int $monthOffset, int $day, bool $notAfterToday = true): string {
        $today = $this->getToday();
        $month = (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify(sprintf('%+d months', $monthOffset));
        $day = max(1, min($day, (int) $month->format('t')));
        $date = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->format('Y-m-d');

        return ($notAfterToday && $date > $today) ? $today : $date;
    }

    /**
     * Today (Y-m-d). Overridable in tests.
     */
    protected function getToday(): string {
        return date('Y-m-d');
    }

    /**
     * Best-effort manual exchange rates so multi-currency net worth converts.
     * Requires the base currency's ECB rate to be available locally (unless
     * base is EUR); otherwise this is silently skipped.
     *
     * @param callable(string):void $log
     */
    private function seedExchangeRates(callable $log, string $u, string $base): void {
        // rate = "1 base = X target"
        $rates = [
            'EUR' => '0.92',
            'GBP' => '0.79',
            'BTC' => '0.0000155',
        ];
        $seeded = 0;
        foreach ($rates as $currency => $rate) {
            if ($currency === $base) {
                continue;
            }
            try {
                $this->manualRateService->setRate($u, $currency, $rate);
                $seeded++;
            } catch (\Throwable $e) {
                // Currency not supported, or base rate unavailable — skip.
            }
        }
        if ($seeded > 0) {
            $log("  · {$seeded} manual exchange rates");
        } else {
            $log('  · exchange rates skipped (run the ExchangeRateUpdateJob, or set base currency to EUR, for conversions)');
        }
    }
}
