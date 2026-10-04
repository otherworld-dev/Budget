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

		return new TemplateResponse(Application::APP_ID, 'quick-add', [
			'accounts' => json_encode($this->quickAddAccounts()),
			'categories' => json_encode($this->quickAddCategories()),
			'touchIcon' => $this->urlGenerator->imagePath(Application::APP_ID, 'quick-add-180.png'),
		]);
	}

	/**
	 * The accounts the quick-add page offers. It only ever creates a
	 * transaction, so like every picker for new activity it leaves out
	 * closed accounts (#372) and accounts shared with the user read-only,
	 * whose save was refused (R2-5).
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private function quickAddAccounts(): array {
		$accountList = array_map(
			fn ($a) => ['id' => $a->getId(), 'name' => $a->getName()],
			$this->accountMapper->findOpen($this->userId)
		);
		foreach ($this->granularShareService->getSharedAccounts($this->userId) as $sa) {
			if (!empty($sa['closed'])
				|| !$this->granularShareService->canWrite((string)$this->userId, 'account', (int)$sa['id'])) {
				continue;
			}
			$accountList[] = ['id' => $sa['id'], 'name' => $sa['name']];
		}
		return $accountList;
	}

	/**
	 * The quick-add category picker's rows, each subcategory straight after
	 * its parent with a nesting level to indent by. A flat list made two
	 * subcategories of the same name under different parents look identical
	 * (#409).
	 *
	 * The page shows one type at a time, so a level counts only ancestors of
	 * the row's own type, the same rule as the main app's pickers
	 * (populateCategorySelect). A shared subcategory whose parent wasn't
	 * shared goes at the top level.
	 *
	 * @return list<array{id: int, name: string, type: string, level: int}>
	 */
	private function quickAddCategories(): array {
		$rows = array_map(fn ($c) => [
			'id' => $c->getId(),
			'name' => $c->getName(),
			'type' => $c->getType(),
			'parentId' => $c->getParentId(),
		], $this->categoryMapper->findAll($this->userId));
		foreach ($this->granularShareService->getSharedCategories($this->userId) as $sc) {
			$rows[] = [
				'id' => $sc['id'],
				'name' => $sc['name'],
				'type' => $sc['type'] ?? 'expense',
				'parentId' => $sc['parentId'] ?? null,
			];
		}

		$ids = array_column($rows, 'id', 'id');
		$children = [];
		foreach ($rows as $row) {
			$parentKey = $row['parentId'] !== null && isset($ids[$row['parentId']]) ? $row['parentId'] : 0;
			$children[$parentKey][] = $row;
		}

		$list = [];
		$seen = [];
		// $levels maps a type to the level its next row of that type sits at
		$walk = function (array $branch, array $levels) use (&$walk, &$list, &$seen, $children): void {
			foreach ($branch as $row) {
				if (isset($seen[$row['id']])) {
					continue;
				}
				$seen[$row['id']] = true;
				$level = $levels[$row['type']] ?? 0;
				$list[] = ['id' => $row['id'], 'name' => $row['name'], 'type' => $row['type'], 'level' => $level];
				$walk($children[$row['id']] ?? [], [$row['type'] => $level + 1] + $levels);
			}
		};
		$walk($children[0] ?? [], []);
		// Rows in a parent loop are never reached from the top; list them
		// rather than lose them
		$walk($rows, []);

		return $list;
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
