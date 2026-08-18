<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Admin-only settings controller. No @NoAdminRequired annotation —
 * Nextcloud restricts these endpoints to admin users by default.
 */
class AdminSettingController extends Controller {
    /** Accepted under an `ocr` object on update; anything else is ignored. */
    private const OCR_FIELDS = ['provider', 'endpoint', 'model', 'apiKey'];

    public function __construct(
        IRequest $request,
        private AdminSettingService $service,
        private OcrSettingsService $ocrSettings,
        private IL10N $l
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * A billing-portal link for the stored relay licence key (#537).
     *
     * The service talks to the relay server-side and throws machine codes;
     * this is where they become sentences.
     */
    public function ocrPortal(): DataResponse {
        try {
            return new DataResponse(['url' => $this->ocrSettings->createPortalUrl()]);
        } catch (\RuntimeException $e) {
            return match ($e->getMessage()) {
                'not_relay' => new DataResponse(
                    ['error' => $this->l->t('Receipt scanning is not using the Otherworld relay.')],
                    Http::STATUS_BAD_REQUEST
                ),
                'no_key' => new DataResponse(
                    ['error' => $this->l->t('No license key is saved yet.')],
                    Http::STATUS_BAD_REQUEST
                ),
                'no_subscription' => new DataResponse(
                    ['error' => $this->l->t('This license has no subscription behind it — it was issued directly, so there is nothing to manage.')],
                    Http::STATUS_NOT_FOUND
                ),
                default => new DataResponse(
                    ['error' => $this->l->t('The billing portal could not be opened. Try again shortly.')],
                    Http::STATUS_BAD_GATEWAY
                ),
            };
        }
    }

    public function index(): DataResponse {
        return new DataResponse($this->all());
    }

    public function update(): DataResponse {
        $params = $this->request->getParams();

        // The OCR block is the only part that can reject, and a rejection
        // answers 400 "nothing was saved" — so it must run before anything
        // else is written, or a mixed payload would commit the bank-sync
        // change behind an error response claiming total failure.
        if (isset($params['ocr']) && is_array($params['ocr'])) {
            try {
                $this->ocrSettings->update(array_intersect_key(
                    $params['ocr'],
                    array_flip(self::OCR_FIELDS)
                ));
            } catch (\InvalidArgumentException $e) {
                return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        }

        if (array_key_exists('bankSyncEnabled', $params)) {
            $this->service->setBankSyncEnabled((bool) $params['bankSyncEnabled']);
        }

        return new DataResponse($this->all());
    }

    private function all(): array {
        return $this->service->getAll() + ['ocr' => $this->ocrSettings->getSettings()];
    }
}
