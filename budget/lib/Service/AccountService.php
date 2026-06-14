<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\GranularShareService;
use OCP\AppFramework\Db\Entity;
use OCP\IL10N;

/**
 * @extends AbstractCrudService<Account>
 */
class AccountService extends AbstractCrudService {
    private TransactionMapper $transactionMapper;
    private InterestRateMapper $interestRateMapper;
    private CurrencyConversionService $conversionService;
    private GranularShareService $granularShareService;
    private IL10N $l;

    public function __construct(
        AccountMapper $mapper,
        TransactionMapper $transactionMapper,
        InterestRateMapper $interestRateMapper,
        CurrencyConversionService $conversionService,
        GranularShareService $granularShareService,
        IL10N $l
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
        $this->interestRateMapper = $interestRateMapper;
        $this->conversionService = $conversionService;
        $this->granularShareService = $granularShareService;
        $this->l = $l;
    }

    public function create(
        string $userId,
        string $name,
        string $type,
        float $balance = 0.0,
        string $currency = 'USD',
        ?string $institution = null,
        ?string $accountNumber = null,
        ?string $routingNumber = null,
        ?string $sortCode = null,
        ?string $iban = null,
        ?string $swiftBic = null,
        ?string $accountHolderName = null,
        ?string $openingDate = null,
        ?float $interestRate = null,
        ?float $creditLimit = null,
        ?float $overdraftLimit = null,
        ?float $minimumPayment = null,
        ?string $walletAddress = null,
        bool $excludedFromReports = false
    ): Account {
        $account = new Account();
        $account->setUserId($userId);
        $account->setName($name);
        $account->setType($type);
        $account->setBalance($balance);
        $account->setOpeningBalance($balance);
        $account->setCurrency($currency);
        $account->setInstitution($institution);
        $account->setAccountNumber($accountNumber);
        $account->setRoutingNumber($routingNumber);
        $account->setSortCode($sortCode);
        $account->setIban($iban);
        $account->setSwiftBic($swiftBic);
        $account->setWalletAddress($walletAddress);
        $account->setAccountHolderName($accountHolderName);
        $account->setOpeningDate($openingDate);
        $account->setInterestRate($interestRate);
        $account->setCreditLimit($creditLimit);
        $account->setOverdraftLimit($overdraftLimit);
        $account->setMinimumPayment($minimumPayment);
        $account->setExcludedFromReports($excludedFromReports);
        $this->setTimestamps($account, true);

        return $this->mapper->insert($account);
    }

    /**
     * @inheritDoc
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        // Check if account has transactions
        $transactions = $this->transactionMapper->findByAccount($entity->getId(), $userId, 1);
        if (!empty($transactions)) {
            throw new \Exception($this->l->t('Cannot delete account with existing transactions. Please delete all transactions first.'));
        }
        // Clean up interest rate records
        $this->interestRateMapper->deleteByAccount($entity->getId(), $userId);
    }

    /**
     * Find an account by ID without user scoping.
     * Used for shared account access after permission has been verified.
     */
    public function findById(int $id): Entity {
        return $this->mapper->findById($id);
    }

    /**
     * Override update to support shared accounts.
     * If the account doesn't belong to the acting user, look it up by ID
     * and use the actual owner's userId for the update.
     */
    public function update(int $id, string $userId, array $updates): Entity {
        // Try owner lookup first; fall back to ID-only for shared accounts
        try {
            $this->find($id, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Account not owned by this user — resolve the actual owner
            $account = $this->mapper->findById($id);
            $userId = $account->getUserId();
        }

        $account = parent::update($id, $userId, $updates);

        if (isset($updates['openingBalance'])) {
            $openingBalance = (string) ($account->getOpeningBalance() ?? 0);
            $newBalance = MoneyCalculator::add($openingBalance, $this->transactionMapper->getNetChangeAll($id));

            $this->mapper->updateBalance($id, $newBalance, $userId);
            $account = $this->find($id, $userId);
        }

        return $account;
    }

    /**
     * Get a single account with balance adjusted to exclude future transactions.
     *
     * @return array Account data array with adjusted balance
     */
    public function findWithCurrentBalance(int $id, string $userId): array {
        $account = $this->find($id, $userId);

        // Get future transaction adjustment for this account
        $today = date('Y-m-d');
        $futureChange = $this->transactionMapper->getNetChangeAfterDate($id, $today);

        // Calculate balance as of today (stored balance minus future transactions)
        $storedBalance = (string) $account->getBalance();
        $balance = MoneyCalculator::subtract($storedBalance, (string) $futureChange);

        // Convert account to array and override balance with adjusted value
        $accountData = $account->toArrayMasked();
        $accountData['balance'] = MoneyCalculator::toFloat($balance);
        $accountData['storedBalance'] = MoneyCalculator::toFloat($storedBalance);

        // Add fiat equivalent for non-base-currency accounts
        $baseCurrency = $this->conversionService->getBaseCurrency($userId);
        $this->addConvertedBalance($accountData, MoneyCalculator::toFloat($balance), $account->getCurrency(), $baseCurrency, $userId);

        return $accountData;
    }

    /**
     * Get all accounts with balances adjusted to exclude future transactions.
     * Returns accounts as arrays with balance reflecting today's actual balance.
     *
     * @return array[] Array of account data arrays
     */
    public function findAllWithCurrentBalances(string $userId): array {
        $accounts = $this->findAll($userId);

        // Get future transaction adjustments for all accounts in one query
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $baseCurrency = $this->conversionService->getBaseCurrency($userId);

        $result = [];
        foreach ($accounts as $account) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = (string) $account->getBalance();
            $futureChange = (string) ($futureChanges[$account->getId()] ?? 0);
            $balance = MoneyCalculator::subtract($storedBalance, $futureChange);

            // Convert account to array and override balance with adjusted value
            $accountData = $account->toArrayMasked();
            $balanceFloat = MoneyCalculator::toFloat($balance);
            $accountData['balance'] = $balanceFloat;

            // Add fiat equivalent for non-base-currency accounts
            $this->addConvertedBalance($accountData, $balanceFloat, $account->getCurrency(), $baseCurrency, $userId);

            $result[] = $accountData;
        }

        return $result;
    }

