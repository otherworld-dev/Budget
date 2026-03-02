<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetMapper;
use OCA\Budget\Db\AssetSnapshot;
use OCA\Budget\Db\AssetSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class AssetService {
	private AssetMapper $assetMapper;
	private AssetSnapshotMapper $snapshotMapper;
	private CurrencyConversionService $conversionService;

	public function __construct(
		AssetMapper $assetMapper,
		AssetSnapshotMapper $snapshotMapper,
		CurrencyConversionService $conversionService
	) {
		$this->assetMapper = $assetMapper;
		$this->snapshotMapper = $snapshotMapper;
		$this->conversionService = $conversionService;
	}

	// =====================
	// Asset CRUD
	// =====================

	/**
	 * @return Asset[]
	 */
	public function findAll(string $userId): array {
		return $this->assetMapper->findAll($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Asset {
		return $this->assetMapper->find($id, $userId);
	}

	public function create(
		string $userId,
		string $name,
		string $type,
		?string $description = null,
		?string $currency = null,
		?float $currentValue = null,
		?float $purchasePrice = null,
		?string $purchaseDate = null,
		?float $annualChangeRate = null
	): Asset {
		$asset = new Asset();
		$asset->setUserId($userId);
		$asset->setName($name);
		$asset->setType($type);
		$asset->setDescription($description);
		$asset->setCurrency($currency ?? 'USD');
		$asset->setCurrentValue($currentValue);
		$asset->setPurchasePrice($purchasePrice);
		$asset->setPurchaseDate($purchaseDate);
		$asset->setAnnualChangeRate($annualChangeRate);

		$now = date('Y-m-d H:i:s');
		$asset->setCreatedAt($now);
		$asset->setUpdatedAt($now);

		$asset = $this->assetMapper->insert($asset);

		// Create initial snapshot if value provided
		if ($currentValue !== null) {
			$this->createSnapshot($asset->getId(), $userId, $currentValue, date('Y-m-d'));
		}

		return $asset;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function update(
		int $id,
		string $userId,
		?string $name = null,
		?string $type = null,
		?string $description = null,
		?string $currency = null,
		?float $currentValue = null,
		?float $purchasePrice = null,
		?string $purchaseDate = null,
		?float $annualChangeRate = null
	): Asset {
		$asset = $this->assetMapper->find($id, $userId);

		if ($name !== null) {
			$asset->setName($name);
		}
		if ($type !== null) {
			$asset->setType($type);
		}
		if ($description !== null) {
			$asset->setDescription($description);
		}
		if ($currency !== null) {
			$asset->setCurrency($currency);
		}
		if ($currentValue !== null) {
			$asset->setCurrentValue($currentValue);
		}
		if ($purchasePrice !== null) {
			$asset->setPurchasePrice($purchasePrice);
		}
		if ($purchaseDate !== null) {
			$asset->setPurchaseDate($purchaseDate);
		}
		if ($annualChangeRate !== null) {
			$asset->setAnnualChangeRate($annualChangeRate);
		}

		$asset->setUpdatedAt(date('Y-m-d H:i:s'));

		return $this->assetMapper->update($asset);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $id, string $userId): void {
		$asset = $this->assetMapper->find($id, $userId);

		// Delete related snapshots first
		$this->snapshotMapper->deleteByAsset($id, $userId);

		$this->assetMapper->delete($asset);
	}

	// =====================
	// Snapshot Operations
	// =====================

	/**
	 * @return AssetSnapshot[]
	 */
	public function getSnapshots(int $assetId, string $userId): array {
		// Verify asset exists and belongs to user
		$this->assetMapper->find($assetId, $userId);
		return $this->snapshotMapper->findByAsset($assetId, $userId);
	}

	public function createSnapshot(
		int $assetId,
		string $userId,
		float $value,
		string $date
	): AssetSnapshot {
		// Verify asset exists and belongs to user
		$asset = $this->assetMapper->find($assetId, $userId);

		$snapshot = new AssetSnapshot();
		$snapshot->setUserId($userId);
		$snapshot->setAssetId($assetId);
		$snapshot->setValue($value);
		$snapshot->setDate($date);
		$snapshot->setCreatedAt(date('Y-m-d H:i:s'));

		$snapshot = $this->snapshotMapper->insert($snapshot);

		// Update asset's current value to match latest snapshot
		$asset->setCurrentValue($value);
		$asset->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->assetMapper->update($asset);

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
	// Summary & Aggregates
	// =====================

	/**
	 * Get summary of all assets for a user.
	 * Values are converted to the user's base currency.
	 */
	public function getSummary(string $userId): array {
		$assets = $this->assetMapper->findAll($userId);
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);

		$totalAssetWorth = 0.0;
		$assetsByType = [];

		foreach (Asset::VALID_TYPES as $type) {
			$assetsByType[$type] = ['count' => 0, 'value' => 0.0];
		}

		foreach ($assets as $asset) {
			$assetCurrency = $asset->getCurrency() ?: $baseCurrency;
			$value = $asset->getCurrentValue() ?? 0;
			$convertedValue = $this->convertAmount($value, $assetCurrency, $baseCurrency, $userId);

			$totalAssetWorth += $convertedValue;

			$type = $asset->getType();
			if (isset($assetsByType[$type])) {
				$assetsByType[$type]['count']++;
				$assetsByType[$type]['value'] += $convertedValue;
			}
		}

		return [
			'totalAssetWorth' => round($totalAssetWorth, 2),
			'assetsByType' => $assetsByType,
			'assetCount' => count($assets),
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
}
