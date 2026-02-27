<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Currency enum for supported ISO 4217 currency codes.
 */
enum Currency: string {
    // Americas
    case USD = 'USD';  // US Dollar
    case CAD = 'CAD';  // Canadian Dollar
    case MXN = 'MXN';  // Mexican Peso
    case BRL = 'BRL';  // Brazilian Real
    case ARS = 'ARS';  // Argentine Peso
    case CLP = 'CLP';  // Chilean Peso
    case COP = 'COP';  // Colombian Peso
    case PEN = 'PEN';  // Peruvian Sol

    // Europe
    case EUR = 'EUR';  // Euro
    case GBP = 'GBP';  // British Pound
    case CHF = 'CHF';  // Swiss Franc
    case SEK = 'SEK';  // Swedish Krona
    case NOK = 'NOK';  // Norwegian Krone
    case DKK = 'DKK';  // Danish Krone
    case PLN = 'PLN';  // Polish Zloty
    case CZK = 'CZK';  // Czech Koruna
    case HUF = 'HUF';  // Hungarian Forint
    case RON = 'RON';  // Romanian Leu
    case UAH = 'UAH';  // Ukrainian Hryvnia
    case ISK = 'ISK';  // Icelandic Krona
    case RUB = 'RUB';  // Russian Ruble
    case TRY = 'TRY';  // Turkish Lira

    // Asia-Pacific
    case JPY = 'JPY';  // Japanese Yen
    case CNY = 'CNY';  // Chinese Yuan
    case KRW = 'KRW';  // South Korean Won
    case INR = 'INR';  // Indian Rupee
    case IDR = 'IDR';  // Indonesian Rupiah
    case THB = 'THB';  // Thai Baht
    case PHP = 'PHP';  // Philippine Peso
    case MYR = 'MYR';  // Malaysian Ringgit
    case VND = 'VND';  // Vietnamese Dong
    case TWD = 'TWD';  // New Taiwan Dollar
    case SGD = 'SGD';  // Singapore Dollar
    case HKD = 'HKD';  // Hong Kong Dollar
    case PKR = 'PKR';  // Pakistani Rupee
    case BDT = 'BDT';  // Bangladeshi Taka
    case AUD = 'AUD';  // Australian Dollar
    case NZD = 'NZD';  // New Zealand Dollar

    // Middle East & Africa
    case AED = 'AED';  // UAE Dirham
    case SAR = 'SAR';  // Saudi Riyal
    case ILS = 'ILS';  // Israeli New Shekel
    case EGP = 'EGP';  // Egyptian Pound
    case NGN = 'NGN';  // Nigerian Naira
    case KES = 'KES';  // Kenyan Shilling
    case ZAR = 'ZAR';  // South African Rand

    // Cryptocurrencies
    case BTC = 'BTC';    // Bitcoin
    case ETH = 'ETH';    // Ethereum
    case XRP = 'XRP';    // Ripple
    case SOL = 'SOL';    // Solana
    case ADA = 'ADA';    // Cardano
    case DOGE = 'DOGE';  // Dogecoin
    case DOT = 'DOT';    // Polkadot
    case LTC = 'LTC';    // Litecoin
    case LINK = 'LINK';  // Chainlink
    case AVAX = 'AVAX';  // Avalanche
    case UNI = 'UNI';    // Uniswap
    case ATOM = 'ATOM';  // Cosmos
    case XLM = 'XLM';    // Stellar
    case ALGO = 'ALGO';  // Algorand
    case NEAR = 'NEAR';  // NEAR Protocol
    case FIL = 'FIL';    // Filecoin
    case APT = 'APT';    // Aptos
    case ARB = 'ARB';    // Arbitrum
    case OP = 'OP';      // Optimism
    case USDT = 'USDT';  // Tether
    case USDC = 'USDC';  // USD Coin
    case DAI = 'DAI';    // Dai
    case BNB = 'BNB';    // BNB
    case MATIC = 'MATIC'; // Polygon
    case SHIB = 'SHIB';  // Shiba Inu

