<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ImportFolderService;
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
 * The settings page's import folders (#425): the subfolders of the user's
 * import folder, and "Check now".
 */
class ImportFolderController extends Controller {
	use ApiErrorHandlerTrait;

	public function __construct(
		IRequest $request,
		private ImportFolderService $service,
		private IL10N $l,
		private string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			return new DataResponse($this->service->list($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load the import folders'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(string $name, int $accountId, ?int $templateId = null): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId, $name, $accountId, $templateId), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to add the import folder'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(int $id): DataResponse {
		try {
			// Only what the request carries: a templateId of null means "no
			// template", one left out means "leave it as it is"
			$changes = array_intersect_key($this->request->getParams(), array_flip(['accountId', 'templateId', 'enabled']));
			return new DataResponse($this->service->update($this->userId, $id, $changes));
		} catch (DoesNotExistException $e) {
			return $this->handleNotFoundError($e, $this->l->t('Import folder'), ['importFolderId' => $id]);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update the import folder'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($this->userId, $id);
			return new DataResponse(['status' => 'success']);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Import folder'), ['importFolderId' => $id]);
		}
	}

	/**
	 * Import what is waiting now, rather than at the next background run.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 6, period: 60)]
	public function check(): DataResponse {
		try {
			return new DataResponse($this->service->check($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to check the import folder'));
		}
	}
}
