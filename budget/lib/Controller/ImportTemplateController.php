<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ImportTemplateService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ImportTemplateController extends Controller {
    use ApiErrorHandlerTrait;

    private ImportTemplateService $service;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        ImportTemplateService $service,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $templates = $this->service->findAll($this->userId);
            return new DataResponse($templates);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve import templates'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $template = $this->service->find($id, $this->userId);
            return new DataResponse($template);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Import template'), ['templateId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        array $mapping,
        string $delimiter = ',',
        bool $skipFirstRow = false,
        ?int $accountId = null
    ): DataResponse {
        try {
            $template = $this->service->create(
                $this->userId,
                $name,
                $mapping,
                $delimiter,
                $skipFirstRow,
                $accountId
            );
            return new DataResponse($template, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create import template'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?array $mapping = null,
        ?string $delimiter = null,
        ?bool $skipFirstRow = null,
        ?int $accountId = null
    ): DataResponse {
        try {
            $updates = [];
            if ($name !== null) {
                $updates['name'] = $name;
            }
            if ($mapping !== null) {
                $updates['mapping'] = $mapping;
            }
            if ($delimiter !== null) {
                $updates['delimiter'] = $delimiter;
            }
            if ($skipFirstRow !== null) {
                $updates['skipFirstRow'] = $skipFirstRow;
            }
            if ($accountId !== null) {
                $updates['accountId'] = $accountId;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $template = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($template);
        } catch (\InvalidArgumentException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update import template'), Http::STATUS_BAD_REQUEST, ['templateId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Import template'), ['templateId' => $id]);
        }
    }
}
