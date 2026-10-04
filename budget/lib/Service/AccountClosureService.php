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
use OCA\Budget\Db\ShareItem;
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
		private ?TransactionService $transactionService = null,
		private ?GranularShareService $granularShareService = null,
	) {
	}

	/**
	 * Refuse, with a reason the form can show verbatim, unless the account can
	 * be closed right now. Money first: a non-zero balance is the usual case and
	 * the one the user must resolve before the rest even matters.
	 *
	 * @param string|null $actingUserId who is closing it, for whose items are
	 *                                  named (the owner when null)
	 * @throws \InvalidArgumentException
	 */
	public function assertClosable(Account $account, ?string $actingUserId = null): void {
		$currency = $account->getCurrency();
		$decimals = Currency::decimalsFor($currency);
		$balance = (float)$account->getBalance();

		// Compared at the currency's own precision: dust the currency cannot
		// express is nothing, not a balance.
		if (MoneyCalculator::compare($balance, '0', $decimals) !== 0) {
			throw new \InvalidArgumentException($this->l->t(
				'This account still has a balance of %1$s. Record the closing withdrawal, or adjust the opening balance so it reads zero, then close it.',
				[MoneyCalculator::format($balance, $currency, $decimals)]
			));
		}

		if ($this->transactionMapper->hasRowsAfterDate((int)$account->getId(), date('Y-m-d'))) {
			throw new \InvalidArgumentException($this->l->t(
				'This account still has transactions dated after today. Delete or move them, then close it.'
			));
		}

		$references = $this->findOpenReferences($account, $actingUserId);
		if ($references === []) {
			return;
		}
		$others = $references['others'] ?? [];
		unset($references['others']);

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

		$messages = [];
		if ($parts !== []) {
			$messages[] = $this->l->t(
				'This account is still used by %1$s. Reassign or deactivate them, then close it.',
				[implode('; ', $parts)]
			);
		}
		if ($others !== []) {
			$messages[] = $this->l->t('Items other people set up still use this account. They need to move or deactivate them before it can be closed.');
		}
		throw new \InvalidArgumentException(implode(' ', $messages));
	}

	/**
	 * Everything still scheduled to post into the account, by kind — only the
	 * kinds with something in them, in a fixed order. A savings goal linked to
	 * the account is deliberately absent: it reads the balance, it never writes.
	 *
	 * Only $viewerId's own items are named (the owner's when null). Anyone
	 * else's are listed under 'others', by kind and without their names: a
	 * name is someone else's text, and the refusal is shown verbatim, so the
	 * owner read whatever a recipient had typed, and a write recipient read
	 * the owner's bill, pension, bank and rule names.
	 *
	 * @return array<string, string[]>
	 */
	public function findOpenReferences(Account $account, ?string $viewerId = null): array {
		$id = (int)$account->getId();
		$userId = (string)$account->getUserId();
		$viewer = $viewerId ?? $userId;

		$refs = [
			'bills' => [],
			'transfers' => [],
			'income' => [],
			'pensions' => [],
			'bankSync' => [],
			'rules' => [],
			'others' => [],
		];
		$add = static function (string $kind, string $owner, string $name) use (&$refs, $viewer): void {
			if ($owner === $viewer) {
				$refs[$kind][] = $name;
			} else {
				$refs['others'][] = $kind;
			}
		};

		// Bills and transfers share a table; a transfer touches the account
		// from either end. Everyone's: someone the account is shared with
		// can set up bills and income on it, and only the owner's were looked
		// at, so a closed shared account went on receiving their payments.
		foreach ($this->billMapper->findActiveByAccount($id) as $bill) {
			if ((int)$bill->getAccountId() !== $id && (int)$bill->getDestinationAccountId() !== $id) {
				continue;
			}
			if (!$this->canPostInto($account, (string)$bill->getUserId())) {
				continue;
			}
			$add($bill->getIsTransfer() ? 'transfers' : 'bills', (string)$bill->getUserId(), (string)$bill->getName());
		}

		foreach ($this->recurringIncomeMapper->findActiveByAccount($id) as $income) {
			if ((int)$income->getAccountId() === $id && $this->canPostInto($account, (string)$income->getUserId())) {
				$add('income', (string)$income->getUserId(), (string)$income->getName());
			}
		}

		foreach ($this->pensionContributionMapper->findActive($userId) as $contribution) {
			if ((int)$contribution->getSourceAccountId() !== $id) {
				continue;
			}
			$add('pensions', $userId, $viewer === $userId ? $this->pensionName((int)$contribution->getPensionId(), $userId) : '');
		}

		foreach ($this->bankConnectionMapper->findAll($userId) as $connection) {
			foreach ($this->bankAccountMappingMapper->findEnabledByConnection((int)$connection->getId()) as $mapping) {
				if ((int)$mapping->getBudgetAccountId() !== $id) {
					continue;
				}
				$add('bankSync', $userId, (string)($mapping->getExternalAccountName() ?: $mapping->getExternalAccountId()));
			}
		}

		foreach ($this->importRuleMapper->findActive($userId) as $rule) {
			if ($this->ruleRoutesInto($rule->getParsedActions(), $id)) {
				$add('rules', $userId, (string)$rule->getName());
			}
		}

		return array_filter($refs, static fn (array $names) => $names !== []);
	}

	/**
	 * Whether $userId's bills and income can still post into the account:
	 * its owner always, anyone else while it is shared with them at write.
	 * One that can't is refused every time it tries, so it doesn't keep the
	 * account open; only its owner could have fixed it, and the account's
	 * owner can't even see it.
	 */
	private function canPostInto(Account $account, string $userId): bool {
		return $userId === $account->getUserId()
			|| $this->granularShareService === null
			|| $this->granularShareService->canWrite($userId, ShareItem::TYPE_ACCOUNT, (int)$account->getId());
	}

	/**
	 * Once the account is closed, the bills, transfers and income of people
	 * who can no longer post into it let go of it, as they do when a share
	 * ends (CrossUserLinks::cutLostAccess()): they didn't block the close,
	 * and left pointing at it they would post into a closed account the day
	 * the share came back. A bill that does stops auto-paying and loses its
	 * pending rows; it stays active, for its owner to point elsewhere.
	 *
	 * @return int how many let go
	 */
	public function detachStaleSchedules(Account $account): int {
		$id = (int)$account->getId();
		$detached = 0;

		foreach ($this->billMapper->findActiveByAccount($id) as $bill) {
			if ($this->canPostInto($account, (string)$bill->getUserId())) {
				continue;
			}
			$this->transactionService?->deleteScheduledBillTransactions((int)$bill->getId());
			if ((int)$bill->getAccountId() === $id) {
				$bill->setAccountId(null);
			}
			if ((int)$bill->getDestinationAccountId() === $id) {
				$bill->setDestinationAccountId(null);
			}
			$bill->setAutoPayEnabled(false);
			$this->billMapper->update($bill);
			$detached++;
		}

		foreach ($this->recurringIncomeMapper->findActiveByAccount($id) as $income) {
			if ((int)$income->getAccountId() !== $id || $this->canPostInto($account, (string)$income->getUserId())) {
				continue;
			}
			$income->setAccountId(null);
			$this->recurringIncomeMapper->update($income);
			$detached++;
		}

		return $detached;
	}

	/**
	 * Stop every bill, transfer and recurring income that pays into or out of
	 * an account being deleted, whoever owns them. Unlike closing, a delete
	 * isn't refused over them (the user has already chosen to lose the
	 * ledger), but left alone they went on running against a deleted id: a
	 * card-statement transfer failed on every Mark Paid and auto-pay, a
	 * foreign-currency bill showed in the base currency, and the edit form
	 * called the account "not shared with you". Each is switched off and lets
	 * go of the deleted account, its pre-booked rows in other accounts go,
	 * and a stored Mark Unpaid goes too: what it would revert is gone.
	 *
	 * @return array<string, string[]> what was stopped, by kind
	 */
	public function stopSchedulesFor(Account $account): array {
		$id = (int)$account->getId();
		$stopped = ['bills' => [], 'transfers' => [], 'income' => []];

		foreach ($this->billMapper->findActiveByAccount($id) as $bill) {
			$this->transactionService?->deleteScheduledBillTransactions((int)$bill->getId());
			if ((int)$bill->getAccountId() === $id) {
				$bill->setAccountId(null);
			}
			if ((int)$bill->getDestinationAccountId() === $id) {
				$bill->setDestinationAccountId(null);
			}
			$bill->setIsActive(false);
			$bill->setPaidUndoState(null);
			$this->billMapper->update($bill);
			$stopped[$bill->getIsTransfer() ? 'transfers' : 'bills'][] = (string)$bill->getName();
		}

		foreach ($this->recurringIncomeMapper->findActiveByAccount($id) as $income) {
			$income->setAccountId(null);
			$income->setIsActive(false);
			$this->recurringIncomeMapper->update($income);
			$stopped['income'][] = (string)$income->getName();
		}

		return array_filter($stopped, static fn (array $names) => $names !== []);
	}

	/** v2 action lists only; the legacy flat shape has no account action. */
	private function ruleRoutesInto(array $parsed, int $accountId): bool {
		foreach (($parsed['actions'] ?? []) as $action) {
			if (!is_array($action)) {
				continue;
			}
			if (($action['type'] ?? null) === 'set_account' && (int)($action['value'] ?? 0) === $accountId) {
				return true;
			}
		}
		return false;
	}

	private function pensionName(int $pensionId, string $userId): string {
		try {
			return (string)$this->pensionAccountMapper->find($pensionId, $userId)->getName();
		} catch (DoesNotExistException) {
			return $this->l->t('Pension #%1$s', [(string)$pensionId]);
		}
	}
}
