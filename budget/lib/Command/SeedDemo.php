<?php

declare(strict_types=1);

namespace OCA\Budget\Command;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SampleDataService;
use OCA\Budget\Service\ShareService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds a demonstration dataset covering the full feature surface of the app:
 * multi-currency accounts, categories + budgets, transactions, splits, transfers,
 * tags, bills, recurring income, savings goals (one shared cross-user), pensions,
 * assets, net-worth history, shared expenses/settlements, and a real budget share
 * between two Nextcloud users.
 *
 * The data itself comes from SampleDataService, which also backs "Try with
 * sample data" on the first-run checklist; the sharing between the two users
 * is this command's own.
 *
 * This is a developer/QA tool — register it in appinfo/info.xml under <commands>.
 */
class SeedDemo extends Command {

	public function __construct(
		private IUserManager $userManager,
		private SampleDataService $sampleDataService,
		private AccountService $accountService,
		private ShareService $shareService,
		private GranularShareService $granularShareService,
		private FactoryResetService $factoryResetService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('budget:seed-demo')
			->setDescription('Seed a demo dataset (multi-currency + shared between two users) covering all app features')
			->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner (primary) Nextcloud user ID', 'admin')
			->addOption('recipient', null, InputOption::VALUE_REQUIRED, 'Second Nextcloud user ID to share with', 'demo')
			->addOption('base-currency', null, InputOption::VALUE_REQUIRED, "Owner's base/reporting currency", 'USD')
			->addOption('wipe', null, InputOption::VALUE_NONE, 'Factory-reset both users before seeding')
			->addOption('force', null, InputOption::VALUE_NONE, 'Seed even if the owner already has accounts')
			->addOption('skip-share', null, InputOption::VALUE_NONE, 'Skip the cross-user sharing step');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$owner = (string)$input->getOption('owner');
		$recipient = (string)$input->getOption('recipient');
		$baseCurrency = strtoupper((string)$input->getOption('base-currency'));
		$wipe = (bool)$input->getOption('wipe');
		$force = (bool)$input->getOption('force');
		$skipShare = (bool)$input->getOption('skip-share');

		// Validate users exist
		if (!$this->userManager->userExists($owner)) {
			$output->writeln("<error>Owner user '{$owner}' does not exist.</error>");
			return Command::FAILURE;
		}
		if (!$skipShare && !$this->userManager->userExists($recipient)) {
			$output->writeln("<error>Recipient user '{$recipient}' does not exist. Create it, or pass --skip-share.</error>");
			return Command::FAILURE;
		}
		if ($owner === $recipient) {
			$output->writeln('<error>Owner and recipient must be different users.</error>');
			return Command::FAILURE;
		}

		// Wipe if requested
		if ($wipe) {
			$output->writeln("Wiping existing data for <info>{$owner}</info>" . ($skipShare ? '' : " and <info>{$recipient}</info>") . '…');
			$this->factoryResetService->executeFactoryReset($owner);
			if (!$skipShare) {
				$this->factoryResetService->executeFactoryReset($recipient);
			}
		}

		// Idempotency guard
		if (!$force && !empty($this->accountService->findAll($owner))) {
			$output->writeln("<error>Owner '{$owner}' already has accounts. Re-run with --wipe (reset first) or --force (add anyway).</error>");
			return Command::FAILURE;
		}

		$log = static function (string $line) use ($output): void {
			$output->writeln($line);
		};

		$output->writeln("Seeding owner profile for <info>{$owner}</info> (base {$baseCurrency})…");
		$ownerData = $this->sampleDataService->seedFullProfile($owner, $baseCurrency, true, $log);

		if (!$skipShare) {
			$output->writeln("Seeding recipient profile for <info>{$recipient}</info>…");
			$this->sampleDataService->seedLightProfile($recipient, $baseCurrency, $log);

			$output->writeln('Wiring cross-user sharing…');
			$this->wireSharing($output, $owner, $recipient, $ownerData);
		}

		$output->writeln('<info>Done.</info> Open the Budget app as each user to explore the dataset.');
		return Command::SUCCESS;
	}

	// ==========================================================
	// Cross-user sharing
	// ==========================================================

	/**
	 * @param array{accountIds: array<string,int>, categoryIds: array<string,int>, holidayGoalId: int, ...} $ownerData
	 */
	private function wireSharing(OutputInterface $out, string $owner, string $recipient, array $ownerData): void {
		$share = $this->ensureAcceptedShare($owner, $recipient);

		// Share the owner's bank accounts (read-only)
		$accountIds = array_values($ownerData['accountIds']);
		$this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_ACCOUNT, $accountIds, ShareItem::PERMISSION_READ);

		// Share a few categories (read-only) so reports line up
		$catIds = array_values(array_filter([
			$ownerData['categoryIds']['Groceries'] ?? null,
			$ownerData['categoryIds']['Rent/Mortgage'] ?? null,
			$ownerData['categoryIds']['Dining Out'] ?? null,
		]));
		if (!empty($catIds)) {
			$this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_CATEGORY, $catIds, ShareItem::PERMISSION_READ);
		}

		// Share the holiday savings goal — with write access (the headline feature)
		$this->granularShareService->updateShareItems($owner, $share->getId(), ShareItem::TYPE_SAVINGS_GOAL, [$ownerData['holidayGoalId']], ShareItem::PERMISSION_WRITE);

		$out->writeln("  · '{$owner}' → '{$recipient}': budget share accepted; " . count($accountIds) . ' accounts (read), 1 savings goal (write)');
	}

	/**
	 * Create-or-reuse an accepted share between owner and recipient.
	 */
	private function ensureAcceptedShare(string $owner, string $recipient): Share {
		try {
			$share = $this->shareService->shareWith($owner, $recipient);
		} catch (\Throwable $e) {
			// A share already exists — find it among the owner's outgoing shares
			$share = null;
			foreach ($this->shareService->getOutgoingShares($owner) as $existing) {
				if ($existing->getSharedWithUserId() === $recipient) {
					$share = $existing;
					break;
				}
			}
			if ($share === null) {
				throw $e;
			}
		}

		if ($share->getStatus() !== Share::STATUS_ACCEPTED) {
			$share = $this->shareService->accept($share->getId(), $recipient);
		}
		return $share;
	}
}