    /**
     * Get specific accounts by IDs with masked data.
     * Used for fetching shared accounts that belong to another user.
     *
     * @param int[] $accountIds
     * @return array[] Array of account data arrays
     */
    public function findByIdsAsArrays(array $accountIds): array {
        if (empty($accountIds)) {
            return [];
        }

        /** @var AccountMapper $mapper */
        $mapper = $this->mapper;
        $accounts = $mapper->findByIds($accountIds);

        $result = [];
        foreach ($accounts as $account) {
            $accountData = $account->toArrayMasked();
            $accountData['_shared'] = true;
            $result[] = $accountData;
        }

        return $result;
    }

    /**
     * Add convertedBalance and baseCurrency to an account data array
     * when the account's currency differs from the user's base currency.
     */
    private function addConvertedBalance(array &$accountData, float $balance, ?string $currency, string $baseCurrency, string $userId): void {
        $currency = $currency ?: 'USD';

        if (strtoupper($currency) === strtoupper($baseCurrency)) {
            return;
        }

        if (!$this->conversionService->canConvert($currency, $userId)) {
            return;
        }

        $accountData['convertedBalance'] = $this->conversionService->convertToBaseFloat($balance, $currency, $userId);
        $accountData['baseCurrency'] = $baseCurrency;
    }

    public function getSummary(string $userId): array {
        $accounts = $this->findAll($userId);

        // Include shared accounts
        $sharedAccountIds = $this->granularShareService->getSharedAccountIds($userId);
        if (!empty($sharedAccountIds)) {
            $sharedAccounts = $this->mapper->findByIds($sharedAccountIds);
            $accounts = array_merge($accounts, $sharedAccounts);
        }

        // The summary feeds the dashboard / overview totals, so drop accounts the
        // user flagged out of aggregations (#286). The account itself stays
        // visible on the accounts page (AccountController::index keeps it).
        $accounts = array_values(array_filter($accounts, static fn($a) => !$a->getExcludedFromReports()));

        $totalBalance = '0.00';
        $currencyBreakdown = [];
        $accountsWithAdjustedBalance = [];

        // Get future transaction adjustments for owned accounts
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        // Get future transaction adjustments for shared accounts
        if (!empty($sharedAccountIds)) {
            $sharedFutureChanges = $this->transactionMapper->getNetChangeAfterDateForAccounts($sharedAccountIds, $today);
            $futureChanges = $futureChanges + $sharedFutureChanges;
        }

        foreach ($accounts as $account) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = (string) $account->getBalance();
            $futureChange = (string) ($futureChanges[$account->getId()] ?? 0);
            $balance = MoneyCalculator::subtract($storedBalance, $futureChange);
            $balanceFloat = MoneyCalculator::toFloat($balance);

            // Convert account to array and override balance with adjusted value
            $accountData = $account->toArrayMasked();
            $accountData['balance'] = $balanceFloat;
            if (in_array($account->getId(), $sharedAccountIds ?? [], true)) {
                $accountData['_shared'] = true;
            }
            $accountsWithAdjustedBalance[] = $accountData;

            $totalBalance = MoneyCalculator::add($totalBalance, $balance);
            $currency = $account->getCurrency();

            if (!isset($currencyBreakdown[$currency])) {
                $currencyBreakdown[$currency] = '0.00';
            }
            $currencyBreakdown[$currency] = MoneyCalculator::add($currencyBreakdown[$currency], $balance);
        }

        // Convert back to float for API response compatibility
        $currencyBreakdownFloat = [];
        foreach ($currencyBreakdown as $currency => $amount) {
            $currencyBreakdownFloat[$currency] = MoneyCalculator::toFloat($amount);
        }

