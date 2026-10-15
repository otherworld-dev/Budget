<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class PensionProjector {
	private PensionAccountMapper $pensionMapper;
	private PensionService $pensionService;
	private CurrencyConversionService $conversionService;

	public function __construct(
		PensionAccountMapper $pensionMapper,
		PensionService $pensionService,
		CurrencyConversionService $conversionService,
		private SettingService $settingService,
	) {
		$this->pensionMapper = $pensionMapper;
		$this->pensionService = $pensionService;
		$this->conversionService = $conversionService;
	}

	/** Default projection target when neither the pension nor the user overrides it. */
	private const DEFAULT_TARGET = 500000.0;
	/** Default assumed inflation when computing "today's money" projections. */
	private const DEFAULT_INFLATION = 0.025;

	/**
	 * Get projection for a single pension.
	 *
	 * @throws DoesNotExistException
	 */
	public function getProjection(int $pensionId, string $userId, ?int $currentAge = null): array {
		$pension = $this->pensionMapper->find($pensionId, $userId);

		if ($pension->isDefinedContribution()) {
			return $this->projectDCPension($pension, $currentAge, $userId);
		} elseif ($pension->isDefinedBenefit()) {
			return $this->projectDBPension($pension, $currentAge);
		} else {
			return $this->projectStatePension($pension, $currentAge);
		}
	}

	/**
	 * Resolve the projection target for a pension: its own override, else the
	 * user's default, else the built-in default.
	 */
	private function resolveTarget(PensionAccount $pension, string $userId): float {
		$perPension = $pension->getProjectionTarget();
		if ($perPension !== null && $perPension > 0) {
			return (float)$perPension;
		}
		$userDefault = $this->settingService->get($userId, 'pension_target');
		if ($userDefault !== null && (float)$userDefault > 0) {
			return (float)$userDefault;
		}
		return self::DEFAULT_TARGET;
	}

	/** The user's assumed inflation rate for "today's money" projections. */
	private function resolveInflation(string $userId): float {
		$value = $this->settingService->get($userId, 'pension_inflation_rate');
		return $value !== null ? (float)$value : self::DEFAULT_INFLATION;
	}

	/**
	 * Get combined projection for all pensions.
	 */
	public function getCombinedProjection(string $userId, ?int $currentAge = null): array {
		$pensions = $this->pensionMapper->findAll($userId);
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);

		$totalCurrentValue = 0.0;
		$totalProjectedValue = 0.0;
		$totalProjectedAnnualIncome = 0.0;
		$projections = [];

		foreach ($pensions as $pension) {
			$projection = $this->getProjection($pension->getId(), $userId, $currentAge);
			$projections[] = $projection;

			$pensionCurrency = $pension->getCurrency() ?: $baseCurrency;

			if ($pension->isDefinedContribution()) {
				$balance = $pension->getCurrentBalance() ?? 0;
				$projectedValue = $projection['projectedValue'] ?? 0;
				$totalCurrentValue += $this->convertAmount($balance, $pensionCurrency, $baseCurrency, $userId);
				$totalProjectedValue += $this->convertAmount($projectedValue, $pensionCurrency, $baseCurrency, $userId);
				// Estimate annual income from DC pot (4% withdrawal rate)
				$totalProjectedAnnualIncome += $this->convertAmount($projectedValue, $pensionCurrency, $baseCurrency, $userId) * 0.04;
			} elseif ($pension->isDefinedBenefit()) {
				$transferValue = $pension->getTransferValue() ?? 0;
				$income = $pension->getAnnualIncome() ?? 0;
				$totalCurrentValue += $this->convertAmount($transferValue, $pensionCurrency, $baseCurrency, $userId);
				$totalProjectedAnnualIncome += $this->convertAmount($income, $pensionCurrency, $baseCurrency, $userId);
			} else {
				$income = $pension->getAnnualIncome() ?? 0;
				$totalProjectedAnnualIncome += $this->convertAmount($income, $pensionCurrency, $baseCurrency, $userId);
			}
		}

		return [
			'totalCurrentValue' => $totalCurrentValue,
			'totalProjectedValue' => $totalProjectedValue,
			'totalProjectedAnnualIncome' => round($totalProjectedAnnualIncome, 2),
			'totalProjectedMonthlyIncome' => round($totalProjectedAnnualIncome / 12, 2),
			'pensionCount' => count($pensions),
			'projections' => $projections,
			'baseCurrency' => $baseCurrency,
			'currentAge' => $currentAge,
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
	 * Project a defined contribution pension.
	 */
	private function projectDCPension(PensionAccount $pension, ?int $currentAge = null, ?string $userId = null): array {
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

		// Configurable target (per-pension override, else user default, else 500k)
		// and the contribution needed to reach it.
		$target = $userId !== null ? $this->resolveTarget($pension, $userId) : self::DEFAULT_TARGET;
		$requiredMonthly = $this->calculateRequiredContribution(
			$currentBalance,
			$target,
			$annualReturnRate / 12,
			$monthsToRetirement
		);

		// Inflation-adjusted ("today's money") view: deflate the nominal pot by
		// the assumed inflation over the years to retirement.
		$inflationRate = $userId !== null ? $this->resolveInflation($userId) : self::DEFAULT_INFLATION;
		$deflator = $yearsToRetirement > 0 ? pow(1 + $inflationRate, $yearsToRetirement) : 1.0;
		$projectedValueReal = $deflator > 0 ? $projectedValue / $deflator : $projectedValue;

		// Generate growth projections for chart (nominal + real per year)
		$growthProjection = $this->generateGrowthProjection(
			$currentBalance,
			$monthlyContribution,
			$annualReturnRate / 12,
			$yearsToRetirement,
			$inflationRate
		);

		// Estimated annual income (4% withdrawal rate)
		$estimatedAnnualIncome = $projectedValue * 0.04;

		$progressPercent = $target > 0 ? round(min(100, ($projectedValue / $target) * 100), 1) : 0.0;

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
			'projectedValueNominal' => round($projectedValue, 2),
			'projectedValueReal' => round($projectedValueReal, 2),
			'projectionTarget' => round($target, 2),
			'progressPercent' => $progressPercent,
			'inflationRate' => $inflationRate,
			'estimatedAnnualIncome' => round($estimatedAnnualIncome, 2),
			'estimatedMonthlyIncome' => round($estimatedAnnualIncome / 12, 2),
			'requiredMonthlyForTarget' => round($requiredMonthly, 2),
			// Back-compat alias (deprecated): kept for one release for older frontends.
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
		int $months,
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
		int $months,
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
		int $years,
		float $inflationRate = 0.0,
	): array {
		$projection = [];
		$currentYear = (int)date('Y');

		for ($year = 0; $year <= $years; $year++) {
			$months = $year * 12;
			$value = $this->calculateFutureValue($presentValue, $monthlyPayment, $monthlyRate, $months);
			$deflator = $inflationRate > 0 ? pow(1 + $inflationRate, $year) : 1.0;

			$projection[] = [
				'year' => $currentYear + $year,
				'value' => round($value, 2),
				'valueReal' => round($value / $deflator, 2),
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
