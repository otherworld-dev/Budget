<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Db\SavingsGoalMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class GoalsService {
    private SavingsGoalMapper $mapper;

    public function __construct(SavingsGoalMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * @return SavingsGoal[]
     */
    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): SavingsGoal {
        return $this->mapper->find($id, $userId);
    }

    public function create(
        string $userId,
        string $name,
        float $targetAmount,
        ?int $targetMonths = null,
        float $currentAmount = 0.0,
        ?string $description = null,
        ?string $targetDate = null
    ): SavingsGoal {
        $goal = new SavingsGoal();
        $goal->setUserId($userId);
        $goal->setName($name);
        $goal->setTargetAmount($targetAmount);
        $goal->setCurrentAmount($currentAmount);
        $goal->setTargetMonths($targetMonths);
        $goal->setDescription($description);
        $goal->setTargetDate($targetDate);
        $goal->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->mapper->insert($goal);
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
        ?string $targetDate = null
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
        if ($currentAmount !== null) {
            $goal->setCurrentAmount($currentAmount);
        }
        if ($description !== null) {
            $goal->setDescription($description);
        }
        if ($targetDate !== null) {
            $goal->setTargetDate($targetDate);
        }

        return $this->mapper->update($goal);
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
        $goal = $this->mapper->find($id, $userId);

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
        $goal = $this->mapper->find($id, $userId);
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
}
