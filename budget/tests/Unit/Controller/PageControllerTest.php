<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PageController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	private PageController $controller;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->controller = new PageController($this->request);
	}

	public function testControllerCanBeInstantiated(): void {
		// PageController::index() calls Util::addScript which requires the
		// full Nextcloud OC runtime. We verify the controller can be
		// instantiated - integration tests cover the actual rendering.
		$this->assertInstanceOf(PageController::class, $this->controller);
	}
}
