<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\InterestRate;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\InterestService;
use PHPUnit\Framework\TestCase;

class InterestServiceTest extends TestCase {
    private InterestService $service;
    private InterestRateMapper $rateMapper;
    private TransactionMapper $transactionMapper;
    private AccountMapper $accountMapper;

    private const USER_ID = 'testuser';
    private const ACCOUNT_ID = 1;

    protected function setUp(): void {
        $this->rateMapper = $this->createMock(InterestRateMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);

        $this->service = new InterestService(
            $this->rateMapper,
            $this->transactionMapper,
            $this->accountMapper
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeAccount(array $overrides = []): Account {
        $defaults = [
            'id' => self::ACCOUNT_ID,
            'userId' => self::USER_ID,
            'balance' => '-1000.00',
            'type' => 'credit_card',
            'interestEnabled' => true,
            'openingDate' => '2025-01-01',
            'createdAt' => '2025-01-01 00:00:00',
            'openingBalance' => '-1000.00',
            'interestRate' => 19.99,
            'compoundingFrequency' => 'daily',
        ];
        $data = array_merge($defaults, $overrides);

        $account = new Account();
        $account->setId($data['id']);
        $account->setUserId($data['userId']);
        $account->setBalance((float) $data['balance']);
        $account->setType($data['type']);
        $account->setInterestEnabled($data['interestEnabled']);
        $account->setOpeningDate($data['openingDate']);
        $account->setCreatedAt($data['createdAt']);
        $account->setOpeningBalance((float) $data['openingBalance']);
        $account->setInterestRate((float) $data['interestRate']);
        $account->setCompoundingFrequency($data['compoundingFrequency']);

        return $account;
    }

    private function makeRate(float $rate, string $effectiveDate, string $compounding = 'daily', int $id = 1): InterestRate {
        $ir = new InterestRate();
        $ir->setId($id);
        $ir->setAccountId(self::ACCOUNT_ID);
        $ir->setUserId(self::USER_ID);
        $ir->setRate($rate);
        $ir->setCompoundingFrequency($compounding);
        $ir->setEffectiveDate($effectiveDate);
        $ir->setCreatedAt('2025-01-01 00:00:00');
        return $ir;
    }

    private function makeTransaction(string $date, string $amount, string $type = 'debit', int $id = 1): Transaction {
        $tx = new Transaction();
        $tx->setId($id);
        $tx->setDate($date);
        $tx->setAmount((float) $amount);
        $tx->setType($type);
        $tx->setStatus('cleared');
        return $tx;
    }

    private function setupCalculation(Account $account, array $rates = [], array $transactions = []): void {
        $this->accountMapper->method('find')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($account);
        $this->rateMapper->method('findByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($rates);
        $this->transactionMapper->method('findAllClearedByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($transactions);
    }

    // ── calculateAccruedInterest Tests ──────────────────────────────

    public function testCalculateAccruedInterest_InterestDisabled(): void {
        $account = $this->makeAccount(['interestEnabled' => false, 'balance' => '-500.00']);
        $this->setupCalculation($account);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID);

        $this->assertEquals(-500.0, $result['principal']);
        $this->assertEquals(0.0, $result['accruedInterest']);
        $this->assertEquals(-500.0, $result['totalOwing']);
        $this->assertTrue($result['isLiability']);
    }

    public function testCalculateAccruedInterest_NoRatePeriods(): void {
        $account = $this->makeAccount(['interestEnabled' => true]);
        $this->setupCalculation($account, [], []);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID);

        $this->assertEquals(0.0, $result['accruedInterest']);
    }

    public function testCalculateAccruedInterest_SimpleCompounding(): void {
        // 10% simple interest on -1000 for 365 days = 100
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'simple')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        $this->assertEquals(100.0, $result['accruedInterest']);
        $this->assertTrue($result['isLiability']);
    }

    public function testCalculateAccruedInterest_DailyCompounding(): void {
        // 10% daily compound on -1000 for 365 days
        // Expected: 1000 * ((1 + 0.1/365)^365 - 1) ~ 105.16
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'daily')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        // Daily compounding of 10% over a year ~ 10.52%
        $this->assertGreaterThan(100.0, $result['accruedInterest']);
        $this->assertLessThan(110.0, $result['accruedInterest']);
        $this->assertEqualsWithDelta(105.15, $result['accruedInterest'], 0.10);
    }

    public function testCalculateAccruedInterest_MonthlyCompounding(): void {
        // 12% monthly compound on -1000 for 1 full month (Jan = 31 days)
        // Monthly rate = 1%, full month = 1% of 1000 = 10
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(12.0, '2025-01-01', 'monthly')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2025-02-01');

        $this->assertEqualsWithDelta(10.0, $result['accruedInterest'], 0.01);
    }

    public function testCalculateAccruedInterest_MonthlyCompounding_PartialMonth(): void {
        // 12% monthly compound on -1000 for 15 days in Jan (31 days)
        // Monthly rate = 1%, pro-rated: 1% * 15/31 = ~4.84
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(12.0, '2025-01-01', 'monthly')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2025-01-16');

        // 1000 * 0.01 * 15/31 = 4.838...
        $this->assertEqualsWithDelta(4.83, $result['accruedInterest'], 0.02);
    }

