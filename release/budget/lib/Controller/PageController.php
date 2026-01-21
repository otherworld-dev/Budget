<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Load scripts and styles
        Util::addScript(Application::APP_ID, 'budget-main');
        Util::addStyle(Application::APP_ID, 'style');
        
        $params = [
            'appName' => Application::APP_ID,
        ];
        
        return new TemplateResponse(Application::APP_ID, 'index', $params);
    }
}