<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MoneyCalculator;
use OCP\AppFramework\Db\DoesNotExistException;

class AccountService {
    private AccountMapper $mapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        AccountMapper $mapper,
        TransactionMapper $transactionMapper
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Account {
        return $this->mapper->find($id, $userId);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
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
        ?float $overdraftLimit = null
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
        $account->setCreatedAt(date('Y-m-d H:i:s'));
        $account->setUpdatedAt(date('Y-m-d H:i:s'));
        
        return $this->mapper->insert($account);
    }

    public function update(int $id, string $userId, array $updates): Account {
        $account = $this->find($id, $userId);

        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            // Use is_callable() instead of method_exists() to support magic methods
            // The Entity parent class uses __call() for getters/setters
            if (is_callable([$account, $setter])) {
                $account->$setter($value);
            }
        }

        $account->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->mapper->update($account);
    }

    public function delete(int $id, string $userId): void {
        $account = $this->find($id, $userId);
        
        // Check if account has transactions
        $transactions = $this->transactionMapper->findByAccount($id, 1);
        if (!empty($transactions)) {
            throw new \Exception('Cannot delete account with existing transactions');
        }
        
        $this->mapper->delete($account);
    }

    public function getSummary(string $userId): array {
        $accounts = $this->findAll($userId);
        $totalBalance = '0.00';
        $currencyBreakdown = [];

        foreach ($accounts as $account) {
            $balance = (string) $account->getBalance();
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
            'accounts' => $accounts,
            'totalBalance' => MoneyCalculator::toFloat($totalBalance),
            'currencyBreakdown' => $currencyBreakdownFloat,
            'accountCount' => count($accounts)
        ];
    }

    public function getBalanceHistory(int $accountId, string $userId, int $days = 30): array {
        $account = $this->find($accountId, $userId);
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $transactions = $this->transactionMapper->findByDateRange(
            $accountId,
            $startDate,
            $endDate
        );

        $balance = (string) $account->getBalance();
        $history = [];

        // Work backwards from current balance
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayTransactions = array_filter($transactions, function($t) use ($date) {
                return $t->getDate() === $date;
            });

            foreach ($dayTransactions as $transaction) {
                $amount = (string) $transaction->getAmount();
                if ($transaction->getType() === 'credit') {
                    $balance = MoneyCalculator::subtract($balance, $amount);
                } else {
                    $balance = MoneyCalculator::add($balance, $amount);
                }
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