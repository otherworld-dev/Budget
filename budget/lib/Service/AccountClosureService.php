<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\Currency;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * The one gate an account passes through to be closed (#372).
 *
 * "Closed" promises the rest of the app two things: the balance is zero, and
 * nothing will post into the account again. This guard makes both true at the
 * moment of closing; the pickers that hide closed accounts keep them true
 * afterwards. Every refusal names what is in the way, so the user fixes it
 * once instead of finding a bill still paying from a dead account months on.
 *
 * Reopening needs no check and never comes here.
 */
class AccountClosureService {

    public function __construct(
        private TransactionMapper $transactionMapper,
        private BillMapper $billMapper,
        private RecurringIncomeMapper $recurringIncomeMapper,
        private PensionRecurringContributionMapper $pensionContributionMapper,
        private PensionAccountMapper $pensionAccountMapper,
        private BankConnectionMapper $bankConnectionMapper,
        private BankAccountMappingMapper $bankAccountMappingMapper,
        private ImportRuleMapper $importRuleMapper,
        private IL10N $l,
    ) {
    }

    /**
     * Refuse, with a reason the form can show verbatim, unless the account can
     * be closed right now. Money first: a non-zero balance is the usual case and
     * the one the user must resolve before the rest even matters.
     *
     * @throws \InvalidArgumentException
     */
    public function assertClosable(Account $account): void {
        $currency = $account->getCurrency();
        $decimals = Currency::decimalsFor($currency);
        $balance = (float) $account->getBalance();

        // Compared at the currency's own precision: dust the currency cannot
        // express is nothing, not a balance.
        if (MoneyCalculator::compare($balance, '0', $decimals) !== 0) {
            throw new \InvalidArgumentException($this->l->t(
                'This account still has a balance of %1$s. Record the closing withdrawal, or adjust the opening balance so it reads zero, then close it.',
                [MoneyCalculator::format($balance, $currency, $decimals)]
            ));
        }

        if ($this->transactionMapper->hasRowsAfterDate((int) $account->getId(), date('Y-m-d'))) {
            throw new \InvalidArgumentException($this->l->t(
                'This account still has transactions dated after today. Delete or move them, then close it.'
            ));
        }

        $references = $this->findOpenReferences($account);
        if ($references === []) {
            return;
        }

        $labels = [
            'bills' => $this->l->t('Bills'),
            'transfers' => $this->l->t('Transfers'),
            'income' => $this->l->t('Recurring income'),
            'pensions' => $this->l->t('Pension contributions'),
            'bankSync' => $this->l->t('Bank sync'),
            'rules' => $this->l->t('Import rules'),
        ];
        $parts = [];
        foreach ($references as $kind => $names) {
            $parts[] = ($labels[$kind] ?? $kind) . ': ' . implode(', ', $names);
        }

        throw new \InvalidArgumentException($this->l->t(
            'This account is still used by %1$s. Reassign or deactivate them, then close it.',
            [implode('; ', $parts)]
        ));
    }

    /**
     * Everything still scheduled to post into the account, by kind — only the
     * kinds with something in them, in a fixed order. A savings goal linked to
     * the account is deliberately absent: it reads the balance, it never writes.
     *
     * @return array<string, string[]>
     */
    public function findOpenReferences(Account $account): array {
        $id = (int) $account->getId();
        $userId = (string) $account->getUserId();

        $refs = [
            'bills' => [],
            'transfers' => [],
            'income' => [],
            'pensions' => [],
            'bankSync' => [],
            'rules' => [],
        ];

        // Bills and transfers share a table; a transfer touches the account
        // from either end.
        foreach ($this->billMapper->findActive($userId) as $bill) {
            if ((int) $bill->getAccountId() !== $id && (int) $bill->getDestinationAccountId() !== $id) {
                continue;
            }
            $refs[$bill->getIsTransfer() ? 'transfers' : 'bills'][] = (string) $bill->getName();
        }

        foreach ($this->recurringIncomeMapper->findActive($userId) as $income) {
            if ((int) $income->getAccountId() === $id) {
                $refs['income'][] = (string) $income->getName();
            }
        }

        foreach ($this->pensionContributionMapper->findActive($userId) as $contribution) {
            if ((int) $contribution->getSourceAccountId() !== $id) {
                continue;
            }
            $refs['pensions'][] = $this->pensionName((int) $contribution->getPensionId(), $userId);
        }

        foreach ($this->bankConnectionMapper->findAll($userId) as $connection) {
            foreach ($this->bankAccountMappingMapper->findEnabledByConnection((int) $connection->getId()) as $mapping) {
                if ((int) $mapping->getBudgetAccountId() !== $id) {
                    continue;
                }
                $refs['bankSync'][] = (string) ($mapping->getExternalAccountName() ?: $mapping->getExternalAccountId());
            }
        }

        foreach ($this->importRuleMapper->findActive($userId) as $rule) {
            if ($this->ruleRoutesInto($rule->getParsedActions(), $id)) {
                $refs['rules'][] = (string) $rule->getName();
            }
        }

        return array_filter($refs, static fn(array $names) => $names !== []);
    }

    /** v2 action lists only; the legacy flat shape has no account action. */
    private function ruleRoutesInto(array $parsed, int $accountId): bool {
        foreach (($parsed['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            if (($action['type'] ?? null) === 'set_account' && (int) ($action['value'] ?? 0) === $accountId) {
                return true;
            }
        }
        return false;
    }

    private function pensionName(int $pensionId, string $userId): string {
        try {
            return (string) $this->pensionAccountMapper->find($pensionId, $userId)->getName();
        } catch (DoesNotExistException) {
            return $this->l->t('Pension #%1$s', [(string) $pensionId]);
        }
    }
}
