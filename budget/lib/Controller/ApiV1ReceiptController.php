<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\OcrQuotaExhaustedException;
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
 * Clients discover whether this endpoint works via ocr_available on
 * GET /api/v1/capabilities (or features.receipt_ocr on GET /api/v1); a 412
 * ocr_not_configured from here means no provider is set up.
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
     * multipart/form-data under the field `image` (the handoff contract's
     * name; `file` is accepted too). JPEG, PNG or WebP.
     *
     * Error statuses and the machine-readable data.error_code values are
     * the capture app's handoff contract, verbatim — the app switches on
     * error_code, never on prose:
     *   412 ocr_not_configured   — no provider set up on this server
     *   429 ocr_quota_exhausted  — the provider's meter ran out
     *   422 ocr_extraction_failed — provider failed, or no total was
     *                               readable (total is the one field the
     *                               contract requires in a draft)
     * (412 is also Nextcloud's own status for a missing OCS-APIRequest
     * header; the app distinguishes by error_code, which the header case
     * never carries.)
     *
     * Rate-limited harder than uploads: every call costs an OCR/vision run
     * on whatever backend the admin is paying for or hosting.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function extract(): DataResponse {
        $uploadedFile = $this->request->getUploadedFile('image') ?? $this->request->getUploadedFile('file');
        if (!$uploadedFile) {
            return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $draft = $this->extractionService->extract($this->userId, $uploadedFile);

            if ($draft['total'] === null) {
                // The contract makes total the one required draft field: a
                // scan that cannot read what was paid has failed, whatever
                // else it deciphered.
                return new DataResponse([
                    'error' => $this->l->t('No total could be read from this receipt. Enter the transaction manually'),
                    'error_code' => 'ocr_extraction_failed',
                ], Http::STATUS_UNPROCESSABLE_ENTITY);
            }

            return new DataResponse(ApiSerializer::receiptDraft($draft));
        } catch (OcrNotConfiguredException $e) {
            return new DataResponse([
                'error' => $this->l->t('Receipt scanning is not set up on this server'),
                'error_code' => 'ocr_not_configured',
            ], Http::STATUS_PRECONDITION_FAILED);
        } catch (OcrQuotaExhaustedException $e) {
            return new DataResponse([
                'error' => $this->l->t('The OCR provider\'s quota is used up. Try again later'),
                'error_code' => 'ocr_quota_exhausted',
            ], Http::STATUS_TOO_MANY_REQUESTS);
        } catch (OcrProviderException $e) {
            return new DataResponse([
                'error' => $this->l->t('The receipt could not be read — the OCR provider failed. Try again, or enter the transaction manually'),
                'error_code' => 'ocr_extraction_failed',
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
