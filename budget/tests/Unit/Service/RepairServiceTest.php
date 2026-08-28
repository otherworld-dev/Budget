<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\RepairService;
use PHPUnit\Framework\TestCase;

class RepairServiceTest extends TestCase {
    private RepairService $service;
    private TransactionMapper $transactionMapper;
    private BillMapper $billMapper;
    private AccountMapper $accountMapper;
    private FrequencyCalculator $frequencyCalculator;
    private AccountService $accountService;

    private const USER_ID = 'testuser';

    protected function setUp(): void {
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->billMapper = $this->createMock(BillMapper::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
        $this->accountService = $this->createMock(AccountService::class);

        $this->service = new RepairService(
            $this->transactionMapper,
            $this->billMapper,
            $this->accountMapper,
            $this->frequencyCalculator,
            $this->accountService
        );
    }

    private function makeAccount(array $overrides = []): Account {
        $defaults = [
            'id' => 1,
            'name' => 'Checking',
            'balance' => '1000.00',
            'openingBalance' => '0.00',
            'userId' => self::USER_ID,
            'type' => 'checking',
            'liabilityInCredit' => null,
        ];
        $data = array_merge($defaults, $overrides);

        $account = new Account();
        $account->setId($data['id']);
        $account->setName($data['name']);
        $account->setBalance((float) $data['balance']);
        $account->setOpeningBalance((float) $data['openingBalance']);
        $account->setUserId($data['userId']);
        $account->setType($data['type']);
        $account->setLiabilityInCredit($data['liabilityInCredit']);

        return $account;
    }

    private function makeTransaction(array $overrides = []): Transaction {
        $defaults = [
            'id' => 1,
            'date' => '2026-05-01',
            'amount' => '50.00',
            'type' => 'debit',
            'status' => 'cleared',
            'vendor' => 'Test Vendor',
            'description' => 'Test Description',
            'notes' => null,
            'createdAt' => '2026-05-01 10:00:00',
            'accountId' => 1,
            'categoryId' => null,
        ];
        $data = array_merge($defaults, $overrides);

        $tx = new Transaction();
        $tx->setId($data['id']);
        $tx->setDate($data['date']);
        $tx->setAmount((float) $data['amount']);
        $tx->setType($data['type']);
        $tx->setStatus($data['status']);
        $tx->setVendor($data['vendor']);
        $tx->setDescription($data['description']);
        $tx->setNotes($data['notes']);
        $tx->setCreatedAt($data['createdAt']);
        $tx->setAccountId($data['accountId']);
        $tx->setCategoryId($data['categoryId']);

        return $tx;
    }

    private function makeBill(array $overrides = []): Bill {
        $defaults = [
            'id' => 1,
            'name' => 'Rent',
            'frequency' => 'monthly',
            'lastPaidDate' => '2026-05-01',
            'nextDueDate' => '2026-06-01',
            'dueDay' => 1,
            'dueMonth' => null,
            'amount' => '500.00',
            'isActive' => true,
            'customRecurrencePattern' => null,
            'userId' => self::USER_ID,
        ];
        $data = array_merge($defaults, $overrides);

        $bill = new Bill();
        $bill->setId($data['id']);
        $bill->setName($data['name']);
        $bill->setFrequency($data['frequency']);
        $bill->setLastPaidDate($data['lastPaidDate']);
        $bill->setNextDueDate($data['nextDueDate']);
        $bill->setDueDay($data['dueDay']);
        $bill->setDueMonth($data['dueMonth']);
        $bill->setAmount((float) $data['amount']);
        $bill->setIsActive($data['isActive']);
        $bill->setCustomRecurrencePattern($data['customRecurrencePattern']);
        $bill->setUserId($data['userId']);

        return $bill;
    }

    /**
     * Helper to set up accountMapper->findAll to return given accounts.
     */
    private function withAccounts(array $accounts): void {
        $this->accountMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn($accounts);
    }

    /**
     * Helper to set up billMapper->findActive to return given bills.
     */
    private function withActiveBills(array $bills): void {
        $this->billMapper->method('findActive')
            ->with(self::USER_ID)
            ->willReturn($bills);
    }

    // ---------------------------------------------------------------
    // 1. diagnose returns all 6 categories
    // ---------------------------------------------------------------

    public function testDiagnoseReturnsEveryDeclaredCategory(): void {
        $this->withAccounts([]);
        $this->withActiveBills([]);

        $result = $this->service->diagnose(self::USER_ID);

        // Asserted against the registry rather than a hand-kept literal, so
        // adding a category cannot leave diagnose() and the controller's
        // whitelist silently out of step.
        $this->assertSame(RepairService::CATEGORIES, array_keys($result));
    }

    public function testBalanceDriftIsReportedLastSoItRecalculatesAfterEveryOtherFix(): void {
        $categories = RepairService::CATEGORIES;
        $this->assertSame('balanceDrift', end($categories));
    }

    // ---------------------------------------------------------------
    // 2-3. findDuplicateAutoGenerated
    // ---------------------------------------------------------------

    public function testFindDuplicateAutoGeneratedIdentifiesPairsWithSameCreatedAtWithin14Days(): void {
        $account = $this->makeAccount(['id' => 1]);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        // Two auto-generated transactions with same amount+createdAt, dates 5 days apart
        $tx1 = $this->makeTransaction([
            'id' => 10,
            'date' => '2026-05-01',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => 'Auto-generated from bill: Internet',
            'vendor' => 'ISP',
        ]);
        $tx2 = $this->makeTransaction([
            'id' => 11,
            'date' => '2026-05-06',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => 'Auto-generated from bill: Internet',
            'vendor' => 'ISP',
        ]);

        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->with(1)
            ->willReturn([$tx1, $tx2]);
        // No manual duplicates
        $this->transactionMapper->method('findByDateRange')
            ->willReturn([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')->willReturn([]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertNotEmpty($result['duplicateTransactions']);
        $ids = array_column($result['duplicateTransactions'], 'duplicateId');
        $this->assertContains(11, $ids);
    }

    public function testFindDuplicateAutoGeneratedIgnoresEntriesOver14DaysApart(): void {
        $account = $this->makeAccount(['id' => 1]);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        // Same amount+createdAt but 30 days apart (different billing cycle)
        $tx1 = $this->makeTransaction([
            'id' => 10,
            'date' => '2026-05-01',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => null,
            'vendor' => 'ISP',
        ]);
        $tx2 = $this->makeTransaction([
            'id' => 11,
            'date' => '2026-05-31',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => null,
            'vendor' => 'ISP',
        ]);

        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([$tx1, $tx2]);
        $this->transactionMapper->method('findByDateRange')->willReturn([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')->willReturn([]);

        $result = $this->service->diagnose(self::USER_ID);

        // The created_at grouping should not flag these as duplicates
        // (they may still be flagged by same-bill-period check if notes match, but
        // with null notes they won't match the auto-generated pattern)
        $duplicateIds = array_column($result['duplicateTransactions'], 'duplicateId');
        $this->assertNotContains(11, $duplicateIds);
    }

    // ---------------------------------------------------------------
    // 4-8. findStuckBills
    // ---------------------------------------------------------------

    public function testFindStuckBillsDetectsNextDueBeforeLastPaid(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 1,
            'name' => 'Rent',
            'frequency' => 'monthly',
            'lastPaidDate' => '2026-05-15',
            'nextDueDate' => '2026-05-01', // before lastPaid
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['stuckBills']);
        $this->assertEquals('next_due_before_paid', $result['stuckBills'][0]['reason']);
        $this->assertEquals(1, $result['stuckBills'][0]['billId']);
    }

    public function testFindStuckBillsDetectsNextDueNotAdvancedForMonthlyBills(): void {
        $this->withAccounts([]);

        // Monthly bill where nextDue is only 3 days after lastPaid (should be ~30 days)
        $bill = $this->makeBill([
            'id' => 2,
            'name' => 'Electric',
            'frequency' => 'monthly',
            'lastPaidDate' => date('Y-m-d', strtotime('-5 days')),
            'nextDueDate' => date('Y-m-d', strtotime('-2 days')),
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['stuckBills']);
        // Could be next_due_not_advanced or next_due_before_paid depending on exact dates
        $this->assertContains($result['stuckBills'][0]['reason'], [
            'next_due_not_advanced',
            'next_due_before_paid',
            'overdue_despite_recent_payment',
        ]);
    }

    public function testFindStuckBillsDetectsOverdueDespiteRecentPayment(): void {
        $this->withAccounts([]);

        // Bill paid 10 days ago but nextDue is yesterday (overdue despite recent payment)
        $bill = $this->makeBill([
            'id' => 3,
            'name' => 'Phone',
            'frequency' => 'monthly',
            'lastPaidDate' => date('Y-m-d', strtotime('-10 days')),
            'nextDueDate' => date('Y-m-d', strtotime('-1 day')),
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['stuckBills']);
        $this->assertEquals('overdue_despite_recent_payment', $result['stuckBills'][0]['reason']);
    }

    public function testFindStuckBillsDetectsFarFutureDueDate(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 4,
            'name' => 'Insurance',
            'frequency' => 'yearly',
            'lastPaidDate' => '2026-01-01',
            'nextDueDate' => '2040-01-01', // >10 years from now
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['stuckBills']);
        $this->assertEquals('far_future_due_date', $result['stuckBills'][0]['reason']);
    }

    public function testFindStuckBillsSkipsOneTimeBills(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 5,
            'name' => 'One-off payment',
            'frequency' => 'one-time',
            'lastPaidDate' => '2026-05-01',
            'nextDueDate' => '2026-04-01', // before lastPaid, but one-time so should be skipped
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertEmpty($result['stuckBills']);
    }

    // ---------------------------------------------------------------
    // 9. findPaidOneTimeBillsStillActive
    // ---------------------------------------------------------------

    public function testFindPaidOneTimeBillsStillActive(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 10,
            'name' => 'Setup Fee',
            'frequency' => 'one-time',
            'lastPaidDate' => '2026-04-15',
            'nextDueDate' => '2026-04-15',
            'isActive' => true,
            'amount' => '250.00',
        ]);
        $this->withActiveBills([$bill]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['paidOneTimeBills']);
        $this->assertEquals(10, $result['paidOneTimeBills'][0]['billId']);
        $this->assertEquals('Setup Fee', $result['paidOneTimeBills'][0]['name']);
        $this->assertEquals(250.00, $result['paidOneTimeBills'][0]['amount']);
    }

    // ---------------------------------------------------------------
    // 10. findFutureClearedTransactions
    // ---------------------------------------------------------------

    public function testFindFutureClearedTransactions(): void {
        $account = $this->makeAccount(['id' => 1, 'name' => 'Checking']);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $futureTx = $this->makeTransaction([
            'id' => 20,
            'date' => $futureDate,
            'amount' => '75.00',
            'type' => 'debit',
            'status' => 'cleared',
            'vendor' => 'Future Payment',
            'description' => '',
            'accountId' => 1,
        ]);

        $this->transactionMapper->method('findByDateRange')
            ->willReturn([$futureTx]);
        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([]);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')
            ->willReturn([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['futureClearedTransactions']);
        $this->assertEquals(20, $result['futureClearedTransactions'][0]['transactionId']);
        $this->assertEquals($futureDate, $result['futureClearedTransactions'][0]['date']);
    }

    // ---------------------------------------------------------------
    // 11. findTransferCreditsWithCategory
    // ---------------------------------------------------------------

    public function testFindTransferCreditsWithCategoryNoLongerReported(): void {
        // Transfer credits with categories are now expected (since v2.26.3)
        $account = $this->makeAccount(['id' => 1, 'name' => 'Savings']);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);
        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([]);
        $this->transactionMapper->method('findByDateRange')
            ->willReturn([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(0, $result['transferCreditCategories']);
    }

    // ---------------------------------------------------------------
    // 12-13. findBalanceDrift
    // ---------------------------------------------------------------

    public function testFindBalanceDriftDetectsMismatch(): void {
        $account = $this->makeAccount([
            'id' => 1,
            'name' => 'Checking',
            'balance' => '1000.00',
            'openingBalance' => '500.00',
        ]);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        // Net transactions = 400, so expected = 500 + 400 = 900, stored = 1000 => drift of 100
        $this->transactionMapper->method('getNetChangeAll')
            ->with(1)
            ->willReturn(400.0);
        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([]);
        $this->transactionMapper->method('findByDateRange')
            ->willReturn([]);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')
            ->willReturn([]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['balanceDrift']);
        $this->assertEquals(1, $result['balanceDrift'][0]['accountId']);
        $this->assertEquals(1000.0, $result['balanceDrift'][0]['storedBalance']);
        $this->assertEquals(900.0, $result['balanceDrift'][0]['expectedBalance']);
        $this->assertEquals(-100.0, $result['balanceDrift'][0]['difference']);
    }

    public function testFindBalanceDriftNoDriftWhenBalanced(): void {
        $account = $this->makeAccount([
            'id' => 1,
            'name' => 'Checking',
            'balance' => '750.00',
            'openingBalance' => '500.00',
        ]);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        // Net = 250, expected = 500 + 250 = 750 = stored
        $this->transactionMapper->method('getNetChangeAll')
            ->with(1)
            ->willReturn(250.0);
        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([]);
        $this->transactionMapper->method('findByDateRange')
            ->willReturn([]);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')
            ->willReturn([]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertEmpty($result['balanceDrift']);
    }

    // ---------------------------------------------------------------
    // 14. repair duplicateTransactions - deletes duplicates
    // ---------------------------------------------------------------

    public function testRepairDuplicateTransactionsDeletesDuplicates(): void {
        $account = $this->makeAccount(['id' => 1]);
        $this->withAccounts([$account]);

        $tx1 = $this->makeTransaction([
            'id' => 10,
            'date' => '2026-05-01',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => 'Auto-generated from bill: Internet',
            'vendor' => 'ISP',
        ]);
        $tx2 = $this->makeTransaction([
            'id' => 11,
            'date' => '2026-05-05',
            'amount' => '100.00',
            'createdAt' => '2026-05-01 12:00:00',
            'notes' => 'Auto-generated from bill: Internet',
            'vendor' => 'ISP',
        ]);

        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([$tx1, $tx2]);
        $this->transactionMapper->method('findByDateRange')
            ->willReturn([]);

        $this->transactionMapper->method('findById')
            ->willReturnCallback(fn($id) => match ($id) {
                11 => $tx2,
                default => null,
            });

        $this->transactionMapper->expects($this->once())
            ->method('delete')
            ->with($tx2);

        $result = $this->service->repair(self::USER_ID, ['duplicateTransactions']);

        $this->assertArrayHasKey('duplicateTransactions', $result);
        $this->assertEquals(1, $result['duplicateTransactions']['deleted']);
    }

    // ---------------------------------------------------------------
    // 15. repair stuckBills - recalculates nextDueDate
    // ---------------------------------------------------------------

    public function testRepairStuckBillsRecalculatesNextDueDate(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 1,
            'name' => 'Rent',
            'frequency' => 'monthly',
            'lastPaidDate' => '2026-05-15',
            'nextDueDate' => '2026-05-01', // stuck: before lastPaid
            'dueDay' => 1,
        ]);
        $this->withActiveBills([$bill]);

        $this->billMapper->method('find')
            ->with(1, self::USER_ID)
            ->willReturn($bill);

        $this->frequencyCalculator->method('calculateNextDueDate')
            ->willReturn('2026-06-01');

        $this->billMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Bill $b) {
                return $b->getNextDueDate() === '2026-06-01';
            }));

        $result = $this->service->repair(self::USER_ID, ['stuckBills']);

        $this->assertEquals(1, $result['stuckBills']['fixed']);
        $this->assertEquals(1, $result['stuckBills']['found']);
    }

    public function testRepairStuckBillsFarFutureRecalcPassesStartDateAnchor(): void {
        // The far-future path recalculates from scratch; for an anchored
        // biweekly bill that recalc must run from the startDate anchor or the
        // repaired date lands in the wrong fortnight (#364).
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 2,
            'name' => 'Pay cycle',
            'frequency' => 'biweekly',
            'lastPaidDate' => '2026-01-16',
            'nextDueDate' => '2040-01-01', // >10 years out: far_future_due_date
            'dueDay' => 5,
        ]);
        $bill->setStartDate('2026-01-02');
        $this->withActiveBills([$bill]);

