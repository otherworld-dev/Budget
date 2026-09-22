<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SchemaVersionService;
use OCP\App\IAppManager;
use OCP\IRequest;
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

		$this->controller = new PageController(
			$request,
			$accountMapper,
			$categoryMapper,
			$granularShareService,
			$schemaVersionService,
			$this->appManager,
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
}
