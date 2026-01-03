<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IAppData;
use OCP\IRequest;

class ImportController extends Controller {
    private ImportService $service;
    private IAppData $appData;
    private string $userId;

    public function __construct(
        IRequest $request,
        ImportService $service,
        IAppData $appData,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->appData = $appData;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function upload(): DataResponse {
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!$uploadedFile) {
                return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => 'File upload failed'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->service->processUpload($this->userId, $uploadedFile);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function preview(
        string $fileId,
        array $mapping = [],
        ?int $accountId = null,
        ?array $accountMapping = null,
        bool $skipDuplicates = true
    ): DataResponse {
        try {
            $preview = $this->service->previewImport(
                $this->userId,
                $fileId,
                $mapping,
                $accountId,
                $accountMapping,
                $skipDuplicates
            );
            return new DataResponse($preview);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function process(
        string $fileId,
        array $mapping = [],
        ?int $accountId = null,
        ?array $accountMapping = null,
        bool $skipDuplicates = true,
        bool $applyRules = true
    ): DataResponse {
        try {
            $result = $this->service->processImport(
                $this->userId,
                $fileId,
                $mapping,
                $accountId,
                $accountMapping,
                $skipDuplicates,
                $applyRules
            );
            return new DataResponse($result);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function templates(): DataResponse {
        try {
            $templates = $this->service->getImportTemplates();
            return new DataResponse($templates);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function history(int $limit = 50): DataResponse {
        try {
            $history = $this->service->getImportHistory($this->userId, $limit);
            return new DataResponse($history);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function validateFile(string $fileId): DataResponse {
        try {
            $validation = $this->service->validateFile($this->userId, $fileId);
            return new DataResponse($validation);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function execute(
        string $importId,
        int $accountId,
        array $transactionIds
    ): DataResponse {
        try {
            $result = $this->service->executeImport(
                $this->userId,
                $importId,
                $accountId,
                $transactionIds
            );
            return new DataResponse($result);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function rollback(int $importId): DataResponse {
        try {
            $result = $this->service->rollbackImport($this->userId, $importId);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}