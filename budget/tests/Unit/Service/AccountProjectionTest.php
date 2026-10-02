<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The account page's projected balance (#163): the balance once the
 * pre-booked bills and transfers go through. The list the page renders
 * from never carried it, and the figure it fell back on left scheduled
 * rows out anyway, so the tile never showed; scheduled rows in the register
 * had an empty balance cell.
 */
class AccountProjectionTest extends TestCase {
	private function account(int $id, float $stored): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName('Current');
		$account->setCurrency('GBP');
		$account->setBalance($stored);
		$account->setOpeningBalance(0.0);
		return $account;
	}

	public function testTheAccountListCarriesTheProjectedBalance(): void {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->account(1, 950.0), $this->account(2, 300.0)]);
		$transactions = $this->createMock(TransactionMapper::class);
		// A cleared row dated next week is in the stored balance, not today's
		$transactions->method('getNetChangeAfterDateBatch')->willReturn([1 => -30.0]);
		// Pre-booked: a 100 bill and a 210 transfer in on account 1
		$transactions->method('getScheduledNetChangeForAccounts')->with([1, 2])->willReturn([1 => 110.0]);
		$conversion = $this->createMock(CurrencyConversionService::class);
		$conversion->method('getBaseCurrency')->willReturn('GBP');
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedAccountIds')->willReturn([]);

		$service = new AccountService(
			$accounts,
			$transactions,
			$this->createMock(InterestRateMapper::class),
			$conversion,
			$shares,
			$this->createMock(TransactionService::class),
			$this->createMock(IL10N::class)
		);

		$byId = array_column($service->findAllWithCurrentBalances('alice'), null, 'id');

		$this->assertSame(980.0, $byId[1]['balance']);
		$this->assertSame(1060.0, $byId[1]['projectedBalance']);
		$this->assertSame(300.0, $byId[2]['projectedBalance']);
	}

	public function testScheduledRowsGetTheirProjectedRunningBalance(): void {
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('findWithFilters')->willReturn(['transactions' => [
			['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4],
		], 'total' => 4]);
		$mapper->expects($this->once())->method('getAllTransactionsForBalance')->with(10, true)->willReturn([
			['id' => 1, 'amount' => '1000.00', 'type' => 'credit', 'status' => 'cleared'],
			['id' => 2, 'amount' => '100.00', 'type' => 'debit', 'status' => 'scheduled'],
			['id' => 3, 'amount' => '50.00', 'type' => 'debit', 'status' => 'cleared'],
			['id' => 4, 'amount' => '210.00', 'type' => 'credit', 'status' => 'scheduled'],
		]);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findById')->willReturn($this->account(10, 950.0));

		$service = new TransactionService(
			$mapper,
			$accounts,
			$this->createMock(\OCA\Budget\Db\TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new \OCA\Budget\Service\UserClock($this->createMock(\OCP\IConfig::class))
		);

		$balances = $service->findWithFilters('alice', ['accountId' => 10], 50, 0)['runningBalances'];

		// Real rows keep the balance money has really reached; a scheduled
		// row shows where the balance will be once it and the scheduled rows
		// before it go through
		$this->assertSame(['1000', '900', '950', '1060'], array_map(
			fn ($b) => rtrim(rtrim((string)$b, '0'), '.'),
			[$balances[1], $balances[2], $balances[3], $balances[4]]
		));
	}
}
