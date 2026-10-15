<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AttachmentController;
use OCA\Budget\Db\Attachment;
use OCA\Budget\Service\AttachmentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttachmentControllerTest extends TestCase {
	private AttachmentController $controller;
	private AttachmentService $service;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AttachmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->controller = new AttachmentController(
			$this->request,
			$this->service,
			$l,
			'user1',
			$this->logger
		);
	}

	private function makeAttachment(int $id = 5, int $transactionId = 10): Attachment {
		$a = new Attachment();
		$a->setId($id);
		$a->setTransactionId($transactionId);
		$a->setUserId('user1');
		$a->setFileId(900);
		$a->setFileName('receipt.jpg');
		$a->setMimeType('image/jpeg');
		$a->setCreatedAt('2026-09-01 10:00:00');
		return $a;
	}

	private function uploadedFile(): array {
		return [
			'name' => 'receipt.pdf',
			'type' => 'application/pdf',
			'tmp_name' => '/tmp/php123',
			'error' => UPLOAD_ERR_OK,
			'size' => 1234,
		];
	}

	// ── counts ──────────────────────────────────────────────────────

	public function testCountsReturnsTheCallersPerTransactionCounts(): void {
		$this->service->expects($this->once())
			->method('getCounts')
			->with('user1')
			->willReturn([10 => 2, 11 => 1]);

		$response = $this->controller->counts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([10 => 2, 11 => 1], $response->getData());
	}

	public function testCountsHidesInternalErrorsBehindAGenericMessage(): void {
		$this->service->method('getCounts')->willThrowException(new \RuntimeException('SQLSTATE secret'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->counts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to load attachments'], $response->getData());
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexListsAttachmentsForTheCallersTransaction(): void {
		$row = $this->makeAttachment()->jsonSerialize() + ['missing' => false, 'isImage' => true];
		$this->service->expects($this->once())
			->method('listForTransaction')
			->with(10, 'user1')
			->willReturn([$row]);

		$response = $this->controller->index(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$row], $response->getData());
	}

	public function testIndexOfAnEmptyTransactionReturnsAnEmptyList(): void {
		$this->service->method('listForTransaction')->willReturn([]);

		$response = $this->controller->index(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	/**
	 * Someone else's transaction and a missing one look the same, so the
	 * endpoint can't be used to probe which ids exist.
	 */
	public function testIndexOfAnotherUsersTransactionIsNotFound(): void {
		$this->service->method('listForTransaction')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->index(99);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Transaction not found'], $response->getData());
	}

	public function testIndexOnAnUnexpectedErrorReturnsGenericMessage(): void {
		$this->service->method('listForTransaction')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->index(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to load attachments', $response->getData()['error']);
	}

	// ── attach ──────────────────────────────────────────────────────

	public function testAttachByFileIdReturnsCreated(): void {
		$attachment = $this->makeAttachment();
		$this->service->expects($this->once())
			->method('attachExisting')
			->with(10, 'user1', 900, null)
			->willReturn($attachment);

		$response = $this->controller->attach(10, 900);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($attachment, $response->getData());
	}

	public function testAttachByPathPassesThePathThrough(): void {
		$this->service->expects($this->once())
			->method('attachExisting')
			->with(10, 'user1', null, '/Receipts/r.pdf')
			->willReturn($this->makeAttachment());

		$response = $this->controller->attach(10, null, '/Receipts/r.pdf');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testAttachToAMissingTransactionIsNotFound(): void {
		$this->service->method('attachExisting')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->attach(99, 900);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Transaction not found'], $response->getData());
	}

	public function testAttachAMissingFileIsNotFoundWithAFileMessage(): void {
		$this->service->method('attachExisting')->willThrowException(new NotFoundException('gone'));

		$response = $this->controller->attach(10, 12345);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'File not found'], $response->getData());
	}

	public function testAttachValidationErrorIsShownToTheUser(): void {
		$this->service->method('attachExisting')
			->willThrowException(new \InvalidArgumentException('A file id or path is required'));

		$response = $this->controller->attach(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A file id or path is required'], $response->getData());
	}

	public function testAttachUnexpectedErrorDoesNotLeakDetails(): void {
		$this->service->method('attachExisting')->willThrowException(new \RuntimeException('/var/www/data/secret'));

		$response = $this->controller->attach(10, 900);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to attach file'], $response->getData());
	}

	// ── upload ──────────────────────────────────────────────────────

	public function testUploadWithoutAFileIsABadRequest(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn(null);
		$this->service->expects($this->never())->method('upload');

		$response = $this->controller->upload(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No file uploaded'], $response->getData());
	}

	public function testUploadWithAnEmptyFileEntryIsABadRequest(): void {
		$this->request->method('getUploadedFile')->willReturn([]);
		$this->service->expects($this->never())->method('upload');

		$response = $this->controller->upload(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUploadStoresTheFileAndReturnsCreated(): void {
		$file = $this->uploadedFile();
		$attachment = $this->makeAttachment();
		$this->request->method('getUploadedFile')->willReturn($file);
		$this->service->expects($this->once())
			->method('upload')
			->with(10, 'user1', $file)
			->willReturn($attachment);

		$response = $this->controller->upload(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($attachment, $response->getData());
	}

	public function testUploadToAMissingTransactionIsNotFound(): void {
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile());
		$this->service->method('upload')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->upload(99);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Transaction not found'], $response->getData());
	}

	public function testUploadRejectedByValidationShowsTheReason(): void {
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile());
		$this->service->method('upload')
			->willThrowException(new \InvalidArgumentException('File exceeds the 25 MB limit'));

		$response = $this->controller->upload(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'File exceeds the 25 MB limit'], $response->getData());
	}

	public function testUploadUnexpectedErrorReturnsGenericMessage(): void {
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile());
		$this->service->method('upload')->willThrowException(new \RuntimeException('disk full'));

		$response = $this->controller->upload(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to upload receipt'], $response->getData());
	}

	public function testUploadIsRateLimited(): void {
		$attrs = (new \ReflectionMethod(AttachmentController::class, 'upload'))
			->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);

		$this->assertCount(1, $attrs);
		$this->assertSame(['limit' => 10, 'period' => 60], $attrs[0]->getArguments());
	}

	// ── detach ──────────────────────────────────────────────────────

	public function testDetachRemovesTheReference(): void {
		$this->service->expects($this->once())
			->method('detach')
			->with(10, 'user1', 5);

		$response = $this->controller->detach(10, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['message' => 'Attachment removed'], $response->getData());
	}

	public function testDetachAMissingAttachmentIsNotFound(): void {
		$this->service->method('detach')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->detach(10, 404);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Attachment not found'], $response->getData());
	}

	public function testDetachAnAttachmentFromTheWrongTransactionIsABadRequest(): void {
		$this->service->method('detach')
			->willThrowException(new \InvalidArgumentException('Attachment does not belong to this transaction'));

		$response = $this->controller->detach(11, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Attachment does not belong to this transaction'], $response->getData());
	}

	public function testDetachUnexpectedErrorReturnsGenericMessage(): void {
		$this->service->method('detach')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->detach(10, 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to remove attachment'], $response->getData());
	}
}
