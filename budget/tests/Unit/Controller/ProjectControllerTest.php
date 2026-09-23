<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ProjectController;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ProjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectControllerTest extends TestCase {
	private ProjectController $controller;
	private ProjectService $service;
	private GranularShareService $shares;
	private IRequest $request;

	/** project id => owner uid; anything missing is invisible */
	private array $owners = [10 => 'user1', 20 => 'owner'];
	private array $writable = [10 => true, 20 => false];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ProjectService::class);
		$this->shares = $this->createMock(GranularShareService::class);
		$this->shares->method('resolveOwner')->willReturnCallback(fn ($u, $t, $id) => $this->owners[$id] ?? null);
		$this->shares->method('canWrite')->willReturnCallback(fn ($u, $t, $id) => $this->writable[$id] ?? false);
		$this->shares->method('requireWriteAccess')->willReturnCallback(function ($u, $t, $id): void {
			if (!($this->writable[$id] ?? false)) {
				throw new ReadOnlyShareException();
			}
		});
		$this->shares->method('ownerDisplayName')->willReturn('The Owner');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});

		$this->controller = new ProjectController(
			$this->request,
			$this->service,
			$this->shares,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIndexListsOwnProjectsThenSharedOnesMarked(): void {
		$this->service->method('listOwn')->with('user1')->willReturn([['id' => 10, 'userId' => 'user1']]);
		$this->shares->method('getSharedProjectIds')->with('user1')->willReturn([20]);
		$this->service->method('listByIds')->with([20])->willReturn([['id' => 20, 'userId' => 'owner']]);

		$data = $this->controller->index()->getData();

		$this->assertSame(10, $data[0]['id']);
		$this->assertArrayNotHasKey('_shared', $data[0]);
		$this->assertSame(['id' => 20, 'userId' => 'owner', '_shared' => true, '_canWrite' => false, '_sharedByName' => 'The Owner'], $data[1]);
	}

	public function testShowIsNotFoundWhenTheProjectIsNotVisible(): void {
		$response = $this->controller->show(99);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testShowMarksASharedProject(): void {
		$this->service->method('get')->with(20, 'owner')->willReturn(['id' => 20, 'userId' => 'owner']);

		$data = $this->controller->show(20)->getData();

		$this->assertTrue($data['_shared']);
		$this->assertFalse($data['_canWrite']);
	}

	public function testCreatePassesEveryFieldAndAnswersCreated(): void {
		$allocations = [['categoryId' => 2, 'amount' => 400]];
		$this->service->expects($this->once())->method('create')
			->with('user1', [
				'name' => 'House renovation',
				'categoryId' => 1,
				'totalAmount' => 900.0,
				'startDate' => '2026-03-01',
				'endDate' => null,
				'allocations' => $allocations,
				'excludeFromBudget' => true,
			])
			->willReturn(['id' => 10]);

		$response = $this->controller->create('House renovation', 1, 900.0, '2026-03-01', null, $allocations, true);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	#[DataProvider('refusals')]
	public function testCreateExplainsARefusal(int $code, string $message): void {
		$this->service->method('create')->willThrowException(new \InvalidArgumentException('refused', $code));

		$response = $this->controller->create('x', 1, 900.0, '2026-03-01');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame($message, $response->getData()['error']);
	}

	public static function refusals(): array {
		return [
			[ProjectService::ERR_NAME, 'Enter a name for the project'],
			[ProjectService::ERR_TOTAL, 'The total must be more than zero'],
			[ProjectService::ERR_CATEGORY, 'Choose one of your own expense categories'],
			[ProjectService::ERR_START_DATE, 'Enter a start date for the project'],
			[ProjectService::ERR_END_DATE, 'The end date cannot be before the start date'],
			[ProjectService::ERR_ALLOC_AMOUNT, 'Every subcategory amount must be more than zero'],
			[ProjectService::ERR_ALLOC_OUTSIDE, 'Amounts can only go to subcategories of the project category'],
			[ProjectService::ERR_ALLOC_DUPLICATE, 'Each subcategory can only have one amount'],
			[ProjectService::ERR_ALLOC_OVERLAP, 'A subcategory and one of its own subcategories cannot both have amounts'],
			[ProjectService::ERR_ALLOC_OVER_TOTAL, 'The subcategory amounts add up to more than the total'],
		];
	}

	public function testUpdateByTheOwnerPassesOnlyWhatWasSent(): void {
		$this->request->method('getParams')->willReturn(['id' => 10, 'name' => 'Renamed', 'endDate' => null]);
		$this->service->expects($this->once())->method('update')
			->with(10, 'user1', ['name' => 'Renamed', 'endDate' => null], true)
			->willReturn(['id' => 10, 'userId' => 'user1']);

		$response = $this->controller->update(10, 'Renamed');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateOfAReadOnlyShareIsForbidden(): void {
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->update(20, 'Renamed');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateByAReadWriteRecipientIsNotAsOwner(): void {
		$this->writable[20] = true;
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->once())->method('update')
			->with(20, 'owner', ['name' => 'Renamed'], false)
			->willReturn(['id' => 20, 'userId' => 'owner']);

		$data = $this->controller->update(20, 'Renamed')->getData();

		$this->assertTrue($data['_shared']);
	}

	public function testUpdateIsNotFoundWhenTheProjectIsNotVisible(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(99, 'x')->getStatus());
	}

	public function testDeleteByTheOwner(): void {
		$this->service->expects($this->once())->method('delete')->with(10, 'user1');
		$this->assertSame(['status' => 'deleted'], $this->controller->destroy(10)->getData());
	}

	public function testOnlyTheOwnerCanDelete(): void {
		$this->writable[20] = true;
		$this->service->expects($this->never())->method('delete');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroy(20)->getStatus());
	}

	public function testDeleteIsNotFoundWhenTheProjectIsNotVisible(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroy(99)->getStatus());
	}

	public function testShowAnswersNotFoundWhenTheRowHasGone(): void {
		$this->service->method('get')->willThrowException(new DoesNotExistException('gone'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(10)->getStatus());
	}
}
