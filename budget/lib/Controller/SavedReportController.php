<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\SavedReportService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CRUD for saved report configurations (#299).
 */
class SavedReportController extends Controller {
	use ApiErrorHandlerTrait;

	private IL10N $l;
	private string $userId;

	public function __construct(
		IRequest $request,
		private SavedReportService $service,
		IL10N $l,
		string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->l = $l;
		$this->userId = $userId;
		$this->setLogger($logger);
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			return new DataResponse($this->service->getAll($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load saved reports'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(string $name, array $config = []): DataResponse {
		try {
			$report = $this->service->create($this->userId, $name, $config);
			return new DataResponse($report, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to save report'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function update(int $id, ?string $name = null, ?array $config = null): DataResponse {
		try {
			$report = $this->service->update($id, $this->userId, $name, $config);
			return new DataResponse($report);
		} catch (DoesNotExistException $e) {
			return $this->handleNotFoundError($e, 'Saved report');
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update saved report'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return $this->handleNotFoundError($e, 'Saved report');
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete saved report'));
		}
	}
}
