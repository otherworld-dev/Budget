<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\OnboardingProbe;

/**
 * The first-run checklist on the dashboard.
 *
 * Each step ticks itself from what the user really has (OnboardingProbe), so
 * nothing needs recording as the user works through it. What is recorded, in
 * the STATE_KEY user setting, is whether the checklist is running at all:
 *
 *   (unset)     never decided. A user with no accounts, transactions or
 *               accepted incoming share starts it on their first dashboard
 *               load ("active"); anyone with data never sees it, so an
 *               existing user updating the app is left alone.
 *   active      showing, until every step is done or the user dismisses it.
 *   dismissed   the user closed it.
 *   done        every step was done; it stays gone even if a step later
 *               stops being true (say the only budget is removed).
 *
 * While sample data is loaded the checklist steps aside for the sample-data
 * banner, and nothing is recorded, so clearing the sample data brings the
 * checklist back where it was.
 */
class OnboardingService {

	public const STATE_KEY = 'onboarding_state';
	public const STATE_ACTIVE = 'active';
	public const STATE_DISMISSED = 'dismissed';
	public const STATE_DONE = 'done';

	public function __construct(
		private OnboardingProbe $probe,
		private SettingService $settingService,
		private SampleDataService $sampleDataService,
	) {
	}

	/**
	 * @return array{show: bool, sampleData: bool, canLoadSampleData: bool, steps: array<string, bool>|null}
	 */
	public function getState(string $userId): array {
		$sampleData = $this->sampleDataService->isLoaded($userId);
		$state = $this->settingService->get($userId, self::STATE_KEY);

		// Settled for good: two setting reads and nothing else
		if (!$sampleData && ($state === self::STATE_DISMISSED || $state === self::STATE_DONE)) {
			return ['show' => false, 'sampleData' => false, 'canLoadSampleData' => false, 'steps' => null];
		}

		$hasAccounts = $this->probe->hasAccounts($userId);
		$hasTransactions = $this->probe->hasTransactions($userId);

		$steps = [
			'currency' => $this->settingService->get($userId, 'default_currency') !== null,
			'categories' => $this->probe->hasCategories($userId),
			'account' => $hasAccounts,
			'transactions' => $hasTransactions || $this->probe->hasBankConnection($userId),
			'budget' => $this->probe->hasBudget($userId),
		];

		return [
			'show' => $this->decideShow($userId, $state, $steps, $sampleData, $hasAccounts || $hasTransactions),
			'sampleData' => $sampleData,
			'canLoadSampleData' => !$sampleData && !$hasAccounts && !$hasTransactions,
			'steps' => $steps,
		];
	}

	public function dismiss(string $userId): void {
		$this->settingService->set($userId, self::STATE_KEY, self::STATE_DISMISSED);
	}

	/**
	 * @param array<string, bool> $steps
	 */
	private function decideShow(string $userId, ?string $state, array $steps, bool $sampleData, bool $hasOwnData): bool {
		if ($sampleData) {
			return false;
		}

		$allDone = !in_array(false, $steps, true);

		if ($state === null) {
			// Only a user with nothing yet starts the checklist; anyone else
			// is settled as done so later loads skip the probes
			if ($allDone || $hasOwnData || $this->probe->hasIncomingShare($userId)) {
				$this->settingService->set($userId, self::STATE_KEY, self::STATE_DONE);
				return false;
			}
			$this->settingService->set($userId, self::STATE_KEY, self::STATE_ACTIVE);
			return true;
		}

		if ($allDone) {
			$this->settingService->set($userId, self::STATE_KEY, self::STATE_DONE);
			return false;
		}

		return true;
	}
}
