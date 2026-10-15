<?php

declare(strict_types=1);

namespace OCA\Budget\Command;

use OCA\Budget\Service\DeletedUserData;
use OCA\Budget\Service\FactoryResetService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the Budget data of Nextcloud users who no longer exist and, with
 * --force, removes it the way deleting a user does since 3.0.
 *
 * Users deleted before 3.0, or whose removal failed, kept everything: their
 * accounts, rows and bills, the shares other users gave them and contacts
 * linked to their uid. The background jobs skip them now, but nothing removes
 * their data on its own: a user backend that is unreachable or switched off
 * for a while (LDAP, SAML) can make a real user look deleted, so the default
 * is a dry run and every user is checked again just before removal.
 */
class PurgeDeletedUsers extends Command {
	public function __construct(
		private DeletedUserData $deletedUserData,
		private FactoryResetService $factoryReset,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('budget:purge-deleted-users')
			->setDescription('List the Budget data of users who no longer exist; remove it with --force')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Only this user id (repeatable)')
			->addOption('force', null, InputOption::VALUE_NONE,
				'Remove the data. Without it, nothing is changed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$found = $this->deletedUserData->find();
		$gone = [];
		foreach ($found['gone'] as $userId => $counts) {
			$gone[(string)$userId] = $counts;
		}

		$only = array_map('strval', (array)$input->getOption('user'));
		if ($only !== []) {
			foreach ($only as $userId) {
				if (!array_key_exists($userId, $gone)) {
					$output->writeln("<comment>$userId: no Budget data of a deleted user by that id (the user exists, or has nothing left).</comment>");
				}
			}
			$gone = array_intersect_key($gone, array_flip($only));
		}

		foreach ($found['unknown'] as $userId) {
			$output->writeln("<comment>$userId: the user backend could not say whether this user exists, so it is left alone.</comment>");
		}

		if ($gone === []) {
			$output->writeln('No Budget data of deleted users found.');
			return 0;
		}

		$output->writeln(sprintf('Budget data of %d user(s) who no longer exist:', count($gone)));
		foreach ($gone as $userId => $counts) {
			$output->writeln('  ' . $userId . ': ' . self::summary($counts));
		}

		if (!$input->getOption('force')) {
			$output->writeln('');
			$output->writeln('Nothing was changed. A user from an external user backend (such as LDAP) can look deleted while that backend is unreachable or switched off: check the list, then run again with --force to remove this data.');
			return 0;
		}

		$failed = 0;
		foreach (array_keys($gone) as $userId) {
			$userId = (string)$userId;
			if ($this->mayExist($userId)) {
				$output->writeln("<comment>$userId: the user exists now, so it is left alone.</comment>");
				continue;
			}
			try {
				$this->factoryReset->purgeDeletedUser($userId);
				$output->writeln("Removed the Budget data of $userId.");
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln("<error>Could not remove the Budget data of $userId: " . $e->getMessage() . '</error>');
			}
		}

		return $failed === 0 ? 0 : 1;
	}

	/** Asked again right before removing anything; "can't tell" counts as yes */
	private function mayExist(string $userId): bool {
		try {
			return $this->userManager->userExists($userId);
		} catch (\Throwable $e) {
			return true;
		}
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function summary(array $counts): string {
		$parts = [];
		foreach ($counts as $table => $rows) {
			$parts[] = (str_starts_with($table, 'budget_') ? substr($table, 7) : $table) . ' ' . $rows;
		}
		return implode(', ', $parts);
	}
}
