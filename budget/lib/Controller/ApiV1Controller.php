<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCA\Budget\Service\OcrSettingsService;
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
        private OcrSettingsService $ocrSettings,
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
            'api_version' => self::API_VERSION,
            'app_version' => $this->appVersion(),
            'user_id' => $this->userId,
            'base_currency' => $this->conversionService->getBaseCurrency($this->userId),
            'features' => [
                'accounts' => true,
                'categories' => true,
                'transactions' => true,
                'create_transaction' => true,
                'receipt_upload' => true,
                // Server-side receipt extraction (#533): true only when this
                // instance has an OCR provider configured AND usable, so the
                // capture flow never appears on a server that cannot serve it.
                'receipt_ocr' => $this->ocrSettings->isConfigured(),
            ],
            'limits' => [
                'max_receipt_bytes' => AttachmentService::MAX_SIZE,
                'receipt_mime_types' => AttachmentService::ALLOWED_MIMES,
                'receipt_ocr_mime_types' => ReceiptExtractionService::EXTRACT_MIMES,
                'transactions_max_limit' => ApiV1TransactionController::MAX_LIMIT,
            ],
        ]);
    }

    /**
     * The capture app's first call, shaped exactly as its handoff contract
     * specifies. GET / (info) carries the same facts and more — this stays
     * the app's minimal, stable view of them.
     */
    #[NoAdminRequired]
    public function capabilities(): DataResponse {
        return new DataResponse([
            'ocr_available' => $this->ocrSettings->isConfigured(),
            // Whether this server accepts per-item splits (a `splits` field on
            // create, and POST /transactions/{id}/splits). A hard-coded true
            // on purpose: it is a property of the code that is answering, so a
            // client can gate its per-item UI on one boolean instead of
            // parsing `version` — which is documented as advisory, never a
            // gate. An older server omits the key entirely, and a client
            // reading it as false gets exactly the right behaviour.
            'splits_available' => true,
            'currency' => $this->conversionService->getBaseCurrency($this->userId),
            'version' => $this->appVersion(),
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
