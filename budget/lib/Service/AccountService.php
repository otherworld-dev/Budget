<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\Entity;

/**
 * @extends AbstractCrudService<Account>
 */
class AccountService extends AbstractCrudService {
    private TransactionMapper $transactionMapper;

    public function __construct(
        AccountMapper $mapper,
        TransactionMapper $transactionMapper
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
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
        ?float $minimumPayment = null
    ): Account {
        $account = new Account();
        $account->setUserId($userId);
        $account->setName($name);
        $account->setType($type);
        $account->setBalance($balance);
        $account->setCurrency($currency);
        $account->setInstitution($institution);
        $account->setAccountNumber($accountNumber);
        $account->setRoutingNumber($routingNumber);
        $account->setSortCode($sortCode);
        $account->setIban($iban);
        $account->setSwiftBic($swiftBic);
        $account->setAccountHolderName($accountHolderName);
        $account->setOpeningDate($openingDate);
        $account->setInterestRate($interestRate);
        $account->setCreditLimit($creditLimit);
        $account->setOverdraftLimit($overdraftLimit);
        $account->setMinimumPayment($minimumPayment);
        $this->setTimestamps($account, true);

        return $this->mapper->insert($account);
    }

    /**
     * @inheritDoc
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        // Check if account has transactions
        $transactions = $this->transactionMapper->findByAccount($entity->getId(), 1);
        if (!empty($transactions)) {
            throw new \Exception('Cannot delete account with existing transactions');
        }
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

        return $accountData;
    }

    /**
     * Get all accounts with balances adjusted to exclude future transactions.
     * Returns accounts as arrays with balance reflecting today's actual balance.
     *
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch accounts for (for shared budgets)
     * @return array[] Array of account data arrays
     */
    public function findAllWithCurrentBalances(string $userId, ?array $accessibleUserIds = null): array {
        $accounts = $this->findAll($userId, $accessibleUserIds);

        // Get future transaction adjustments for all accounts in one query
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today, $accessibleUserIds);

        $result = [];
        foreach ($accounts as $account) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = (string) $account->getBalance();
            $futureChange = (string) ($futureChanges[$account->getId()] ?? 0);
            $balance = MoneyCalculator::subtract($storedBalance, $futureChange);

            // Convert account to array and override balance with adjusted value
            $accountData = $account->toArrayMasked();
            $accountData['balance'] = MoneyCalculator::toFloat($balance);
            $accountData['userId'] = $account->getUserId(); // Include userId for ownership checking
            $result[] = $accountData;
        }

        return $result;
    }

    public function getSummary(string $userId): array {
        $accounts = $this->findAll($userId);
        $totalBalance = '0.00';
        $currencyBreakdown = [];
        $accountsWithAdjustedBalance = [];

        // Get future transaction adjustments for all accounts in one query
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        foreach ($accounts as $account) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = (string) $account->getBalance();
            $futureChange = (string) ($futureChanges[$account->getId()] ?? 0);
            $balance = MoneyCalculator::subtract($storedBalance, $futureChange);
            $balanceFloat = MoneyCalculator::toFloat($balance);

            // Convert account to array and override balance with adjusted value
            $accountData = $account->toArrayMasked();
            $accountData['balance'] = $balanceFloat;
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

    public function reconcile(int $accountId, string $userId, float $statementBalance): array {
        $account = $this->find($accountId, $userId);
        $currentBalance = (string) $account->getBalance();
        $statementBalanceStr = (string) $statementBalance;
        $difference = MoneyCalculator::subtract($statementBalanceStr, $currentBalance);

        return [
            'currentBalance' => MoneyCalculator::toFloat($currentBalance),
            'statementBalance' => $statementBalance,
            'difference' => MoneyCalculator::toFloat($difference),
            'isBalanced' => MoneyCalculator::equals($currentBalance, $statementBalanceStr, '0.01')
        ];
    }
}
