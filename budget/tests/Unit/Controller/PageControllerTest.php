<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCA\Budget\Db\AccountMapper;
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

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$accountMapper = $this->createMock(AccountMapper::class);
		$categoryMapper = $this->createMock(CategoryMapper::class);
		$granularShareService = $this->createMock(GranularShareService::class);
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

	/** @return array<string, mixed> */
	private function manifest(): array {
		// quickAddManifest() wraps this in a cached response, which needs a
		// running server.
		return (new \ReflectionMethod(PageController::class, 'quickAddManifestData'))->invoke($this->controller);
	}
}
