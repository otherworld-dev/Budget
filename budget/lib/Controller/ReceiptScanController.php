<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\OcrQuotaExhaustedException;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCA\Budget\Service\OcrSettingsService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Receipt scanning for the web UI.
 *
 * Deliberately NOT the public v1 endpoint. The OCS surface is a contract with
 * outside clients — snake_case, frozen field names, a version to bump. The web
 * UI is this app's own front end and gets its own route so the two can move
 * independently; both call the same ReceiptExtractionService, so the reading
 * itself can never diverge.
 */
class ReceiptScanController extends Controller {
    use ApiErrorHandlerTrait;

    public function __construct(
        IRequest $request,
        private ReceiptExtractionService $extractionService,
        private OcrSettingsService $settings,
        private IL10N $l,
        ?string $userId,
        LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->setLogger($logger);
        // An unauthenticated request reaches the constructor before the
        // security middleware rejects it, so the injected user is null.
        // Typing this `string` turns the clean 401 the middleware is about to
        // produce into a 500 plus a TypeError in nextcloud.log.
        $this->userId = $userId ?? '';
    }

    private string $userId;

    /**
     * Whether this server can scan a receipt at all.
     *
     * The UI asks before showing the scan button: an admin configures OCR
     * server-wide, so an ordinary user has no other way to know, and offering
     * a button that always errors is worse than not offering one.
     *
     * @NoAdminRequired
     */
    public function status(): DataResponse {
        return new DataResponse([
            'available' => $this->settings->isConfigured(),
            'mimeTypes' => ReceiptExtractionService::EXTRACT_MIMES,
        ]);
    }

    /**
     * Read a receipt image and return draft fields for the transaction form.
     *
     * Nothing is written: the user reviews the draft in the form and saves it
     * themselves, so a bad read costs a retype rather than a wrong record.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function extract(): DataResponse {
        $file = $this->request->getUploadedFile('image') ?? $this->request->getUploadedFile('file');
        if (!$file) {
            return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $draft = $this->extractionService->extract($this->userId, $file);

            if ($draft['total'] === null) {
                // Without an amount there is nothing worth prefilling, and a
                // form that fills in everything except the number reads as a
                // success. Say it failed.
                return new DataResponse([
                    'error' => $this->l->t('No total could be read from this receipt. Enter the details manually'),
                ], Http::STATUS_UNPROCESSABLE_ENTITY);
            }

            return new DataResponse($draft);
        } catch (OcrNotConfiguredException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Receipt scanning is not set up on this server')],
                Http::STATUS_PRECONDITION_FAILED
            );
        } catch (OcrQuotaExhaustedException $e) {
            return new DataResponse(
                ['error' => $this->l->t('The receipt scanning quota is used up. Try again later')],
                Http::STATUS_TOO_MANY_REQUESTS
            );
        } catch (OcrProviderException $e) {
            return new DataResponse(
                ['error' => $this->l->t('The receipt could not be read. Try a clearer photo, or enter the details manually')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
