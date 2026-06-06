<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class GoalsService {
    private SavingsGoalMapper $mapper;
    private TransactionTagMapper $transactionTagMapper;

    public function __construct(SavingsGoalMapper $mapper, TransactionTagMapper $transactionTagMapper) {
        $this->mapper = $mapper;
        $this->transactionTagMapper = $transactionTagMapper;
    }

    /**
     * @return SavingsGoal[]
     */
    public function findAll(string $userId): array {
        $goals = $this->mapper->findAll($userId);
        return $this->enrichWithTagAmounts($goals, $userId);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): SavingsGoal {
        $goal = $this->mapper->find($id, $userId);
        return $this->enrichGoalWithTagAmount($goal);
    }

    /**
     * Fetch goals shared with a user by their pre-authorized IDs.
     *
     * IDs must already be authorized (e.g. via GranularShareService). Each goal
     * is tag-enriched against its own owner and flagged with `_shared`.
     *
     * @param int[] $ids
     * @return array[]
     */
    public function findShared(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        $goals = $this->mapper->findByIds($ids);
        return array_map(function (SavingsGoal $goal) {
            $this->enrichGoalWithTagAmount($goal);
            return array_merge($goal->jsonSerialize(), ['_shared' => true]);
        }, $goals);
    }

    public function create(
        string $userId,
        string $name,
        float $targetAmount,
        ?int $targetMonths = null,
        float $currentAmount = 0.0,
        ?string $description = null,
        ?string $targetDate = null,
        ?int $tagId = null,
        ?int $accountId = null,
        ?string $color = null
    ): SavingsGoal {
        $goal = new SavingsGoal();
        $goal->setUserId($userId);
        $goal->setName($name);
        $goal->setTargetAmount($targetAmount);
        $goal->setCurrentAmount($tagId !== null ? 0.0 : $currentAmount);
        $goal->setTargetMonths($targetMonths);
        $goal->setDescription($description);
        $goal->setTargetDate($targetDate);
        $goal->setTagId($tagId);
        $goal->setAccountId($accountId);
        $goal->setColor($color);
        $goal->setCreatedAt(date('Y-m-d H:i:s'));

        $inserted = $this->mapper->insert($goal);
        return $this->enrichGoalWithTagAmount($inserted);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(
        int $id,
        string $userId,
        ?string $name = null,
        ?float $targetAmount = null,
        ?int $targetMonths = null,
        ?float $currentAmount = null,
        ?string $description = null,
        ?string $targetDate = null,
        ?int $tagId = null,
        bool $updateTagId = false,
        ?int $accountId = null,
        bool $updateAccountId = false,
        ?string $color = null
    ): SavingsGoal {
        $goal = $this->mapper->find($id, $userId);

        if ($name !== null) {
            $goal->setName($name);
        }
        if ($targetAmount !== null) {
            $goal->setTargetAmount($targetAmount);
        }
        if ($targetMonths !== null) {
            $goal->setTargetMonths($targetMonths);
        }
        if ($description !== null) {
            $goal->setDescription($description);
        }
        if ($targetDate !== null) {
            $goal->setTargetDate($targetDate);
        }

        if ($updateTagId) {
            $goal->setTagId($tagId);
        }

        if ($updateAccountId) {
            $goal->setAccountId($accountId);
        }

        if ($color !== null) {
            $goal->setColor($color);
        }

        // Only update currentAmount if goal is not tag-linked
        if ($goal->getTagId() === null && $currentAmount !== null) {
            $goal->setCurrentAmount($currentAmount);
        }

        $updated = $this->mapper->update($goal);
        return $this->enrichGoalWithTagAmount($updated);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $goal = $this->mapper->find($id, $userId);
        $this->mapper->delete($goal);
    }

    /**
     * @throws DoesNotExistException
     */
    public function getProgress(int $id, string $userId): array {
        $goal = $this->find($id, $userId);

        $targetAmount = $goal->getTargetAmount();
        $currentAmount = $goal->getCurrentAmount();
        $remaining = $targetAmount - $currentAmount;
        $percentage = $targetAmount > 0 ? ($currentAmount / $targetAmount) * 100 : 0;

        $monthlyRequired = 0.0;
        $onTrack = true;
        $projectedCompletion = null;

        if ($goal->getTargetDate() && $remaining > 0) {
            $targetDate = new \DateTime($goal->getTargetDate());
            $now = new \DateTime();
            $monthsRemaining = max(1, (int) $now->diff($targetDate)->format('%m') + ((int) $now->diff($targetDate)->format('%y') * 12));

            if ($targetDate > $now) {
                $monthlyRequired = $remaining / $monthsRemaining;
            } else {
                $onTrack = false;
            }
        } elseif ($goal->getTargetMonths() && $remaining > 0) {
            $monthlyRequired = $remaining / $goal->getTargetMonths();
        }

        return [
            'goalId' => $id,
            'percentage' => round($percentage, 2),
            'remaining' => $remaining,
            'monthlyRequired' => round($monthlyRequired, 2),
            'onTrack' => $onTrack,
            'projectedCompletion' => $projectedCompletion,
        ];
    }

    /**
     * @throws DoesNotExistException
     */
    public function getForecast(int $id, string $userId): array {
        $goal = $this->find($id, $userId);
        $progress = $this->getProgress($id, $userId);

        $recommendations = [];
        if (!$progress['onTrack']) {
            $recommendations[] = 'Increase monthly savings to meet target date';
        }
        if ($progress['percentage'] < 25) {
            $recommendations[] = 'Consider automating transfers to build momentum';
        }
        if ($progress['percentage'] >= 75) {
            $recommendations[] = 'Great progress! Keep up the current savings rate';
        }

        return [
            'goalId' => $id,
            'currentProjection' => $progress['onTrack'] ? 'On track to complete by target date' : 'Behind schedule',
            'estimatedCompletion' => $goal->getTargetDate(),
            'monthlyContribution' => $progress['monthlyRequired'],
            'probabilityOfSuccess' => $progress['onTrack'] ? 85.0 : 50.0,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * For tag-linked goals, replace stored currentAmount with
     * the calculated sum from tagged transactions.
     *
     * @param SavingsGoal[] $goals
     * @param string $userId
     * @return SavingsGoal[]
     */
    private function enrichWithTagAmounts(array $goals, string $userId): array {
        $tagIds = [];
        foreach ($goals as $goal) {
            if ($goal->getTagId() !== null) {
                $tagIds[] = $goal->getTagId();
            }
        }

        if (empty($tagIds)) {
            return $goals;
        }

        $sums = $this->transactionTagMapper->sumTransactionAmountsByTags($tagIds, $userId);

        foreach ($goals as $goal) {
            $tagId = $goal->getTagId();
            if ($tagId !== null) {
                $goal->setCurrentAmount($sums[$tagId] ?? 0.0);
            }
        }

        return $goals;
    }

    private function enrichGoalWithTagAmount(SavingsGoal $goal): SavingsGoal {
        if ($goal->getTagId() !== null) {
            // Tag sums are always scoped to the goal's owner so that shared
            // goals reflect the owner's tagged transactions, not the viewer's.
            $sum = $this->transactionTagMapper->sumTransactionAmountsByTag($goal->getTagId(), $goal->getUserId());
            $goal->setCurrentAmount($sum);
        }
        return $goal;
    }
}
