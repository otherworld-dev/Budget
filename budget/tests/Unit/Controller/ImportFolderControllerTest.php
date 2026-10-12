<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ImportFolderController;
use OCA\Budget\Db\ImportFolder;
use OCA\Budget\Service\ImportFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportFolderControllerTest extends TestCase {
	private ImportFolderController $controller;
	/** @var ImportFolderService&\PHPUnit\Framework\MockObject\MockObject */
	private $service;
	/** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ImportFolderService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->controller = new ImportFolderController($this->request, $this->service, $l, 'alice', $this->createMock(LoggerInterface::class));
	}

	private function folder(): ImportFolder {
		$folder = new ImportFolder();
		$folder->setId(1);
		$folder->setName('Conto');
		$folder->setAccountId(3);
		return $folder;
	}

	public function testIndexListsTheFolders(): void {
		$this->service->method('list')->with('alice')->willReturn(['folder' => 'Budget/Import', 'folders' => []]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['folder' => 'Budget/Import', 'folders' => []], $response->getData());
	}

	public function testCreate(): void {
		$this->service->expects($this->once())->method('create')->with('alice', 'Conto', 3, 5)->willReturn($this->folder());

		$response = $this->controller->create('Conto', 3, 5);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateShowsWhyANameWasRefused(): void {
		$this->service->method('create')->willThrowException(new \InvalidArgumentException('You already have an import folder called Conto.'));

		$response = $this->controller->create('Conto', 3);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('You already have an import folder called Conto.', $response->getData()['error']);
	}

	public function testUpdatePassesOnlyTheFieldsTheRequestCarries(): void {
		$this->request->method('getParams')->willReturn(['id' => '1', 'templateId' => null, 'enabled' => false, '_route' => 'x', 'name' => 'ignored']);
		$this->service->expects($this->once())->method('update')
			->with('alice', 1, ['templateId' => null, 'enabled' => false])
			->willReturn($this->folder());

		$this->assertSame(Http::STATUS_OK, $this->controller->update(1)->getStatus());
	}

	public function testUpdateOfAFolderThatIsNotTheirsIsNotFound(): void {
		$this->request->method('getParams')->willReturn(['enabled' => true]);
		$this->service->method('update')->willThrowException(new DoesNotExistException('no'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(9)->getStatus());
	}

	public function testDestroy(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 1);

		$this->assertSame(['status' => 'success'], $this->controller->destroy(1)->getData());
	}

	public function testCheckReturnsWhatHappened(): void {
		$this->service->method('check')->with('alice')->willReturn(['imported' => 2, 'failed' => 1, 'waiting' => 0]);

		$this->assertSame(['imported' => 2, 'failed' => 1, 'waiting' => 0], $this->controller->check()->getData());
	}
}
