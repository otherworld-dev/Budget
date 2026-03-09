<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AuditLog;
use OCA\Budget\Db\AuditLogMapper;
use OCA\Budget\Service\AuditService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AuditServiceTest extends TestCase {
    private AuditService $service;
    private AuditLogMapper $mapper;
    private IRequest $request;

    protected function setUp(): void {
        $this->mapper = $this->createMock(AuditLogMapper::class);
        $this->request = $this->createMock(IRequest::class);
        $this->service = new AuditService($this->mapper, $this->request);
    }

    // ===== log =====

    public function testLogCreatesAuditLogEntry(): void {
        $this->request->method('getHeader')
            ->willReturnMap([
                ['X-Forwarded-For', ''],
                ['User-Agent', 'TestBrowser/1.0'],
            ]);
        $this->request->method('getRemoteAddress')->willReturn('192.168.1.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals('user1', $log->getUserId());
                $this->assertEquals('test_action', $log->getAction());
                $this->assertEquals('entity', $log->getEntityType());
                $this->assertEquals(42, $log->getEntityId());
                $this->assertEquals('192.168.1.1', $log->getIpAddress());
                return $log;
            });

        $this->service->log('user1', 'test_action', 'entity', 42, ['key' => 'val']);
    }

    public function testLogWithForwardedIp(): void {
        $this->request->method('getHeader')
            ->willReturnMap([
                ['X-Forwarded-For', '10.0.0.1, 192.168.1.1'],
                ['User-Agent', 'Browser'],
            ]);

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals('10.0.0.1', $log->getIpAddress());
                return $log;
            });

        $this->service->log('user1', 'action');
    }

    public function testLogWithoutRequest(): void {
        $service = new AuditService($this->mapper, null);

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertNull($log->getIpAddress());
                $this->assertNull($log->getUserAgent());
                return $log;
            });

        $service->log('user1', 'action');
    }

    public function testLogSanitizesSensitiveDetails(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $details = json_decode($log->getDetails(), true);
                $this->assertEquals('[REDACTED]', $details['password']);
                $this->assertEquals('[REDACTED]', $details['accountNumber']);
                $this->assertEquals('safe_value', $details['name']);
                return $log;
            });

        $this->service->log('user1', 'action', null, null, [
            'password' => 'secret123',
            'accountNumber' => '12345678',
            'name' => 'safe_value',
        ]);
    }

    public function testLogTruncatesLongUserAgent(): void {
        $this->request->method('getHeader')
            ->willReturnMap([
                ['X-Forwarded-For', ''],
                ['User-Agent', str_repeat('A', 1000)],
            ]);
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals(512, strlen($log->getUserAgent()));
                return $log;
            });

        $this->service->log('user1', 'action');
    }

    // ===== convenience methods =====

    public function testLogAccountCreated(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals(AuditService::ACTION_ACCOUNT_CREATED, $log->getAction());
                $this->assertEquals(AuditService::ENTITY_ACCOUNT, $log->getEntityType());
                $this->assertEquals(1, $log->getEntityId());
                return $log;
            });

        $this->service->logAccountCreated('user1', 1, 'Checking');
    }

    public function testLogAccountUpdatedRedactsValues(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $details = json_decode($log->getDetails(), true);
                // Should only log field names, not values
                $this->assertContains('name', $details['changedFields']);
                return $log;
            });

        $this->service->logAccountUpdated('user1', 1, ['name' => 'New Name']);
    }

    public function testLogImportStarted(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals(AuditService::ACTION_IMPORT_STARTED, $log->getAction());
                $this->assertEquals(AuditService::ENTITY_IMPORT, $log->getEntityType());
                return $log;
            });

        $this->service->logImportStarted('user1', 'data.csv', 'csv');
    }

    public function testLogImportCompleted(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $this->assertEquals(AuditService::ACTION_IMPORT_COMPLETED, $log->getAction());
                $details = json_decode($log->getDetails(), true);
                $this->assertEquals(50, $details['imported']);
                $this->assertEquals(3, $details['skipped']);
                return $log;
            });

        $this->service->logImportCompleted('user1', 1, 50, 3);
    }

    // ===== query methods =====

    public function testGetLogsForUserDelegatesToMapper(): void {
        $this->mapper->expects($this->once())->method('findByUser')
            ->with('user1', 'login', null, null, 50, 10)
            ->willReturn([]);

        $this->service->getLogsForUser('user1', 'login', 50, 10);
    }

    public function testGetLogsForAccountDelegatesToMapper(): void {
        $this->mapper->expects($this->once())->method('findByEntity')
            ->with(AuditService::ENTITY_ACCOUNT, 5, 25)
            ->willReturn([]);

        $this->service->getLogsForAccount(5, 25);
    }

    // ===== sanitize edge cases =====

    public function testSanitizeHandlesNestedArrays(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $details = json_decode($log->getDetails(), true);
                $this->assertEquals('[REDACTED]', $details['nested']['token']);
                $this->assertEquals('visible', $details['nested']['name']);
                return $log;
            });

        $this->service->log('user1', 'action', null, null, [
            'nested' => [
                'token' => 'secret',
                'name' => 'visible',
            ],
        ]);
    }

    public function testSanitizeHandlesIndexedArrayWithSensitiveFieldNames(): void {
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AuditLog $log) {
                $details = json_decode($log->getDetails(), true);
                // 'password' as a value in indexed array should be redacted
                $this->assertEquals('[REDACTED]', $details['fieldsRevealed'][0]);
                return $log;
            });

        $this->service->log('user1', 'action', null, null, [
            'fieldsRevealed' => ['password'],
        ]);
    }
}
