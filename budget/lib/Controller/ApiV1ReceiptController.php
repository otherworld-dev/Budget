<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Receipt extraction over the public REST API (v1) — #533.
 *
 * Capture-before-save: the client sends a photographed receipt and gets back
 * a draft transaction to show the user, then records it (or not) through the
 * ordinary POST /transactions. Nothing is written here — extraction has no
 * side effects, so a retry is always safe.
 *
 * Clients discover whether this endpoint works via features.receiptOcr on
 * GET /api/v1; a 501 from here means the server has no provider configured.
 */
class ApiV1ReceiptController extends OCSController {
    private string $userId;

    public function __construct(
        IRequest $request,
        private ReceiptExtractionService $extractionService,
        private IL10N $l,
        ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
        // Null until the security middleware rejects the request — see
        // ApiV1Controller for why this must not be typed non-null.
        $this->userId = $userId ?? '';
    }

    /**
     * Extract a draft transaction from a receipt photo, uploaded as
     * multipart/form-data under the field `file` (JPEG, PNG or WebP).
     *
     * Rate-limited harder than uploads: every call costs an OCR/vision run
     * on whatever backend the admin is paying for or hosting.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function extract(): DataResponse {
        $uploadedFile = $this->request->getUploadedFile('file');
        if (!$uploadedFile) {
            return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $draft = $this->extractionService->extract($this->userId, $uploadedFile);

            return new DataResponse(ApiSerializer::receiptDraft($draft));
        } catch (OcrNotConfiguredException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Receipt scanning is not set up on this server')],
                Http::STATUS_NOT_IMPLEMENTED
            );
        } catch (OcrProviderException $e) {
            return new DataResponse(
                ['error' => $this->l->t('The receipt could not be read — the OCR provider failed. Try again, or enter the transaction manually')],
                Http::STATUS_BAD_GATEWAY
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
