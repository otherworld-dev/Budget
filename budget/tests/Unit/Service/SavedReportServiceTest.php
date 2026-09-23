<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\SavedReport;
use OCA\Budget\Db\SavedReportMapper;
use OCA\Budget\Service\SavedReportService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class SavedReportServiceTest extends TestCase {
	/** @var SavedReportMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $mapper;
	private SavedReportService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(SavedReportMapper::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => $text);
		$this->service = new SavedReportService($this->mapper, $l);
	}

	public function testCreateStoresNameAndConfigAsJson(): void {
		$this->mapper->method('existsByName')->willReturn(false);
		$this->mapper->method('insert')->willReturnArgument(0);

		$report = $this->service->create('user1', '  Credit cards  ', [
			'reportType' => 'spending',
			'accountIds' => [1, 2, 3],
		]);

		$this->assertSame('user1', $report->getUserId());
		$this->assertSame('Credit cards', $report->getName()); // trimmed
		$decoded = json_decode($report->getConfig(), true);
		$this->assertSame('spending', $decoded['reportType']);
		$this->assertSame([1, 2, 3], $decoded['accountIds']);
		$this->assertNotEmpty($report->getCreatedAt());
	}

	public function testCreateRejectsEmptyName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('user1', '   ', []);
	}

	public function testCreateRejectsDuplicateName(): void {
		$this->mapper->method('existsByName')->willReturn(true);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('user1', 'Credit cards', []);
	}

	public function testUpdateAppliesNameAndConfig(): void {
		$existing = new SavedReport();
		$existing->setId(7);
		$existing->setUserId('user1');
		$existing->setName('Old');
		$existing->setConfig('{}');
		$this->mapper->method('findByIdForUser')->with(7, 'user1')->willReturn($existing);
		$this->mapper->method('existsByName')->willReturn(false);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update(7, 'user1', 'New name', ['reportType' => 'summary']);

		$this->assertSame('New name', $updated->getName());
		$this->assertSame('summary', json_decode($updated->getConfig(), true)['reportType']);
	}

	public function testDeleteResolvesOwnershipThenDeletes(): void {
		$existing = new SavedReport();
		$existing->setId(7);
		$existing->setUserId('user1');
		$this->mapper->method('findByIdForUser')->with(7, 'user1')->willReturn($existing);
		$this->mapper->expects($this->once())->method('delete')->with($existing);

		$this->service->delete(7, 'user1');
	}
}
