<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Service\BankSync\BankSyncProviderInterface;
use OCA\Budget\Service\BankSync\GoCardlessProvider;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\BankSync\SimpleFINProvider;
use PHPUnit\Framework\TestCase;

class ProviderFactoryTest extends TestCase {
    private ProviderFactory $factory;
    private BankSyncProviderInterface $simplefin;
    private BankSyncProviderInterface $gocardless;

    protected function setUp(): void {
        $this->simplefin = $this->createMock(SimpleFINProvider::class);
        $this->simplefin->method('getIdentifier')->willReturn('simplefin');
        $this->simplefin->method('getDisplayName')->willReturn('SimpleFIN');

        $this->gocardless = $this->createMock(GoCardlessProvider::class);
        $this->gocardless->method('getIdentifier')->willReturn('gocardless');
        $this->gocardless->method('getDisplayName')->willReturn('GoCardless');

        $this->factory = new ProviderFactory($this->simplefin, $this->gocardless);
    }

    public function testGetProviderReturnsSimplefin(): void {
        $provider = $this->factory->getProvider('simplefin');
        $this->assertSame($this->simplefin, $provider);
    }

    public function testGetProviderReturnsGocardless(): void {
        $provider = $this->factory->getProvider('gocardless');
        $this->assertSame($this->gocardless, $provider);
    }

    public function testGetProviderThrowsForUnknownIdentifier(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown bank sync provider: plaid');

        $this->factory->getProvider('plaid');
    }

    public function testGetAvailableProvidersReturnsBoth(): void {
        $providers = $this->factory->getAvailableProviders();

        $this->assertCount(2, $providers);
        $this->assertSame(['id' => 'simplefin', 'name' => 'SimpleFIN'], $providers[0]);
        $this->assertSame(['id' => 'gocardless', 'name' => 'GoCardless'], $providers[1]);
    }
}
