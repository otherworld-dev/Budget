<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Enum;

use OCA\Budget\Enum\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase {
    public static function decimalsForProvider(): array {
        return [
            'BTC is 8dp' => ['BTC', 8],
            'ETH is 8dp' => ['ETH', 8],
            'XRP is 6dp' => ['XRP', 6],
            'USD is 2dp' => ['USD', 2],
            'JOD is 3dp' => ['JOD', 3],
            'JPY is 0dp' => ['JPY', 0],
            'lowercase code resolves' => ['btc', 8],
            'unknown code defaults to 2' => ['ZZZ', 2],
            'null defaults to 2' => [null, 2],
            'empty defaults to 2' => ['', 2],
        ];
    }

    #[DataProvider('decimalsForProvider')]
    public function testDecimalsFor(?string $code, int $expected): void {
        $this->assertSame($expected, Currency::decimalsFor($code));
    }

    /**
     * symbol() and name() are exhaustive matches, so a case added without its arms
     * is a fatal UnhandledMatchError at runtime rather than a missing label.
     */
    public function testEveryCaseHasASymbolAndAName(): void {
        foreach (Currency::cases() as $currency) {
            $this->assertNotSame('', $currency->symbol(), $currency->value . ' has no symbol');
            $this->assertNotSame('', $currency->name(), $currency->value . ' has no name');
        }
    }

    public function testBynIsSupported(): void {
        $byn = Currency::tryFromString('byn');

        $this->assertSame(Currency::BYN, $byn);
        $this->assertSame('Br', $byn->symbol());
        $this->assertSame('Belarusian Ruble', $byn->name());
        $this->assertSame(2, $byn->decimals());
        $this->assertFalse($byn->isCrypto());
    }
}