    public function testCalculateAccruedInterest_YearlyCompounding(): void {
        // 10% yearly compound on -1000 for 1 full year = 100
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'yearly')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        $this->assertEqualsWithDelta(100.0, $result['accruedInterest'], 0.01);
    }

    public function testCalculateAccruedInterest_RateChangeMidPeriod(): void {
        // Start at 10% simple, change to 20% simple halfway through the year
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [
            $this->makeRate(10.0, '2025-01-01', 'simple', 1),
            $this->makeRate(20.0, '2025-07-02', 'simple', 2),  // ~182 days in
        ];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        // First half: 1000 * 0.10 * 182/365 = ~49.86
        // Second half: (1000 + ~49.86) * 0.20 * 183/365 = ~105.19
        // Total: ~155
        $this->assertGreaterThan(140.0, $result['accruedInterest']);
        $this->assertLessThan(170.0, $result['accruedInterest']);
    }

    public function testCalculateAccruedInterest_TransactionsAdjustPrincipal(): void {
        // Start with -1000, add -500 debit on day 183, simple 10%
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'simple')];
        $transactions = [
            $this->makeTransaction('2025-07-02', '500.00', 'debit', 1),
        ];
        $this->setupCalculation($account, $rates, $transactions);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        // First 182 days on -1000: 1000 * 0.10 * 182/365 = ~49.86
        // After debit of 500, principal = -1500
        // Next 183 days on outstanding (1500 + ~49.86): (1549.86) * 0.10 * 183/365 = ~77.69
        // Total ~ 127.55
        $this->assertGreaterThan(120.0, $result['accruedInterest']);
        $this->assertLessThan(135.0, $result['accruedInterest']);
    }

    public function testCalculateAccruedInterest_AsOfDateLimitsCalculation(): void {
        // Only calculate up to asOfDate, not full period
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'simple')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2025-04-01');

        // 90 days: 1000 * 0.10 * 90/365 = ~24.66
        $this->assertEqualsWithDelta(24.65, $result['accruedInterest'], 0.02);
    }

    public function testCalculateAccruedInterest_LiabilityOnlyChargesWhenNegative(): void {
        // Liability with positive balance (overpayment) should not accrue interest
        $account = $this->makeAccount([
            'openingBalance' => '100.00',
            'openingDate' => '2025-01-01',
            'balance' => '100.00',
            'type' => 'credit_card',
        ]);
        $rates = [$this->makeRate(20.0, '2025-01-01', 'simple')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        $this->assertEquals(0.0, $result['accruedInterest']);
        $this->assertTrue($result['isLiability']);
    }

    public function testCalculateAccruedInterest_AssetOnlyEarnsWhenPositive(): void {
        // Savings account with positive balance earns interest
        $account = $this->makeAccount([
            'openingBalance' => '5000.00',
            'openingDate' => '2025-01-01',
            'balance' => '5000.00',
            'type' => 'savings',
            'interestEnabled' => true,
        ]);
        $rates = [$this->makeRate(5.0, '2025-01-01', 'simple')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        // 5000 * 0.05 * 365/365 = 250
        $this->assertEqualsWithDelta(250.0, $result['accruedInterest'], 0.01);
        $this->assertFalse($result['isLiability']);
    }

    public function testCalculateAccruedInterest_AssetNoInterestWhenNegative(): void {
        // Savings account with negative balance (overdraft) should not earn interest
        $account = $this->makeAccount([
            'openingBalance' => '-100.00',
            'openingDate' => '2025-01-01',
            'balance' => '-100.00',
            'type' => 'savings',
            'interestEnabled' => true,
        ]);
        $rates = [$this->makeRate(5.0, '2025-01-01', 'simple')];
        $this->setupCalculation($account, $rates);

        $result = $this->service->calculateAccruedInterest(self::ACCOUNT_ID, self::USER_ID, '2026-01-01');

        $this->assertEquals(0.0, $result['accruedInterest']);
        $this->assertFalse($result['isLiability']);
    }

    // ── enableInterest Tests ────────────────────────────────────────

    public function testEnableInterest_CreatesRateAndUpdatesAccount(): void {
        $account = $this->makeAccount([
            'interestRate' => 15.0,
            'openingDate' => '2025-03-01',
        ]);

        $this->accountMapper->expects($this->once())
            ->method('find')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($account);

        $this->rateMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (InterestRate $rate) {
                return $rate->getAccountId() === self::ACCOUNT_ID
                    && $rate->getUserId() === self::USER_ID
                    && $rate->getRate() === 15.0
                    && $rate->getCompoundingFrequency() === 'monthly'
                    && $rate->getEffectiveDate() === '2025-03-01';
            }));

        $this->accountMapper->expects($this->once())
            ->method('update')
            ->with($account);

        $this->service->enableInterest(self::ACCOUNT_ID, self::USER_ID, 'monthly');
    }

    public function testEnableInterest_UsesProvidedEffectiveDate(): void {
        $account = $this->makeAccount(['interestRate' => 10.0]);

        $this->accountMapper->method('find')->willReturn($account);

        $this->rateMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (InterestRate $rate) {
                return $rate->getEffectiveDate() === '2025-06-15';
            }));

        $this->accountMapper->expects($this->once())->method('update');

        $this->service->enableInterest(self::ACCOUNT_ID, self::USER_ID, 'daily', '2025-06-15');
    }

    // ── disableInterest Tests ───────────────────────────────────────

    public function testDisableInterest_DeletesRatesAndClearsFlags(): void {
        $account = $this->makeAccount();

        $this->accountMapper->expects($this->once())
            ->method('find')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($account);

        $this->rateMapper->expects($this->once())
            ->method('deleteByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID);

        $this->accountMapper->expects($this->once())
            ->method('update')
            ->with($account);

        $this->service->disableInterest(self::ACCOUNT_ID, self::USER_ID);
    }

    // ── addRateChange Tests ─────────────────────────────────────────

    public function testAddRateChange_CreatesRecord(): void {
        $insertedRate = $this->makeRate(8.5, '2025-06-01', 'monthly', 5);

        $this->rateMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (InterestRate $rate) {
                return $rate->getAccountId() === self::ACCOUNT_ID
                    && $rate->getUserId() === self::USER_ID
                    && $rate->getRate() === 8.5
                    && $rate->getCompoundingFrequency() === 'monthly'
                    && $rate->getEffectiveDate() === '2025-06-01';
            }))
            ->willReturn($insertedRate);

        $result = $this->service->addRateChange(self::ACCOUNT_ID, self::USER_ID, 8.5, 'monthly', '2025-06-01');

        $this->assertSame($insertedRate, $result);
    }

    // ── deleteRateChange Tests ──────────────────────────────────────

    public function testDeleteRateChange_ThrowsWhenOnlyOneRecord(): void {
        $rate = $this->makeRate(10.0, '2025-01-01', 'daily', 7);

        $this->rateMapper->method('find')
            ->with(7, self::USER_ID)
            ->willReturn($rate);

        $this->rateMapper->method('countByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn(1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete the only rate record');

        $this->service->deleteRateChange(7, self::USER_ID);
    }

    public function testDeleteRateChange_SucceedsWhenMultipleRecords(): void {
        $rate = $this->makeRate(10.0, '2025-01-01', 'daily', 7);

        $this->rateMapper->method('find')
            ->with(7, self::USER_ID)
            ->willReturn($rate);

        $this->rateMapper->method('countByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn(3);

        $this->rateMapper->expects($this->once())
            ->method('delete')
            ->with($rate);

        $this->service->deleteRateChange(7, self::USER_ID);
    }

    // ── getRateHistory Tests ────────────────────────────────────────

    public function testGetRateHistory_DelegatesToMapper(): void {
        $rates = [
            $this->makeRate(10.0, '2025-01-01', 'daily', 1),
            $this->makeRate(12.0, '2025-06-01', 'monthly', 2),
        ];

        $this->rateMapper->expects($this->once())
            ->method('findByAccount')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($rates);

        $result = $this->service->getRateHistory(self::ACCOUNT_ID, self::USER_ID);

        $this->assertCount(2, $result);
        $this->assertSame($rates, $result);
    }

    // ── refreshAccruedInterestCache Tests ───────────────────────────

    public function testRefreshAccruedInterestCache_RecalculatesAndUpdates(): void {
        $account = $this->makeAccount([
            'openingBalance' => '-1000.00',
            'openingDate' => '2025-01-01',
        ]);
        $rates = [$this->makeRate(10.0, '2025-01-01', 'simple')];

        $this->accountMapper->method('find')
            ->with(self::ACCOUNT_ID, self::USER_ID)
            ->willReturn($account);
        $this->rateMapper->method('findByAccount')
            ->willReturn($rates);
        $this->transactionMapper->method('findAllClearedByAccount')
            ->willReturn([]);

        $this->accountMapper->expects($this->once())
            ->method('updateAccruedInterest')
            ->with(self::ACCOUNT_ID, $this->callback(function (string $amount) {
                // Should be some positive interest value
                return (float) $amount > 0;
            }));

        $result = $this->service->refreshAccruedInterestCache(self::ACCOUNT_ID, self::USER_ID);

        $this->assertIsString($result);
        $this->assertGreaterThan(0, (float) $result);
    }

    public function testRefreshAccruedInterestCache_ReturnsZeroWhenDisabled(): void {
        $account = $this->makeAccount(['interestEnabled' => false]);
        $this->accountMapper->method('find')->willReturn($account);

        $this->accountMapper->expects($this->once())
            ->method('updateAccruedInterest')
            ->with(self::ACCOUNT_ID, '0.00');

        $result = $this->service->refreshAccruedInterestCache(self::ACCOUNT_ID, self::USER_ID);

        $this->assertEquals('0.00', $result);
    }
}
