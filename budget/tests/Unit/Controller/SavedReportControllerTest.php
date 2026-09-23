<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SavedReportController;
use OCA\Budget\Db\SavedReport;
use OCA\Budget\Service\SavedReportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SavedReportControllerTest extends TestCase {
	private SavedReportController $controller;
	private SavedReportService $service;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(SavedReportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn($text, $params = []) => vsprintf($text, $params));

		$this->controller = new SavedReportController(
			$this->createMock(IRequest::class),
			$this->service,
			$l,
			'user1',
			$this->logger
		);
	}

	private function makeReport(int $id = 3, string $name = 'Monthly spending', array $config = ['type' => 'spending']): SavedReport {
		$report = new SavedReport();
		$report->setId($id);
		$report->setUserId('user1');
		$report->setName($name);
		$report->setConfig(json_encode($config));
		$report->setCreatedAt('2026-09-01 10:00:00');
		$report->setUpdatedAt('2026-09-01 10:00:00');
		return $report;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsOnlyTheCallersReports(): void {
		$reports = [$this->makeReport(1), $this->makeReport(2, 'Yearly')];
		$this->service->expects($this->once())
			->method('getAll')
			->with('user1')
			->willReturn($reports);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($reports, $response->getData());
	}

	public function testIndexWithNoReportsIsAnEmptyList(): void {
		$this->service->method('getAll')->willReturn([]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testIndexErrorIsGenericAndLogged(): void {
		$this->service->method('getAll')->willThrowException(new \RuntimeException('SQLSTATE boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to load saved reports'], $response->getData());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateReturnsTheNewReportWithCreatedStatus(): void {
		$config = ['type' => 'spending', 'accountIds' => [1, 2]];
		$report = $this->makeReport(7, 'Groceries', $config);
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 'Groceries', $config)
			->willReturn($report);

		$response = $this->controller->create('Groceries', $config);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testCreateWithoutConfigSendsAnEmptyConfig(): void {
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 'Bare', [])
			->willReturn($this->makeReport(8, 'Bare', []));

		$response = $this->controller->create('Bare');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateWithABlankNameShowsTheValidationMessage(): void {
		$this->service->method('create')
			->willThrowException(new \InvalidArgumentException('Please enter a name for the report'));

		$response = $this->controller->create('   ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Please enter a name for the report'], $response->getData());
	}

	public function testCreateUnexpectedErrorReturnsGenericMessage(): void {
		$this->service->method('create')->willThrowException(new \RuntimeException('disk'));

		$response = $this->controller->create('Groceries');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save report'], $response->getData());
	}

	public function testCreateIsRateLimited(): void {
		$attrs = (new \ReflectionMethod(SavedReportController::class, 'create'))
			->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);

		$this->assertCount(1, $attrs);
		$this->assertSame(['limit' => 30, 'period' => 60], $attrs[0]->getArguments());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateRenamesAndReconfigures(): void {
		$report = $this->makeReport(3, 'Renamed', ['type' => 'income']);
		$this->service->expects($this->once())
			->method('update')
			->with(3, 'user1', 'Renamed', ['type' => 'income'])
			->willReturn($report);

		$response = $this->controller->update(3, 'Renamed', ['type' => 'income']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testUpdateWithOnlyANameLeavesConfigUntouched(): void {
		$this->service->expects($this->once())
			->method('update')
			->with(3, 'user1', 'Renamed', null)
			->willReturn($this->makeReport(3, 'Renamed'));

		$response = $this->controller->update(3, 'Renamed');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateWithOnlyAConfigLeavesNameUntouched(): void {
		$this->service->expects($this->once())
			->method('update')
			->with(3, 'user1', null, ['type' => 'income'])
			->willReturn($this->makeReport(3));

		$response = $this->controller->update(3, null, ['type' => 'income']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * The service looks reports up by (id, user), so someone else's report
	 * is indistinguishable from a missing one.
	 */
	public function testUpdateOfAnotherUsersReportIsNotFound(): void {
		$this->service->method('update')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->update(99, 'Mine now');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Saved report not found'], $response->getData());
	}

	public function testUpdateWithAnInvalidNameShowsTheValidationMessage(): void {
		$this->service->method('update')
			->willThrowException(new \InvalidArgumentException('A saved report with this name already exists'));

		$response = $this->controller->update(3, 'Duplicate');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A saved report with this name already exists'], $response->getData());
	}

	public function testUpdateUnexpectedErrorReturnsGenericMessage(): void {
		$this->service->method('update')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->update(3, 'x');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update saved report'], $response->getData());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesTheCallersReport(): void {
		$this->service->expects($this->once())
			->method('delete')
			->with(3, 'user1');

		$response = $this->controller->destroy(3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	public function testDestroyOfAnotherUsersReportIsNotFound(): void {
		$this->service->method('delete')->willThrowException(new DoesNotExistException('no'));

		$response = $this->controller->destroy(99);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Saved report not found'], $response->getData());
	}

	public function testDestroyUnexpectedErrorReturnsGenericMessage(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->destroy(3);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete saved report'], $response->getData());
	}
}
