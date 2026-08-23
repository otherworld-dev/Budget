<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryTransferService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Export and import of the user's category tree as a file (#354).
 *
 * Web-UI back end only (not part of the public OCS API). Always acts on the
 * caller's own categories — shared categories belong to their owner and are
 * neither exported nor touched by an import.
 */
class CategoryTransferController extends Controller {
    use ApiErrorHandlerTrait;

    private CategoryTransferService $service;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryTransferService $service,
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
     * Download the category tree as a JSON file.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function export(): DataDownloadResponse|DataResponse {
        try {
            $json = json_encode(
                $this->service->export($this->userId),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            return new DataDownloadResponse(
                $json . "\n",
                'budget-categories-' . date('Y-m-d') . '.json',
                'application/json'
            );
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to export categories'));
        }
    }

    /**
     * Parse a file and report what importing it would create, without
     * writing anything.
     *
     * @NoAdminRequired
     * @param string $content The file's text (JSON or CSV)
     * @param string|null $format 'json' or 'csv'; detected when omitted
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function preview(string $content, ?string $format = null): DataResponse {
        try {
            $parsed = $this->service->parse($content, $format);
            $plan = $this->service->plan($this->userId, $parsed['categories']);
            return new DataResponse([
                'categories' => $plan['categories'],
                'counts' => $plan['counts'],
                'warnings' => $parsed['warnings'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to read the category file'));
        }
    }

    /**
     * Parse a file and create the categories it holds that do not exist yet.
     *
     * @NoAdminRequired
     * @param string $content The file's text (JSON or CSV)
     * @param string|null $format 'json' or 'csv'; detected when omitted
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function import(string $content, ?string $format = null): DataResponse {
        try {
            $parsed = $this->service->parse($content, $format);
            $result = $this->service->import($this->userId, $parsed['categories']);
            return new DataResponse($result + ['warnings' => $parsed['warnings']]);
        } catch (\InvalidArgumentException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to import categories'));
        }
    }
}
