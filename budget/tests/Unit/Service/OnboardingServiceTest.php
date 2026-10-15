<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\OnboardingProbe;
use OCA\Budget\Db\Setting;
use OCA\Budget\Service\OnboardingService;
use OCA\Budget\Service\SampleDataService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

class OnboardingServiceTest extends TestCase {
	private OnboardingProbe $probe;
	private SampleDataService $sampleData;
	private OnboardingService $service;

	/** @var array<string, string> */
	private array $settings = [];
	/** @var array<string, bool> what the probe reports */
	private array $has = [];
	private bool $sampleLoaded = false;

	protected function setUp(): void {
		$this->probe = $this->createMock(OnboardingProbe::class);
		foreach (['hasAccounts', 'hasCategories', 'hasBankConnection', 'hasBudget', 'hasTransactions', 'hasIncomingShare'] as $method) {
			$this->probe->method($method)->willReturnCallback(fn () => $this->has[$method] ?? false);
		}

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')->willReturnCallback(fn (string $u, string $k) => $this->settings[$k] ?? null);
		$settingService->method('set')->willReturnCallback(function (string $u, string $k, string $v): Setting {
			$this->settings[$k] = $v;
			return new Setting();
		});

		$this->sampleData = $this->createMock(SampleDataService::class);
		$this->sampleData->method('isLoaded')->willReturnCallback(fn () => $this->sampleLoaded);

		$this->service = new OnboardingService($this->probe, $settingService, $this->sampleData);
	}

	public function testANewUserStartsTheChecklist(): void {
		$state = $this->service->getState('alice');

		$this->assertTrue($state['show']);
		$this->assertTrue($state['canLoadSampleData']);
		$this->assertSame(OnboardingService::STATE_ACTIVE, $this->settings[OnboardingService::STATE_KEY]);
		$this->assertSame(
			['currency' => false, 'categories' => false, 'account' => false, 'transactions' => false, 'budget' => false],
			$state['steps']
		);
	}

	public function testAnExistingUserWithAccountsNeverSeesIt(): void {
		$this->has = ['hasAccounts' => true, 'hasTransactions' => true];

		$state = $this->service->getState('bob');

		$this->assertFalse($state['show']);
		// Settled, so the next load reads two settings and stops
		$this->assertSame(OnboardingService::STATE_DONE, $this->settings[OnboardingService::STATE_KEY]);
		$this->probe->expects($this->never())->method('hasAccounts');
		$this->assertFalse($this->service->getState('bob')['show']);
	}

	public function testAUserSeeingSomeoneElsesSharedBudgetNeverSeesIt(): void {
		$this->has = ['hasIncomingShare' => true];

		$this->assertFalse($this->service->getState('carol')['show']);
	}

	public function testStepsTickFromRealState(): void {
		$this->settings = [OnboardingService::STATE_KEY => OnboardingService::STATE_ACTIVE, 'default_currency' => 'EUR'];
		$this->has = ['hasCategories' => true, 'hasAccounts' => true, 'hasBankConnection' => true];

		$state = $this->service->getState('alice');

		$this->assertTrue($state['show']);
		$this->assertFalse($state['canLoadSampleData']);
		$this->assertSame(
			['currency' => true, 'categories' => true, 'account' => true, 'transactions' => true, 'budget' => false],
			$state['steps']
		);
	}

	public function testItHidesForGoodOnceEveryStepIsDone(): void {
		$this->settings = [OnboardingService::STATE_KEY => OnboardingService::STATE_ACTIVE, 'default_currency' => 'EUR'];
		$this->has = ['hasCategories' => true, 'hasAccounts' => true, 'hasTransactions' => true, 'hasBudget' => true];

		$this->assertFalse($this->service->getState('alice')['show']);
		$this->assertSame(OnboardingService::STATE_DONE, $this->settings[OnboardingService::STATE_KEY]);

		// A step later undone does not bring it back
		$this->has['hasBudget'] = false;
		$this->assertFalse($this->service->getState('alice')['show']);
	}

	public function testDismissHidesIt(): void {
		$this->service->getState('alice');
		$this->service->dismiss('alice');

		$state = $this->service->getState('alice');
		$this->assertFalse($state['show']);
		$this->assertSame(OnboardingService::STATE_DISMISSED, $this->settings[OnboardingService::STATE_KEY]);
	}

	public function testSampleDataStepsAsideWithoutRecordingAnything(): void {
		$this->settings = [OnboardingService::STATE_KEY => OnboardingService::STATE_ACTIVE, 'default_currency' => 'EUR'];
		$this->has = ['hasCategories' => true, 'hasAccounts' => true, 'hasTransactions' => true, 'hasBudget' => true];
		$this->sampleLoaded = true;

		$state = $this->service->getState('alice');

		$this->assertFalse($state['show']);
		$this->assertTrue($state['sampleData']);
		$this->assertFalse($state['canLoadSampleData']);
		// Still active, so clearing the sample data brings the checklist back
		$this->assertSame(OnboardingService::STATE_ACTIVE, $this->settings[OnboardingService::STATE_KEY]);
	}
}
