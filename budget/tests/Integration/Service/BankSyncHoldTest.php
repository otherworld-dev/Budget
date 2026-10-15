<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Db\DismissedImportMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\BankSync\BankSyncProviderInterface;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Bank-sync holds against the real database: a hold that paid a bill keeps
 * that bill paid by whatever the bank finally posts, and a cancelled one
 * undoes the payment it made. The provider is the only fake; the services,
 * the unique (account, import id) index and the bill all are real.
 */
class BankSyncHoldTest extends IntegrationTestCase {
	private int $accountId;
	private int $connectionId;
	/** @var array<int, array<string, mixed>> what the fake provider returns */
	private array $feed = [];
	private BankSyncService $sync;

	protected function setUp(): void {
		parent::setUp();
		$this->accountId = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0])->getId();

		$connection = new BankConnection();
		$connection->setUserId($this->userId);
		$connection->setProvider('simplefin');
		$connection->setName('Test bank');
		$connection->setCredentials('{}');
		$connection->setStatus('active');
		$connection->setIncludePending(true);
		$connection->setApplyRules(false);
		$connection->setCreatedAt($this->now());
		$connection->setUpdatedAt($this->now());
		$this->connectionId = $this->service(BankConnectionMapper::class)->insert($connection)->getId();

		$mapping = new BankAccountMapping();
		$mapping->setConnectionId($this->connectionId);
		$mapping->setExternalAccountId('ext-1');
		$mapping->setExternalAccountName('Current');
		$mapping->setBudgetAccountId($this->accountId);
		$mapping->setEnabled(true);
		$mapping->setCreatedAt($this->now());
		$mapping->setUpdatedAt($this->now());
		$this->service(BankAccountMappingMapper::class)->insert($mapping);

		$provider = $this->createMock(BankSyncProviderInterface::class);
		$provider->method('requiresReauthorization')->willReturn(false);
		$provider->method('fetchAccounts')->willReturnCallback(fn () => ['accounts' => [[
			'id' => 'ext-1', 'name' => 'Current', 'balance' => '0', 'currency' => 'GBP',
			'transactions' => $this->feed,
		]]]);
		$providers = $this->createMock(ProviderFactory::class);
		$providers->method('getProvider')->willReturn($provider);
		$admin = $this->createMock(AdminSettingService::class);
		$admin->method('isBankSyncEnabled')->willReturn(true);

		$this->sync = new BankSyncService(
			$this->service(BankConnectionMapper::class),
			$this->service(BankAccountMappingMapper::class),
			$providers,
			$this->service(TransactionService::class),
			$this->service(AuditService::class),
			$admin,
			$this->service(AccountMapper::class),
			$this->service(DismissedImportMapper::class),
			$this->service(ImportRuleApplicator::class),
			$this->service(TransactionTagService::class),
			$this->service(BillService::class),
			$this->service(IFactory::class)->get('budget'),
			$this->service(LoggerInterface::class),
		);
	}

	private function daysAgo(int $days): string {
		return date('Y-m-d', strtotime("-{$days} days"));
	}

	/** A gym bill whose latest payment was the given hold, linked to it. */
	private function billPaidByHold(string $restoredDue, string $paidOn, string $nextDue): int {
		return $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Gym', 'amount' => '20.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'is_active' => true, 'due_day' => (int)date('j', strtotime($restoredDue)),
			'next_due_date' => $nextDue, 'last_paid_date' => $paidOn, 'auto_detect_pattern' => 'PUREGYM',
			'create_transaction' => true, 'created_at' => $this->now(),
		]);
	}

	private function linkSnapshot(int $billId, int $holdId, string $restoredDue, string $paidOn): void {
		$qb = $this->db()->getQueryBuilder();
		$qb->update('budget_bills')
			->set('paid_undo_state', $qb->createNamedParameter(json_encode([
				'previousState' => [
					'lastPaidDate' => null, 'nextDueDate' => $restoredDue, 'remainingPayments' => null,
					'isActive' => true, 'autoPayFailed' => false, 'amount' => 20.0,
				],
				'createdTransactionIds' => [],
				'scheduledTransactionIds' => [],
				'linkedTransactionId' => $holdId,
				'hadScheduledTransaction' => false,
				'paidDate' => $paidOn,
			])))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function testAStaleBillHoldTakesOverItsPostedCopyUnderTheUniqueImportIndex(): void {
		// The bank listed the payment as pending and posted at once, so the
		// posted copy was imported as its own row. Once the hold drops off
		// it takes the copy's import id, which only works if the copy goes
		// first: two rows can't share (account, import id).
		$holdOn = $this->daysAgo(8);
		$bill = $this->billPaidByHold($this->daysAgo(9), $holdOn, date('Y-m-d', strtotime('+3 weeks')));
		$hold = $this->makeTransaction($this->accountId, [
			'date' => $holdOn, 'amount' => '20.00', 'description' => 'PUREGYM', 'status' => 'pending',
			'import_id' => 'simplefin:h1', 'bill_id' => $bill, 'notes' => 'membership',
			'created_at' => $holdOn . ' 08:00:00',
		]);
		$copy = $this->makeTransaction($this->accountId, [
			'date' => $this->daysAgo(6), 'amount' => '20.40', 'description' => 'PUREGYM LTD', 'status' => 'cleared',
			'import_id' => 'simplefin:p1', 'created_at' => $this->daysAgo(6) . ' 09:00:00',
		]);
		$this->linkSnapshot($bill, $hold, $this->daysAgo(9), $holdOn);
		$this->feed = [
			['id' => 'p1', 'date' => $this->daysAgo(6), 'amount' => '-20.40', 'description' => 'PUREGYM LTD', 'vendor' => null, 'pending' => false],
		];

		$this->sync->sync($this->userId, $this->connectionId);

		$this->assertNull($this->fetchRow('budget_transactions', $copy), 'the copy is merged away');
		$row = $this->fetchRow('budget_transactions', $hold);
		$this->assertNotNull($row, 'the hold carries on as the posted payment');
		$this->assertSame('cleared', $row['status']);
		$this->assertSame('simplefin:p1', $row['import_id']);
		$this->assertEqualsWithDelta(20.40, (float)$row['amount'], 0.001);
		$this->assertSame($bill, (int)$row['bill_id']);
		$this->assertSame('membership', $row['notes']);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->accountId, 'import_id' => 'simplefin:p1']));
		$this->assertNotNull($this->fetchRow('budget_bills', $bill)['paid_undo_state'], 'the bill stays paid');
		$this->assertEqualsWithDelta(979.60, (float)$this->service(AccountMapper::class)->find($this->accountId, $this->userId)->getBalance(), 0.001);
	}

	public function testACancelledBillHoldUndoesThePaymentAndBringsBackThePreBookedRow(): void {
		$holdOn = $this->daysAgo(10);
		$restoredDue = $this->daysAgo(8);
		$bill = $this->billPaidByHold($restoredDue, $holdOn, date('Y-m-d', strtotime($restoredDue . ' +1 month')));
		$hold = $this->makeTransaction($this->accountId, [
			'date' => $holdOn, 'amount' => '20.00', 'description' => 'PUREGYM', 'status' => 'pending',
			'import_id' => 'simplefin:h1', 'bill_id' => $bill, 'created_at' => $holdOn . ' 08:00:00',
		]);
		$this->linkSnapshot($bill, $hold, $restoredDue, $holdOn);
		$this->feed = [];

		$this->sync->sync($this->userId, $this->connectionId);

		$this->assertNull($this->fetchRow('budget_transactions', $hold));
		$restored = $this->fetchRow('budget_bills', $bill);
		$this->assertSame($restoredDue, substr((string)$restored['next_due_date'], 0, 10));
		$this->assertNull($restored['last_paid_date']);
		$this->assertNull($restored['paid_undo_state']);
		$this->assertSame(1, $this->countRows('budget_transactions', ['bill_id' => $bill, 'status' => 'scheduled']),
			'the restored occurrence gets its pre-booked row back');
	}

	public function testAHoldPostingForADifferentAmountUnderANewIdKeepsItsBill(): void {
		$holdOn = $this->daysAgo(3);
		$bill = $this->billPaidByHold($this->daysAgo(2), $holdOn, date('Y-m-d', strtotime('+4 weeks')));
		$hold = $this->makeTransaction($this->accountId, [
			'date' => $holdOn, 'amount' => '20.00', 'description' => 'PUREGYM', 'status' => 'pending',
			'import_id' => 'simplefin:h1', 'bill_id' => $bill, 'created_at' => $holdOn . ' 08:00:00',
		]);
		$this->feed = [
			['id' => 'p1', 'date' => $this->daysAgo(1), 'amount' => '-21.00', 'description' => 'PUREGYM LTD', 'vendor' => null, 'pending' => false],
		];

		$this->sync->sync($this->userId, $this->connectionId);

		$row = $this->fetchRow('budget_transactions', $hold);
		$this->assertSame('cleared', $row['status']);
		$this->assertSame('simplefin:p1', $row['import_id']);
		$this->assertEqualsWithDelta(21.00, (float)$row['amount'], 0.001);
		$this->assertSame($bill, (int)$row['bill_id']);
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $this->accountId]));
		$this->assertEqualsWithDelta(979.0, (float)$this->service(AccountMapper::class)->find($this->accountId, $this->userId)->getBalance(), 0.001);
	}
}
