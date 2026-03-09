<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TagSetController;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagSetControllerTest extends TestCase {
	private TagSetController $controller;
	private TagSetService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TagSetService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new TagSetController(
			$this->request,
			$this->service,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllTagSets(): void {
		$tagSets = [['id' => 1, 'name' => 'Priority']];
		$this->service->method('getAllTagSetsWithTags')->with('user1')->willReturn($tagSets);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexReturnsCategoryTagSets(): void {
		$tagSets = [['id' => 1, 'name' => 'Priority']];
		$this->service->method('getCategoryTagSetsWithTags')->with(5, 'user1')->willReturn($tagSets);

		$response = $this->controller->index(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('getAllTagSetsWithTags')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsTagSet(): void {
		$tagSet = $this->createMock(TagSet::class);
		$this->service->method('getTagSetWithTags')->with(1, 'user1')->willReturn($tagSet);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('getTagSetWithTags')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesTagSet(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyHandlesError(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── getTags ─────────────────────────────────────────────────────

	public function testGetTagsReturnsData(): void {
		$tagSet = $this->createMock(TagSet::class);
		$tagSet->method('getTags')->willReturn([['id' => 1, 'name' => 'High']]);
		$this->service->method('getTagSetWithTags')->with(1, 'user1')->willReturn($tagSet);

		$response = $this->controller->getTags(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGetTagsHandlesError(): void {
		$this->service->method('getTagSetWithTags')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->getTags(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroyTag ──────────────────────────────────────────────────

	public function testDestroyTagDeletesTag(): void {
		$this->service->expects($this->once())->method('deleteTag')->with(5, 'user1');

		$response = $this->controller->destroyTag(1, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyTagHandlesError(): void {
		$this->service->method('deleteTag')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroyTag(1, 999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