        $this->billMapper->method('find')
            ->with(2, self::USER_ID)
            ->willReturn($bill);

        $captured = null;
        $this->frequencyCalculator->method('calculateNextDueDate')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args;
                return '2026-09-04';
            });

        $result = $this->service->repair(self::USER_ID, ['stuckBills']);

        $this->assertEquals(1, $result['stuckBills']['fixed']);
        $this->assertSame('2026-01-02', $captured[6] ?? null, 'far-future recalc must anchor to the start date');
    }

    // ---------------------------------------------------------------
    // 16. repair paidOneTimeBills - deactivates them
    // ---------------------------------------------------------------

    public function testRepairPaidOneTimeBillsDeactivatesThem(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 10,
            'name' => 'Setup Fee',
            'frequency' => 'one-time',
            'lastPaidDate' => '2026-04-15',
            'nextDueDate' => '2026-04-15',
            'isActive' => true,
        ]);
        $this->withActiveBills([$bill]);

        $this->billMapper->method('find')
            ->with(10, self::USER_ID)
            ->willReturn($bill);

        $this->billMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Bill $b) {
                return $b->getIsActive() === false
                    && $b->getNextDueDate() === null;
            }));

        $result = $this->service->repair(self::USER_ID, ['paidOneTimeBills']);

        $this->assertEquals(1, $result['paidOneTimeBills']['fixed']);
        $this->assertEquals(1, $result['paidOneTimeBills']['found']);
    }

    // ---------------------------------------------------------------
    // 17. repair futureClearedTransactions - sets to scheduled, reverses balance
    // ---------------------------------------------------------------

    public function testRepairFutureClearedTransactionsSetsToScheduled(): void {
        $account = $this->makeAccount([
            'id' => 1,
            'name' => 'Checking',
            'balance' => '900.00',
        ]);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);

        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $futureTx = $this->makeTransaction([
            'id' => 20,
            'date' => $futureDate,
            'amount' => '75.00',
            'type' => 'debit',
            'status' => 'cleared',
            'vendor' => 'Future Payment',
            'accountId' => 1,
        ]);

        $this->transactionMapper->method('findByDateRange')
            ->willReturn([$futureTx]);
        $this->transactionMapper->method('findAutoGeneratedBillTransactions')
            ->willReturn([]);
        $this->transactionMapper->method('findLinkedCreditsWithCategory')
            ->willReturn([]);
        $this->transactionMapper->method('getNetChangeAll')
            ->willReturn(0.0);

        $this->transactionMapper->method('findById')
            ->with(20)
            ->willReturn($futureTx);

        $this->accountMapper->method('findById')
            ->with(1)
            ->willReturn($account);

        $this->transactionMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Transaction $tx) {
                return $tx->getStatus() === 'scheduled'
                    && $tx->getUpdatedAt() !== null;
            }));

        // Balances are recomputed from the ledger after the status change —
        // no hand-computed reversal (#274 root cause)
        $this->accountService->expects($this->once())
            ->method('recalculateAllBalances')
            ->with(self::USER_ID);

        $result = $this->service->repair(self::USER_ID, ['futureClearedTransactions']);

        $this->assertEquals(1, $result['futureClearedTransactions']['fixed']);
        $this->assertEquals(1, $result['futureClearedTransactions']['found']);
    }

    // ---------------------------------------------------------------
    // splitParentCategories: split transactions left carrying a category (#360)

    private function makeSplitParentWithCategory(int $id, int $categoryId): Transaction {
        $tx = new Transaction();
        $tx->setId($id);
        $tx->setDate('2026-08-10');
        $tx->setAmount('60.00');
        $tx->setCategoryId($categoryId);
        $tx->setDescription('Tesco');
        $tx->setVendor('Tesco');
        $tx->setIsSplit(true);
        return $tx;
    }

    public function testDiagnoseReportsSplitTransactionsCarryingACategory(): void {
        $this->transactionMapper->method('findCategorisedSplitParents')
            ->with(self::USER_ID)
            ->willReturn([$this->makeSplitParentWithCategory(7, 3)]);

        $result = $this->service->diagnose(self::USER_ID);

        $this->assertCount(1, $result['splitParentCategories']);
        $this->assertSame(7, $result['splitParentCategories'][0]['transactionId']);
        $this->assertSame(3, $result['splitParentCategories'][0]['categoryId']);
    }

    public function testRepairClearsTheStrayCategoryOffASplit(): void {
        $tx = $this->makeSplitParentWithCategory(7, 3);
        $this->transactionMapper->method('findCategorisedSplitParents')->willReturn([$tx]);
        $this->transactionMapper->method('findById')->with(7)->willReturn($tx);

        $updated = null;
        $this->transactionMapper->method('update')->willReturnCallback(
            function (Transaction $t) use (&$updated) {
                $updated = $t;
                return $t;
            }
        );

        $result = $this->service->repair(self::USER_ID, ['splitParentCategories']);

        $this->assertSame(1, $result['splitParentCategories']['fixed']);
        $this->assertNotNull($updated);
        $this->assertNull($updated->getCategoryId());
        // The row stays a split -- only the category it should never have had goes.
        $this->assertTrue($updated->getIsSplit());
    }

    public function testRepairReportsNothingWhenThereIsNothingToFix(): void {
        $this->transactionMapper->method('findCategorisedSplitParents')->willReturn([]);
        $this->transactionMapper->expects($this->never())->method('update');

        $result = $this->service->repair(self::USER_ID, ['splitParentCategories']);

        $this->assertSame(['fixed' => 0, 'found' => 0], $result['splitParentCategories']);
    }

    public function testSplitParentCategoriesIsARecognisedRepairCategory(): void {
        $this->assertContains('splitParentCategories', RepairService::CATEGORIES);
    }

    // 18. transferCreditCategories repair removed (categories now preserved)
    // ---------------------------------------------------------------

    public function testRepairTransferCreditCategoriesNoLongerAvailable(): void {
        // transferCreditCategories repair was removed — should be ignored
        $result = $this->service->repair(self::USER_ID, ['transferCreditCategories']);
        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // 19. repair balanceDrift - delegates to accountService
    // ---------------------------------------------------------------

    public function testRepairBalanceDriftDelegatesToAccountService(): void {
        $this->accountService->expects($this->once())
            ->method('recalculateAllBalances')
            ->with(self::USER_ID)
            ->willReturn(['recalculated' => 2]);

        $result = $this->service->repair(self::USER_ID, ['balanceDrift']);

        $this->assertArrayHasKey('balanceDrift', $result);
        $this->assertEquals(['recalculated' => 2], $result['balanceDrift']);
    }

    // ---------------------------------------------------------------
    // 20. repair only runs requested categories
    // ---------------------------------------------------------------

    public function testRepairOnlyRunsRequestedCategories(): void {
        // Only request balanceDrift - other repairs should NOT run
        $this->accountService->expects($this->once())
            ->method('recalculateAllBalances')
            ->with(self::USER_ID)
            ->willReturn(['recalculated' => 1]);

        // These should never be called since we only requested balanceDrift
        $this->transactionMapper->expects($this->never())
            ->method('delete');
        $this->billMapper->expects($this->never())
            ->method('update');

        $result = $this->service->repair(self::USER_ID, ['balanceDrift']);

        $this->assertArrayHasKey('balanceDrift', $result);
        $this->assertArrayNotHasKey('duplicateTransactions', $result);
        $this->assertArrayNotHasKey('stuckBills', $result);
        $this->assertArrayNotHasKey('paidOneTimeBills', $result);
        $this->assertArrayNotHasKey('futureClearedTransactions', $result);
        $this->assertArrayNotHasKey('transferCreditCategories', $result);
    }

    // ---------------------------------------------------------------
    // Additional edge case: stuck bill far-future uses null currentDue
    // ---------------------------------------------------------------

    public function testRepairStuckBillFarFutureRecalculatesFromScratch(): void {
        $this->withAccounts([]);

        $bill = $this->makeBill([
            'id' => 4,
            'name' => 'Insurance',
            'frequency' => 'yearly',
            'lastPaidDate' => '2026-01-01',
            'nextDueDate' => '2040-01-01',
            'dueDay' => 1,
            'dueMonth' => 1,
        ]);
        $this->withActiveBills([$bill]);

        $this->billMapper->method('find')
            ->with(4, self::USER_ID)
            ->willReturn($bill);

        // For far-future, currentDue should be null and forceAdvance should be false
        $this->frequencyCalculator->expects($this->once())
            ->method('calculateNextDueDate')
            ->with('yearly', 1, 1, null, null, false)
            ->willReturn('2027-01-01');

        $this->billMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Bill $b) {
                return $b->getNextDueDate() === '2027-01-01';
            }));

        $result = $this->service->repair(self::USER_ID, ['stuckBills']);

        $this->assertEquals(1, $result['stuckBills']['fixed']);
    }

    // ---------------------------------------------------------------
    // liabilitySignFlipped (#353)
    //
    // A liability holding a POSITIVE opening balance is being counted as money
    // you have rather than money you owe. It is only ambiguous because a real
    // overpayment looks identical, so the user decides per account and the
    // decision is persisted -- otherwise the scan reports it forever.
    // ---------------------------------------------------------------

    public function testFindLiabilitySignFlippedDetectsAPositiveDebt(): void {
        $this->withAccounts([
            $this->makeAccount(['id' => 1, 'name' => 'Loan', 'type' => 'loan', 'openingBalance' => '90904.56', 'balance' => '90904.56']),
        ]);
        $this->withActiveBills([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);

        $found = $this->service->diagnose(self::USER_ID)['liabilitySignFlipped'];

        $this->assertCount(1, $found);
        $this->assertSame('Loan', $found[0]['accountName']);
        // The figures the user recognises, not raw opening balances.
        $this->assertEqualsWithDelta(90904.56, $found[0]['currentBalance'], 0.005);
        $this->assertEqualsWithDelta(-90904.56, $found[0]['repairedBalance'], 0.005);
    }

    public function testFindLiabilitySignFlippedIgnoresACorrectlySignedDebt(): void {
        $this->withAccounts([
            $this->makeAccount(['id' => 1, 'type' => 'loan', 'openingBalance' => '-5000.00', 'balance' => '-5000.00']),
        ]);
        $this->withActiveBills([]);

        $this->assertSame([], $this->service->diagnose(self::USER_ID)['liabilitySignFlipped']);
    }

    public function testFindLiabilitySignFlippedIgnoresAssets(): void {
        $this->withAccounts([
            $this->makeAccount(['id' => 1, 'type' => 'savings', 'openingBalance' => '5000.00', 'balance' => '5000.00']),
        ]);
        $this->withActiveBills([]);

        $this->assertSame([], $this->service->diagnose(self::USER_ID)['liabilitySignFlipped']);
    }

    public function testFindLiabilitySignFlippedIgnoresADeclaredOverpayment(): void {
        // This is what stops the category nagging forever once the user has
        // said yes, I really did overpay this one.
        $this->withAccounts([
            $this->makeAccount(['id' => 1, 'type' => 'credit_card', 'openingBalance' => '50.00', 'balance' => '50.00', 'liabilityInCredit' => true]),
        ]);
        $this->withActiveBills([]);

        $this->assertSame([], $this->service->diagnose(self::USER_ID)['liabilitySignFlipped']);
    }

    public function testFindLiabilitySignFlippedReportsLargestFirst(): void {
        $this->withAccounts([
            $this->makeAccount(['id' => 1, 'name' => 'Card', 'type' => 'credit_card', 'openingBalance' => '1200.00', 'balance' => '1200.00']),
            $this->makeAccount(['id' => 2, 'name' => 'Mortgage', 'type' => 'mortgage', 'openingBalance' => '250000.00', 'balance' => '250000.00']),
        ]);
        $this->withActiveBills([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);

        $found = $this->service->diagnose(self::USER_ID)['liabilitySignFlipped'];

        $this->assertSame('Mortgage', $found[0]['accountName']);
    }

    public function testRepairLiabilitySignFlippedNegatesOpeningBalanceThenRecalculates(): void {
        $account = $this->makeAccount(['id' => 1, 'type' => 'loan', 'openingBalance' => '90904.56', 'balance' => '90904.56']);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);
        $this->accountMapper->method('findById')->willReturn($account);

        $captured = null;
        $this->accountMapper->method('update')->willReturnCallback(function (Account $a) use (&$captured) {
            $captured = $a;
            return $a;
        });
        // balance is always re-derived FROM opening_balance, so repairing only
        // the balance would look right and silently regress on the next edit.
        $this->accountService->expects($this->once())->method('recalculateAllBalances');

        $result = $this->service->repair(self::USER_ID, ['liabilitySignFlipped']);

        $this->assertSame(1, $result['liabilitySignFlipped']['fixed']);
        $this->assertEqualsWithDelta(-90904.56, $captured->getOpeningBalance(), 0.005);
        $this->assertFalse($captured->getLiabilityInCredit());
    }

    public function testRepairLiabilitySignFlippedMarksDeselectedAccountsAsGenuineCredits(): void {
        $account = $this->makeAccount(['id' => 7, 'type' => 'credit_card', 'openingBalance' => '50.00', 'balance' => '50.00']);
        $this->withAccounts([$account]);
        $this->withActiveBills([]);
        $this->transactionMapper->method('getNetChangeAll')->willReturn(0.0);
        $this->accountMapper->method('findById')->willReturn($account);

        $captured = null;
        $this->accountMapper->method('update')->willReturnCallback(function (Account $a) use (&$captured) {
            $captured = $a;
            return $a;
        });
        $this->accountService->expects($this->never())->method('recalculateAllBalances');

        // Account 7 was unticked in the preview: this one really is overpaid.
        $result = $this->service->repair(self::USER_ID, ['liabilitySignFlipped'], ['accountIds' => []]);

        $this->assertSame(0, $result['liabilitySignFlipped']['fixed']);
        $this->assertSame(1, $result['liabilitySignFlipped']['confirmed']);
        $this->assertEqualsWithDelta(50.00, $captured->getOpeningBalance(), 0.005);
        $this->assertTrue($captured->getLiabilityInCredit());
    }
}