    /**
     * Get the currency symbol.
     */
    public function symbol(): string {
        return match ($this) {
            // Americas
            self::USD => '$',
            self::CAD => 'C$',
            self::MXN => 'MX$',
            self::BRL => 'R$',
            self::ARS => 'AR$',
            self::CLP => 'CL$',
            self::COP => 'CO$',
            self::PEN => 'S/',
            // Europe
            self::EUR => '€',
            self::GBP => '£',
            self::CHF => 'CHF',
            self::SEK => 'kr',
            self::NOK => 'kr',
            self::DKK => 'kr',
            self::PLN => 'zł',
            self::CZK => 'Kč',
            self::HUF => 'Ft',
            self::RON => 'lei',
            self::UAH => '₴',
            self::ISK => 'kr',
            self::RUB => '₽',
            self::TRY => '₺',
            // Asia-Pacific
            self::JPY => '¥',
            self::CNY => '¥',
            self::KRW => '₩',
            self::INR => '₹',
            self::IDR => 'Rp',
            self::THB => '฿',
            self::PHP => '₱',
            self::MYR => 'RM',
            self::VND => '₫',
            self::TWD => 'NT$',
            self::SGD => 'S$',
            self::HKD => 'HK$',
            self::PKR => 'Rs',
            self::BDT => '৳',
            self::AUD => 'A$',
            self::NZD => 'NZ$',
            // Middle East & Africa
            self::AED => 'AED',
            self::SAR => 'SAR',
            self::ILS => '₪',
            self::EGP => 'E£',
            self::NGN => '₦',
            self::KES => 'KSh',
            self::ZAR => 'R',
            // Cryptocurrencies (use ticker as symbol)
            self::BTC => 'BTC',
            self::ETH => 'ETH',
            self::XRP => 'XRP',
            self::SOL => 'SOL',
            self::ADA => 'ADA',
            self::DOGE => 'DOGE',
            self::DOT => 'DOT',
            self::LTC => 'LTC',
            self::LINK => 'LINK',
            self::AVAX => 'AVAX',
            self::UNI => 'UNI',
            self::ATOM => 'ATOM',
            self::XLM => 'XLM',
            self::ALGO => 'ALGO',
            self::NEAR => 'NEAR',
            self::FIL => 'FIL',
            self::APT => 'APT',
            self::ARB => 'ARB',
            self::OP => 'OP',
            self::USDT => 'USDT',
            self::USDC => 'USDC',
            self::DAI => 'DAI',
            self::BNB => 'BNB',
            self::MATIC => 'MATIC',
            self::SHIB => 'SHIB',
        };
    }

    /**
     * Get the number of decimal places for this currency.
     */
    public function decimals(): int {
        return match ($this) {
            self::JPY, self::KRW, self::VND, self::CLP, self::ISK, self::HUF, self::IDR => 0,
            self::XRP, self::ADA, self::ATOM, self::ALGO, self::USDT, self::USDC => 6,
            self::XLM => 7,
            self::BTC, self::ETH, self::SOL, self::DOGE, self::DOT, self::LTC,
            self::LINK, self::AVAX, self::UNI, self::NEAR, self::FIL, self::APT,
            self::ARB, self::OP, self::DAI, self::BNB, self::MATIC, self::SHIB => 8,
            default => 2,
        };
    }

