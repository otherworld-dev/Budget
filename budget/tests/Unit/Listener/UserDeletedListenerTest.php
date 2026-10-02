<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Listener;

use OCA\Budget\Listener\UserDeletedListener;
use OCA\Budget\Service\FactoryResetService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {
	public function testDeletingAUserPurgesTheirBudgetData(): void {
		// Nothing listened for user deletion: the jobs kept auto-paying the
		// deleted user's bills into other users' shared accounts, and a
		// re-created uid inherited every account, bill and share
		$reset = $this->createMock(FactoryResetService::class);
		$reset->expects($this->once())->method('purgeDeletedUser')->with('gone');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('gone');

		(new UserDeletedListener($reset, $this->createMock(LoggerInterface::class)))
			->handle(new UserDeletedEvent($user));
	}

	public function testOtherEventsAreIgnored(): void {
		$reset = $this->createMock(FactoryResetService::class);
		$reset->expects($this->never())->method('purgeDeletedUser');

		(new UserDeletedListener($reset, $this->createMock(LoggerInterface::class)))->handle(new Event());
	}

	public function testAFailedPurgeDoesNotBlockTheUserDeletion(): void {
		$reset = $this->createMock(FactoryResetService::class);
		$reset->method('purgeDeletedUser')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('gone');

		(new UserDeletedListener($reset, $logger))->handle(new UserDeletedEvent($user));
	}
}
