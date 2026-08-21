<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Ocr;

use OCA\Budget\Service\Ocr\NextcloudOcrBackend;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * These pin the TaskProcessing CONTRACT the backend must speak — the part a
 * mock in the extraction-service tests can never verify, and exactly what
 * shipped wrong the first time: core:image2text:ocr takes a LIST OF FILE
 * IDS and answers a LIST OF TEXTS, not base64 in / string out.
 */
class NextcloudOcrBackendTest extends TestCase {
	private NextcloudOcrBackend $backend;
	private IManager $manager;
	private File $file;
	private Folder $folder;

	/** The Task instance handed to runTask(), captured for inspection. */
	private ?Task $scheduledTask = null;

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->manager);

		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(9001);

		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('newFile')->willReturn($this->file);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($this->folder);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->backend = new NextcloudOcrBackend(
			$container,
			$rootFolder,
			$this->createMock(LoggerInterface::class)
		);
	}

	/** Makes runTask capture its task and answer with the given output. */
	private function taskFinishesWith(?array $output, int $status = Task::STATUS_SUCCESSFUL): void {
		$this->manager->method('runTask')->willReturnCallback(function (Task $task) use ($output, $status) {
			$this->scheduledTask = $task;
			$task->setOutput($output);
			$task->setStatus($status);

			return $task;
		});
	}

	public function testTaskInputIsAListOfFileIdsNotBase64(): void {
		$this->taskFinishesWith(['output' => ['RECEIPT TEXT']]);

		$this->backend->extractText('raw-image-bytes', 'image/png', 'user1');

		$this->assertSame('core:image2text:ocr', $this->scheduledTask->getTaskTypeId());
		// The slot shape the framework validates: ListOfFiles = [int, ...].
		$this->assertSame(['input' => [9001]], $this->scheduledTask->getInput());
	}

	public function testListOfTextsOutputIsJoinedToOneString(): void {
		$this->taskFinishesWith(['output' => ['PAGE ONE', 'PAGE TWO']]);

		$this->assertSame("PAGE ONE\nPAGE TWO", $this->backend->extractText('bytes', 'image/jpeg', 'user1'));
	}

	public function testTheTempFileIsDeletedOnSuccess(): void {
		$this->taskFinishesWith(['output' => ['TEXT']]);
		$this->file->expects($this->once())->method('delete');

		$this->backend->extractText('bytes', 'image/png', 'user1');
	}

	public function testTheTempFileIsDeletedWhenTheTaskFails(): void {
		$this->taskFinishesWith(null, Task::STATUS_FAILED);
		$this->file->expects($this->once())->method('delete');

		$this->expectException(OcrProviderException::class);

		$this->backend->extractText('bytes', 'image/png', 'user1');
	}

	public function testEmptyOutputIsAProviderFailure(): void {
		$this->taskFinishesWith(['output' => []]);

		$this->expectException(OcrProviderException::class);

		$this->backend->extractText('bytes', 'image/png', 'user1');
	}

	/** Folder mock that records the names created beneath it and hands back the next level. */
	private function recordingFolder(array &$created, Folder $leaf, int $depth): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(function (string $name) use (&$created, $leaf, $depth) {
			$created[] = $name;
			return $depth <= 1 ? $leaf : $this->recordingFolder($created, $leaf, $depth - 1);
		});
		return $folder;
	}

	public function testTheHoldingFolderIsCreatedWhenMissing(): void {
		// Created segment by segment: the receipts folder itself may not exist yet
		$created = [];
		$userFolder = $this->recordingFolder($created, $this->folder, 3);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->manager);
		$this->taskFinishesWith(['output' => ['TEXT']]);

		$backend = new NextcloudOcrBackend($container, $rootFolder, $this->createMock(LoggerInterface::class));

		$this->assertSame('TEXT', $backend->extractText('bytes', 'image/webp', 'user1'));
		$this->assertSame(['Budget', 'Receipts', '.ocr-tmp'], $created);
	}

	public function testTheHoldingFolderFollowsTheReceiptsFolderSetting(): void {
		$created = [];
		$userFolder = $this->recordingFolder($created, $this->folder, 3);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->manager);
		$this->taskFinishesWith(['output' => ['TEXT']]);

		$attachments = $this->createMock(\OCA\Budget\Service\AttachmentService::class);
		$attachments->method('receiptsFolderFor')->with('user1')->willReturn('Applications/Budget');

		$backend = new NextcloudOcrBackend($container, $rootFolder, $this->createMock(LoggerInterface::class), $attachments);

		$this->assertSame('TEXT', $backend->extractText('bytes', 'image/webp', 'user1'));
		$this->assertSame(['Applications', 'Budget', '.ocr-tmp'], $created);
	}
}
