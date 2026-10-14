<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Exception\ValidationException;
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
	public function create(string $name, int $accountId, ?int $templateId = null, string $mode = 'auto'): DataResponse {
		try {
			['folder' => $folder, 'leftAlone' => $leftAlone] = $this->service->create($this->userId, $name, $accountId, $templateId, $mode);
			// How many files were in the folder already: left where they are,
			// which the page tells the user
			return new DataResponse($folder->jsonSerialize() + ['leftAlone' => $leftAlone], Http::STATUS_CREATED);
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
			$changes = array_intersect_key($this->request->getParams(), array_flip(['accountId', 'templateId', 'enabled', 'mode']));
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

	/**
	 * The files waiting in the user's Review folders.
	 *
	 * @NoAdminRequired
	 */
	public function review(): DataResponse {
		try {
			return new DataResponse($this->service->listReview($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load the files waiting for review'));
		}
	}

	/**
	 * Read a waiting file in as an upload, for the import wizard.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 5, period: 60)]
	public function openReview(int $fileId): DataResponse {
		try {
			return new DataResponse($this->service->openReview($this->userId, $fileId));
		} catch (DoesNotExistException $e) {
			return $this->handleNotFoundError($e, $this->l->t('Waiting file'), ['fileId' => $fileId]);
		} catch (ValidationException|\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to open the file'));
		}
	}

	/**
	 * Move a waiting file to Imported (the wizard imported it) or Skipped.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function finishReview(int $fileId, string $outcome = ''): DataResponse {
		try {
			$this->service->finishReview($this->userId, $fileId, $outcome);
			return new DataResponse(['status' => 'success']);
		} catch (DoesNotExistException $e) {
			return $this->handleNotFoundError($e, $this->l->t('Waiting file'), ['fileId' => $fileId]);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to move the file'));
		}
	}
}
