<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ShareController;
use OCA\Budget\Db\Share;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareControllerTest extends TestCase {
	private ShareController $controller;
	private ShareService $shareService;
	private GranularShareService $granularShareService;
	private IRequest $request;
	private LoggerInterface $logger;
	private IL10N $l;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new ShareController(
			$this->request,
			$this->shareService,
			$this->granularShareService,
			$this->l,
			'user1',
			$this->logger
		);
	}

	private function makeShare(string $owner, string $sharedWith): Share {
		$share = new Share();
		$share->setOwnerUserId($owner);
		$share->setSharedWithUserId($sharedWith);
		return $share;
	}

	// ── outgoing ────────────────────────────────────────────────────

	public function testOutgoingReturnsShares(): void {
		$shares = [['id' => 1, 'sharedWith' => 'user2']];
		$this->shareService->method('getOutgoingShares')
			->with('user1')
			->willReturn($shares);

		$response = $this->controller->outgoing();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($shares, $response->getData());
	}

	public function testOutgoingHandlesError(): void {
		$this->shareService->method('getOutgoingShares')
			->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->outgoing();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve shares', $response->getData()['error']);
	}

	// ── incoming ────────────────────────────────────────────────────

	public function testIncomingReturnsShares(): void {
		$shares = [['id' => 2, 'owner' => 'user2']];
		$this->shareService->method('getIncomingShares')
			->with('user1')
			->willReturn($shares);

		$response = $this->controller->incoming();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($shares, $response->getData());
	}

	// ── pending ─────────────────────────────────────────────────────

	public function testPendingReturnsShares(): void {
		$shares = [['id' => 3, 'owner' => 'user3']];
		$this->shareService->method('getPendingShares')
			->with('user1')
			->willReturn($shares);

		$response = $this->controller->pending();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($shares, $response->getData());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateReturnsCreatedStatus(): void {
		$share = new Share();
		$share->setId(1);
		$this->shareService->expects($this->once())
			->method('shareWith')
			->with('user1', 'user2')
			->willReturn($share);

		$response = $this->controller->create('user2');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateValidationErrorReturnsBadRequest(): void {
		$this->shareService->method('shareWith')
			->willThrowException(new \InvalidArgumentException('Cannot share with yourself'));

		$response = $this->controller->create('user1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Cannot share with yourself', $response->getData()['error']);
	}

	// ── accept ──────────────────────────────────────────────────────

	public function testAcceptReturnsShare(): void {
		$share = new Share();
		$share->setId(1);
		$this->shareService->expects($this->once())
			->method('accept')
			->with(1, 'user1')
			->willReturn($share);

		$response = $this->controller->accept(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAcceptNotFoundReturns404(): void {
		$this->shareService->method('accept')
			->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->accept(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Share not found', $response->getData()['error']);
	}

	// ── decline ─────────────────────────────────────────────────────

	public function testDeclineReturnsShare(): void {
		$share = new Share();
		$share->setId(1);
		$this->shareService->expects($this->once())
			->method('decline')
			->with(1, 'user1')
			->willReturn($share);

		$response = $this->controller->decline(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── revoke ──────────────────────────────────────────────────────

	public function testRevokeReturnsSuccess(): void {
		$this->shareService->expects($this->once())
			->method('revoke')
			->with(1, 'user1');

		$response = $this->controller->revoke(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testRevokeValidationErrorReturnsBadRequest(): void {
		$this->shareService->method('revoke')
			->willThrowException(new \InvalidArgumentException('Not the owner'));

		$response = $this->controller->revoke(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Not the owner', $response->getData()['error']);
	}

	// ── leave ───────────────────────────────────────────────────────

	public function testLeaveReturnsSuccess(): void {
		$this->shareService->expects($this->once())
			->method('leave')
			->with(1, 'user1');

		$response = $this->controller->leave(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	// ── getConfig ───────────────────────────────────────────────────

	public function testGetConfigReturnsConfigForOwner(): void {
		$share = $this->makeShare('user1', 'user2');
		$this->shareService->method('findById')->with(1)->willReturn($share);

		$config = ['accounts' => [], 'categories' => []];
		$this->granularShareService->method('getShareConfig')
			->with(1)
			->willReturn($config);

		$response = $this->controller->getConfig(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($config, $response->getData());
	}

	public function testGetConfigReturnsConfigForRecipient(): void {
		$share = $this->makeShare('user2', 'user1');
		$this->shareService->method('findById')->with(1)->willReturn($share);

		$config = ['accounts' => []];
		$this->granularShareService->method('getShareConfig')
			->with(1)
			->willReturn($config);

		$response = $this->controller->getConfig(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGetConfigReturns404ForNonInvolvedUser(): void {
		$share = $this->makeShare('user2', 'user3');
		$this->shareService->method('findById')->with(1)->willReturn($share);

		$response = $this->controller->getConfig(1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Share not found', $response->getData()['error']);
	}

	// ── updateTypeItems ─────────────────────────────────────────────

	public function testUpdateTypeItemsReturnsSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['entityIds', [], [1, 3, 7]],
				['permission', 'read', 'read'],
			]);

		$this->granularShareService->expects($this->once())
			->method('updateShareItems')
			->with('user1', 1, 'accounts', [1, 3, 7], 'read');

		$response = $this->controller->updateTypeItems(1, 'accounts');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testUpdateTypeItemsValidationErrorReturnsBadRequest(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['entityIds', [], [1]],
				['permission', 'read', 'read'],
			]);

		$this->granularShareService->method('updateShareItems')
			->willThrowException(new \InvalidArgumentException('Invalid type'));

		$response = $this->controller->updateTypeItems(1, 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid type', $response->getData()['error']);
	}
}
