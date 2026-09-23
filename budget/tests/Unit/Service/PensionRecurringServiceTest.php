<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContribution;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\PensionRecurringService;
use OCA\Budget\Service\PensionService;
use PHPUnit\Framework\TestCase;

class PensionRecurringServiceTest extends TestCase {
	private PensionRecurringService $service;
	/** @var PensionRecurringContributionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $recurringMapper;
	/** @var PensionAccountMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionMapper;
	/** @var PensionService&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionService;

	protected function setUp(): void {
		$this->recurringMapper = $this->createMock(PensionRecurringContributionMapper::class);
		$this->pensionMapper = $this->createMock(PensionAccountMapper::class);
		$this->pensionService = $this->createMock(PensionService::class);

		// FrequencyCalculator is pure logic — use the real one.
		$this->service = new PensionRecurringService(
			$this->recurringMapper,
			$this->pensionMapper,
			$this->pensionService,
			new FrequencyCalculator()
		);
	}

	private function makeRecur(array $overrides = []): PensionRecurringContribution {
		$recur = new PensionRecurringContribution();
		$defaults = [
			'id' => 5,
			'userId' => 'user1',
			'pensionId' => 1,
			'amount' => 200.0,
			'frequency' => 'monthly',
			'sourceAccountId' => null,
			'nextDueDate' => '2026-03-01',
		];
		$data = array_merge($defaults, $overrides);
		$recur->setId($data['id']);
		$recur->setUserId($data['userId']);
		$recur->setPensionId($data['pensionId']);
		$recur->setAmount($data['amount']);
		$recur->setFrequency($data['frequency']);
		$recur->setSourceAccountId($data['sourceAccountId']);
		$recur->setNextDueDate($data['nextDueDate']);
		return $recur;
	}

	public function testProcessAutoPostCreatesManualContributionAndAdvances(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-03-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->recurringMapper->method('update')->willReturnCallback(fn ($r) => $r);

		$this->pensionService->expects($this->once())->method('createContribution')
			->with(1, 'user1', 200.0, '2026-03-01', null);
		$this->pensionService->expects($this->never())->method('createContributionWithTransfer');

		$result = $this->service->processAutoPost(5, 'user1');

		$this->assertTrue($result['success']);
		$this->assertGreaterThan('2026-03-01', $recur->getNextDueDate()); // advanced
		$this->assertSame('2026-03-01', $recur->getLastPostedDate());
	}

	public function testProcessAutoPostWithSourceAccountUsesTransfer(): void {
		$recur = $this->makeRecur(['sourceAccountId' => 10, 'nextDueDate' => '2026-03-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->recurringMapper->method('update')->willReturnCallback(fn ($r) => $r);

		$this->pensionService->expects($this->once())->method('createContributionWithTransfer')
			->with(1, 'user1', 200.0, '2026-03-01', 10, null);
		$this->pensionService->expects($this->never())->method('createContribution');

		$result = $this->service->processAutoPost(5, 'user1');
		$this->assertTrue($result['success']);
	}

	public function testProcessAutoPostReturnsFailureOnError(): void {
		$this->recurringMapper->method('find')->willThrowException(new \RuntimeException('boom'));

		$result = $this->service->processAutoPost(5, 'user1');

		$this->assertFalse($result['success']);
		$this->assertSame('boom', $result['message']);
	}

	public function testPostNowUsesTodayAndAdvances(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-03-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->recurringMapper->method('update')->willReturnCallback(fn ($r) => $r);

		$today = date('Y-m-d');
		$this->pensionService->expects($this->once())->method('createContribution')
			->with(1, 'user1', 200.0, $today, null);

		$result = $this->service->postNow(5, 'user1');
		$this->assertSame($today, $result->getLastPostedDate());
	}

	public function testCreateVerifiesPensionOwnership(): void {
		$this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1');
		$this->recurringMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionRecurringContribution $r) {
				$this->assertSame(1, $r->getPensionId());
				$this->assertSame('quarterly', $r->getFrequency());
				$this->assertTrue($r->getAutoPostEnabled());
				$r->setId(9);
				return $r;
			});

		$this->service->create(1, 'user1', 350.0, 'quarterly', null, true, '2026-09-01', 'note');
	}
}
