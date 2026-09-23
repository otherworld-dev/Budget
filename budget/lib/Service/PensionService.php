<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class PensionService {
	private PensionAccountMapper $pensionMapper;
	private PensionSnapshotMapper $snapshotMapper;
	private PensionContributionMapper $contributionMapper;
	private CurrencyConversionService $conversionService;

	public function __construct(
		PensionAccountMapper $pensionMapper,
		PensionSnapshotMapper $snapshotMapper,
		PensionContributionMapper $contributionMapper,
		CurrencyConversionService $conversionService,
		private TransactionService $transactionService,
		private AccountMapper $accountMapper,
		private PensionRecurringContributionMapper $recurringMapper,
		private IDBConnection $db,
	) {
		$this->pensionMapper = $pensionMapper;
		$this->snapshotMapper = $snapshotMapper;
		$this->contributionMapper = $contributionMapper;
		$this->conversionService = $conversionService;
	}

	// =====================
	// Pension Account CRUD
	// =====================

	/**
	 * @return PensionAccount[]
	 */
	public function findAll(string $userId): array {
		return $this->pensionMapper->findAll($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): PensionAccount {
		return $this->pensionMapper->find($id, $userId);
	}

	public function create(
		string $userId,
		string $name,
		string $type,
		?string $provider = null,
		?string $currency = null,
		?float $currentBalance = null,
		?float $monthlyContribution = null,
		?float $expectedReturnRate = null,
		?int $retirementAge = null,
		?float $annualIncome = null,
		?float $transferValue = null,
		?float $projectionTarget = null,
	): PensionAccount {
		$pension = new PensionAccount();
		$pension->setUserId($userId);
		$pension->setName($name);
		$pension->setType($type);
		$pension->setProvider($provider);
		$pension->setCurrency($currency ?? 'GBP');
		$pension->setCurrentBalance($currentBalance);
		$pension->setMonthlyContribution($monthlyContribution);
		$pension->setExpectedReturnRate($expectedReturnRate);
		$pension->setRetirementAge($retirementAge);
		$pension->setAnnualIncome($annualIncome);
		$pension->setTransferValue($transferValue);
		$pension->setProjectionTarget($projectionTarget);

		$now = date('Y-m-d H:i:s');
		$pension->setCreatedAt($now);
		$pension->setUpdatedAt($now);

		$pension = $this->pensionMapper->insert($pension);

		// Create initial snapshot if balance provided for DC pensions
		if ($currentBalance !== null && $pension->isDefinedContribution()) {
			$this->createSnapshot($pension->getId(), $userId, $currentBalance, date('Y-m-d'));
		}

		return $pension;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function update(
		int $id,
		string $userId,
		?string $name = null,
		?string $type = null,
		?string $provider = null,
		?string $currency = null,
		?float $currentBalance = null,
		?float $monthlyContribution = null,
		?float $expectedReturnRate = null,
		?int $retirementAge = null,
		?float $annualIncome = null,
		?float $transferValue = null,
		?float $projectionTarget = null,
	): PensionAccount {
		$pension = $this->pensionMapper->find($id, $userId);

		if ($projectionTarget !== null) {
			$pension->setProjectionTarget($projectionTarget);
		}
		if ($name !== null) {
			$pension->setName($name);
		}
		if ($type !== null) {
			$pension->setType($type);
		}
		if ($provider !== null) {
			$pension->setProvider($provider);
		}
		if ($currency !== null) {
			$pension->setCurrency($currency);
		}
		if ($currentBalance !== null) {
			$pension->setCurrentBalance($currentBalance);
		}
		if ($monthlyContribution !== null) {
			$pension->setMonthlyContribution($monthlyContribution);
		}
		if ($expectedReturnRate !== null) {
			$pension->setExpectedReturnRate($expectedReturnRate);
		}
		if ($retirementAge !== null) {
			$pension->setRetirementAge($retirementAge);
		}
		if ($annualIncome !== null) {
			$pension->setAnnualIncome($annualIncome);
		}
		if ($transferValue !== null) {
			$pension->setTransferValue($transferValue);
		}

		$pension->setUpdatedAt(date('Y-m-d H:i:s'));

		return $this->pensionMapper->update($pension);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $id, string $userId): void {
		$pension = $this->pensionMapper->find($id, $userId);

		// Detach any bank legs that funded contributions/withdrawals (#304) so
		// they survive as plain transactions rather than dangling pension markers.
		$linked = $this->contributionMapper->findLinkedByPension($id, $userId);
		if (!empty($linked)) {
			$contribIds = array_map(static fn ($c) => $c->getId(), $linked);
			$this->transactionService->clearPensionContribMarkers($contribIds);
		}

		// Delete related snapshots, contributions and recurring schedules
		$this->snapshotMapper->deleteByPension($id, $userId);
		$this->contributionMapper->deleteByPension($id, $userId);
		$this->recurringMapper->deleteByPension($id, $userId);

		$this->pensionMapper->delete($pension);
	}

	// =====================
	// Snapshot Operations
	// =====================

	/**
	 * @return PensionSnapshot[]
	 */
	public function getSnapshots(int $pensionId, string $userId): array {
		// Verify pension exists and belongs to user
		$this->pensionMapper->find($pensionId, $userId);
		return $this->snapshotMapper->findByPension($pensionId, $userId);
	}

	public function createSnapshot(
		int $pensionId,
		string $userId,
		float $balance,
		string $date,
	): PensionSnapshot {
		// Verify pension exists and belongs to user
		$pension = $this->pensionMapper->find($pensionId, $userId);

		$snapshot = new PensionSnapshot();
		$snapshot->setUserId($userId);
		$snapshot->setPensionId($pensionId);
		$snapshot->setBalance($balance);
		$snapshot->setDate($date);
		$snapshot->setCreatedAt(date('Y-m-d H:i:s'));

		$snapshot = $this->snapshotMapper->insert($snapshot);

		// Update pension's current balance to match latest snapshot
		$pension->setCurrentBalance($balance);
		$pension->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->pensionMapper->update($pension);

		return $snapshot;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function deleteSnapshot(int $snapshotId, string $userId): void {
		$snapshot = $this->snapshotMapper->find($snapshotId, $userId);
		$this->snapshotMapper->delete($snapshot);
	}

	// =====================
	// Contribution Operations
	// =====================

	/**
	 * @return PensionContribution[]
	 */
	public function getContributions(int $pensionId, string $userId): array {
		// Verify pension exists and belongs to user
		$this->pensionMapper->find($pensionId, $userId);
		return $this->contributionMapper->findByPension($pensionId, $userId);
	}

	public function createContribution(
		int $pensionId,
		string $userId,
		float $amount,
		string $date,
		?string $note = null,
	): PensionContribution {
		// Verify pension exists and belongs to user
		$this->pensionMapper->find($pensionId, $userId);

		$contribution = new PensionContribution();
		$contribution->setUserId($userId);
		$contribution->setPensionId($pensionId);
		$contribution->setAmount($amount);
		$contribution->setDate($date);
		$contribution->setNote($note);
		$contribution->setKind(PensionContribution::KIND_CONTRIBUTION);
		$contribution->setCreatedAt(date('Y-m-d H:i:s'));

		return $this->contributionMapper->insert($contribution);
	}

	/**
	 * Record a contribution funded by a transfer from a bank account (#304): the
	 * money leaving the account becomes a linked bank debit (excluded from
	 * spending) and a pension contribution, created atomically.
	 *
	 * The entered amount is the contribution in the pension's currency; the bank
	 * debit is converted to the account's currency. The pension's current_balance
	 * is intentionally NOT changed (a snapshot is the valuation source of truth —
	 * bumping it would double-count against the bank balance in net worth).
	 *
	 * @throws DoesNotExistException if the pension or account is not found
	 */
	public function createContributionWithTransfer(
		int $pensionId,
		string $userId,
		float $amount,
		string $date,
		int $sourceAccountId,
		?string $note = null,
	): PensionContribution {
		return $this->createLinkedEntry($pensionId, $userId, $amount, $date, $sourceAccountId, $note, PensionContribution::KIND_CONTRIBUTION);
	}

	/**
	 * Record a withdrawal/drawdown paid into a bank account (the reverse of
	 * #304): a linked bank credit (excluded from income) plus a withdrawal row.
	 *
	 * @throws DoesNotExistException
	 */
	public function createWithdrawalWithTransfer(
		int $pensionId,
		string $userId,
		float $amount,
		string $date,
		int $destAccountId,
		?string $note = null,
	): PensionContribution {
		return $this->createLinkedEntry($pensionId, $userId, $amount, $date, $destAccountId, $note, PensionContribution::KIND_WITHDRAWAL);
	}

	/**
	 * Record a withdrawal with no linked bank account (manual).
	 */
	public function createWithdrawal(
		int $pensionId,
		string $userId,
		float $amount,
		string $date,
		?string $note = null,
	): PensionContribution {
		$this->pensionMapper->find($pensionId, $userId);

		$withdrawal = new PensionContribution();
		$withdrawal->setUserId($userId);
		$withdrawal->setPensionId($pensionId);
		$withdrawal->setAmount($amount);
		$withdrawal->setDate($date);
		$withdrawal->setNote($note);
		$withdrawal->setKind(PensionContribution::KIND_WITHDRAWAL);
		$withdrawal->setCreatedAt(date('Y-m-d H:i:s'));

		return $this->contributionMapper->insert($withdrawal);
	}

	/**
	 * Shared implementation for contribution/withdrawal funded by a bank leg.
	 */
	private function createLinkedEntry(
		int $pensionId,
		string $userId,
		float $amount,
		string $date,
		int $accountId,
		?string $note,
		string $kind,
	): PensionContribution {
		$pension = $this->pensionMapper->find($pensionId, $userId);
		$account = $this->accountMapper->find($accountId, $userId); // verifies ownership

		$isWithdrawal = $kind === PensionContribution::KIND_WITHDRAWAL;
		$bankType = $isWithdrawal ? 'credit' : 'debit';

		// Contribution is recorded in the pension's currency; the bank leg in the
		// account's currency (converted with cached rates, graceful fallback).
		$pensionCurrency = $pension->getCurrency() ?: ($account->getCurrency() ?: 'GBP');
		$accountCurrency = $account->getCurrency() ?: $pensionCurrency;
		$bankAmount = round((float)$this->conversionService->convertLocal($amount, $pensionCurrency, $accountCurrency, $date), 2);

		$description = $isWithdrawal
			? 'Pension withdrawal: ' . $pension->getName()
			: 'Pension contribution: ' . $pension->getName();

		$this->db->beginTransaction();
		try {
			$tx = $this->transactionService->create(
				$userId,
				$accountId,
				$date,
				$description,
				$bankAmount,
				$bankType,
				null,            // categoryId — keep out of category spending
				$pension->getName(), // vendor
				null,            // reference
				$note            // notes
			);

			$contribution = new PensionContribution();
			$contribution->setUserId($userId);
			$contribution->setPensionId($pensionId);
			$contribution->setAmount($amount);
			$contribution->setDate($date);
			$contribution->setNote($note);
			$contribution->setTransactionId($tx->getId());
			$contribution->setSourceAccountId($accountId);
			$contribution->setKind($kind);
			$contribution->setCreatedAt(date('Y-m-d H:i:s'));
			$contribution = $this->contributionMapper->insert($contribution);

			// Mark the bank leg so it's excluded from spending/income aggregates.
			$this->transactionService->markPensionContribLink($tx->getId(), $userId, $contribution->getId());

			$this->db->commit();
			return $contribution;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function deleteContribution(int $contributionId, string $userId): void {
		$contribution = $this->contributionMapper->find($contributionId, $userId);

		// If funded by a bank transfer (#304), remove the linked bank transaction
		// too (which restores the account balance). Atomic so both go together.
		$txId = $contribution->getTransactionId();
		$this->db->beginTransaction();
		try {
			if ($txId !== null) {
				try {
					$this->transactionService->delete($txId, $userId);
				} catch (DoesNotExistException $e) {
					// Bank leg already gone — nothing to clean up.
				}
			}
			$this->contributionMapper->delete($contribution);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	// =====================
	// Summary & Aggregates
	// =====================

	/**
	 * Get summary of all pensions for a user.
	 * Values are converted to the user's base currency.
	 */
	public function getSummary(string $userId): array {
		$pensions = $this->pensionMapper->findAll($userId);
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);

		$totalDCBalance = 0.0;
		$totalDBTransferValue = 0.0;
		$totalDBIncome = 0.0;
		$stateIncome = 0.0;
		$dcCount = 0;
		$dbCount = 0;
		$stateCount = 0;

		foreach ($pensions as $pension) {
			$pensionCurrency = $pension->getCurrency() ?: $baseCurrency;

			if ($pension->isDefinedContribution()) {
				$balance = $pension->getCurrentBalance() ?? 0;
				$totalDCBalance += $this->convertAmount($balance, $pensionCurrency, $baseCurrency, $userId);
				$dcCount++;
			} elseif ($pension->isDefinedBenefit()) {
				$transferValue = $pension->getTransferValue() ?? 0;
				$income = $pension->getAnnualIncome() ?? 0;
				$totalDBTransferValue += $this->convertAmount($transferValue, $pensionCurrency, $baseCurrency, $userId);
				$totalDBIncome += $this->convertAmount($income, $pensionCurrency, $baseCurrency, $userId);
				$dbCount++;
			} elseif ($pension->isStatePension()) {
				$income = $pension->getAnnualIncome() ?? 0;
				$stateIncome += $this->convertAmount($income, $pensionCurrency, $baseCurrency, $userId);
				$stateCount++;
			}
		}

		// Total pension worth for net worth calculation
		$totalPensionWorth = $totalDCBalance + $totalDBTransferValue;

		return [
			'totalPensionWorth' => $totalPensionWorth,
			'totalDCBalance' => $totalDCBalance,
			'totalDBTransferValue' => $totalDBTransferValue,
			'totalDBIncome' => $totalDBIncome,
			'stateIncome' => $stateIncome,
			'totalProjectedIncome' => $totalDBIncome + $stateIncome,
			'pensionCount' => count($pensions),
			'dcCount' => $dcCount,
			'dbCount' => $dbCount,
			'stateCount' => $stateCount,
			'baseCurrency' => $baseCurrency,
		];
	}

	/**
	 * Convert an amount to the base currency if different.
	 */
	private function convertAmount(float $amount, string $fromCurrency, string $baseCurrency, string $userId): float {
		if ($amount == 0 || strtoupper($fromCurrency) === strtoupper($baseCurrency)) {
			return $amount;
		}
		return $this->conversionService->convertToBaseFloat($amount, $fromCurrency, $userId);
	}

	/**
	 * Get total contributions for a pension.
	 */
	public function getTotalContributions(int $pensionId, string $userId): float {
		// Verify pension exists and belongs to user
		$this->pensionMapper->find($pensionId, $userId);
		return $this->contributionMapper->getTotalByPension($pensionId, $userId);
	}

	// =====================
	// Charts & Activity
	// =====================

	/**
	 * Snapshot balance series for the detail-panel chart (date ASC). If there
	 * are no snapshots but a balance is known, returns a single synthetic point
	 * so the chart isn't empty.
	 *
	 * @return array{labels: string[], values: float[], currency: string}
	 * @throws DoesNotExistException
	 */
	public function getBalanceHistory(int $pensionId, string $userId): array {
		$pension = $this->pensionMapper->find($pensionId, $userId);

		// findByPension is date DESC; reverse for a left-to-right chart.
		$snapshots = array_reverse($this->snapshotMapper->findByPension($pensionId, $userId));

		$labels = [];
		$values = [];
		foreach ($snapshots as $snapshot) {
			$labels[] = $snapshot->getDate();
			$values[] = (float)$snapshot->getBalance();
		}

		if (empty($values) && $pension->getCurrentBalance() !== null) {
			$labels[] = date('Y-m-d');
			$values[] = (float)$pension->getCurrentBalance();
		}

		return [
			'labels' => $labels,
			'values' => $values,
			'currency' => $pension->getCurrency() ?: 'GBP',
		];
	}

	/**
	 * Merged, most-recent-first activity timeline for a pension: contributions,
	 * withdrawals (incl. ones linked to a bank transfer, #304) and balance
	 * snapshots. One row per real-world event — the bank leg is not emitted
	 * separately.
	 *
	 * @throws DoesNotExistException
	 */
	public function getActivity(int $pensionId, string $userId): array {
		$this->pensionMapper->find($pensionId, $userId);

		$items = [];

		foreach ($this->contributionMapper->findByPension($pensionId, $userId) as $c) {
			$isWithdrawal = $c->getKind() === PensionContribution::KIND_WITHDRAWAL;
			if ($isWithdrawal) {
				$type = 'withdrawal';
			} else {
				$type = $c->getTransactionId() !== null ? 'transfer_in' : 'contribution';
			}
			$items[] = [
				'type' => $type,
				'id' => $c->getId(),
				'date' => $c->getDate(),
				'amount' => (float)$c->getAmount(),
				'note' => $c->getNote(),
				'transactionId' => $c->getTransactionId(),
				'sourceAccountId' => $c->getSourceAccountId(),
			];
		}

		foreach ($this->snapshotMapper->findByPension($pensionId, $userId) as $s) {
			$items[] = [
				'type' => 'snapshot',
				'id' => $s->getId(),
				'date' => $s->getDate(),
				'amount' => (float)$s->getBalance(),
				'note' => null,
				'transactionId' => null,
				'sourceAccountId' => null,
			];
		}

		// Most recent first; stable within a day.
		usort($items, static function (array $a, array $b) {
			$cmp = strcmp((string)$b['date'], (string)$a['date']);
			return $cmp !== 0 ? $cmp : (($b['id'] ?? 0) <=> ($a['id'] ?? 0));
		});

		return $items;
	}
}
