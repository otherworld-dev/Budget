<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AttachmentService;
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
 * Receipt attachments on transactions. Owner-only (v1): shared-account
 * viewers cannot resolve the owner's files, so all endpoints scope strictly
 * to the requesting user's own transactions.
 */
class AttachmentController extends Controller {
	use ApiErrorHandlerTrait;

	public function __construct(
		IRequest $request,
		private AttachmentService $service,
		private IL10N $l,
		private string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
	}

	/**
	 * Attachment counts per transaction (for list badges).
	 * @NoAdminRequired
	 */
	public function counts(): DataResponse {
		try {
			return new DataResponse($this->service->getCounts($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load attachments'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(int $id): DataResponse {
		try {
			return new DataResponse($this->service->listForTransaction($id, $this->userId));
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => $this->l->t('Transaction not found')], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load attachments'));
		}
	}

	/**
	 * Attach an existing file from the user's Files (by picker path or fileId).
	 * @NoAdminRequired
	 */
	public function attach(int $id, ?int $fileId = null, ?string $path = null): DataResponse {
		try {
			$attachment = $this->service->attachExisting($id, $this->userId, $fileId, $path);
			return new DataResponse($attachment, Http::STATUS_CREATED);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => $this->l->t('Transaction not found')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(['error' => $this->l->t('File not found')], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to attach file'));
		}
	}

	/**
	 * Upload a new receipt file and attach it.
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function upload(int $id): DataResponse {
		try {
			$uploadedFile = $this->request->getUploadedFile('file');
			if (!$uploadedFile) {
				return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
			}

			$attachment = $this->service->upload($id, $this->userId, $uploadedFile);
			return new DataResponse($attachment, Http::STATUS_CREATED);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => $this->l->t('Transaction not found')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to upload receipt'));
		}
	}

	/**
	 * Remove the attachment reference (never deletes the file).
	 * @NoAdminRequired
	 */
	public function detach(int $id, int $attachmentId): DataResponse {
		try {
			$this->service->detach($id, $this->userId, $attachmentId);
			return new DataResponse(['message' => $this->l->t('Attachment removed')]);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => $this->l->t('Attachment not found')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to remove attachment'));
		}
	}
}
