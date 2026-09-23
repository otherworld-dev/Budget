<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\BackgroundJob\Queued\UserDigestJob;
use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Daily fan-out of the digests and the per-user alert checks.
 *
 * Visiting every user inside this one job ran a digest plus three alert
 * checks for each of them in a single cron slot, however many users there
 * were. Instead it queues one UserDigestJob per user — everyone who owns an
 * account (the alert checks default on) plus anyone opted in to the digest —
 * and cron works through that queue under its own time limits, a few users
 * per run. See UserDigestJob for the per-user work.
 *
 * A user whose job from an earlier day is still waiting is left alone:
 * IJobList::add() on an existing job moves it to the back of the queue, so
 * re-adding every user daily would push the same users back each time a
 * backlog never drained. Left alone, every queued job keeps its place and
 * runs in turn — nobody is skipped for ever.
 */
class DigestJob extends TimedJob {

	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$jobList = Server::get(IJobList::class);
		$users = new JobUsers(Server::get(IDBConnection::class));

		$queued = 0;
		$waiting = 0;
		foreach (JobUsers::union($users->withSettingEnabled('digest_enabled'), $users->accountOwners()) as $userId) {
			$jobArgument = ['userId' => $userId];
			if ($jobList->has(UserDigestJob::class, $jobArgument)) {
				$waiting++;
				continue;
			}
			$jobList->add(UserDigestJob::class, $jobArgument);
			$queued++;
		}

		Server::get(LoggerInterface::class)->info(
			"Digest job queued {$queued} users" . ($waiting > 0 ? ", {$waiting} still waiting from an earlier run" : ''),
			['app' => 'budget']
		);
	}
}
