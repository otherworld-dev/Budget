<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

class AmountFormatterTest extends TestCase {
    private AmountFormatter $formatter;
    private SettingService $settingService;

    protected function setUp(): void {
        $this->settingService = $this->createMock(SettingService::class);
        $this->formatter = new AmountFormatter($this->settingService);
    }

    public function testFormatKnownSymbols(): void {
        $this->assertSame('$1,234.50', $this->formatter->format(1234.5, 'USD'));
        $this->assertSame('€99.00', $this->formatter->format(99.0, 'EUR'));
        $this->assertSame('£0.99', $this->formatter->format(0.99, 'GBP'));
    }

    public function testFormatUnknownCurrencyFallsBackToCodePrefix(): void {
        $this->assertSame('SEK 10.00', $this->formatter->format(10.0, 'SEK'));
    }

    public function testFormatForUserReadsUserCurrency(): void {
        $this->settingService->method('get')
            ->with('alice', 'default_currency')
            ->willReturn('EUR');

        $this->assertSame('€5.00', $this->formatter->formatForUser('alice', 5.0));
    }

    public function testFormatForUserExplicitCurrencySkipsSettings(): void {
        $this->settingService->expects($this->never())->method('get');

        $this->assertSame('£5.00', $this->formatter->formatForUser('alice', 5.0, 'GBP'));
    }

    public function testFormatForUserFallsBackToDefaultOnError(): void {
        $this->settingService->method('get')
            ->willThrowException(new \RuntimeException('db gone'));

        $this->assertSame('£5.00', $this->formatter->formatForUser('alice', 5.0));
    }

    public function testFormatForUserFallsBackToDefaultWhenUnset(): void {
        $this->settingService->method('get')->willReturn(null);

        $this->assertSame('£5.00', $this->formatter->formatForUser('alice', 5.0));
    }
}
