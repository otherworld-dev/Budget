<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCA\Budget\Enum\AccountType;

class NetWorthService {
    /**
     * Account types considered liabilities (balances treated as debts).
     * Aligned with frontend classification in main.js
     */
    private const LIABILITY_TYPES = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

    private NetWorthSnapshotMapper $snapshotMapper;
    private AccountMapper $accountMapper;

    public function __construct(
        NetWorthSnapshotMapper $snapshotMapper,
        AccountMapper $accountMapper
    ) {
        $this->snapshotMapper = $snapshotMapper;
        $this->accountMapper = $accountMapper;
    }

    /**
     * Calculate current net worth from account balances.
     *
     * @return array{totalAssets: float, totalLiabilities: float, netWorth: float}
     */
    public function calculateNetWorth(string $userId): array {
        $accounts = $this->accountMapper->findAll($userId);

        $totalAssets = 0.0;
        $totalLiabilities = 0.0;

        foreach ($accounts as $account) {
            $balance = (float) ($account->getBalance() ?? 0);
            $type = $account->getType();

            if ($this->isLiabilityType($type)) {
                // Liabilities: use absolute value
                $totalLiabilities += abs($balance);
            } else {
                // Assets: add balance directly
                $totalAssets += $balance;
            }
        }

        $netWorth = $totalAssets - $totalLiabilities;

        return [
            'totalAssets' => round($totalAssets, 2),
            'totalLiabilities' => round($totalLiabilities, 2),
            'netWorth' => round($netWorth, 2),
        ];
    }

    /**
     * Check if an account type is a liability.
     */
    private function isLiabilityType(string $type): bool {
        // Check our explicit list first
        if (in_array($type, self::LIABILITY_TYPES, true)) {
            return true;
        }

        // Fall back to enum if type is defined there
        $accountType = AccountType::tryFrom($type);
        if ($accountType !== null) {
            return $accountType->isLiability();
        }

        // Default to asset for unknown types
        return false;
    }

    /**
     * Create or update a snapshot for a given date.
     */
    public function createSnapshot(
        string $userId,
        string $source = NetWorthSnapshot::SOURCE_MANUAL,
        ?string $date = null
    ): NetWorthSnapshot {
        $date = $date ?? date('Y-m-d');
        $netWorthData = $this->calculateNetWorth($userId);

        // Check if snapshot already exists for this date (upsert logic)
        $existing = $this->snapshotMapper->findByDate($userId, $date);

        if ($existing !== null) {
            // Update existing snapshot
            $existing->setTotalAssets($netWorthData['totalAssets']);
            $existing->setTotalLiabilities($netWorthData['totalLiabilities']);
            $existing->setNetWorth($netWorthData['netWorth']);
            $existing->setSource($source);
            return $this->snapshotMapper->update($existing);
        }

        // Create new snapshot
        $snapshot = new NetWorthSnapshot();
        $snapshot->setUserId($userId);
        $snapshot->setTotalAssets($netWorthData['totalAssets']);
        $snapshot->setTotalLiabilities($netWorthData['totalLiabilities']);
        $snapshot->setNetWorth($netWorthData['netWorth']);
        $snapshot->setDate($date);
        $snapshot->setSource($source);
        $snapshot->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->snapshotMapper->insert($snapshot);
    }

    /**
     * Get snapshots for charting with date range filter.
     *
     * @return NetWorthSnapshot[]
     */
    public function getSnapshots(string $userId, int $days = 30): array {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        return $this->snapshotMapper->findByDateRange($userId, $startDate, $endDate);
    }

    /**
     * Get all snapshots for a user.
     *
     * @return NetWorthSnapshot[]
     */
    public function getAllSnapshots(string $userId): array {
        return $this->snapshotMapper->findAll($userId);
    }

    /**
     * Get the most recent snapshot.
     */
    public function getLatestSnapshot(string $userId): ?NetWorthSnapshot {
        return $this->snapshotMapper->findLatest($userId);
    }

    /**
     * Delete a specific snapshot.
     */
    public function deleteSnapshot(int $id, string $userId): void {
        $snapshot = $this->snapshotMapper->find($id, $userId);
        $this->snapshotMapper->delete($snapshot);
    }
}
