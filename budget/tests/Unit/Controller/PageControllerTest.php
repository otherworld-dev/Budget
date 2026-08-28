<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SchemaVersionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	private PageController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$accountMapper = $this->createMock(AccountMapper::class);
		$categoryMapper = $this->createMock(CategoryMapper::class);
		$granularShareService = $this->createMock(GranularShareService::class);
		$schemaVersionService = $this->createMock(SchemaVersionService::class);

		$this->controller = new PageController(
			$request,
			$accountMapper,
			$categoryMapper,
			$granularShareService,
			$schemaVersionService,
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
			null
		);
		$this->assertInstanceOf(PageController::class, $controller);
	}
}
