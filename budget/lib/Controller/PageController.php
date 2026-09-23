<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SchemaVersionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	private AccountMapper $accountMapper;
	private CategoryMapper $categoryMapper;
	private GranularShareService $granularShareService;
	private SchemaVersionService $schemaVersionService;
	private IAppManager $appManager;
	private IURLGenerator $urlGenerator;
	private IL10N $l;
	private Defaults $defaults;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		AccountMapper $accountMapper,
		CategoryMapper $categoryMapper,
		GranularShareService $granularShareService,
		SchemaVersionService $schemaVersionService,
		IAppManager $appManager,
		IURLGenerator $urlGenerator,
		IL10N $l,
		Defaults $defaults,
		// Nullable: the controller is constructed before the auth middleware
		// runs, so an unauthenticated request injects null here (the page
		// routes still require login, which the middleware enforces next).
		?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->accountMapper = $accountMapper;
		$this->categoryMapper = $categoryMapper;
		$this->granularShareService = $granularShareService;
		$this->schemaVersionService = $schemaVersionService;
		$this->appManager = $appManager;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->defaults = $defaults;
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

		return new TemplateResponse(Application::APP_ID, 'index', $this->indexParams());
	}

	/** @return array<string, mixed> */
	private function indexParams(): array {
		return [
			'appName' => Application::APP_ID,
			// An upgrade Nextcloud never ran leaves the database behind the
			// code, and nothing notices until a save fails on a column that
			// was never added (#333). Say so before that happens.
			'schemaWarning' => $this->schemaVersionService->getWarning(),
			// Read by the What's new popup (src/utils/whatsNew.js) to tell
			// whether this user has seen the notes for the installed version.
			'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
		];
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
		// Quick-add creates a transaction, so closed accounts are not offered (#372)
		$accounts = $this->accountMapper->findOpen($this->userId);
		$sharedAccounts = $this->granularShareService->getSharedAccounts($this->userId);

		$accountList = array_map(fn ($a) => ['id' => $a->getId(), 'name' => $a->getName()], $accounts);
		foreach ($sharedAccounts as $sa) {
			if (!empty($sa['closed'])) {
				continue;
			}
			$accountList[] = ['id' => $sa['id'], 'name' => $sa['name']];
		}

		$categories = $this->categoryMapper->findAll($this->userId);
		$sharedCategories = $this->granularShareService->getSharedCategories($this->userId);

		$categoryList = array_map(fn ($c) => [
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
			'touchIcon' => $this->urlGenerator->imagePath(Application::APP_ID, 'quick-add-180.png'),
		]);
	}

	/**
	 * Web app manifest for the quick-add page, so it can be installed on a
	 * home screen or desktop and open straight to the form (#530).
	 *
	 * The page layout already links Nextcloud's own manifest for the app,
	 * whose start URL is Budget's main page, and a browser only reads the
	 * first manifest link — so the quick-add template points that link here.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function quickAddManifest(): JSONResponse {
		$response = new JSONResponse($this->quickAddManifestData());
		$response->cacheFor(3600);
		return $response;
	}

	/** @return array<string, mixed> */
	private function quickAddManifestData(): array {
		$icon = fn (int $size, string $purpose) => [
			'src' => $this->urlGenerator->imagePath(Application::APP_ID, 'quick-add-' . $size . '.png'),
			'sizes' => $size . 'x' . $size,
			'type' => 'image/png',
			'purpose' => $purpose,
		];

		return [
			'name' => $this->l->t('Quick Add Transaction'),
			'short_name' => $this->l->t('Quick Add'),
			// Relative to this manifest's own URL (.../quick-add/manifest), so
			// they resolve to the page with or without index.php in the path.
			'start_url' => '../quick-add',
			'scope' => '../quick-add',
			'display' => 'standalone',
			'theme_color' => $this->defaults->getColorPrimary(),
			'background_color' => $this->defaults->getColorPrimary(),
			// The icons are full-bleed with the artwork inside the maskable
			// safe zone, so the same files serve both purposes.
			'icons' => [
				$icon(192, 'any'),
				$icon(512, 'any'),
				$icon(192, 'maskable'),
				$icon(512, 'maskable'),
			],
		];
	}
}
