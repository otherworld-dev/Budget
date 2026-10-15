<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUserManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Whether a user may link a shared-expense contact to a Nextcloud user.
 *
 * A linked contact's shared expenses show up in that person's "shared with
 * me", so the link may only reach someone the user picker would offer (the
 * sharee search, which applies the admin's enumeration settings) and whom
 * the admin's "only share with group members" setting lets the user share
 * with. The same check as creating a contact (SharedExpenseController); a
 * backup restore applies it to the contacts it brings back, which used to
 * keep whatever user id the archive named.
 */
class ContactLinkPolicy {
	/** @var array<string, bool> "userId\x1fuid" => verdict, per instance */
	private array $verdicts = [];

	public function __construct(
		private IUserManager $userManager,
		private ShareService $shareService,
		private ISearch $collaboratorSearch,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether $userId may link a contact to the Nextcloud user $uid. The
	 * sharee search runs as the signed-in user, which must be $userId.
	 */
	public function mayLink(string $userId, string $uid): bool {
		$key = $userId . "\x1f" . $uid;
		if (!isset($this->verdicts[$key])) {
			$user = $uid === '' ? null : $this->userManager->get($uid);
			$this->verdicts[$key] = $user !== null
				&& $this->shareService->mayShareWith($userId, $user)
				&& $this->isOfferedByUserSearch($uid);
		}
		return $this->verdicts[$key];
	}

	/**
	 * Whether the sharee search behind the user picker returns this user id
	 * for a search on it.
	 */
	private function isOfferedByUserSearch(string $uid): bool {
		try {
			[$found] = $this->collaboratorSearch->search($uid, [IShare::TYPE_USER], false, 50, 0);
		} catch (\Throwable $e) {
			$this->logger->error('User search failed', ['exception' => $e]);
			return false;
		}
		foreach (array_merge($found['exact']['users'] ?? [], $found['users'] ?? []) as $entry) {
			if (($entry['value']['shareWith'] ?? null) === $uid) {
				return true;
			}
		}
		return false;
	}
}
