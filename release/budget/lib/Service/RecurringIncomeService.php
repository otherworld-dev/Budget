<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Manages recurring income CRUD operations and summary calculations.
 */
class RecurringIncomeService {
    private RecurringIncomeMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;

    public function __construct(
        RecurringIncomeMapper $mapper,
        FrequencyCalculator $frequencyCalculator
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): RecurringIncome {
        return $this->mapper->find($id, $userId);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }

    public function findExpectedThisMonth(string $userId): array {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        return $this->mapper->findExpectedInRange($userId, $startDate, $endDate);
    }

    /**
     * Find upcoming income sorted by expected date.
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        return $this->mapper->findUpcoming($userId, $days);
    }

    public function create(
        string $userId,
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $expectedDay = null,
        ?int $expectedMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $source = null,
        ?string $autoDetectPattern = null,
        ?string $notes = null
    ): RecurringIncome {
        $income = new RecurringIncome();
        $income->setUserId($userId);
        $income->setName($name);
        $income->setAmount($amount);
        $income->setFrequency($frequency);
        $income->setExpectedDay($expectedDay);
        $income->setExpectedMonth($expectedMonth);
        $income->setCategoryId($categoryId);
        $income->setAccountId($accountId);
        $income->setSource($source);
        $income->setAutoDetectPattern($autoDetectPattern);
        $income->setIsActive(true);
        $income->setNotes($notes);
        $income->setCreatedAt(date('Y-m-d H:i:s'));

        $nextExpected = $this->frequencyCalculator->calculateNextDueDate($frequency, $expectedDay, $expectedMonth);
        $income->setNextExpectedDate($nextExpected);

        return $this->mapper->insert($income);
    }

    public function update(int $id, string $userId, array $updates): RecurringIncome {
        $income = $this->find($id, $userId);

        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($income, $setter)) {
                $income->$setter($value);
            }
        }

        // Recalculate next expected date if frequency or expected day changed
        if (isset($updates['frequency']) || isset($updates['expectedDay']) || isset($updates['expectedMonth'])) {
            $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
                $income->getFrequency(),
                $income->getExpectedDay(),
                $income->getExpectedMonth()
            );
            $income->setNextExpectedDate($nextExpected);
        }

        return $this->mapper->update($income);
    }

    public function delete(int $id, string $userId): void {
        $income = $this->find($id, $userId);
        $this->mapper->delete($income);
    }

    /**
     * Mark income as received and advance to next expected date.
     */
    public function markReceived(int $id, string $userId, ?string $receivedDate = null): RecurringIncome {
        $income = $this->find($id, $userId);

        $received = $receivedDate ?? date('Y-m-d');
        $income->setLastReceivedDate($received);

        $nextExpected = $this->frequencyCalculator->calculateNextDueDate(
            $income->getFrequency(),
            $income->getExpectedDay(),
            $income->getExpectedMonth(),
            $received
        );
        $income->setNextExpectedDate($nextExpected);

        return $this->mapper->update($income);
    }

    /**
     * Get monthly summary of recurring income.
     */
    public function getMonthlySummary(string $userId): array {
        $incomes = $this->findActive($userId);
        $totalMonthly = 0.0;
        $byFrequency = [];

        foreach ($incomes as $income) {
            $monthlyEquiv = $this->getMonthlyEquivalent($income);
            $totalMonthly += $monthlyEquiv;

            $freq = $income->getFrequency();
            if (!isset($byFrequency[$freq])) {
                $byFrequency[$freq] = [
                    'count' => 0,
                    'totalMonthly' => 0.0,
                ];
            }
            $byFrequency[$freq]['count']++;
            $byFrequency[$freq]['totalMonthly'] += $monthlyEquiv;
        }

        return [
            'totalCount' => count($incomes),
            'totalMonthly' => round($totalMonthly, 2),
            'totalYearly' => round($totalMonthly * 12, 2),
            'byFrequency' => $byFrequency,
        ];
    }

    /**
     * Get income expected for a specific month.
     */
    public function getIncomeForMonth(string $userId, int $year, int $month): array {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $expected = $this->mapper->findExpectedInRange($userId, $startDate, $endDate);

        $total = 0.0;
        foreach ($expected as $income) {
            $total += $income->getAmount();
        }

        return [
            'incomes' => $expected,
            'total' => round($total, 2),
            'count' => count($expected),
        ];
    }

    /**
     * Convert any income frequency to monthly equivalent.
     */
    private function getMonthlyEquivalent(RecurringIncome $income): float {
        $amount = $income->getAmount();

        return match ($income->getFrequency()) {
            'daily' => $amount * 30,
            'weekly' => $amount * 52 / 12,
            'biweekly' => $amount * 26 / 12,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }
}
