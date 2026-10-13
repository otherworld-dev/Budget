<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1TagController;
use OCA\Budget\Service\TransactionTagService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GET /api/v1/tags (discussion 420): the caller's own tags.
 */
class ApiV1TagControllerTest extends TestCase {
	private TransactionTagService $tagService;

	protected function setUp(): void {
		$this->tagService = $this->createMock(TransactionTagService::class);
	}

	private function controller(?string $userId = 'user1'): ApiV1TagController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		return new ApiV1TagController(
			$this->createMock(IRequest::class),
			$this->tagService,
			$l,
			$userId,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIndexListsTheCallersTagsInTheOrderTheServiceGives(): void {
		$this->tagService->method('tagDetailsForUser')->with('user1')->willReturn([
			['id' => 12, 'name' => 'Haushalt', 'color' => '#3b82f6', 'userId' => 'user1'],
			['id' => 27, 'name' => 'Tesco', 'hidden' => true, 'tagSetId' => 4, 'tagSetName' => 'Shop', 'categoryId' => 415, 'userId' => 'user1'],
		]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			[
				'id' => 12, 'name' => 'Haushalt', 'color' => '#3b82f6',
				'tag_set_id' => null, 'tag_set_name' => null, 'category_id' => null,
				'hidden' => false, 'owner' => 'user1',
			],
			[
				'id' => 27, 'name' => 'Tesco', 'color' => null,
				'tag_set_id' => 4, 'tag_set_name' => 'Shop', 'category_id' => 415,
				'hidden' => true, 'owner' => 'user1',
			],
		], $response->getData());
	}

	public function testIndexIsAnEmptyListForAUserWithoutTags(): void {
		$this->tagService->method('tagDetailsForUser')->willReturn([]);

		$this->assertSame([], $this->controller()->index()->getData());
	}

	public function testIndexHandlesAFailure(): void {
		$this->tagService->method('tagDetailsForUser')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller()->index();

		// As every v1 list does: the generic message, never the cause
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve tags', $response->getData()['error']);
	}

	/**
	 * OCS builds the controller before the auth check, with no user: that
	 * must not be a TypeError (see ApiV1Controller).
	 */
	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		$this->assertInstanceOf(ApiV1TagController::class, $this->controller(null));
	}
}
