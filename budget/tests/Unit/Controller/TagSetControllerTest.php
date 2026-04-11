<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TagSetController;
use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagSetControllerTest extends TestCase {
	private TagSetController $controller;
	private TagSetService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;
	private IL10N $l;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TagSetService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		// Default pass-through for validation
		$this->validationService->method('validateName')
			->willReturnCallback(function ($name, $required) {
				return ['valid' => true, 'sanitized' => $name];
			});
		$this->validationService->method('validateColor')
			->willReturnCallback(function ($color) {
				return ['valid' => true, 'sanitized' => $color];
			});

		$this->controller = new TagSetController(
			$this->request,
			$this->service,
			$this->validationService,
			$this->l,
			'user1',
			$this->logger
		);

		// Register mock stream wrapper for php://input
		MockPhpInputStream::$data = '';
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', MockPhpInputStream::class);
	}

	protected function tearDown(): void {
		stream_wrapper_restore('php');
	}

	private function mockInput(string $json): void {
		MockPhpInputStream::$data = $json;
	}

	private function controllerWithValidation(ValidationService $vs): TagSetController {
		return new TagSetController(
			$this->request,
			$this->service,
			$vs,
			$this->l,
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

	// ── create ──────────────────────────────────────────────────────

	public function testCreateSuccess(): void {
		$this->mockInput(json_encode([
			'categoryId' => 5,
			'name' => 'Priority',
		]));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 5, 'Priority', null, 0)
			->willReturn($tagSet);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateWithAllFields(): void {
		$this->mockInput(json_encode([
			'categoryId' => 5,
			'name' => 'Priority',
			'description' => 'Tag set description',
			'sortOrder' => 3,
		]));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 5, 'Priority', 'Tag set description', 3)
			->willReturn($tagSet);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateMissingCategoryId(): void {
		$this->mockInput(json_encode(['name' => 'Priority']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateMissingName(): void {
		$this->mockInput(json_encode(['categoryId' => 5]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateMissingBothFields(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateEmptyBody(): void {
		$this->mockInput('');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidName(): void {
		$this->mockInput(json_encode([
			'categoryId' => 5,
			'name' => 'Priority',
		]));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name is too long']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Name is too long', $response->getData()['error']);
	}

	public function testCreateServiceException(): void {
		$this->mockInput(json_encode([
			'categoryId' => 5,
			'name' => 'Priority',
		]));

		$this->service->method('create')
			->willThrowException(new \RuntimeException('Category not found'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateName(): void {
		$this->mockInput(json_encode(['name' => 'Updated Name']));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', ['name' => 'Updated Name'])
			->willReturn($tagSet);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateDescription(): void {
		$this->mockInput(json_encode(['description' => 'New description']));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', ['description' => 'New description'])
			->willReturn($tagSet);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSortOrder(): void {
		$this->mockInput(json_encode(['sortOrder' => 5]));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', ['sortOrder' => 5])
			->willReturn($tagSet);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'New Name',
			'description' => 'New desc',
			'sortOrder' => 2,
		]));

		$tagSet = new TagSet();
		$tagSet->setId(1);
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', [
				'name' => 'New Name',
				'description' => 'New desc',
				'sortOrder' => 2,
			])
			->willReturn($tagSet);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateEmptyBody(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid fields', $response->getData()['error']);
	}

	public function testUpdateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidName(): void {
		$this->mockInput(json_encode(['name' => 'Bad Name']));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Invalid name']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid name', $response->getData()['error']);
	}

	public function testUpdateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$this->service->method('update')
			->willThrowException(new \RuntimeException('Not found'));

		$response = $this->controller->update(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

	// ── createTag ───────────────────────────────────────────────────

	public function testCreateTagSuccess(): void {
		$this->mockInput(json_encode(['name' => 'High']));

		$tag = new Tag();
		$tag->setId(1);
		$this->service->expects($this->once())
			->method('createTag')
			->with(10, 'user1', 'High', null, 0)
			->willReturn($tag);

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateTagWithAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'High',
			'color' => '#ff0000',
			'sortOrder' => 2,
		]));

		$tag = new Tag();
		$tag->setId(1);
		$this->service->expects($this->once())
			->method('createTag')
			->with(10, 'user1', 'High', '#ff0000', 2)
			->willReturn($tag);

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateTagMissingName(): void {
		$this->mockInput(json_encode(['color' => '#ff0000']));

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateTagEmptyBody(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateTagInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateTagInvalidName(): void {
		$this->mockInput(json_encode(['name' => 'Bad']));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name too short']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->createTag(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Name too short', $response->getData()['error']);
	}

	public function testCreateTagInvalidColor(): void {
		$this->mockInput(json_encode([
			'name' => 'High',
			'color' => 'not-a-color',
		]));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'High']);
		$vs->method('validateColor')->willReturn(['valid' => false, 'error' => 'Invalid color format']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->createTag(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid color format', $response->getData()['error']);
	}

	public function testCreateTagWithNullColorSkipsValidation(): void {
		$this->mockInput(json_encode(['name' => 'High']));

		$tag = new Tag();
		$tag->setId(1);
		$this->service->expects($this->once())
			->method('createTag')
			->with(10, 'user1', 'High', null, 0)
			->willReturn($tag);

		// Color validation should NOT be called when color is null
		$this->validationService->expects($this->never())->method('validateColor');

		$response = $this->controller->createTag(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateTagServiceException(): void {
		$this->mockInput(json_encode(['name' => 'High']));

		$this->service->method('createTag')
			->willThrowException(new \RuntimeException('Tag set not found'));

		$response = $this->controller->createTag(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── updateTag ───────────────────────────────────────────────────

	public function testUpdateTagName(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$tag = new Tag();
		$tag->setId(5);
		$this->service->expects($this->once())
			->method('updateTag')
			->with(5, 'user1', ['name' => 'Updated'])
			->willReturn($tag);

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTagColor(): void {
		$this->mockInput(json_encode(['color' => '#00ff00']));

		$tag = new Tag();
		$tag->setId(5);
		$this->service->expects($this->once())
			->method('updateTag')
			->with(5, 'user1', ['color' => '#00ff00'])
			->willReturn($tag);

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTagSortOrder(): void {
		$this->mockInput(json_encode(['sortOrder' => 3]));

		$tag = new Tag();
		$tag->setId(5);
		$this->service->expects($this->once())
			->method('updateTag')
			->with(5, 'user1', ['sortOrder' => 3])
			->willReturn($tag);

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTagAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'New',
			'color' => '#0000ff',
			'sortOrder' => 1,
		]));

		$tag = new Tag();
		$tag->setId(5);
		$this->service->expects($this->once())
			->method('updateTag')
			->with(5, 'user1', [
				'name' => 'New',
				'color' => '#0000ff',
				'sortOrder' => 1,
			])
			->willReturn($tag);

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTagEmptyBody(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid fields', $response->getData()['error']);
	}

	public function testUpdateTagInvalidJson(): void {
		$this->mockInput('bad json');

		$response = $this->controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateTagInvalidName(): void {
		$this->mockInput(json_encode(['name' => 'Bad']));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name invalid']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Name invalid', $response->getData()['error']);
	}

	public function testUpdateTagInvalidColor(): void {
		$this->mockInput(json_encode(['color' => 'xyz']));

		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateColor')->willReturn(['valid' => false, 'error' => 'Bad color']);
		$controller = $this->controllerWithValidation($vs);

		$response = $controller->updateTag(10, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad color', $response->getData()['error']);
	}

	public function testUpdateTagServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$this->service->method('updateTag')
			->willThrowException(new \RuntimeException('Not found'));

		$response = $this->controller->updateTag(10, 999);

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
