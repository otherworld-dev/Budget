<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class AssetProjector {
	private AssetMapper $assetMapper;
	private CurrencyConversionService $conversionService;

	public function __construct(
		AssetMapper $assetMapper,
		CurrencyConversionService $conversionService
	) {
		$this->assetMapper = $assetMapper;
		$this->conversionService = $conversionService;
	}

	/**
	 * Get projection for a single asset.
	 *
	 * @throws DoesNotExistException
	 */
	public function getProjection(int $assetId, string $userId, int $years = 10): array {
		$asset = $this->assetMapper->find($assetId, $userId);
		return $this->projectAsset($asset, $years);
	}

	/**
	 * Get combined projection for all assets.
	 */
	public function getCombinedProjection(string $userId, int $years = 10): array {
		$assets = $this->assetMapper->findAll($userId);
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);

		$totalCurrentValue = 0.0;
		$totalProjectedValue = 0.0;
		$projections = [];

		foreach ($assets as $asset) {
			$projection = $this->projectAsset($asset, $years);
			$projections[] = $projection;

			$assetCurrency = $asset->getCurrency() ?: $baseCurrency;
			$currentValue = $asset->getCurrentValue() ?? 0;
			$projectedValue = $projection['projectedValue'] ?? $currentValue;

			$totalCurrentValue += $this->convertAmount($currentValue, $assetCurrency, $baseCurrency, $userId);
			$totalProjectedValue += $this->convertAmount($projectedValue, $assetCurrency, $baseCurrency, $userId);
		}

		return [
			'totalCurrentValue' => round($totalCurrentValue, 2),
			'totalProjectedValue' => round($totalProjectedValue, 2),
			'assetCount' => count($assets),
			'projections' => $projections,
			'baseCurrency' => $baseCurrency,
		];
	}

	/**
	 * Project a single asset's value over time using compound appreciation/depreciation.
	 */
	private function projectAsset(Asset $asset, int $years): array {
		$currentValue = $asset->getCurrentValue() ?? 0;
		$annualRate = $asset->getAnnualChangeRate() ?? 0;

		// Calculate projected value: currentValue * (1 + rate)^years
		$projectedValue = $annualRate != 0
			? $currentValue * pow(1 + $annualRate, $years)
			: $currentValue;

		// Generate year-by-year projection
		$growthProjection = $this->generateGrowthProjection($currentValue, $annualRate, $years);

		return [
			'assetId' => $asset->getId(),
			'assetName' => $asset->getName(),
			'type' => $asset->getType(),
			'currentValue' => $currentValue,
			'annualChangeRate' => $annualRate,
			'projectedValue' => round($projectedValue, 2),
			'totalChange' => round($projectedValue - $currentValue, 2),
			'totalChangePercent' => $currentValue > 0
				? round((($projectedValue - $currentValue) / $currentValue) * 100, 2)
				: 0,
			'growthProjection' => $growthProjection,
		];
	}

	/**
	 * Generate year-by-year projection.
	 */
	private function generateGrowthProjection(float $currentValue, float $annualRate, int $years): array {
		$projection = [];
		$currentYear = (int)date('Y');

		for ($year = 0; $year <= $years; $year++) {
			$value = $annualRate != 0
				? $currentValue * pow(1 + $annualRate, $year)
				: $currentValue;

			$projection[] = [
				'year' => $currentYear + $year,
				'value' => round($value, 2),
			];
		}

		return $projection;
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
}
