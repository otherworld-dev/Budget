<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class PensionProjector {
    private PensionAccountMapper $pensionMapper;
    private PensionService $pensionService;

    public function __construct(
        PensionAccountMapper $pensionMapper,
        PensionService $pensionService
    ) {
        $this->pensionMapper = $pensionMapper;
        $this->pensionService = $pensionService;
    }

    /**
     * Get projection for a single pension.
     *
     * @throws DoesNotExistException
     */
    public function getProjection(int $pensionId, string $userId, ?int $currentAge = null): array {
        $pension = $this->pensionMapper->find($pensionId, $userId);

        if ($pension->isDefinedContribution()) {
            return $this->projectDCPension($pension, $currentAge);
        } elseif ($pension->isDefinedBenefit()) {
            return $this->projectDBPension($pension, $currentAge);
        } else {
            return $this->projectStatePension($pension, $currentAge);
        }
    }

    /**
     * Get combined projection for all pensions.
     */
    public function getCombinedProjection(string $userId, ?int $currentAge = null): array {
        $pensions = $this->pensionMapper->findAll($userId);

        $totalCurrentValue = 0.0;
        $totalProjectedValue = 0.0;
        $totalProjectedAnnualIncome = 0.0;
        $projections = [];

        foreach ($pensions as $pension) {
            $projection = $this->getProjection($pension->getId(), $userId, $currentAge);
            $projections[] = $projection;

            if ($pension->isDefinedContribution()) {
                $totalCurrentValue += $pension->getCurrentBalance() ?? 0;
                $totalProjectedValue += $projection['projectedValue'] ?? 0;
                // Estimate annual income from DC pot (4% withdrawal rate)
                $totalProjectedAnnualIncome += ($projection['projectedValue'] ?? 0) * 0.04;
            } elseif ($pension->isDefinedBenefit()) {
                $totalCurrentValue += $pension->getTransferValue() ?? 0;
                $totalProjectedAnnualIncome += $pension->getAnnualIncome() ?? 0;
            } else {
                $totalProjectedAnnualIncome += $pension->getAnnualIncome() ?? 0;
            }
        }

        return [
            'totalCurrentValue' => $totalCurrentValue,
            'totalProjectedValue' => $totalProjectedValue,
            'totalProjectedAnnualIncome' => round($totalProjectedAnnualIncome, 2),
            'totalProjectedMonthlyIncome' => round($totalProjectedAnnualIncome / 12, 2),
            'pensionCount' => count($pensions),
            'projections' => $projections,
        ];
    }

    /**
     * Project a defined contribution pension.
     */
    private function projectDCPension(PensionAccount $pension, ?int $currentAge = null): array {
        $currentBalance = $pension->getCurrentBalance() ?? 0;
        $monthlyContribution = $pension->getMonthlyContribution() ?? 0;
        $annualReturnRate = $pension->getExpectedReturnRate() ?? 0.05;
        $retirementAge = $pension->getRetirementAge() ?? 65;

        // Calculate years until retirement
        $yearsToRetirement = $currentAge !== null ? max(0, $retirementAge - $currentAge) : 25;
        $monthsToRetirement = $yearsToRetirement * 12;

        // Project future value using compound interest formula with regular contributions
        // FV = PV * (1 + r)^n + PMT * ((1 + r)^n - 1) / r
        $projectedValue = $this->calculateFutureValue(
            $currentBalance,
            $monthlyContribution,
            $annualReturnRate / 12,
            $monthsToRetirement
        );

        // Calculate required contribution to reach a target (e.g., 25x desired income)
        $targetPot = 500000; // Example target
        $requiredMonthly = $this->calculateRequiredContribution(
            $currentBalance,
            $targetPot,
            $annualReturnRate / 12,
            $monthsToRetirement
        );

        // Generate growth projections for chart
        $growthProjection = $this->generateGrowthProjection(
            $currentBalance,
            $monthlyContribution,
            $annualReturnRate / 12,
            $yearsToRetirement
        );

        // Estimated annual income (4% withdrawal rate)
        $estimatedAnnualIncome = $projectedValue * 0.04;

        return [
            'pensionId' => $pension->getId(),
            'pensionName' => $pension->getName(),
            'type' => $pension->getType(),
            'currentBalance' => $currentBalance,
            'monthlyContribution' => $monthlyContribution,
            'expectedReturnRate' => $annualReturnRate,
            'retirementAge' => $retirementAge,
            'yearsToRetirement' => $yearsToRetirement,
            'projectedValue' => round($projectedValue, 2),
            'estimatedAnnualIncome' => round($estimatedAnnualIncome, 2),
            'estimatedMonthlyIncome' => round($estimatedAnnualIncome / 12, 2),
            'requiredMonthlyFor500k' => round($requiredMonthly, 2),
            'growthProjection' => $growthProjection,
            'recommendations' => $this->generateDCRecommendations($pension, $projectedValue, $yearsToRetirement),
        ];
    }

    /**
     * Project a defined benefit pension.
     */
    private function projectDBPension(PensionAccount $pension, ?int $currentAge = null): array {
        $annualIncome = $pension->getAnnualIncome() ?? 0;
        $transferValue = $pension->getTransferValue() ?? 0;
        $retirementAge = $pension->getRetirementAge() ?? 65;

        $yearsToRetirement = $currentAge !== null ? max(0, $retirementAge - $currentAge) : 25;

        return [
            'pensionId' => $pension->getId(),
            'pensionName' => $pension->getName(),
            'type' => $pension->getType(),
            'annualIncome' => $annualIncome,
            'monthlyIncome' => round($annualIncome / 12, 2),
            'transferValue' => $transferValue,
            'retirementAge' => $retirementAge,
            'yearsToRetirement' => $yearsToRetirement,
            'recommendations' => $this->generateDBRecommendations($pension),
        ];
    }

    /**
     * Project state pension.
     */
    private function projectStatePension(PensionAccount $pension, ?int $currentAge = null): array {
        $annualIncome = $pension->getAnnualIncome() ?? 0;
        $retirementAge = $pension->getRetirementAge() ?? 67; // UK state pension age

        $yearsToRetirement = $currentAge !== null ? max(0, $retirementAge - $currentAge) : 30;

        return [
            'pensionId' => $pension->getId(),
            'pensionName' => $pension->getName(),
            'type' => $pension->getType(),
            'annualIncome' => $annualIncome,
            'monthlyIncome' => round($annualIncome / 12, 2),
            'retirementAge' => $retirementAge,
            'yearsToRetirement' => $yearsToRetirement,
            'recommendations' => [],
        ];
    }

    /**
     * Calculate future value with regular contributions.
     */
    private function calculateFutureValue(
        float $presentValue,
        float $monthlyPayment,
        float $monthlyRate,
        int $months
    ): float {
        if ($months <= 0) {
            return $presentValue;
        }

        if ($monthlyRate === 0.0) {
            return $presentValue + ($monthlyPayment * $months);
        }

        $compoundFactor = pow(1 + $monthlyRate, $months);

        // Future value of lump sum
        $fvLumpSum = $presentValue * $compoundFactor;

        // Future value of annuity (regular payments)
        $fvAnnuity = $monthlyPayment * (($compoundFactor - 1) / $monthlyRate);

        return $fvLumpSum + $fvAnnuity;
    }

    /**
     * Calculate required monthly contribution to reach target.
     */
    private function calculateRequiredContribution(
        float $presentValue,
        float $targetValue,
        float $monthlyRate,
        int $months
    ): float {
        if ($months <= 0) {
            return 0;
        }

        if ($monthlyRate === 0.0) {
            return ($targetValue - $presentValue) / $months;
        }

        $compoundFactor = pow(1 + $monthlyRate, $months);

        // Future value of current balance
        $fvLumpSum = $presentValue * $compoundFactor;

        // Remaining amount needed from contributions
        $remaining = $targetValue - $fvLumpSum;

        if ($remaining <= 0) {
            return 0;
        }

        // Required monthly payment
        return $remaining * $monthlyRate / ($compoundFactor - 1);
    }

    /**
     * Generate year-by-year growth projection.
     */
    private function generateGrowthProjection(
        float $presentValue,
        float $monthlyPayment,
        float $monthlyRate,
        int $years
    ): array {
        $projection = [];
        $currentYear = (int) date('Y');

        for ($year = 0; $year <= $years; $year++) {
            $months = $year * 12;
            $value = $this->calculateFutureValue($presentValue, $monthlyPayment, $monthlyRate, $months);

            $projection[] = [
                'year' => $currentYear + $year,
                'value' => round($value, 2),
            ];
        }

        return $projection;
    }

    /**
     * Generate recommendations for DC pension.
     */
    private function generateDCRecommendations(PensionAccount $pension, float $projectedValue, int $yearsToRetirement): array {
        $recommendations = [];
        $monthlyContribution = $pension->getMonthlyContribution() ?? 0;

        if ($monthlyContribution === 0.0) {
            $recommendations[] = 'Start making regular contributions to grow your pension pot';
        }

        if ($projectedValue < 100000 && $yearsToRetirement > 10) {
            $recommendations[] = 'Consider increasing contributions to build a larger retirement fund';
        }

        if ($yearsToRetirement < 10 && $projectedValue < 200000) {
            $recommendations[] = 'With retirement approaching, review if your projected pot meets your income needs';
        }

        if ($pension->getExpectedReturnRate() === null || $pension->getExpectedReturnRate() === 0.0) {
            $recommendations[] = 'Set an expected return rate for more accurate projections';
        }

        return $recommendations;
    }

    /**
     * Generate recommendations for DB pension.
     */
    private function generateDBRecommendations(PensionAccount $pension): array {
        $recommendations = [];

        if ($pension->getAnnualIncome() === null || $pension->getAnnualIncome() === 0.0) {
            $recommendations[] = 'Add your projected annual income from pension statements';
        }

        if ($pension->getTransferValue() === null) {
            $recommendations[] = 'Consider adding your transfer value for net worth tracking';
        }

        return $recommendations;
    }
}
