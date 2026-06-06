<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\ImportTemplateService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IAppData;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ImportController extends Controller {
    use ApiErrorHandlerTrait;

    private ImportService $service;
    private ImportTemplateService $templateService;
    private AuditService $auditService;
    private IAppData $appData;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        ImportService $service,
        ImportTemplateService $templateService,
        AuditService $auditService,
        IAppData $appData,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->templateService = $templateService;
        $this->auditService = $auditService;
        $this->appData = $appData;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Resolve a saved import template into concrete import parameters.
     *
     * For CSV templates the stored column mapping + delimiter take precedence.
     * For OFX/QIF templates the stored account routing is used, with any routing
     * supplied in the request taking precedence per source account (so a user can
     * tweak one destination this run without losing the rest). The template's
     * default account fills in only when the request did not specify one.
     *
     * Import options (skip-duplicates, apply-rules) are intentionally NOT forced
     * here — the frontend applies them to the controls on select so the user's
     * final toggle still wins; the request value is authoritative.
     *
     * @param array{mapping: array, accountId: ?int, delimiter: string, accountMapping: array} $params
     * @return array{mapping: array, accountId: ?int, delimiter: string, accountMapping: array}
     */
    private function applyTemplate(int $templateId, array $params): array {
        $template = $this->templateService->find($templateId, $this->userId);
        $format = $template->getFormat() ?? 'csv';

        if ($format === 'csv') {
            $params['mapping'] = $template->getParsedMapping();
            $params['delimiter'] = $template->getDelimiter() ?: $params['delimiter'];
        } else {
            // Union (+) not array_merge: numeric account-number keys must be preserved.
            $requested = is_array($params['accountMapping'] ?? null) ? $params['accountMapping'] : [];
            $params['accountMapping'] = $requested + $template->getParsedAccountMapping();
        }

        if (($params['accountId'] ?? null) === null) {
            $params['accountId'] = $template->getAccountId();
        }
        return $params;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function upload(): DataResponse {
        try {
            $uploadedFile = $this->request->getUploadedFile('file');
            if (!$uploadedFile) {
                return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
            }

            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => $this->l->t('File upload failed')], Http::STATUS_BAD_REQUEST);
            }

            // Log import start
            $this->auditService->logImportStarted(
                $this->userId,
                $uploadedFile['name'],
                pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)
            );

            $result = $this->service->processUpload($this->userId, $uploadedFile);
            return new DataResponse($result);
        } catch (\Exception $e) {
            // Log import failure
            $this->auditService->logImportFailed(
                $this->userId,
                $uploadedFile['name'] ?? 'unknown',
                $e->getMessage()
            );
            return $this->handleError($e, $this->l->t('Failed to upload file'));
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
        bool $skipDuplicates = true,
        string $delimiter = ',',
        ?string $presetId = null,
        ?int $templateId = null
    ): DataResponse {
        try {
            if ($templateId !== null) {
                ['mapping' => $mapping, 'accountId' => $accountId, 'delimiter' => $delimiter, 'accountMapping' => $accountMapping] =
                    $this->applyTemplate($templateId, [
                        'mapping' => $mapping,
                        'accountId' => $accountId,
                        'delimiter' => $delimiter,
                        'accountMapping' => $accountMapping ?? [],
                    ]);
            }

            $preview = $this->service->previewImport(
                $this->userId,
                $fileId,
                $mapping,
                $accountId,
                $accountMapping,
                $skipDuplicates,
                $delimiter,
                $presetId
            );
            return new DataResponse($preview);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to preview import'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function process(
        string $fileId,
        array $mapping = [],
        ?int $accountId = null,
        ?array $accountMapping = null,
        bool $skipDuplicates = true,
        bool $applyRules = true,
        string $delimiter = ',',
        ?string $presetId = null,
        ?int $templateId = null
    ): DataResponse {
        try {
            if ($templateId !== null) {
                ['mapping' => $mapping, 'accountId' => $accountId, 'delimiter' => $delimiter, 'accountMapping' => $accountMapping] =
                    $this->applyTemplate($templateId, [
                        'mapping' => $mapping,
                        'accountId' => $accountId,
                        'delimiter' => $delimiter,
                        'accountMapping' => $accountMapping ?? [],
                    ]);
            }

            $result = $this->service->processImport(
                $this->userId,
                $fileId,
                $mapping,
                $accountId,
                $accountMapping,
                $skipDuplicates,
                $applyRules,
                $delimiter,
                $presetId
            );

            // Log completed imports for each account
            if (!empty($result['accountResults'])) {
                foreach ($result['accountResults'] as $accountResult) {
                    if (!empty($accountResult['destinationAccountId']) && $accountResult['imported'] > 0) {
                        $this->auditService->logImportCompleted(
                            $this->userId,
                            (int) $accountResult['destinationAccountId'],
                            $accountResult['imported'],
                            $accountResult['skipped'] ?? 0
                        );
                    }
                }
            }

            return new DataResponse($result);
        } catch (\Exception $e) {
            $this->auditService->logImportFailed($this->userId, $fileId, $e->getMessage());
            return $this->handleError($e, $this->l->t('Failed to process import'));
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
            return $this->handleError($e, $this->l->t('Failed to retrieve import templates'));
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
            return $this->handleError($e, $this->l->t('Failed to retrieve import history'));
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
            return $this->handleError($e, $this->l->t('Failed to validate file'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
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
            return $this->handleError($e, $this->l->t('Failed to execute import'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function rollback(int $importId): DataResponse {
        try {
            $result = $this->service->rollbackImport($this->userId, $importId);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to rollback import'), Http::STATUS_BAD_REQUEST, ['importId' => $importId]);
        }
    }
}