    /**
     * Get human-readable name.
     */
    public function name(): string {
        return match ($this) {
            // Americas
            self::USD => 'US Dollar',
            self::CAD => 'Canadian Dollar',
            self::MXN => 'Mexican Peso',
            self::BRL => 'Brazilian Real',
            self::ARS => 'Argentine Peso',
            self::CLP => 'Chilean Peso',
            self::COP => 'Colombian Peso',
            self::PEN => 'Peruvian Sol',
            // Europe
            self::EUR => 'Euro',
            self::GBP => 'British Pound',
            self::CHF => 'Swiss Franc',
            self::SEK => 'Swedish Krona',
            self::NOK => 'Norwegian Krone',
            self::DKK => 'Danish Krone',
            self::PLN => 'Polish Zloty',
            self::CZK => 'Czech Koruna',
            self::HUF => 'Hungarian Forint',
            self::RON => 'Romanian Leu',
            self::UAH => 'Ukrainian Hryvnia',
            self::ISK => 'Icelandic Krona',
            self::RUB => 'Russian Ruble',
            self::TRY => 'Turkish Lira',
            // Asia-Pacific
            self::JPY => 'Japanese Yen',
            self::CNY => 'Chinese Yuan',
            self::KRW => 'South Korean Won',
            self::INR => 'Indian Rupee',
            self::IDR => 'Indonesian Rupiah',
            self::THB => 'Thai Baht',
            self::PHP => 'Philippine Peso',
            self::MYR => 'Malaysian Ringgit',
            self::VND => 'Vietnamese Dong',
            self::TWD => 'New Taiwan Dollar',
            self::SGD => 'Singapore Dollar',
            self::HKD => 'Hong Kong Dollar',
            self::PKR => 'Pakistani Rupee',
            self::BDT => 'Bangladeshi Taka',
            self::AUD => 'Australian Dollar',
            self::NZD => 'New Zealand Dollar',
            // Middle East & Africa
            self::AED => 'UAE Dirham',
            self::SAR => 'Saudi Riyal',
            self::ILS => 'Israeli New Shekel',
            self::EGP => 'Egyptian Pound',
            self::NGN => 'Nigerian Naira',
            self::KES => 'Kenyan Shilling',
            self::ZAR => 'South African Rand',
            // Cryptocurrencies
            self::BTC => 'Bitcoin',
            self::ETH => 'Ethereum',
            self::XRP => 'Ripple',
            self::SOL => 'Solana',
            self::ADA => 'Cardano',
            self::DOGE => 'Dogecoin',
            self::DOT => 'Polkadot',
            self::LTC => 'Litecoin',
            self::LINK => 'Chainlink',
            self::AVAX => 'Avalanche',
            self::UNI => 'Uniswap',
            self::ATOM => 'Cosmos',
            self::XLM => 'Stellar',
            self::ALGO => 'Algorand',
            self::NEAR => 'NEAR Protocol',
            self::FIL => 'Filecoin',
            self::APT => 'Aptos',
            self::ARB => 'Arbitrum',
            self::OP => 'Optimism',
            self::USDT => 'Tether',
            self::USDC => 'USD Coin',
            self::DAI => 'Dai',
            self::BNB => 'BNB',
            self::MATIC => 'Polygon',
            self::SHIB => 'Shiba Inu',
        };
    }

    /**
     * Check if this currency is a cryptocurrency.
     */
    public function isCrypto(): bool {
        return match ($this) {
            self::BTC, self::ETH, self::XRP, self::SOL, self::ADA, self::DOGE,
            self::DOT, self::LTC, self::LINK, self::AVAX, self::UNI, self::ATOM,
            self::XLM, self::ALGO, self::NEAR, self::FIL, self::APT, self::ARB,
            self::OP, self::USDT, self::USDC, self::DAI, self::BNB, self::MATIC,
            self::SHIB => true,
            default => false,
        };
    }

    /**
     * Format an amount in this currency.
     */
    public function format(float $amount): string {
        return $this->symbol() . number_format($amount, $this->decimals());
    }

    /**
     * Get all valid currency codes as strings.
     */
    public static function values(): array {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    /**
     * Check if a string is a valid currency code.
     */
    public static function isValid(string $value): bool {
        return in_array(strtoupper($value), self::values(), true);
    }

    /**
     * Try to create from string (case-insensitive).
     */
    public static function tryFromString(string $value): ?self {
        return self::tryFrom(strtoupper(trim($value)));
    }
}
