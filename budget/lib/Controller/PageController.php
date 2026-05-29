<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    private AccountMapper $accountMapper;
    private CategoryMapper $categoryMapper;
    private GranularShareService $granularShareService;
    private string $userId;

    public function __construct(
        IRequest $request,
        AccountMapper $accountMapper,
        CategoryMapper $categoryMapper,
        GranularShareService $granularShareService,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->accountMapper = $accountMapper;
        $this->categoryMapper = $categoryMapper;
        $this->granularShareService = $granularShareService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Load scripts and styles
        Util::addScript(Application::APP_ID, 'budget-app');
        Util::addStyle(Application::APP_ID, 'style');

        $params = [
            'appName' => Application::APP_ID,
        ];

        return new TemplateResponse(Application::APP_ID, 'index', $params);
    }

    /**
     * Minimal quick-add page for mobile transaction entry.
     * Renders only the transaction form — no dashboard, no sensitive data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function quickAdd(): TemplateResponse {
        Util::addStyle(Application::APP_ID, 'style');

        // Fetch minimal data needed for the form
        $accounts = $this->accountMapper->findAll($this->userId);
        $sharedAccounts = $this->granularShareService->getSharedAccounts($this->userId);

        $accountList = array_map(fn($a) => ['id' => $a->getId(), 'name' => $a->getName()], $accounts);
        foreach ($sharedAccounts as $sa) {
            $accountList[] = ['id' => $sa['id'], 'name' => $sa['name']];
        }

        $categories = $this->categoryMapper->findAll($this->userId);
        $sharedCategories = $this->granularShareService->getSharedCategories($this->userId);

        $categoryList = array_map(fn($c) => [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'type' => $c->getType(),
        ], $categories);
        foreach ($sharedCategories as $sc) {
            $categoryList[] = ['id' => $sc['id'], 'name' => $sc['name'], 'type' => $sc['type'] ?? 'expense'];
        }

        return new TemplateResponse(Application::APP_ID, 'quick-add', [
            'accounts' => json_encode($accountList),
            'categories' => json_encode($categoryList),
        ]);
    }
}