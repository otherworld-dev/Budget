<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Asset;
use OCA\Budget\Service\AssetProjector;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AssetController extends Controller {
	use ApiErrorHandlerTrait;
	use InputValidationTrait;

	private AssetService $service;
	private AssetProjector $projector;
	private ValidationService $validationService;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		AssetService $service,
		AssetProjector $projector,
		ValidationService $validationService,
		?string $userId,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->service = $service;
		$this->projector = $projector;
		$this->validationService = $validationService;
		$this->userId = $userId;
		$this->setLogger($logger);
		$this->setInputValidator($validationService);
	}

	/**
	 * Get the current user ID or throw an error if not authenticated.
	 */
	private function getUserId(): string {
		if ($this->userId === null) {
			throw new \RuntimeException('User not authenticated');
		}
		return $this->userId;
	}

	// =====================
	// Asset CRUD
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			$assets = $this->service->findAll($this->getUserId());
			return new DataResponse($assets);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve assets');
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		try {
			$asset = $this->service->find($id, $this->getUserId());
			return new DataResponse($asset);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, 'Asset', ['assetId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(): DataResponse {
		try {
			$rawInput = file_get_contents('php://input');
			$data = json_decode($rawInput, true);

			if (!$data) {
				return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
			}

			$name = $data['name'] ?? null;
			$type = $data['type'] ?? null;
			$description = $data['description'] ?? null;
			$currency = $data['currency'] ?? null;
			$currentValue = isset($data['currentValue']) ? (float)$data['currentValue'] : null;
			$purchasePrice = isset($data['purchasePrice']) ? (float)$data['purchasePrice'] : null;
			$purchaseDate = $data['purchaseDate'] ?? null;
			$annualChangeRate = isset($data['annualChangeRate']) ? (float)$data['annualChangeRate'] : null;

			// Validate required fields
			if (!$name || !$type) {
				return new DataResponse(['error' => 'Name and type are required'], Http::STATUS_BAD_REQUEST);
			}

			// Validate name
			$nameValidation = $this->validationService->validateName($name, true);
			if (!$nameValidation['valid']) {
				return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$name = $nameValidation['sanitized'];

			// Validate asset type
			if (!in_array($type, Asset::VALID_TYPES, true)) {
				return new DataResponse([
					'error' => 'Invalid asset type. Must be one of: ' . implode(', ', Asset::VALID_TYPES)
				], Http::STATUS_BAD_REQUEST);
			}

			// Validate description if provided
			if ($description !== null && $description !== '') {
				$descValidation = $this->validationService->validateDescription($description, false);
				if (!$descValidation['valid']) {
					return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$description = $descValidation['sanitized'];
			}

			// Validate currency if provided
			if ($currency !== null && strlen($currency) !== 3) {
				return new DataResponse(['error' => 'Currency must be a 3-letter code'], Http::STATUS_BAD_REQUEST);
			}

			// Validate numeric fields
			if ($currentValue !== null && $currentValue < 0) {
				return new DataResponse(['error' => 'Current value cannot be negative'], Http::STATUS_BAD_REQUEST);
			}
			if ($purchasePrice !== null && $purchasePrice < 0) {
				return new DataResponse(['error' => 'Purchase price cannot be negative'], Http::STATUS_BAD_REQUEST);
			}
			if ($annualChangeRate !== null && ($annualChangeRate < -1 || $annualChangeRate > 1)) {
				return new DataResponse(['error' => 'Annual change rate must be between -1 and 1 (e.g., 0.03 for 3%)'], Http::STATUS_BAD_REQUEST);
			}

			// Validate purchase date if provided
			if ($purchaseDate !== null && $purchaseDate !== '') {
				$dateValidation = $this->validationService->validateDate($purchaseDate, 'Purchase date', false);
				if (!$dateValidation['valid']) {
					return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			}

			$asset = $this->service->create(
				$this->getUserId(),
				$name,
				$type,
				$description,
				$currency,
				$currentValue,
				$purchasePrice,
				$purchaseDate,
				$annualChangeRate
			);
			return new DataResponse($asset, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to create asset');
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id): DataResponse {
		try {
			$rawInput = file_get_contents('php://input');
			$data = json_decode($rawInput, true);

			if (!$data) {
				return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
			}

			$name = $data['name'] ?? null;
			$type = $data['type'] ?? null;
			$description = $data['description'] ?? null;
			$currency = $data['currency'] ?? null;
			$currentValue = isset($data['currentValue']) ? (float)$data['currentValue'] : null;
			$purchasePrice = isset($data['purchasePrice']) ? (float)$data['purchasePrice'] : null;
			$purchaseDate = $data['purchaseDate'] ?? null;
			$annualChangeRate = isset($data['annualChangeRate']) ? (float)$data['annualChangeRate'] : null;

			// Validate name if provided
			if ($name !== null) {
				$nameValidation = $this->validationService->validateName($name, false);
				if (!$nameValidation['valid']) {
					return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$name = $nameValidation['sanitized'];
			}

			// Validate asset type if provided
			if ($type !== null && !in_array($type, Asset::VALID_TYPES, true)) {
				return new DataResponse([
					'error' => 'Invalid asset type. Must be one of: ' . implode(', ', Asset::VALID_TYPES)
				], Http::STATUS_BAD_REQUEST);
			}

			// Validate description if provided
			if ($description !== null && $description !== '') {
				$descValidation = $this->validationService->validateDescription($description, false);
				if (!$descValidation['valid']) {
					return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$description = $descValidation['sanitized'];
			}

			// Validate currency if provided
			if ($currency !== null && strlen($currency) !== 3) {
				return new DataResponse(['error' => 'Currency must be a 3-letter code'], Http::STATUS_BAD_REQUEST);
			}

			// Validate numeric fields
			if ($currentValue !== null && $currentValue < 0) {
				return new DataResponse(['error' => 'Current value cannot be negative'], Http::STATUS_BAD_REQUEST);
			}
			if ($purchasePrice !== null && $purchasePrice < 0) {
				return new DataResponse(['error' => 'Purchase price cannot be negative'], Http::STATUS_BAD_REQUEST);
			}
			if ($annualChangeRate !== null && ($annualChangeRate < -1 || $annualChangeRate > 1)) {
				return new DataResponse(['error' => 'Annual change rate must be between -1 and 1 (e.g., 0.03 for 3%)'], Http::STATUS_BAD_REQUEST);
			}

			// Validate purchase date if provided
			if ($purchaseDate !== null && $purchaseDate !== '') {
				$dateValidation = $this->validationService->validateDate($purchaseDate, 'Purchase date', false);
				if (!$dateValidation['valid']) {
					return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			}

			$asset = $this->service->update(
				$id,
				$this->getUserId(),
				$name,
				$type,
				$description,
				$currency,
				$currentValue,
				$purchasePrice,
				$purchaseDate,
				$annualChangeRate
			);
			return new DataResponse($asset);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to update asset', Http::STATUS_BAD_REQUEST, ['assetId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->getUserId());
			return new DataResponse(['message' => 'Asset deleted successfully']);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to delete asset', Http::STATUS_BAD_REQUEST, ['assetId' => $id]);
		}
	}

	// =====================
	// Snapshot Endpoints
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function snapshots(int $id): DataResponse {
		try {
			$snapshots = $this->service->getSnapshots($id, $this->getUserId());
			return new DataResponse($snapshots);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve snapshots', Http::STATUS_BAD_REQUEST, ['assetId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function createSnapshot(int $id): DataResponse {
		try {
			$rawInput = file_get_contents('php://input');
			$data = json_decode($rawInput, true);

			if (!$data) {
				return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
			}

			$value = isset($data['value']) ? (float)$data['value'] : null;
			$date = $data['date'] ?? null;

			if ($value === null || $date === null) {
				return new DataResponse(['error' => 'Value and date are required'], Http::STATUS_BAD_REQUEST);
			}

			// Validate value
			if ($value < 0) {
				return new DataResponse(['error' => 'Value cannot be negative'], Http::STATUS_BAD_REQUEST);
			}

			// Validate date
			$dateValidation = $this->validationService->validateDate($date, 'Date', true);
			if (!$dateValidation['valid']) {
				return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
			}

			$snapshot = $this->service->createSnapshot($id, $this->getUserId(), $value, $date);
			return new DataResponse($snapshot, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to create snapshot', Http::STATUS_BAD_REQUEST, ['assetId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroySnapshot(int $snapshotId): DataResponse {
		try {
			$this->service->deleteSnapshot($snapshotId, $this->getUserId());
			return new DataResponse(['message' => 'Snapshot deleted successfully']);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to delete snapshot', Http::STATUS_BAD_REQUEST, ['snapshotId' => $snapshotId]);
		}
	}

	// =====================
	// Summary & Projections
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function summary(): DataResponse {
		try {
			$summary = $this->service->getSummary($this->getUserId());
			return new DataResponse($summary);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve asset summary');
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function projection(int $id, ?int $years = null): DataResponse {
		try {
			$projection = $this->projector->getProjection($id, $this->getUserId(), $years ?? 10);
			return new DataResponse($projection);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve asset projection', Http::STATUS_BAD_REQUEST, ['assetId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function combinedProjection(?int $years = null): DataResponse {
		try {
			$projection = $this->projector->getCombinedProjection($this->getUserId(), $years ?? 10);
			return new DataResponse($projection);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve combined asset projection');
		}
	}
}
