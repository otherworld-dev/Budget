<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob\Queued;

use OCA\Budget\BackgroundJob\Queued\BankSyncConnectionJob;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BankSyncConnectionJobTest extends TestCase {
	private BankSyncConnectionJob $job;
	private AdminSettingService $adminSettings;
	private BankSyncService $syncService;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->syncService = $this->createMock(BankSyncService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new BankSyncConnectionJob(
			$this->createMock(ITimeFactory::class),
			$this->adminSettings,
			$this->syncService,
			$this->logger
		);
	}

	private function runWith(mixed $argument): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, $argument);
	}

	public function testSyncsTheQueuedConnection(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->syncService->expects($this->once())->method('sync')->with('user1', 7)->willReturn([]);

		$this->runWith(['userId' => 'user1', 'connectionId' => 7]);
	}

	public function testDoesNothingOnceTheAdminTurnedBankSyncOff(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
		$this->syncService->expects($this->never())->method('sync');

		$this->runWith(['userId' => 'user1', 'connectionId' => 7]);
	}

	public function testLogsAFailedSyncInsteadOfFailingTheJob(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->syncService->method('sync')->willThrowException(new \RuntimeException('API timeout'));

		$this->logger->expects($this->once())->method('warning')
			->with(
				$this->stringContains('Bank sync failed for connection 7: API timeout'),
				$this->callback(fn ($ctx) => $ctx['connectionId'] === 7 && $ctx['userId'] === 'user1')
			);

		$this->runWith(['userId' => 'user1', 'connectionId' => 7]);
	}

	public function testIgnoresAMalformedArgument(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->syncService->expects($this->never())->method('sync');

		$this->runWith(null);
		$this->runWith(['userId' => 'user1']);
		$this->runWith(['connectionId' => 7]);
	}
}
