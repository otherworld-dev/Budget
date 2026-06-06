<?php

declare(strict_types=1);

namespace OCA\Budget\Command;

use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCA\Budget\Service\NetWorthService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Service\SharedExpenseService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds a demonstration dataset covering the full feature surface of the app:
 * multi-currency accounts, categories + budgets, transactions, splits, transfers,
 * tags, bills, recurring income, savings goals (one shared cross-user), pensions,
 * assets, net-worth history, shared expenses/settlements, and a real budget share
 * between two Nextcloud users.
 *
 * This is a developer/QA tool — register it in appinfo/info.xml under <commands>.
 */
class SeedDemo extends Command {

    public function __construct(
        private IUserManager $userManager,
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
        private ShareService $shareService,
        private GranularShareService $granularShareService,
        private SharedExpenseService $sharedExpenseService,
        private FactoryResetService $factoryResetService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('budget:seed-demo')
            ->setDescription('Seed a demo dataset (multi-currency + shared between two users) covering all app features')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner (primary) Nextcloud user ID', 'admin')
            ->addOption('recipient', null, InputOption::VALUE_REQUIRED, 'Second Nextcloud user ID to share with', 'demo')
            ->addOption('base-currency', null, InputOption::VALUE_REQUIRED, "Owner's base/reporting currency", 'USD')
            ->addOption('wipe', null, InputOption::VALUE_NONE, 'Factory-reset both users before seeding')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Seed even if the owner already has accounts')
            ->addOption('skip-share', null, InputOption::VALUE_NONE, 'Skip the cross-user sharing step');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $owner = (string) $input->getOption('owner');
        $recipient = (string) $input->getOption('recipient');
        $baseCurrency = strtoupper((string) $input->getOption('base-currency'));
        $wipe = (bool) $input->getOption('wipe');
        $force = (bool) $input->getOption('force');
        $skipShare = (bool) $input->getOption('skip-share');

        // Validate users exist
        if (!$this->userManager->userExists($owner)) {
            $output->writeln("<error>Owner user '{$owner}' does not exist.</error>");
            return Command::FAILURE;
        }
        if (!$skipShare && !$this->userManager->userExists($recipient)) {
            $output->writeln("<error>Recipient user '{$recipient}' does not exist. Create it, or pass --skip-share.</error>");
            return Command::FAILURE;
        }
        if ($owner === $recipient) {
            $output->writeln('<error>Owner and recipient must be different users.</error>');
            return Command::FAILURE;
        }

        // Wipe if requested
        if ($wipe) {
            $output->writeln("Wiping existing data for <info>{$owner}</info>" . ($skipShare ? '' : " and <info>{$recipient}</info>") . '…');
            $this->factoryResetService->executeFactoryReset($owner);
            if (!$skipShare) {
                $this->factoryResetService->executeFactoryReset($recipient);
            }
        }

        // Idempotency guard
        if (!$force && !empty($this->accountService->findAll($owner))) {
            $output->writeln("<error>Owner '{$owner}' already has accounts. Re-run with --wipe (reset first) or --force (add anyway).</error>");
            return Command::FAILURE;
        }

        $output->writeln("Seeding owner profile for <info>{$owner}</info> (base {$baseCurrency})…");
        $ownerData = $this->seedOwner($output, $owner, $baseCurrency);

        if (!$skipShare) {
            $output->writeln("Seeding recipient profile for <info>{$recipient}</info>…");
            $this->seedRecipient($output, $recipient, $baseCurrency);

            $output->writeln('Wiring cross-user sharing…');
            $this->wireSharing($output, $owner, $recipient, $ownerData);
        }

        $output->writeln('<info>Done.</info> Open the Budget app as each user to explore the dataset.');
        return Command::SUCCESS;
    }

    // ==========================================================
    // Owner profile
    // ==========================================================

