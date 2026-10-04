<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Paid by linking a transaction that already exists. The row's id comes
 * from the browser, so it must be one the user paying can see, and the row
 * only takes the bill's category when its own ledger can use it.
 *
 * Bob owns the bill. Carol pays it through a share of the bill. The row sits
 * in account 30, which belongs to Dave and is shared with Bob for writing.
 */
class BillLinkedRowAccessTest extends TestCase {
	private BillService $service;
	private BillMapper $mapper;
	private TransactionService $transactions;
	/** @var array<string, int[]> user => accounts they can see */
	private array $visible = ['bob' => [10, 30]];
	/** @var array<string, int[]> user => categories their ledger can use */
	private array $usable = ['bob' => [5]];

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactions = $this->createMock(TransactionService::class);

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canWrite')->willReturnCallback(
			fn (string $user, string $type, int $id) => $type === 'account' && $user === 'bob' && in_array($id, [10, 30], true)
		);
		$shares->method('canAccess')->willReturnCallback(
			fn (string $user, string $type, int $id) => $type === 'account' && in_array($id, $this->visible[$user] ?? [], true)
		);
		$shares->method('requireUsableCategory')->willReturnCallback(function (string $owner, ?int $categoryId) {
			if ($categoryId !== null && !in_array($categoryId, $this->usable[$owner] ?? [], true)) {
				throw new \InvalidArgumentException('Category not found');
			}
		});

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findById')->willReturnCallback(function (int $id) {
			$account = new Account();
			$account->setId($id);
			$account->setUserId($id === 30 ? 'dave' : 'bob');
			return $account;
		});

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new BillService(
			$this->mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$this->transactions,
			$l,
			$accounts,
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			$shares,
		);
	}

	private function bill(?int $accountId, ?int $categoryId = null): void {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('bob');
		$bill->setName('Electricity');
		$bill->setAmount(60.0);
		$bill->setFrequency('monthly');
		$bill->setDueDay(15);
		$bill->setIsActive(true);
		$bill->setAccountId($accountId);
		$bill->setCategoryId($categoryId);
		$bill->setNextDueDate('2026-09-15');
		$bill->setAutoPayEnabled(false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer(false);
		$this->mapper->method('find')->willReturnCallback(fn () => clone $bill);
	}

	private function row(int $accountId): void {
		$row = new Transaction();
		$row->setId(77);
		$row->setAccountId($accountId);
		$row->setDate('2026-09-14');
		$row->setAmount(60.0);
		$row->setType('debit');
		$row->setIsSplit(false);
		$this->transactions->method('findTransaction')->with(77)->willReturn($row);
	}

	public function testARowTheUserPayingCannotSeeIsNotLinked(): void {
		// Carol may pay Bob's bill, which has no account. Dave's account
		// isn't shared with her, yet she could link and stamp its row 77
		$this->bill(null);
		$this->row(30);
		$this->transactions->expects($this->never())->method('linkBillAsAccountOwner');

		$this->expectExceptionMessage('That transaction can\'t pay this bill');
		$this->service->markPaid(1, 'bob', null, false, 77, null, 'carol');
	}

	public function testARowInTheBillsOwnAccountNeedsTheUserToSeeItToo(): void {
		$this->bill(10);
		$this->row(10);
		$this->transactions->expects($this->never())->method('linkBillAsAccountOwner');

		$this->expectExceptionMessage('That transaction can\'t pay this bill');
		$this->service->markPaid(1, 'bob', null, false, 77, null, 'carol');
	}

	public function testSomeoneWhoCanSeeTheRowLinksIt(): void {
		$this->visible['carol'] = [30];
		$this->bill(null);
		$this->row(30);
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')->willReturn(new Transaction());

		$result = $this->service->markPaid(1, 'bob', null, false, 77, null, 'carol');

		$this->assertTrue($result['linkedExistingTransaction']);
	}

	public function testAPaymentMatchedByTheAppIsHeldToTheBillsOwnerOnly(): void {
		// Import auto-match and bank sync name no user
		$this->bill(10);
		$this->row(10);
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')->willReturn(new Transaction());

		$this->service->markPaid(1, 'bob', null, false, 77);
	}

	public function testTheBillsCategoryStaysOutOfALedgerThatCannotUseIt(): void {
		// Bob's category 5 is not Dave's: the row in Dave's account kept it
		$this->bill(null, 5);
		$this->row(30);
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')
			->with(77, $this->anything(), false)->willReturn(new Transaction());

		$this->service->markPaid(1, 'bob', null, false, 77, null, 'bob');
	}

	public function testTheBillsCategoryIsFiledWhereItCanBeUsed(): void {
		$this->usable['dave'] = [5];
		$this->bill(null, 5);
		$this->row(30);
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')
			->with(77, $this->anything(), true)->willReturn(new Transaction());

		$this->service->markPaid(1, 'bob', null, false, 77, null, 'bob');
	}
}
