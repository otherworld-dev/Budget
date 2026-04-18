<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only settings controller. No @NoAdminRequired annotation —
 * Nextcloud restricts these endpoints to admin users by default.
 */
class AdminSettingController extends Controller {
    public function __construct(
        IRequest $request,
        private AdminSettingService $service
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function index(): DataResponse {
        return new DataResponse($this->service->getAll());
    }

    public function update(): DataResponse {
        $params = $this->request->getParams();

        if (array_key_exists('bankSyncEnabled', $params)) {
            $this->service->setBankSyncEnabled((bool) $params['bankSyncEnabled']);
        }

        return new DataResponse($this->service->getAll());
    }
}
