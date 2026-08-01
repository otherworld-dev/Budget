<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\CurrencyConversionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Discovery endpoint for the public REST API (v1).
 *
 * A client hits this first: it confirms the app is installed and the
 * credentials work, and it reports which optional features this instance
 * actually has, so the app can hide capture flows the server cannot serve
 * rather than failing at the point of use.
 */
class ApiV1Controller extends OCSController {
    /**
     * Contract version of /ocs/v2.php/apps/budget/api/v1. Bumped only for a
     * breaking change; additive fields keep the same version.
     */
    public const API_VERSION = '1.0';

    private string $userId;

    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
        private CurrencyConversionService $conversionService,
        ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
        // OCS routes instantiate the controller before the security middleware
        // runs its auth check, so an unauthenticated request arrives here with
        // a null user. Typing it non-null makes that a TypeError — an empty
        // 200 and a PHP error in the log — instead of the clean 401 the
        // middleware is about to produce.
        $this->userId = $userId ?? '';
    }

    #[NoAdminRequired]
    public function info(): DataResponse {
        return new DataResponse([
            'apiVersion' => self::API_VERSION,
            'appVersion' => $this->appVersion(),
            'userId' => $this->userId,
            'baseCurrency' => $this->conversionService->getBaseCurrency($this->userId),
            'features' => [
                'accounts' => true,
                'categories' => true,
                'transactions' => true,
                'createTransaction' => true,
                'receiptUpload' => true,
                // Server-side receipt extraction (#531). Always false in v1;
                // clients must keep working when it flips to true.
                'receiptOcr' => false,
            ],
            'limits' => [
                'maxReceiptBytes' => AttachmentService::MAX_SIZE,
                'receiptMimeTypes' => AttachmentService::ALLOWED_MIMES,
                'transactionsMaxLimit' => ApiV1TransactionController::MAX_LIMIT,
            ],
        ]);
    }

    /**
     * The installed app version, or null when the app manager cannot resolve
     * it — a client should treat the version as advisory, never as a gate.
     */
    private function appVersion(): ?string {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
