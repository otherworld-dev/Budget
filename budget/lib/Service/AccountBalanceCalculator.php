<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\Currency;

/**
 * The one place an account's balance is derived from its ledger:
 * opening_balance + net(non-scheduled transactions), computed with bcmath at
 * the account currency's precision so a crypto balance keeps its 8dp (#331).
 *
 * Every recompute routes through here; hand-copied versions of this sum drifted
 * (the scheduled-transaction job rounded crypto balances to 2dp).
 */
class AccountBalanceCalculator {
    public function __construct(
        private AccountMapper $accountMapper,
        private TransactionMapper $transactionMapper,
    ) {
    }

    /**
     * The balance the ledger says this account should have, as a decimal
     * string at the account currency's scale.
     */
    public function expectedBalance(Account $account): string {
        return $this->balanceFor($account->getId(), $account->getOpeningBalance(), $account->getCurrency());
    }

    /**
     * The ledger sum for an account id, for callers that already hold the
     * opening balance and currency.
     */
    public function balanceFor(int $accountId, float|int|string|null $openingBalance, ?string $currency): string {
        // Pass a float through: MoneyCalculator normalizes it without
        // scientific notation (a string cast of a tiny float would not).
        return MoneyCalculator::add(
            is_string($openingBalance) ? $openingBalance : (float) ($openingBalance ?? 0.0),
            $this->transactionMapper->getNetChangeAll($accountId),
            Currency::decimalsFor($currency)
        );
    }

    /**
     * Recompute and store the account's balance from its ledger.
     *
     * @return string The balance written
     */
    public function recalculate(Account $account): string {
        $balance = $this->expectedBalance($account);
        $this->accountMapper->updateBalance($account->getId(), $balance, $account->getUserId());
        return $balance;
    }
}
