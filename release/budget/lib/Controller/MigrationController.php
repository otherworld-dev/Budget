<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for data migration (export/import) operations.
 */
class MigrationController extends Controller {
    use ApiErrorHandlerTrait;

    public function __construct(
        IRequest $request,
        private MigrationService $migrationService,
        private AuditService $auditService,
        private string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->setLogger($logger);
    }

    /**
     * Export all user data as a ZIP file.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 5, period: 300)]
    public function export(): DataDownloadResponse|DataResponse {
        try {
            $result = $this->migrationService->exportAll($this->userId);

            // Log the export
            $this->auditService->log(
                $this->userId,
                'data_export',
                'migration',
                0,
                ['filename' => $result['filename']]
            );

            return new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['contentType']
            );
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to export data');
        }
    }

    /**
     * Preview import without executing.
     * Returns information about the data in the uploaded file.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function preview(): DataResponse {
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!$uploadedFile) {
                return new DataResponse(
                    ['error' => 'No file uploaded'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(
                    ['error' => 'File upload failed'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $zipContent = file_get_contents($uploadedFile['tmp_name']);
            $preview = $this->migrationService->previewImport($zipContent);

            return new DataResponse($preview);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage(), 'valid' => false],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to preview import');
        }
    }

    /**
     * Import data from a ZIP file.
     * This replaces ALL existing data for the user.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 300)]
    public function import(): DataResponse {
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!$uploadedFile) {
                return new DataResponse(
                    ['error' => 'No file uploaded'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(
                    ['error' => 'File upload failed'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Confirmation parameter to prevent accidental imports
            $confirmed = $this->request->getParam('confirmed', false);
            if (!$confirmed) {
                return new DataResponse(
                    ['error' => 'Import not confirmed. This operation will replace ALL existing data. Pass confirmed=true to proceed.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $zipContent = file_get_contents($uploadedFile['tmp_name']);

            // Log import start
            $this->auditService->log(
                $this->userId,
                'data_import_start',
                'migration',
                0,
                ['filename' => $uploadedFile['name']]
            );

            $result = $this->migrationService->importAll($this->userId, $zipContent);

            // Log import completion
            $this->auditService->log(
                $this->userId,
                'data_import_complete',
                'migration',
                0,
                ['counts' => $result['counts']]
            );

            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            $this->auditService->log(
                $this->userId,
                'data_import_failed',
                'migration',
                0,
                ['error' => $e->getMessage()]
            );
            return new DataResponse(
                ['error' => $e->getMessage(), 'success' => false],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->auditService->log(
                $this->userId,
                'data_import_failed',
                'migration',
                0,
                ['error' => $e->getMessage()]
            );
            return $this->handleError($e, 'Failed to import data');
        }
    }
}
