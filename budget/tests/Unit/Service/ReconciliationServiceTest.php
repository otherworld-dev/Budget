<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ReconciliationSession;
use OCA\Budget\Db\ReconciliationSessionMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ReconciliationConflictException;
use OCA\Budget\Service\ReconciliationService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ReconciliationServiceTest extends TestCase {
    private ReconciliationService $service;
    private ReconciliationSessionMapper $sessionMapper;
    private TransactionMapper $transactionMapper;
    private AccountMapper $accountMapper;
    private AuditService $auditService;

    private const USER = 'alice';
    private const ACCOUNT = 7;

    protected function setUp(): void {
        $this->sessionMapper = $this->createMock(ReconciliationSessionMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->auditService = $this->createMock(AuditService::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn(string $text, array $params = []) => vsprintf(str_replace('%1$s', '%s', $text), $params));

        $account = new Account();
        $account->setId(self::ACCOUNT);
        $account->setOpeningBalance(1000.0);
        $this->accountMapper->method('find')->with(self::ACCOUNT, self::USER)->willReturn($account);
        $this->accountMapper->method('update')->willReturnArgument(0);

        $this->service = new ReconciliationService(
            $this->sessionMapper,
            $this->transactionMapper,
            $this->accountMapper,
            $this->auditService,
            $l
        );
    }

    private function makeSession(array $overrides = []): ReconciliationSession {
        $session = new ReconciliationSession();
        $defaults = [
            'id' => 11,
            'accountId' => self::ACCOUNT,
            'userId' => self::USER,
            'statementDate' => '2026-06-30',
            'statementBalance' => '1500.00',
            'startingBalance' => '1000.00',
            'status' => ReconciliationSession::STATUS_IN_PROGRESS,
            'reconciledCount' => 0,
            'createdAt' => '2026-06-12 10:00:00',
        ];
        foreach (array_merge($defaults, $overrides) as $key => $value) {
            $session->{'set' . ucfirst($key)}($value);
        }
        return $session;
    }

    public function testFirstSessionAnchorIsOpeningPlusReconciledNet(): void {
        $this->sessionMapper->method('findInProgress')->willReturn(null);
        $this->sessionMapper->method('findLastCompleted')->willReturn(null);
        $this->transactionMapper->method('getReconciledNetChange')->with(self::ACCOUNT)->willReturn(250.0);
        $this->sessionMapper->method('insert')->willReturnCallback(function (ReconciliationSession $s) {
            $s->setId(11);
            return $s;
        });
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(0.0);
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([]);

        $state = $this->service->startSession(self::ACCOUNT, self::USER, 1500.0, '2026-06-30');

        $this->assertSame(1250.0, $state['session']['startingBalance']);
        // difference = 1500 − (1250 + 0)
        $this->assertSame(250.0, $state['difference']);
        $this->assertFalse($state['isBalanced']);
    }

    public function testNextSessionAnchorsOnLastStatementBalance(): void {
        $this->sessionMapper->method('findInProgress')->willReturn(null);
        $this->sessionMapper->method('findLastCompleted')->willReturn(
            $this->makeSession(['statementBalance' => '1234.56', 'status' => ReconciliationSession::STATUS_COMPLETED])
        );
        $this->transactionMapper->expects($this->never())->method('getReconciledNetChange');
        $this->sessionMapper->method('insert')->willReturnCallback(function (ReconciliationSession $s) {
            $s->setId(12);
            return $s;
        });
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(0.0);
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([]);

        $state = $this->service->startSession(self::ACCOUNT, self::USER, 1500.0, '2026-07-31');

        $this->assertSame(1234.56, $state['session']['startingBalance']);
    }

    public function testStartConflictsWhenSessionInProgress(): void {
        $this->sessionMapper->method('findInProgress')->willReturn($this->makeSession());
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(0.0);
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([]);

        $this->expectException(ReconciliationConflictException::class);
        $this->service->startSession(self::ACCOUNT, self::USER, 1500.0, '2026-06-30');
    }

    public function testTickedSumDrivesDifference(): void {
        $this->sessionMapper->method('findInProgress')->willReturn($this->makeSession());
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(500.0);
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([1, 2, 3]);

        $state = $this->service->getActiveSession(self::ACCOUNT, self::USER);

        // difference = 1500 − (1000 + 500) = 0 → balanced
        $this->assertSame(0.0, $state['difference']);
        $this->assertTrue($state['isBalanced']);
        $this->assertSame(3, $state['tickedCount']);
        $this->assertSame(1500.0, $state['clearedBalance']);
    }

    public function testCompleteRejectsUnbalancedSession(): void {
        $this->sessionMapper->method('findInProgress')->willReturn($this->makeSession());
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(100.0); // difference 400
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([1]);
        $this->transactionMapper->expects($this->never())->method('markSessionReconciled');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->complete(self::ACCOUNT, self::USER);
    }

    public function testCompleteMarksReconciledAndStampsAccount(): void {
        $session = $this->makeSession();
        $this->sessionMapper->method('findInProgress')->willReturn($session);
        $this->transactionMapper->method('getSessionTickedSum')->willReturn(500.0); // balanced
        $this->transactionMapper->method('getSessionTransactionIds')->willReturn([1, 2]);
        $this->transactionMapper->method('countUntickedBefore')->willReturn(3);
        $this->transactionMapper->expects($this->once())->method('markSessionReconciled')->with(11)->willReturn(2);
        $this->sessionMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (ReconciliationSession $s) {
                $this->assertSame(ReconciliationSession::STATUS_COMPLETED, $s->getStatus());
                $this->assertSame(2, $s->getReconciledCount());
                $this->assertNotNull($s->getCompletedAt());
                return $s;
            });
        $this->accountMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (Account $a) {
                $this->assertNotNull($a->getLastReconciled());
                return $a;
            });
        $this->auditService->expects($this->once())->method('log')
            ->with(self::USER, 'reconciliation_completed', 'account', self::ACCOUNT, $this->anything());

        $result = $this->service->complete(self::ACCOUNT, self::USER);

        $this->assertSame(2, $result['reconciledCount']);
        $this->assertSame(3, $result['untickedBeforeStatementDate']);
    }

    public function testCancelReleasesTickedTransactions(): void {
        $session = $this->makeSession();
        $this->sessionMapper->method('findInProgress')->willReturn($session);
        $this->transactionMapper->expects($this->once())->method('clearSession')->with(11);
        $this->sessionMapper->expects($this->once())->method('delete')->with($session);

        $this->service->cancel(self::ACCOUNT, self::USER);
    }

    public function testTickRejectsOversizedBatch(): void {
        $this->sessionMapper->method('findInProgress')->willReturn($this->makeSession());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->tick(self::ACCOUNT, self::USER, range(1, 501), true);
    }

    public function testTickWithoutActiveSessionFails(): void {
        $this->sessionMapper->method('findInProgress')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->tick(self::ACCOUNT, self::USER, [1], true);
    }

    public function testStartRejectsInvalidDate(): void {
        $this->sessionMapper->method('findInProgress')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->startSession(self::ACCOUNT, self::USER, 1500.0, 'not-a-date');
    }
}