    /**
     * @return array{accountIds: array<string,int>, categoryIds: array<string,int>, holidayGoalId: int}
     */
    private function seedOwner(OutputInterface $out, string $u, string $base): array {
        $this->settingService->set($u, 'default_currency', $base);
        $this->seedExchangeRates($out, $u, $base);

        // --- Accounts (multi-currency) ---
        $acct = [
            'checking' => $this->accountService->create($u, 'Main Checking', 'checking', 0.0, 'USD', 'Globex Bank')->getId(),
            'savings'  => $this->accountService->create($u, 'Euro Savings', 'savings', 0.0, 'EUR', 'Banque Centrale')->getId(),
            'uk'       => $this->accountService->create($u, 'UK Current Account', 'checking', 0.0, 'GBP', 'Stark Bank UK')->getId(),
            'card'     => $this->accountService->create($u, 'Rewards Credit Card', 'credit_card', 0.0, 'USD', 'Globex Bank')->getId(),
            'btc'      => $this->accountService->create($u, 'Bitcoin Wallet', 'cryptocurrency', 0.0, 'BTC', 'Coinbase')->getId(),
        ];
        $out->writeln('  · 5 accounts (USD/EUR/GBP/BTC)');

        // --- Categories + budgets (full default tree) ---
        $created = $this->categoryService->createDefaultCategories($u, 3600.0);
        $cat = [];
        foreach ($created as $c) {
            $cat[$c->getName()] = $c->getId();
        }
        $out->writeln('  · ' . count($created) . ' categories with budgets');

        // --- Transactions ---
        // Row: [acctKey, date, description, signedAmount, categoryName|null, vendor|null, ref|null, note|null, key|null]
        $rows = [
            // Main Checking (USD)
            ['checking', '2026-03-01', 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-2603', null, null],
            ['checking', '2026-03-03', 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-03', null, null],
            ['checking', '2026-03-05', 'Weekly groceries', -92.40, 'Groceries', 'Greenleaf Market', null, null, null],
            ['checking', '2026-03-08', 'Electricity and gas', -138.75, 'Utilities', 'PowerCo', null, null, null],
            ['checking', '2026-03-15', 'Transfer to Euro Savings', -500.00, null, null, 'TRF-03', 'Cross-currency transfer out', 'xferOut'],
            ['checking', '2026-03-22', 'Freelance project', 650.00, 'Freelance', 'Initech', 'INV-204', null, null],
            ['checking', '2026-04-01', 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-2604', null, null],
            ['checking', '2026-04-03', 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-04', null, null],
            ['checking', '2026-04-06', 'Grocery run (bulk)', -120.00, 'Groceries', 'Costco', null, null, 'splitTx'],
            ['checking', '2026-04-09', 'Monthly transit pass', -60.00, 'Public Transit', 'Metro Transit', null, null, null],
            ['checking', '2026-04-18', 'Cinema tickets', -27.00, 'Movies/Shows', 'Odeon', null, null, null],
            ['checking', '2026-04-30', 'Credit card payment', -250.00, null, null, 'PMT-04', 'Payment to card', 'payOut'],
            ['checking', '2026-05-01', 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-2605', null, null],
            ['checking', '2026-05-03', 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-05', null, null],
            ['checking', '2026-05-07', 'Weekly groceries', -88.90, 'Groceries', 'Costco', null, null, 'tagTx'],
            ['checking', '2026-05-18', 'Dinner with Sam', -90.00, 'Dining Out', 'Sakura Sushi', null, 'Split 50/50 with Sam', 'shareTx'],
            ['checking', '2026-06-01', 'Monthly salary', 3200.00, 'Salary', 'Globex Payroll', 'PAY-2606', null, null],
            ['checking', '2026-06-03', 'Apartment rent', -1450.00, 'Rent/Mortgage', 'Citywide Lettings', 'RENT-06', null, null],
            ['checking', '2026-06-05', 'Weekly groceries', -79.55, 'Groceries', 'Greenleaf Market', null, null, null],

            // Euro Savings (EUR)
            ['savings', '2026-03-15', 'Transfer in from checking', 460.00, null, null, 'TRF-03', 'Cross-currency transfer in', 'xferIn'],
            ['savings', '2026-03-31', 'Monthly interest', 3.18, 'Other Income', 'Banque Centrale', null, null, null],
            ['savings', '2026-04-30', 'Monthly interest', 3.41, 'Other Income', 'Banque Centrale', null, null, null],
            ['savings', '2026-05-31', 'Monthly interest', 3.74, 'Other Income', 'Banque Centrale', null, null, null],

            // UK Current Account (GBP)
            ['uk', '2026-03-14', 'Clothing', -64.99, 'Clothing', 'Marks & Spencer', null, null, null],
            ['uk', '2026-04-19', 'Train to London', -48.20, 'Public Transit', 'National Rail', null, null, null],
            ['uk', '2026-05-23', 'Bookshop', -31.45, 'Movies/Shows', 'Waterstones', null, null, null],

            // Rewards Credit Card (USD)
            ['card', '2026-03-11', 'Online order', -89.99, 'Electronics', 'Amazon', null, null, null],
            ['card', '2026-03-16', 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', '2026-04-16', 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', '2026-04-27', 'Prescription', -58.00, 'Prescriptions', 'WellCare Pharmacy', null, null, null],
            ['card', '2026-04-30', 'Card payment received', 250.00, null, null, 'PMT-04', 'Payment from checking', 'payIn'],
            ['card', '2026-05-10', 'New laptop', -220.00, 'Electronics', 'Currys PC World', null, 'Saving towards this', 'laptopTx'],
            ['card', '2026-05-16', 'Streaming subscription', -12.99, 'Streaming Services', 'Netflix', null, null, null],
            ['card', '2026-05-29', 'Concert tickets', -120.00, 'Movies/Shows', 'TicketHub', null, null, null],

            // Bitcoin Wallet (BTC)
            ['btc', '2026-03-18', 'Bought Bitcoin', 0.01500000, 'Investment', 'Coinbase', null, 'DCA purchase', null],
            ['btc', '2026-04-18', 'Bought Bitcoin', 0.01200000, 'Investment', 'Coinbase', null, 'DCA purchase', null],
            ['btc', '2026-05-12', 'Sold Bitcoin', -0.00500000, 'Investment', 'Coinbase', null, 'Took some profit', null],
        ];

        $tx = [];
        foreach ($rows as $r) {
            [$ak, $date, $desc, $amt, $catName, $vendor, $ref, $note, $key] = $r;
            $type = $amt < 0 ? 'debit' : 'credit';
            $catId = ($catName !== null && isset($cat[$catName])) ? $cat[$catName] : null;
            $created = $this->transactionService->create(
                $u, $acct[$ak], $date, $desc, abs($amt), $type, $catId, $vendor, $ref, $note
            );
            if ($key !== null) {
                $tx[$key] = $created;
            }
        }
        $out->writeln('  · ' . count($rows) . ' transactions');

        // --- Transfers (link the two legs) ---
        try {
            $this->transactionService->linkTransactions($tx['payOut']->getId(), $tx['payIn']->getId(), $u);
        } catch (\Throwable $e) {
            $out->writeln('    (skip same-currency transfer link: ' . $e->getMessage() . ')');
        }
        try {
            $this->transactionService->linkTransactions($tx['xferOut']->getId(), $tx['xferIn']->getId(), $u);
        } catch (\Throwable $e) {
            $out->writeln('    (skip cross-currency transfer link: ' . $e->getMessage() . ')');
        }
        $out->writeln('  · 2 transfers linked (same- and cross-currency)');

        // --- Split a transaction ---
        try {
            $this->splitService->splitTransaction($tx['splitTx']->getId(), $u, [
                ['categoryId' => $cat['Groceries'] ?? null, 'amount' => 80.00, 'description' => 'Food'],
                ['categoryId' => $cat['Home Goods'] ?? ($cat['Shopping'] ?? null), 'amount' => 40.00, 'description' => 'Household'],
            ]);
            $out->writeln('  · 1 split transaction');
        } catch (\Throwable $e) {
            $out->writeln('    (skip split: ' . $e->getMessage() . ')');
        }

        // --- Tags: a category tag set + a global tag ---
        $laptopTagId = null;
        try {
            $tagSet = $this->tagSetService->create($u, $cat['Groceries'], 'Store', 'Where the shop happened');
            $costco = $this->tagSetService->createTag($tagSet->getId(), $u, 'Costco');
            $this->tagSetService->createTag($tagSet->getId(), $u, 'Whole Foods');
            $this->transactionTagService->setTransactionTags($tx['tagTx']->getId(), $u, [$costco->getId()]);

            $laptopTag = $this->tagSetService->createGlobalTag($u, 'Laptop Fund');
            $laptopTagId = $laptopTag->getId();
            $this->transactionTagService->setTransactionTags($tx['laptopTx']->getId(), $u, [$laptopTagId]);
            $out->writeln('  · tag set + global tag applied');
        } catch (\Throwable $e) {
            $out->writeln('    (skip tags: ' . $e->getMessage() . ')');
        }

        // --- Bills ---
        $this->billService->create($u, 'Apartment Rent', 1450.0, 'monthly', 3, null, $cat['Rent/Mortgage'] ?? null, $acct['checking'], 'rent', 'Apartment rent', null, 3);
        $this->billService->create($u, 'Netflix', 12.99, 'monthly', 16, null, $cat['Streaming Services'] ?? null, $acct['card'], 'netflix', 'Streaming subscription', null, 3);
        $this->billService->create($u, 'Home Internet', 45.0, 'monthly', 20, null, $cat['Utilities'] ?? null, $acct['checking'], 'internet', 'Broadband', null, 3);
        $out->writeln('  · 3 bills');

        // --- Recurring income ---
        $this->incomeService->create($u, 'Salary', 3200.0, 'monthly', 1, null, $cat['Salary'] ?? null, $acct['checking'], 'Globex', 'salary');
        $out->writeln('  · 1 recurring income');

        // --- Savings goals (one tag-linked, one shared) ---
        $this->goalsService->create($u, 'Emergency Fund', 10000.0, null, 3500.0, '3 months of expenses', null, null, $acct['savings'], '#22c55e');
        $holiday = $this->goalsService->create($u, 'Group Holiday 2026', 2000.0, null, 750.0, 'Shared trip with Sam', '2026-12-01', null, null, '#3b82f6');
        if ($laptopTagId !== null) {
            $this->goalsService->create($u, 'New Laptop', 1500.0, null, 0.0, 'Auto-tracked via Laptop Fund tag', null, $laptopTagId, null, '#a855f7');
        }
        $out->writeln('  · ' . ($laptopTagId !== null ? 3 : 2) . ' savings goals');

        // --- Pension ---
        try {
            $pension = $this->pensionService->create($u, 'Workplace Pension', 'workplace', 'Aviva', $base, 42000.0, 350.0, 0.06, 67, null, null);
            $this->pensionService->createContribution($pension->getId(), $u, 350.0, '2026-04-30', 'April contribution');
            $this->pensionService->createContribution($pension->getId(), $u, 350.0, '2026-05-31', 'May contribution');
            $this->pensionService->createSnapshot($pension->getId(), $u, 43200.0, '2026-05-31');
            $out->writeln('  · 1 pension (+contributions, snapshot)');
        } catch (\Throwable $e) {
            $out->writeln('    (skip pension: ' . $e->getMessage() . ')');
        }

        // --- Assets ---
        try {
            $this->assetService->create($u, 'Family Home', 'real_estate', 'Primary residence', $base, 320000.0, 280000.0, '2019-06-01', 0.04);
            $this->assetService->create($u, 'Car', 'vehicle', 'Daily driver', $base, 18000.0, 26000.0, '2022-03-15', -0.12);
            $out->writeln('  · 2 assets');
        } catch (\Throwable $e) {
            $out->writeln('    (skip assets: ' . $e->getMessage() . ')');
        }

        // --- Net worth history ---
        foreach (['2026-03-31', '2026-04-30', '2026-05-31', date('Y-m-d')] as $d) {
            try {
                $this->netWorthService->createSnapshot($u, NetWorthSnapshot::SOURCE_AUTO, $d);
            } catch (\Throwable $e) {
                // best effort
            }
        }
        $out->writeln('  · net-worth snapshots');

        // --- Shared expenses (split with a contact, then settle) ---
        try {
            $contact = $this->sharedExpenseService->createContact($u, 'Sam Chen', null, null);
            $this->sharedExpenseService->splitFiftyFifty($u, $tx['shareTx']->getId(), $contact->getId(), 'Dinner split 50/50');
            $this->sharedExpenseService->recordSettlement($u, $contact->getId(), 45.0, '2026-05-25', 'Sam repaid half of dinner', 'USD');
            $out->writeln('  · shared expense (split + settlement)');
        } catch (\Throwable $e) {
            $out->writeln('    (skip shared expense: ' . $e->getMessage() . ')');
        }

        return [
            'accountIds' => $acct,
            'categoryIds' => $cat,
            'holidayGoalId' => $holiday->getId(),
        ];
    }

    // ==========================================================
    // Recipient profile (lighter)
    // ==========================================================

    private function seedRecipient(OutputInterface $out, string $u, string $base): void {
        $this->settingService->set($u, 'default_currency', $base);

        $checking = $this->accountService->create($u, 'Sam Checking', 'checking', 0.0, 'USD', 'Wayne Bank')->getId();
        $travel = $this->accountService->create($u, 'Sam Travel Card', 'checking', 0.0, 'EUR', 'Wayne Bank')->getId();

        $created = $this->categoryService->createDefaultCategories($u, 2750.0);
        $cat = [];
        foreach ($created as $c) {
            $cat[$c->getName()] = $c->getId();
        }

        $rows = [
            [$checking, '2026-03-01', 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, '2026-03-04', 'Rent share', -725.00, 'Rent/Mortgage', 'Citywide Lettings'],
            [$checking, '2026-03-13', 'Gym membership', -39.00, 'Doctor Visits', 'FlexFit'],
            [$checking, '2026-04-01', 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, '2026-04-04', 'Rent share', -725.00, 'Rent/Mortgage', 'Citywide Lettings'],
            [$checking, '2026-04-09', 'Weekly groceries', -83.15, 'Groceries', 'Greenleaf Market'],
            [$checking, '2026-05-01', 'Monthly salary', 2750.00, 'Salary', 'Wayne Enterprises'],
            [$checking, '2026-05-25', 'Repaid by Alex', 45.00, 'Other Income', 'Alex Rivera'],
            [$travel, '2026-04-21', 'Flights for trip', -310.00, 'Other Income', 'SkyHigh Air'],
            [$travel, '2026-05-02', 'Car hire', -95.50, 'Public Transit', 'EuroCar'],
        ];
        foreach ($rows as [$aid, $date, $desc, $amt, $catName, $vendor]) {
            $type = $amt < 0 ? 'debit' : 'credit';
            $catId = $cat[$catName] ?? null;
            $this->transactionService->create($u, $aid, $date, $desc, abs($amt), $type, $catId, $vendor);
        }

        $this->goalsService->create($u, 'New Bike', 800.0, null, 120.0, null, '2026-10-01', null, null, '#f59e0b');
        $out->writeln('  · 2 accounts, ' . count($rows) . ' transactions, 1 goal');
    }

    // ==========================================================
    // Cross-user sharing
    // ==========================================================

    /**
     * @param array{accountIds: array<string,int>, categoryIds: array<string,int>, holidayGoalId: int} $ownerData
     */
    private function wireSharing(OutputInterface $out, string $owner, string $recipient, array $ownerData): void {
        $share = $this->ensureAcceptedShare($owner, $recipient);

        // Share the owner's bank accounts (read-only)
        $accountIds = array_values($ownerData['accountIds']);
        $this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_ACCOUNT, $accountIds, ShareItem::PERMISSION_READ);

        // Share a few categories (read-only) so reports line up
        $catIds = array_values(array_filter([
            $ownerData['categoryIds']['Groceries'] ?? null,
            $ownerData['categoryIds']['Rent/Mortgage'] ?? null,
            $ownerData['categoryIds']['Dining Out'] ?? null,
        ]));
        if (!empty($catIds)) {
            $this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_CATEGORY, $catIds, ShareItem::PERMISSION_READ);
        }

        // Share the holiday savings goal — with write access (the headline feature)
        $this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_SAVINGS_GOAL, [$ownerData['holidayGoalId']], ShareItem::PERMISSION_WRITE);

        $out->writeln("  · '{$owner}' → '{$recipient}': budget share accepted; " . count($accountIds) . ' accounts (read), 1 savings goal (write)');
    }

    /**
     * Create-or-reuse an accepted share between owner and recipient.
     */
    private function ensureAcceptedShare(string $owner, string $recipient): Share {
        try {
            $share = $this->shareService->shareWith($owner, $recipient);
        } catch (\Throwable $e) {
            // A share already exists — find it among the owner's outgoing shares
            $share = null;
            foreach ($this->shareService->getOutgoingShares($owner) as $existing) {
                if ($existing->getSharedWithUserId() === $recipient) {
                    $share = $existing;
                    break;
                }
            }
            if ($share === null) {
                throw $e;
            }
        }

        if ($share->getStatus() !== Share::STATUS_ACCEPTED) {
            $share = $this->shareService->accept($share->getId(), $recipient);
        }
        return $share;
    }

    // ==========================================================
    // Helpers
    // ==========================================================

    /**
     * Best-effort manual exchange rates so multi-currency net worth converts.
     * Requires the owner's base-currency ECB rate to be available locally
     * (unless base is EUR); otherwise this is silently skipped.
     */
    private function seedExchangeRates(OutputInterface $out, string $u, string $base): void {
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
            $out->writeln("  · {$seeded} manual exchange rates");
        } else {
            $out->writeln('  · exchange rates skipped (run the ExchangeRateUpdateJob, or set base currency to EUR, for conversions)');
        }
    }
}
