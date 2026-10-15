<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountClosureService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * "Dated after today" is judged on the account owner's calendar, not the
 * server's UTC date.
 */
class AccountClosureClockTest extends TestCase {
	public function testFutureRowsAreJudgedOnTheOwnersDate(): void {
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->expects($this->once())->method('hasRowsAfterDate')
			->with(5, '2030-06-15')
			->willReturn(false);
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->with('owner')->willReturn('2030-06-15');

		$service = new AccountClosureService(
			$transactions,
			$this->createMock(BillMapper::class),
			$this->createMock(RecurringIncomeMapper::class),
			$this->createMock(PensionRecurringContributionMapper::class),
			$this->createMock(PensionAccountMapper::class),
			$this->createMock(BankConnectionMapper::class),
			$this->createMock(BankAccountMappingMapper::class),
			$this->createMock(ImportRuleMapper::class),
			$this->createMock(IL10N::class),
			null,
			$clock
		);

		$account = new Account();
		$account->setId(5);
		$account->setUserId('owner');
		$account->setCurrency('GBP');
		$account->setBalance(0.0);

		$service->assertClosable($account);
	}
}
