<?php

declare(strict_types=1);

namespace OCA\Budget\Listener;

use OCA\Budget\Service\FactoryResetService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted Nextcloud user's budget data.
 *
 * Without it everything stayed: the background jobs read user ids from the
 * app's own tables, so they went on auto-paying the deleted user's bills into
 * accounts other users had shared with them, recipients kept write access to
 * a deleted owner's accounts, and an account re-created with the same uid
 * inherited all of it.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private FactoryResetService $factoryReset,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			$this->factoryReset->purgeDeletedUser($userId);
		} catch (\Throwable $e) {
			// The user is being deleted regardless; never block that
			$this->logger->error('Failed to remove the budget data of deleted user ' . $userId . ': ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
