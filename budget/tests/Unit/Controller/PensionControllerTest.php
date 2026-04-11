<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PensionController;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Service\PensionProjector;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PensionControllerTest extends TestCase {
	private PensionController $controller;
	private PensionService $service;
	private PensionProjector $projector;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;
	private IL10N $l;
	private bool $streamOverridden = false;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PensionService::class);
		$this->projector = $this->createMock(PensionProjector::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		// Default validation mocks
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'My Pension']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);
		$this->validationService->method('validateDescription')
			->willReturn(['valid' => true, 'sanitized' => 'note']);

		$this->controller = new PensionController(
			$this->request,
			$this->service,
			$this->projector,
			$this->validationService,
			$this->l,
			'user1',
			$this->logger
		);
	}

	protected function tearDown(): void {
		if ($this->streamOverridden) {
			stream_wrapper_restore('php');
			$this->streamOverridden = false;
		}
	}

	private function mockInput(string $json): void {
		MockPhpInputStream::$data = $json;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', MockPhpInputStream::class);
		$this->streamOverridden = true;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsPensions(): void {
		$pensions = [['id' => 1, 'name' => 'Workplace Pension']];
		$this->service->method('findAll')->with('user1')->willReturn($pensions);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsPension(): void {
		$pension = $this->createMock(PensionAccount::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($pension);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateSuccess(): void {
		$this->mockInput(json_encode([
			'name' => 'Workplace',
			'type' => 'workplace',
		]));

		$pension = $this->createMock(PensionAccount::class);
		$this->service->method('create')->willReturn($pension);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateMissingName(): void {
		$this->mockInput(json_encode(['type' => 'workplace']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateMissingType(): void {
		$this->mockInput(json_encode(['name' => 'My Pension']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name required']);

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['name' => '', 'type' => 'workplace']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidType(): void {
		$this->mockInput(json_encode(['name' => 'My Pension', 'type' => 'invalid_type']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid pension type', $response->getData()['error']);
	}

	public function testCreateInvalidProvider(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')
			->willReturnCallback(function ($value, $required) {
				if ($required) {
					return ['valid' => true, 'sanitized' => 'My Pension'];
				}
				return ['valid' => false, 'error' => 'Provider invalid'];
			});

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'provider' => '<script>',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidCurrency(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'currency' => 'ABCD',
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('3-letter', $response->getData()['error']);
	}

	public function testCreateNegativeBalance(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'currentBalance' => -100,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('negative', $response->getData()['error']);
	}

	public function testCreateNegativeContribution(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'monthlyContribution' => -50,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidReturnRate(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'expectedReturnRate' => 1.5,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('between 0% and 100%', $response->getData()['error']);
	}

	public function testCreateInvalidRetirementAge(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'retirementAge' => 15,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('18 and 100', $response->getData()['error']);
	}

	public function testCreateRetirementAgeOver100(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'retirementAge' => 101,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateNegativeAnnualIncome(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'annualIncome' => -1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateNegativeTransferValue(): void {
		$this->mockInput(json_encode([
			'name' => 'My Pension', 'type' => 'workplace', 'transferValue' => -100,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateWithAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'Full Pension',
			'type' => 'workplace',
			'provider' => 'Aviva',
			'currency' => 'GBP',
			'currentBalance' => 45000.00,
			'monthlyContribution' => 500.00,
			'expectedReturnRate' => 0.05,
			'retirementAge' => 67,
			'annualIncome' => 60000.00,
			'transferValue' => 42000.00,
		]));

		$pension = $this->createMock(PensionAccount::class);
		$this->service->method('create')->willReturn($pension);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Pension', 'type' => 'workplace']));
		$this->service->method('create')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateSuccess(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$pension = $this->createMock(PensionAccount::class);
		$this->service->method('update')->willReturn($pension);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Bad name']);

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['name' => '']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidType(): void {
		$this->mockInput(json_encode(['type' => 'invalid']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidCurrency(): void {
		$this->mockInput(json_encode(['currency' => 'ABCD']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateNegativeBalance(): void {
		$this->mockInput(json_encode(['currentBalance' => -1]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidReturnRate(): void {
		$this->mockInput(json_encode(['expectedReturnRate' => -0.5]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidRetirementAge(): void {
		$this->mockInput(json_encode(['retirementAge' => 10]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));
		$this->service->method('update')->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesPension(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Pension deleted successfully', $response->getData()['message']);
	}

	public function testDestroyHandlesError(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── snapshots ───────────────────────────────────────────────────

	public function testSnapshotsReturnsData(): void {
		$snaps = [['id' => 1, 'balance' => 45000.00]];
		$this->service->method('getSnapshots')->with(1, 'user1')->willReturn($snaps);

		$response = $this->controller->snapshots(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsHandlesError(): void {
		$this->service->method('getSnapshots')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->snapshots(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── createSnapshot ──────────────────────────────────────────────

	public function testCreateSnapshotSuccess(): void {
		$this->mockInput(json_encode([
			'balance' => 50000.00,
			'date' => '2026-03-01',
		]));

		$snapshot = new PensionSnapshot();
		$snapshot->setId(1);
		$this->service->method('createSnapshot')->willReturn($snapshot);

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateSnapshotInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotMissingBalance(): void {
		$this->mockInput(json_encode(['date' => '2026-03-01']));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateSnapshotMissingDate(): void {
		$this->mockInput(json_encode(['balance' => 50000]));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotNegativeBalance(): void {
		$this->mockInput(json_encode(['balance' => -1, 'date' => '2026-03-01']));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('negative', $response->getData()['error']);
	}

	public function testCreateSnapshotInvalidDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['balance' => 50000, 'date' => 'bad']));

		$response = $controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotServiceException(): void {
		$this->mockInput(json_encode(['balance' => 50000, 'date' => '2026-03-01']));
		$this->service->method('createSnapshot')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroySnapshot ─────────────────────────────────────────────

	public function testDestroySnapshotDeletesSnapshot(): void {
		$this->service->expects($this->once())->method('deleteSnapshot')->with(1, 'user1');

		$response = $this->controller->destroySnapshot(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroySnapshotHandlesError(): void {
		$this->service->method('deleteSnapshot')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->destroySnapshot(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── contributions ───────────────────────────────────────────────

	public function testContributionsReturnsData(): void {
		$contributions = [['id' => 1, 'amount' => 500.00]];
		$this->service->method('getContributions')->with(1, 'user1')->willReturn($contributions);

		$response = $this->controller->contributions(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testContributionsHandlesError(): void {
		$this->service->method('getContributions')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->contributions(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── createContribution ──────────────────────────────────────────

	public function testCreateContributionSuccess(): void {
		$this->mockInput(json_encode([
			'amount' => 500.00,
			'date' => '2026-03-01',
		]));

		$contribution = new PensionContribution();
		$contribution->setId(1);
		$this->service->method('createContribution')->willReturn($contribution);

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateContributionInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionMissingAmount(): void {
		$this->mockInput(json_encode(['date' => '2026-03-01']));

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionMissingDate(): void {
		$this->mockInput(json_encode(['amount' => 500.00]));

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionZeroAmount(): void {
		$this->mockInput(json_encode(['amount' => 0, 'date' => '2026-03-01']));

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('greater than zero', $response->getData()['error']);
	}

	public function testCreateContributionNegativeAmount(): void {
		$this->mockInput(json_encode(['amount' => -100, 'date' => '2026-03-01']));

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionInvalidDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['amount' => 500, 'date' => 'bad']));

		$response = $controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionInvalidNote(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateDate')->willReturn(['valid' => true]);
		$vs->method('validateDescription')->willReturn(['valid' => false, 'error' => 'Note too long']);

		$controller = new PensionController(
			$this->request, $this->service, $this->projector, $vs, $this->l, 'user1', $this->logger
		);

		$this->mockInput(json_encode([
			'amount' => 500, 'date' => '2026-03-01', 'note' => str_repeat('x', 5000),
		]));

		$response = $controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateContributionWithNote(): void {
		$this->mockInput(json_encode([
			'amount' => 500.00,
			'date' => '2026-03-01',
			'note' => 'Monthly contribution',
		]));

		$contribution = new PensionContribution();
		$contribution->setId(1);
		$this->service->method('createContribution')->willReturn($contribution);

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateContributionServiceException(): void {
		$this->mockInput(json_encode(['amount' => 500, 'date' => '2026-03-01']));
		$this->service->method('createContribution')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->createContribution(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroyContribution ─────────────────────────────────────────

	public function testDestroyContributionDeletesContribution(): void {
		$this->service->expects($this->once())->method('deleteContribution')->with(1, 'user1');

		$response = $this->controller->destroyContribution(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroyContributionHandlesError(): void {
		$this->service->method('deleteContribution')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->destroyContribution(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalBalance' => 90000.00, 'count' => 2];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('getSummary')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── projection ──────────────────────────────────────────────────

	public function testProjectionReturnsData(): void {
		$projection = ['projectedBalance' => 500000.00, 'years' => 30];
		$this->projector->method('getProjection')->with(1, 'user1', 35)->willReturn($projection);

		$response = $this->controller->projection(1, 35);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testProjectionHandlesError(): void {
		$this->projector->method('getProjection')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->projection(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── combinedProjection ──────────────────────────────────────────

	public function testCombinedProjectionReturnsData(): void {
		$projection = ['totalProjected' => 1000000.00];
		$this->projector->method('getCombinedProjection')->with('user1', null)->willReturn($projection);

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCombinedProjectionHandlesError(): void {
		$this->projector->method('getCombinedProjection')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCombinedProjectionWithAge(): void {
		$this->projector->expects($this->once())
			->method('getCombinedProjection')
			->with('user1', 40)
			->willReturn([]);

		$response = $this->controller->combinedProjection(40);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── null userId ─────────────────────────────────────────────────

	public function testNullUserIdThrowsOnIndex(): void {
		$controller = new PensionController(
			$this->request, $this->service, $this->projector,
			$this->validationService, $this->l, null, $this->logger
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
