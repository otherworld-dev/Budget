<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class PensionService {
    private PensionAccountMapper $pensionMapper;
    private PensionSnapshotMapper $snapshotMapper;
    private PensionContributionMapper $contributionMapper;

    public function __construct(
        PensionAccountMapper $pensionMapper,
        PensionSnapshotMapper $snapshotMapper,
        PensionContributionMapper $contributionMapper
    ) {
        $this->pensionMapper = $pensionMapper;
        $this->snapshotMapper = $snapshotMapper;
        $this->contributionMapper = $contributionMapper;
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
        ?float $transferValue = null
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
        ?float $transferValue = null
    ): PensionAccount {
        $pension = $this->pensionMapper->find($id, $userId);

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

        // Delete related snapshots and contributions
        $this->snapshotMapper->deleteByPension($id, $userId);
        $this->contributionMapper->deleteByPension($id, $userId);

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
        string $date
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
        ?string $note = null
    ): PensionContribution {
        // Verify pension exists and belongs to user
        $this->pensionMapper->find($pensionId, $userId);

        $contribution = new PensionContribution();
        $contribution->setUserId($userId);
        $contribution->setPensionId($pensionId);
        $contribution->setAmount($amount);
        $contribution->setDate($date);
        $contribution->setNote($note);
        $contribution->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->contributionMapper->insert($contribution);
    }

    /**
     * @throws DoesNotExistException
     */
    public function deleteContribution(int $contributionId, string $userId): void {
        $contribution = $this->contributionMapper->find($contributionId, $userId);
        $this->contributionMapper->delete($contribution);
    }

    // =====================
    // Summary & Aggregates
    // =====================

    /**
     * Get summary of all pensions for a user.
     */
    public function getSummary(string $userId): array {
        $pensions = $this->pensionMapper->findAll($userId);

        $totalDCBalance = 0.0;
        $totalDBTransferValue = 0.0;
        $totalDBIncome = 0.0;
        $stateIncome = 0.0;
        $dcCount = 0;
        $dbCount = 0;
        $stateCount = 0;

        foreach ($pensions as $pension) {
            if ($pension->isDefinedContribution()) {
                $totalDCBalance += $pension->getCurrentBalance() ?? 0;
                $dcCount++;
            } elseif ($pension->isDefinedBenefit()) {
                $totalDBTransferValue += $pension->getTransferValue() ?? 0;
                $totalDBIncome += $pension->getAnnualIncome() ?? 0;
                $dbCount++;
            } elseif ($pension->isStatePension()) {
                $stateIncome += $pension->getAnnualIncome() ?? 0;
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
        ];
    }

    /**
     * Get total contributions for a pension.
     */
    public function getTotalContributions(int $pensionId, string $userId): float {
        // Verify pension exists and belongs to user
        $this->pensionMapper->find($pensionId, $userId);
        return $this->contributionMapper->getTotalByPension($pensionId, $userId);
    }
}
