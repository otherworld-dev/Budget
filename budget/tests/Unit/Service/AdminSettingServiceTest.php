<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdminSettingServiceTest extends TestCase {
    private AdminSettingService $service;
    private IConfig $config;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->service = new AdminSettingService($this->config);
    }

    // ===== isBankSyncEnabled() =====

    public function testIsBankSyncEnabledReturnsTrueWhenEnabled(): void {
        $this->config->method('getAppValue')
            ->with(Application::APP_ID, 'bank_sync_enabled', 'false')
            ->willReturn('true');

        $this->assertTrue($this->service->isBankSyncEnabled());
    }

    public function testIsBankSyncEnabledReturnsFalseWhenDisabled(): void {
        $this->config->method('getAppValue')
            ->with(Application::APP_ID, 'bank_sync_enabled', 'false')
            ->willReturn('false');

        $this->assertFalse($this->service->isBankSyncEnabled());
    }

    public function testIsBankSyncEnabledReturnsFalseByDefault(): void {
        $this->config->method('getAppValue')
            ->with(Application::APP_ID, 'bank_sync_enabled', 'false')
            ->willReturn('false');

        $this->assertFalse($this->service->isBankSyncEnabled());
    }

    // ===== setBankSyncEnabled() =====

    public function testSetBankSyncEnabledSetsAppValue(): void {
        $this->config->expects($this->exactly(2))
            ->method('setAppValue')
            ->willReturnCallback(function (string $appId, string $key, string $value) {
                $this->assertEquals(Application::APP_ID, $appId);
                $this->assertEquals('bank_sync_enabled', $key);
                $this->assertContains($value, ['true', 'false']);
            });

        $this->service->setBankSyncEnabled(true);
        $this->service->setBankSyncEnabled(false);
    }

    // ===== getAll() =====

    public function testGetAllReturnsStructuredArray(): void {
        $this->config->method('getAppValue')
            ->with(Application::APP_ID, 'bank_sync_enabled', 'false')
            ->willReturn('true');

        $result = $this->service->getAll();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('bankSyncEnabled', $result);
        $this->assertTrue($result['bankSyncEnabled']);
    }
}
