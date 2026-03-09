<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class SettingServiceTest extends TestCase {
    private SettingService $service;
    private SettingMapper $mapper;

    protected function setUp(): void {
        $this->mapper = $this->createMock(SettingMapper::class);
        $this->service = new SettingService($this->mapper);
    }

    private function makeSetting(string $key, string $value): Setting {
        $setting = new Setting();
        $setting->setUserId('user1');
        $setting->setKey($key);
        $setting->setValue($value);
        return $setting;
    }

    // ===== get =====

    public function testGetReturnsValueWhenFound(): void {
        $setting = $this->makeSetting('theme', 'dark');
        $this->mapper->method('findByKey')->willReturn($setting);

        $result = $this->service->get('user1', 'theme');

        $this->assertEquals('dark', $result);
    }

    public function testGetReturnsNullWhenNotFound(): void {
        $this->mapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(''));

        $result = $this->service->get('user1', 'missing');

        $this->assertNull($result);
    }

    // ===== set =====

    public function testSetUpdatesExistingSetting(): void {
        $existing = $this->makeSetting('theme', 'light');
        $this->mapper->method('findByKey')->willReturn($existing);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->set('user1', 'theme', 'dark');

        $this->assertEquals('dark', $result->getValue());
    }

    public function testSetCreatesNewSetting(): void {
        $this->mapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(''));
        $this->mapper->method('insert')->willReturnArgument(0);

        $result = $this->service->set('user1', 'currency', 'USD');

        $this->assertEquals('user1', $result->getUserId());
        $this->assertEquals('currency', $result->getKey());
        $this->assertEquals('USD', $result->getValue());
    }

    // ===== getAll =====

    public function testGetAllReturnsKeyValuePairs(): void {
        $this->mapper->method('findAll')->willReturn([
            $this->makeSetting('theme', 'dark'),
            $this->makeSetting('currency', 'GBP'),
        ]);

        $result = $this->service->getAll('user1');

        $this->assertEquals(['theme' => 'dark', 'currency' => 'GBP'], $result);
    }

    public function testGetAllReturnsEmptyArrayWhenNoSettings(): void {
        $this->mapper->method('findAll')->willReturn([]);

        $result = $this->service->getAll('user1');

        $this->assertEmpty($result);
    }

    // ===== delete =====

    public function testDeleteCallsMapper(): void {
        $this->mapper->method('deleteByKey')->willReturn(1);

        $result = $this->service->delete('user1', 'theme');

        $this->assertEquals(1, $result);
    }

    // ===== deleteAll =====

    public function testDeleteAllCallsMapper(): void {
        $this->mapper->method('deleteAll')->willReturn(5);

        $result = $this->service->deleteAll('user1');

        $this->assertEquals(5, $result);
    }

    // ===== exists =====

    public function testExistsReturnsTrueWhenFound(): void {
        $this->mapper->method('findByKey')->willReturn($this->makeSetting('theme', 'dark'));

        $this->assertTrue($this->service->exists('user1', 'theme'));
    }

    public function testExistsReturnsFalseWhenNotFound(): void {
        $this->mapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertFalse($this->service->exists('user1', 'missing'));
    }
}
