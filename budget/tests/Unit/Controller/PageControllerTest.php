<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SchemaVersionService;
use OCP\App\IAppManager;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	private PageController $controller;
	private IAppManager $appManager;
	private CategoryMapper $categoryMapper;
	private GranularShareService $granularShareService;
	private AccountMapper $accountMapper;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$accountMapper = $this->accountMapper = $this->createMock(AccountMapper::class);
		$categoryMapper = $this->categoryMapper = $this->createMock(CategoryMapper::class);
		$granularShareService = $this->granularShareService = $this->createMock(GranularShareService::class);
		$schemaVersionService = $this->createMock(SchemaVersionService::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(fn (string $app, string $image) => '/apps-extra/' . $app . '/img/' . $image);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$defaults = $this->createMock(Defaults::class);
		$defaults->method('getColorPrimary')->willReturn('#00679e');

		$this->controller = new PageController(
			$request,
			$accountMapper,
			$categoryMapper,
			$granularShareService,
			$schemaVersionService,
			$this->appManager,
			$urlGenerator,
			$l,
			$defaults,
			'user1'
		);
	}

	public function testControllerCanBeInstantiated(): void {
		$this->assertInstanceOf(PageController::class, $this->controller);
	}

	public function testConstructsWithNullUserId(): void {
		// Unauthenticated requests inject a null userId before the auth
		// middleware runs — construction must not throw (issue #259).
		$controller = new PageController(
			$this->createMock(IRequest::class),
			$this->createMock(AccountMapper::class),
			$this->createMock(CategoryMapper::class),
			$this->createMock(GranularShareService::class),
			$this->createMock(SchemaVersionService::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IL10N::class),
			$this->createMock(Defaults::class),
			null
		);
		$this->assertInstanceOf(PageController::class, $controller);
	}

	public function testIndexPassesTheInstalledVersionToThePage(): void {
		// The What's new popup compares it with the last version the user saw.
		$this->appManager->method('getAppVersion')->with('budget')->willReturn('2.54.0');

		// index() itself calls Util::addScript, which needs a running server.
		$params = (new \ReflectionMethod(PageController::class, 'indexParams'))->invoke($this->controller);

		$this->assertSame('2.54.0', $params['appVersion']);
	}

	public function testQuickAddManifestOpensTheQuickAddPage(): void {
		$manifest = $this->manifest();

		// Served from .../quick-add/manifest, so both resolve to .../quick-add
		// whichever form of the URL the page was loaded under (#530).
		$this->assertSame('../quick-add', $manifest['start_url']);
		$this->assertSame('../quick-add', $manifest['scope']);
		$this->assertSame('standalone', $manifest['display']);
		$this->assertSame('Quick Add', $manifest['short_name']);
		$this->assertSame('#00679e', $manifest['theme_color']);
	}

	public function testQuickAddManifestHasTheIconSizesBrowsersRequireToInstall(): void {
		$manifest = $this->manifest();

		$icons = [];
		foreach ($manifest['icons'] as $icon) {
			$icons[$icon['purpose'] . ' ' . $icon['sizes']] = $icon['src'];
		}

		$this->assertSame([
			'any 192x192' => '/apps-extra/budget/img/quick-add-192.png',
			'any 512x512' => '/apps-extra/budget/img/quick-add-512.png',
			'maskable 192x192' => '/apps-extra/budget/img/quick-add-192.png',
			'maskable 512x512' => '/apps-extra/budget/img/quick-add-512.png',
		], $icons);
		foreach ($icons as $src) {
			$this->assertFileExists(__DIR__ . '/../../../img/' . basename($src));
		}
	}

	public function testQuickAddListsSubcategoriesIndentedUnderTheirParent(): void {
		// Two subcategories with the same name under different parents were
		// indistinguishable in a flat list (#409).
		$this->categoryMapper->method('findAll')->willReturn([
			$this->category(1, 'Food', 'expense', null),
			$this->category(2, 'Travel', 'expense', null),
			$this->category(3, 'Other', 'expense', 1),
			$this->category(4, 'Other', 'expense', 2),
			$this->category(5, 'Snacks', 'expense', 3),
		]);
		$this->granularShareService->method('getSharedCategories')->willReturn([]);

		$this->assertSame([
			[1, 'Food', 0],
			[3, 'Other', 1],
			[5, 'Snacks', 2],
			[2, 'Travel', 0],
			[4, 'Other', 1],
		], $this->quickAddRows());
	}

	public function testQuickAddIndentsOnlyUnderParentsOfTheSameType(): void {
		// The page shows one type at a time, so an income subcategory of an
		// expense parent sits at the top level, as in the main app's pickers.
		$this->categoryMapper->method('findAll')->willReturn([
			$this->category(1, 'Work', 'expense', null),
			$this->category(2, 'Refunds', 'income', 1),
			$this->category(3, 'Expenses refunds', 'income', 2),
		]);
		$this->granularShareService->method('getSharedCategories')->willReturn([]);

		$this->assertSame([
			[1, 'Work', 0],
			[2, 'Refunds', 0],
			[3, 'Expenses refunds', 1],
		], $this->quickAddRows());
	}

	public function testQuickAddNestsSharedCategoriesAndKeepsOrphansAtTheTop(): void {
		// A shared subcategory whose parent wasn't shared has nothing to sit under.
		$this->categoryMapper->method('findAll')->willReturn([
			$this->category(1, 'Food', 'expense', null),
		]);
		$this->granularShareService->method('getSharedCategories')->willReturn([
			['id' => 10, 'name' => 'House', 'type' => 'expense', 'parentId' => null],
			['id' => 11, 'name' => 'Repairs', 'type' => 'expense', 'parentId' => 10],
			['id' => 12, 'name' => 'Garden', 'type' => 'expense', 'parentId' => 99],
		]);

		$this->assertSame([
			[1, 'Food', 0],
			[10, 'House', 0],
			[11, 'Repairs', 1],
			[12, 'Garden', 0],
		], $this->quickAddRows());
	}

	public function testQuickAddStillListsCategoriesCaughtInAParentLoop(): void {
		$this->categoryMapper->method('findAll')->willReturn([
			$this->category(1, 'Food', 'expense', null),
			$this->category(2, 'Loop A', 'expense', 3),
			$this->category(3, 'Loop B', 'expense', 2),
		]);
		$this->granularShareService->method('getSharedCategories')->willReturn([]);

		$this->assertSame([
			[1, 'Food', 0],
			[2, 'Loop A', 0],
			[3, 'Loop B', 1],
		], $this->quickAddRows());
	}

	public function testQuickAddOffersOnlyAccountsTheUserCanWriteTo(): void {
		// Saving into an account shared read-only was refused (R2-5)
		$own = new \OCA\Budget\Db\Account();
		$own->setId(1);
		$own->setName('Current');
		$this->accountMapper->method('findOpen')->with('user1')->willReturn([$own]);
		$this->granularShareService->method('getSharedAccounts')->with('user1')->willReturn([
			['id' => 4, 'name' => 'Joint', 'closed' => false],
			['id' => 6, 'name' => 'Read only', 'closed' => false],
			['id' => 7, 'name' => 'Closed joint', 'closed' => true],
		]);
		$this->granularShareService->method('canWrite')
			->willReturnCallback(fn (string $user, string $type, int $id) => $type === 'account' && in_array($id, [4, 7], true));

		$accounts = (new \ReflectionMethod(PageController::class, 'quickAddAccounts'))->invoke($this->controller);

		$this->assertSame([['id' => 1, 'name' => 'Current', 'owner' => null], ['id' => 4, 'name' => 'Joint', 'owner' => null]], $accounts);
	}

	public function testQuickAddTellsTheOwnersOfSharedAccountsAndCategories(): void {
		// A row in someone else's account takes only their categories; the
		// page filters the category list by the account's owner (V4-4)
		$own = new \OCA\Budget\Db\Account();
		$own->setId(1);
		$own->setName('Current');
		$this->accountMapper->method('findOpen')->willReturn([$own]);
		$this->granularShareService->method('getSharedAccounts')->willReturn([
			['id' => 4, 'name' => 'Joint', 'closed' => false, 'userId' => 'owen'],
		]);
		$this->granularShareService->method('canWrite')->willReturn(true);
		$this->categoryMapper->method('findAll')->willReturn([$this->category(1, 'Food', 'expense', null)]);
		$this->granularShareService->method('getSharedCategories')->willReturn([
			['id' => 10, 'name' => 'House', 'type' => 'expense', 'parentId' => null, '_sharedBy' => 'owen'],
		]);

		$accounts = (new \ReflectionMethod(PageController::class, 'quickAddAccounts'))->invoke($this->controller);
		$categories = (new \ReflectionMethod(PageController::class, 'quickAddCategories'))->invoke($this->controller);

		$this->assertSame([null, 'owen'], array_column($accounts, 'owner'));
		$this->assertSame([1 => null, 10 => 'owen'], array_column($categories, 'owner', 'id'));
	}

	/** @return list<array{int, string, int}> */
	private function quickAddRows(): array {
		// quickAdd() itself calls Util::addStyle, which needs a running server.
		$rows = (new \ReflectionMethod(PageController::class, 'quickAddCategories'))->invoke($this->controller);
		return array_map(fn (array $r) => [$r['id'], $r['name'], $r['level']], $rows);
	}

	private function category(int $id, string $name, string $type, ?int $parentId): Category {
		$category = new Category();
		$category->setId($id);
		$category->setName($name);
		$category->setType($type);
		$category->setParentId($parentId);
		return $category;
	}

	/** @return array<string, mixed> */
	private function manifest(): array {
		// quickAddManifest() wraps this in a cached response, which needs a
		// running server.
		return (new \ReflectionMethod(PageController::class, 'quickAddManifestData'))->invoke($this->controller);
	}
}
