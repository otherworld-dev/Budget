<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\OnboardingController;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\OnboardingService;
use OCA\Budget\Service\SampleDataService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OnboardingControllerTest extends TestCase {
	private IRequest $request;
	private OnboardingService $onboarding;
	private SampleDataService $sampleData;
	private AuditService $audit;
	private OnboardingController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->onboarding = $this->createMock(OnboardingService::class);
		$this->sampleData = $this->createMock(SampleDataService::class);
		$this->audit = $this->createMock(AuditService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->controller = new OnboardingController(
			$this->request,
			$this->onboarding,
			$this->sampleData,
			$this->audit,
			$l,
			'alice',
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testStateReturnsTheServiceState(): void {
		$state = ['show' => true, 'sampleData' => false, 'canLoadSampleData' => true, 'steps' => ['account' => false]];
		$this->onboarding->method('getState')->with('alice')->willReturn($state);

		$response = $this->controller->state();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($state, $response->getData());
	}

	public function testDismiss(): void {
		$this->onboarding->expects($this->once())->method('dismiss')->with('alice');

		$this->assertSame(Http::STATUS_OK, $this->controller->dismiss()->getStatus());
	}

	public function testLoadSampleDataSeedsAnEmptyBudget(): void {
		$this->sampleData->method('hasData')->willReturn(false);
		$this->sampleData->method('isLoaded')->willReturn(false);
		$this->sampleData->expects($this->once())->method('loadForUser')->with('alice')
			->willReturn(['accounts' => 4, 'transactions' => 34, 'categories' => 40]);
		$this->audit->expects($this->once())->method('log')->with('alice', 'sample_data_loaded');

		$response = $this->controller->loadSampleData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(4, $response->getData()['created']['accounts']);
	}

	public function testLoadSampleDataRefusesWhenTheUserHasData(): void {
		$this->sampleData->method('hasData')->willReturn(true);
		$this->sampleData->expects($this->never())->method('loadForUser');

		$response = $this->controller->loadSampleData();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testLoadSampleDataRefusesASecondLoad(): void {
		$this->sampleData->method('hasData')->willReturn(false);
		$this->sampleData->method('isLoaded')->willReturn(true);
		$this->sampleData->expects($this->never())->method('loadForUser');

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->loadSampleData()->getStatus());
	}

	public function testLoadSampleDataFailureIsAServerError(): void {
		$this->sampleData->method('loadForUser')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->loadSampleData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('boom', json_encode($response->getData()));
	}

	public function testClearSampleDataNeedsConfirmation(): void {
		$this->request->method('getParam')->with('confirmed', false)->willReturn(false);
		$this->sampleData->expects($this->never())->method('clearForUser');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->clearSampleData()->getStatus());
	}

	public function testClearSampleDataRefusesWithoutSampleData(): void {
		// Never a back door to the factory reset
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$this->sampleData->method('isLoaded')->willReturn(false);
		$this->sampleData->expects($this->never())->method('clearForUser');

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->clearSampleData()->getStatus());
	}

	public function testClearSampleData(): void {
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$this->sampleData->method('isLoaded')->willReturn(true);
		$this->sampleData->expects($this->once())->method('clearForUser')->with('alice')->willReturn(['accounts' => 4]);
		$this->audit->expects($this->once())->method('log')->with('alice', 'sample_data_cleared');

		$response = $this->controller->clearSampleData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['accounts' => 4], $response->getData()['deletedCounts']);
	}
}
