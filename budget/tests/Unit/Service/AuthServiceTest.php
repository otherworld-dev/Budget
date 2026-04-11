<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Auth;
use OCA\Budget\Db\AuthMapper;
use OCA\Budget\Service\AuthService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase {
    private AuthService $service;
    private AuthMapper $mapper;
    private SettingService $settingService;

    protected function setUp(): void {
        $this->mapper = $this->createMock(AuthMapper::class);
        $this->settingService = $this->createMock(SettingService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
            return $text;
        });
        $this->service = new AuthService(
            $this->mapper,
            $this->settingService,
            $l
        );
    }

    private function makeAuth(
        string $userId = 'user1',
        string $passwordHash = '',
        int $failedAttempts = 0,
        ?string $lockedUntil = null,
        ?string $sessionToken = null,
        ?string $sessionExpiresAt = null
    ): Auth {
        $auth = new Auth();
        $auth->setUserId($userId);
        $auth->setPasswordHash($passwordHash ?: password_hash('password123', PASSWORD_DEFAULT));
        $auth->setFailedAttempts($failedAttempts);
        $auth->setLockedUntil($lockedUntil);
        $auth->setSessionToken($sessionToken);
        $auth->setSessionExpiresAt($sessionExpiresAt);
        $auth->setCreatedAt(date('Y-m-d H:i:s'));
        $auth->setUpdatedAt(date('Y-m-d H:i:s'));
        return $auth;
    }

    // ===== isPasswordProtectionEnabled() =====

    public function testIsPasswordProtectionEnabledReturnsTrue(): void {
        $this->settingService->method('get')
            ->with('user1', 'password_protection_enabled')
            ->willReturn('true');

        $this->assertTrue($this->service->isPasswordProtectionEnabled('user1'));
    }

    public function testIsPasswordProtectionEnabledReturnsFalseWhenDisabled(): void {
        $this->settingService->method('get')
            ->with('user1', 'password_protection_enabled')
            ->willReturn('false');

        $this->assertFalse($this->service->isPasswordProtectionEnabled('user1'));
    }

    public function testIsPasswordProtectionEnabledReturnsFalseWhenNull(): void {
        $this->settingService->method('get')
            ->with('user1', 'password_protection_enabled')
            ->willReturn(null);

        $this->assertFalse($this->service->isPasswordProtectionEnabled('user1'));
    }

    // ===== hasPasswordProtection() =====

    public function testHasPasswordProtectionReturnsTrueWhenRecordExists(): void {
        $this->mapper->method('findByUserId')
            ->with('user1')
            ->willReturn($this->makeAuth());

        $this->assertTrue($this->service->hasPasswordProtection('user1'));
    }

    public function testHasPasswordProtectionReturnsFalseWhenNoRecord(): void {
        $this->mapper->method('findByUserId')
            ->with('user1')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->hasPasswordProtection('user1'));
    }

    // ===== setupPassword() =====

    public function testSetupPasswordCreatesNewRecord(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Auth $auth) {
                $this->assertEquals('user1', $auth->getUserId());
                $this->assertTrue(password_verify('secure123', $auth->getPasswordHash()));
                $this->assertEquals(0, $auth->getFailedAttempts());
                return $auth;
            });

        $result = $this->service->setupPassword('user1', 'secure123');
        $this->assertInstanceOf(Auth::class, $result);
    }

    public function testSetupPasswordUpdatesExistingRecord(): void {
        $existing = $this->makeAuth();
        $this->mapper->method('findByUserId')->willReturn($existing);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Auth $auth) {
                $this->assertTrue(password_verify('newpass123', $auth->getPasswordHash()));
                $this->assertEquals(0, $auth->getFailedAttempts());
                $this->assertNull($auth->getLockedUntil());
                $this->assertNull($auth->getSessionToken());
                return $auth;
            });

        $this->service->setupPassword('user1', 'newpass123');
    }

    public function testSetupPasswordRejectsTooShort(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 6 characters');

        $this->service->setupPassword('user1', '12345');
    }

    public function testSetupPasswordAcceptsExactlySixChars(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->setupPassword('user1', '123456');
        $this->assertInstanceOf(Auth::class, $result);
    }

    // ===== verifyPassword() =====

    public function testVerifyPasswordSuccessCreatesSession(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->settingService->method('get')
            ->with('user1', 'session_timeout_minutes')
            ->willReturn('30');

        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->verifyPassword('user1', 'correct');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('sessionToken', $result);
        $this->assertEquals(64, strlen($result['sessionToken']));
    }

    public function testVerifyPasswordFailsWithWrongPassword(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->verifyPassword('user1', 'wrong');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Incorrect password', $result['error']);
        $this->assertStringContainsString('attempt(s) remaining', $result['error']);
    }

    public function testVerifyPasswordFailsWhenNotSetUp(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $result = $this->service->verifyPassword('user1', 'anything');

        $this->assertFalse($result['success']);
        $this->assertEquals('Password protection not set up', $result['error']);
    }

    public function testVerifyPasswordLocksAfterMaxAttempts(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT), 4);
        $this->mapper->method('findByUserId')->willReturn($auth);

        $updatedAuth = null;
        $this->mapper->method('update')
            ->willReturnCallback(function (Auth $a) use (&$updatedAuth) {
                $updatedAuth = $a;
                return $a;
            });

        $result = $this->service->verifyPassword('user1', 'wrong');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('locked', $result['error']);
        $this->assertEquals(5, $updatedAuth->getFailedAttempts());
        $this->assertNotNull($updatedAuth->getLockedUntil());
    }

    public function testVerifyPasswordRejectsLockedAccount(): void {
        $lockedUntil = (new \DateTime())->modify('+3 minutes')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT), 5, $lockedUntil);
        $this->mapper->method('findByUserId')->willReturn($auth);

        $result = $this->service->verifyPassword('user1', 'correct');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('locked', $result['error']);
        $this->assertStringContainsString('minute(s)', $result['error']);
    }

    public function testVerifyPasswordClearsExpiredLockout(): void {
        $expiredLock = (new \DateTime())->modify('-1 minute')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT), 5, $expiredLock);
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->settingService->method('get')
            ->with('user1', 'session_timeout_minutes')
            ->willReturn('30');

        $this->mapper->expects($this->atLeastOnce())->method('update');

        $result = $this->service->verifyPassword('user1', 'correct');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('sessionToken', $result);
    }

    public function testVerifyPasswordResetsFailedAttemptsOnSuccess(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT), 3);
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->settingService->method('get')
            ->with('user1', 'session_timeout_minutes')
            ->willReturn('30');

        $updatedAuth = null;
        $this->mapper->method('update')
            ->willReturnCallback(function (Auth $a) use (&$updatedAuth) {
                $updatedAuth = $a;
                return $a;
            });

        $result = $this->service->verifyPassword('user1', 'correct');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $updatedAuth->getFailedAttempts());
        $this->assertNull($updatedAuth->getLockedUntil());
    }

    public function testVerifyPasswordUsesDefaultTimeoutWhenNotConfigured(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->settingService->method('get')
            ->with('user1', 'session_timeout_minutes')
            ->willReturn(null);

        $updatedAuth = null;
        $this->mapper->method('update')
            ->willReturnCallback(function (Auth $a) use (&$updatedAuth) {
                $updatedAuth = $a;
                return $a;
            });

        $result = $this->service->verifyPassword('user1', 'correct');

        $this->assertTrue($result['success']);
        // Default timeout is 30 minutes
        $expiresAt = new \DateTime($updatedAuth->getSessionExpiresAt());
        $now = new \DateTime();
        $diffMinutes = ($expiresAt->getTimestamp() - $now->getTimestamp()) / 60;
        $this->assertEqualsWithDelta(30, $diffMinutes, 1);
    }

    // ===== isValidSession() =====

    public function testIsValidSessionReturnsTrueForValidToken(): void {
        $expiresAt = (new \DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', '', 0, null, 'validtoken', $expiresAt);
        $this->mapper->method('findBySessionToken')
            ->with('validtoken')
            ->willReturn($auth);

        $this->assertTrue($this->service->isValidSession('validtoken'));
    }

    public function testIsValidSessionReturnsFalseForExpiredToken(): void {
        $expiresAt = (new \DateTime())->modify('-5 minutes')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', '', 0, null, 'expiredtoken', $expiresAt);
        $this->mapper->method('findBySessionToken')
            ->with('expiredtoken')
            ->willReturn($auth);

        $this->mapper->expects($this->once())->method('update');

        $this->assertFalse($this->service->isValidSession('expiredtoken'));
    }

    public function testIsValidSessionReturnsFalseForUnknownToken(): void {
        $this->mapper->method('findBySessionToken')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->isValidSession('unknowntoken'));
    }

    // ===== getUserIdFromSession() =====

    public function testGetUserIdFromSessionReturnsUserIdForValidSession(): void {
        $expiresAt = (new \DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', '', 0, null, 'token123', $expiresAt);
        $this->mapper->method('findBySessionToken')
            ->with('token123')
            ->willReturn($auth);

        $this->assertEquals('user1', $this->service->getUserIdFromSession('token123'));
    }

    public function testGetUserIdFromSessionReturnsNullForExpiredSession(): void {
        $expiresAt = (new \DateTime())->modify('-5 minutes')->format('Y-m-d H:i:s');
        $auth = $this->makeAuth('user1', '', 0, null, 'token123', $expiresAt);
        $this->mapper->method('findBySessionToken')
            ->with('token123')
            ->willReturn($auth);

        $this->assertNull($this->service->getUserIdFromSession('token123'));
    }

    public function testGetUserIdFromSessionReturnsNullForUnknownToken(): void {
        $this->mapper->method('findBySessionToken')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertNull($this->service->getUserIdFromSession('unknown'));
    }

    // ===== extendSession() =====

    public function testExtendSessionExtendsExpiryForCorrectUser(): void {
        $auth = $this->makeAuth('user1', '', 0, null, 'token123');
        $this->mapper->method('findBySessionToken')
            ->with('token123')
            ->willReturn($auth);

        $this->settingService->method('get')
            ->with('user1', 'session_timeout_minutes')
            ->willReturn('60');

        $updatedAuth = null;
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Auth $a) use (&$updatedAuth) {
                $updatedAuth = $a;
                return $a;
            });

        $result = $this->service->extendSession('token123', 'user1');

        $this->assertTrue($result);
        $expiresAt = new \DateTime($updatedAuth->getSessionExpiresAt());
        $now = new \DateTime();
        $diffMinutes = ($expiresAt->getTimestamp() - $now->getTimestamp()) / 60;
        $this->assertEqualsWithDelta(60, $diffMinutes, 1);
    }

    public function testExtendSessionRejectsWrongUser(): void {
        $auth = $this->makeAuth('user1', '', 0, null, 'token123');
        $this->mapper->method('findBySessionToken')
            ->with('token123')
            ->willReturn($auth);

        $this->mapper->expects($this->never())->method('update');

        $result = $this->service->extendSession('token123', 'otheruser');
        $this->assertFalse($result);
    }

    public function testExtendSessionReturnsFalseForUnknownToken(): void {
        $this->mapper->method('findBySessionToken')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->extendSession('unknown', 'user1'));
    }

    // ===== lockSession() =====

    public function testLockSessionClearsSessionData(): void {
        $auth = $this->makeAuth('user1', '', 0, null, 'token123', '2026-12-31 23:59:59');
        $this->mapper->method('findByUserId')
            ->with('user1')
            ->willReturn($auth);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Auth $a) {
                $this->assertNull($a->getSessionToken());
                $this->assertNull($a->getSessionExpiresAt());
                return $a;
            });

        $this->service->lockSession('user1');
    }

    public function testLockSessionHandlesNoAuthRecord(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $this->mapper->expects($this->never())->method('update');

        // Should not throw
        $this->service->lockSession('user1');
    }

    // ===== disablePasswordProtection() =====

    public function testDisablePasswordProtectionWithCorrectPassword(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->mapper->expects($this->once())
            ->method('deleteByUserId')
            ->with('user1');

        $this->settingService->expects($this->once())
            ->method('set')
            ->with('user1', 'password_protection_enabled', 'false');

        $this->assertTrue($this->service->disablePasswordProtection('user1', 'correct'));
    }

    public function testDisablePasswordProtectionRejectsWrongPassword(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->mapper->expects($this->never())->method('deleteByUserId');

        $this->assertFalse($this->service->disablePasswordProtection('user1', 'wrong'));
    }

    public function testDisablePasswordProtectionReturnsFalseWhenNoRecord(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->disablePasswordProtection('user1', 'any'));
    }

    // ===== changePassword() =====

    public function testChangePasswordSuccess(): void {
        $auth = $this->makeAuth('user1', password_hash('oldpass', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Auth $a) {
                $this->assertTrue(password_verify('newpass123', $a->getPasswordHash()));
                return $a;
            });

        $this->assertTrue($this->service->changePassword('user1', 'oldpass', 'newpass123'));
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void {
        $auth = $this->makeAuth('user1', password_hash('correct', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->mapper->expects($this->never())->method('update');

        $this->assertFalse($this->service->changePassword('user1', 'wrong', 'newpass123'));
    }

    public function testChangePasswordRejectsTooShortNewPassword(): void {
        $auth = $this->makeAuth('user1', password_hash('oldpass', PASSWORD_DEFAULT));
        $this->mapper->method('findByUserId')->willReturn($auth);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->changePassword('user1', 'oldpass', '12345');
    }

    public function testChangePasswordReturnsFalseWhenNoRecord(): void {
        $this->mapper->method('findByUserId')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->changePassword('user1', 'old', 'newpass123'));
    }
}
