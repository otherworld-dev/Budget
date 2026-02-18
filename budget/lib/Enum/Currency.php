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
        };
    }

    /**
     * Get the number of decimal places for this currency.
     */
    public function decimals(): int {
        return match ($this) {
            self::JPY, self::KRW, self::VND, self::CLP, self::ISK, self::HUF, self::IDR => 0,
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