        return [
            'accounts' => $accountsWithAdjustedBalance,
            'totalBalance' => MoneyCalculator::toFloat($totalBalance),
            'currencyBreakdown' => $currencyBreakdownFloat,
            'accountCount' => count($accounts)
        ];
    }

    /**
     * Get balance history for an account over a number of days.
     * OPTIMIZED: Uses aggregated SQL query instead of O(days × transactions) algorithm.
     */
    public function getBalanceHistory(int $accountId, string $userId, int $days = 30): array {
        $account = $this->find($accountId, $userId);
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // Single aggregated query for daily balance changes
        $dailyChanges = $this->transactionMapper->getDailyBalanceChanges($accountId, $startDate, $endDate);

        $balance = (string) $account->getBalance();
        $history = [];

        // Work backwards from current balance - O(days) instead of O(days × transactions)
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));

            // Reverse the day's net change to get the balance at start of day
            if (isset($dailyChanges[$date])) {
                $netChange = (string) $dailyChanges[$date];
                $balance = MoneyCalculator::subtract($balance, $netChange);
            }

            $history[] = [
                'date' => $date,
                'balance' => MoneyCalculator::toFloat($balance)
            ];
        }

        return array_reverse($history);
    }

    /**
     * Whole-account overview metrics for the account-detail tiles (#285):
     * total transaction count, this month's income and expenses, and the
     * average transaction amount. Computed server-side over the whole account
     * so the values no longer reflect only the currently displayed page.
     *
     * @return array{totalTransactions: int, thisMonthIncome: float, thisMonthExpenses: float, avgTransaction: float}
     */
    public function getAccountMetrics(int $accountId, string $userId): array {
        // Access check (throws if the account is not owned by / shared with the user)
        $this->find($accountId, $userId);

        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $metrics = $this->transactionMapper->getAccountMetrics($accountId, $monthStart, $monthEnd);

        return [
            'totalTransactions' => $metrics['count'],
            'thisMonthIncome' => $metrics['monthIncome'],
            'thisMonthExpenses' => $metrics['monthExpenses'],
            'avgTransaction' => $metrics['average'],
        ];
    }

    public function reconcile(int $accountId, string $userId, float $statementBalance, ?string $statementDate = null): array {
        $account = $this->find($accountId, $userId);

        // Calculate balance as of the statement date (excluding transactions after that date).
        // If no date provided, use today.
        $asOfDate = $statementDate ?: date('Y-m-d');
        $futureChange = $this->transactionMapper->getNetChangeAfterDate($accountId, $asOfDate);
        $storedBalance = (string) $account->getBalance();
        $currentBalance = MoneyCalculator::subtract($storedBalance, (string) $futureChange);

        $statementBalanceStr = (string) $statementBalance;
        $difference = MoneyCalculator::subtract($statementBalanceStr, $currentBalance);

        return [
            'currentBalance' => MoneyCalculator::toFloat($currentBalance),
            'statementBalance' => $statementBalance,
            'difference' => MoneyCalculator::toFloat($difference),
            'isBalanced' => MoneyCalculator::equals($currentBalance, $statementBalanceStr, '0.01')
        ];
    }

    /**
     * Complete reconciliation: mark transactions as reconciled and update lastReconciled.
     */
    public function completeReconciliation(int $accountId, string $userId, array $transactionIds): array {
        $account = $this->find($accountId, $userId);

        // Mark transactions as reconciled
        $reconciled = 0;
        if (!empty($transactionIds)) {
            $reconciled = $this->transactionMapper->bulkSetReconciled($accountId, $transactionIds, true);
        }

        // Update lastReconciled timestamp on account
        $now = date('Y-m-d H:i:s');
        $account->setLastReconciled($now);
        $account->setUpdatedAt($now);
        $this->mapper->update($account);

        return [
            'reconciledCount' => $reconciled,
            'lastReconciled' => $now,
        ];
    }

    /**
     * Recalculate all account balances from opening_balance + transaction history.
     *
     * @return array{updated: int, accounts: array}
     */
    public function recalculateAllBalances(string $userId): array {
        $accounts = $this->findAll($userId);
        $updatedAccounts = [];
        $updatedCount = 0;

        foreach ($accounts as $account) {
            $accountId = $account->getId();
            $oldBalance = (string) $account->getBalance();
            $openingBalance = (string) ($account->getOpeningBalance() ?? 0);

            // new_balance = opening_balance + net transaction effect
            $newBalance = MoneyCalculator::add($openingBalance, $this->transactionMapper->getNetChangeAll($accountId));

            $diff = MoneyCalculator::subtract($newBalance, $oldBalance);
            $changed = !MoneyCalculator::equals($newBalance, $oldBalance, '0.005');

            if ($changed) {
                $this->mapper->updateBalance($accountId, $newBalance, $userId);
                $updatedCount++;
            }

            $updatedAccounts[] = [
                'id' => $accountId,
                'name' => $account->getName(),
                'oldBalance' => MoneyCalculator::toFloat($oldBalance),
                'newBalance' => MoneyCalculator::toFloat($newBalance),
                'difference' => MoneyCalculator::toFloat($diff),
                'changed' => $changed,
            ];
        }

        return [
            'updated' => $updatedCount,
            'total' => count($accounts),
            'accounts' => $updatedAccounts,
        ];
    }
}
