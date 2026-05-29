<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\GranularShareService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	private PageController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$accountMapper = $this->createMock(AccountMapper::class);
		$categoryMapper = $this->createMock(CategoryMapper::class);
		$granularShareService = $this->createMock(GranularShareService::class);

		$this->controller = new PageController(
			$request,
			$accountMapper,
			$categoryMapper,
			$granularShareService,
			'user1'
		);
	}

	public function testControllerCanBeInstantiated(): void {
		$this->assertInstanceOf(PageController::class, $this->controller);
	}
}
