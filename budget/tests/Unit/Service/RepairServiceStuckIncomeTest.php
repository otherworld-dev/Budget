<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\LegacyIncomeRows;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\RepairService;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;

/**
 * Repair Data's "income received early but still due" (#399): the incomes
 * 2.54.0 left due on a receipt it had already booked. The upgrade settles
 * the ones it finds (migration 125); a backup restored since brings them
 * back, and Repair Data lists and settles them the same way.
 */
class RepairServiceStuckIncomeTest extends TestCase {
	private const USER_ID = 'terje';

	private LegacyIncomeRows $legacyIncomeRows;

	protected function setUp(): void {
		$this->legacyIncomeRows = $this->createMock(LegacyIncomeRows::class);
	}

	private function service(?LegacyIncomeRows $legacyIncomeRows): RepairService {
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findAll')->willReturn([]);
		$billMapper = $this->createMock(BillMapper::class);
		$billMapper->method('findAll')->willReturn([]);
		$billMapper->method('findActive')->willReturn([]);
		return new RepairService(
			$this->createMock(TransactionMapper::class),
			$billMapper,
			$accountMapper,
			new FrequencyCalculator(),
			$this->createMock(AccountService::class),
			$this->createMock(TransactionService::class),
			null,
			$legacyIncomeRows
		);
	}

	private function sbbAbo(int $id = 9): array {
		return [
			'incomeId' => $id, 'userId' => self::USER_ID, 'name' => 'Gutschrift SBB Abo Leon', 'accountId' => 1,
			'transactionId' => 1293, 'amount' => 100.0, 'lastReceivedDate' => '2026-09-28',
			'nextExpectedDate' => '2026-09-29', 'settledNextExpectedDate' => '2026-10-29',
		];
	}

	public function testItIsARepairCategory(): void {
		$this->assertContains('stuckIncome', RepairService::CATEGORIES);
	}

	public function testDiagnoseListsTheUsersStuckIncomes(): void {
		$this->legacyIncomeRows->expects($this->once())->method('stuckEarlyReceipts')
			->with(self::USER_ID)
			->willReturn([$this->sbbAbo()]);

		$found = $this->service($this->legacyIncomeRows)->diagnose(self::USER_ID)['stuckIncome'];

		$this->assertSame([[
			'incomeId' => 9,
			'name' => 'Gutschrift SBB Abo Leon',
			'amount' => 100.0,
			'transactionId' => 1293,
			'lastReceivedDate' => '2026-09-28',
			'nextExpectedDate' => '2026-09-29',
			'settledNextExpectedDate' => '2026-10-29',
		]], $found);
	}

	public function testRepairSettlesEachOne(): void {
		$this->legacyIncomeRows->method('stuckEarlyReceipts')->with(self::USER_ID)
			->willReturn([$this->sbbAbo(9), $this->sbbAbo(12)]);
		$settled = [];
		$this->legacyIncomeRows->expects($this->exactly(2))->method('settle')
			->willReturnCallback(function (array $stuck) use (&$settled) {
				$settled[] = $stuck['incomeId'];
				// The second was received in another tab since the scan
				return $stuck['incomeId'] === 9;
			});

		$result = $this->service($this->legacyIncomeRows)->repair(self::USER_ID, ['stuckIncome']);

		$this->assertSame([9, 12], $settled);
		$this->assertSame(['fixed' => 1, 'found' => 2], $result['stuckIncome']);
	}

	public function testNothingIsSettledUnlessTheCategoryIsAskedFor(): void {
		$this->legacyIncomeRows->expects($this->never())->method('settle');

		$this->service($this->legacyIncomeRows)->repair(self::USER_ID, ['balanceDrift']);
	}

	public function testWithoutTheHelperThereIsNothingToFind(): void {
		$service = $this->service(null);

		$this->assertSame([], $service->diagnose(self::USER_ID)['stuckIncome']);
		$this->assertSame(['fixed' => 0, 'found' => 0], $service->repair(self::USER_ID, ['stuckIncome'])['stuckIncome']);
	}
}
