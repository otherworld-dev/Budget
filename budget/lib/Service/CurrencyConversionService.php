<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\ManualExchangeRateMapper;

/**
 * Converts monetary amounts between currencies using cached exchange rates.
 *
 * Conversion goes through EUR as an intermediate:
 *   amount_target = amount * (target_rate / source_rate)
 * where rate = units of currency per 1 EUR.
 *
 * For user-scoped conversions (convertToBase), manual rate overrides
 * take priority over automatic rates from FloatRates/CoinGecko.
 */
class CurrencyConversionService {
	private ExchangeRateService $exchangeRateService;
	private SettingService $settingService;
	private ManualExchangeRateMapper $manualRateMapper;

	/**
	 * Bumped by every write to a user's settings or manual rates (the two
	 * mappers, and UserTableCleaner for reset and restore). The memo below
	 * holds only what was read since the last bump.
	 */
	private static int $userDataVersion = 0;

	private int $memoVersion = -1;

	/** @var array<string, string> user id => base currency */
	private array $baseCurrencies = [];

	/** @var array<string, array<string, string|null>> user id => currency => manual rate per EUR (null: none) */
	private array $manualRates = [];

	public function __construct(
		ExchangeRateService $exchangeRateService,
		SettingService $settingService,
		ManualExchangeRateMapper $manualRateMapper,
	) {
		$this->exchangeRateService = $exchangeRateService;
		$this->settingService = $settingService;
		$this->manualRateMapper = $manualRateMapper;
	}

	/**
	 * Convert an amount from one currency to another.
	 *
	 * @param string|float $amount The amount to convert
	 * @param string $fromCurrency Source currency code
	 * @param string $toCurrency Target currency code
	 * @param string|null $date Date for historical rate lookup (null = today)
	 * @return string Converted amount as string (bcmath precision)
	 */
	public function convert($amount, string $fromCurrency, string $toCurrency, ?string $date = null): string {
		$fromCurrency = strtoupper($fromCurrency);
		$toCurrency = strtoupper($toCurrency);

		// Short-circuit: same currency
		if ($fromCurrency === $toCurrency) {
			return MoneyCalculator::plain($amount);
		}

		$fromRate = $this->exchangeRateService->getRate($fromCurrency, $date);
		$toRate = $this->exchangeRateService->getRate($toCurrency, $date);

		// If either rate is unavailable, return amount unchanged (graceful degradation)
		if ($fromRate === null || $toRate === null) {
			return MoneyCalculator::plain($amount);
		}

		// amount_target = amount * (target_rate / source_rate)
		$ratio = bcdiv($toRate, $fromRate, 10);
		return bcmul(MoneyCalculator::plain($amount), $ratio, 10);
	}

	/**
	 * Convert an amount between currencies using only cached/DB rates (no network calls).
	 *
	 * Suitable for aggregation paths (dashboard, reports) where network latency
	 * is unacceptable and graceful degradation is preferred over accuracy.
	 *
	 * @param string|float $amount The amount to convert
	 * @param string $fromCurrency Source currency code
	 * @param string $toCurrency Target currency code
	 * @param string|null $date Date for rate lookup (null = today)
	 * @return string Converted amount as string (bcmath precision)
	 */
	public function convertLocal($amount, string $fromCurrency, string $toCurrency, ?string $date = null): string {
		$fromCurrency = strtoupper($fromCurrency);
		$toCurrency = strtoupper($toCurrency);

		if ($fromCurrency === $toCurrency) {
			return MoneyCalculator::plain($amount);
		}

		$fromRate = $this->exchangeRateService->getRateLocal($fromCurrency, $date);
		$toRate = $this->exchangeRateService->getRateLocal($toCurrency, $date);

		if ($fromRate === null || $toRate === null) {
			return MoneyCalculator::plain($amount);
		}

		$ratio = bcdiv($toRate, $fromRate, 10);
		return bcmul(MoneyCalculator::plain($amount), $ratio, 10);
	}

	/**
	 * Convert an amount to the user's base currency.
	 * Uses manual rate overrides when available (highest priority).
	 *
	 * @param string|float $amount The amount to convert
	 * @param string $fromCurrency Source currency code
	 * @param string $userId User ID (to look up base currency and manual rates)
	 * @param string|null $date Date for historical rate lookup
	 * @return string Converted amount
	 */
	public function convertToBase($amount, string $fromCurrency, string $userId, ?string $date = null): string {
		$baseCurrency = strtoupper($this->getBaseCurrency($userId));
		$fromCurrency = strtoupper($fromCurrency);

		if ($fromCurrency === $baseCurrency) {
			return MoneyCalculator::plain($amount);
		}

		$fromRate = $this->getEffectiveRate($fromCurrency, $userId, $date);
		$toRate = $this->getEffectiveRate($baseCurrency, $userId, $date);

		if ($fromRate === null || $toRate === null) {
			return MoneyCalculator::plain($amount);
		}

		$ratio = bcdiv($toRate, $fromRate, 10);
		return bcmul(MoneyCalculator::plain($amount), $ratio, 10);
	}

	/**
	 * Convert an amount between any two currencies at the user's rates:
	 * their own standing rate where they set one, else the automatic one.
	 * Null when either rate is unknown - unlike convert(), which hands the
	 * amount back unchanged, so a caller booking money can refuse rather
	 * than book the same number in another currency.
	 *
	 * @param string|float $amount
	 * @return string|null converted amount (bcmath precision)
	 */
	public function convertBetween($amount, string $fromCurrency, string $toCurrency, string $userId, ?string $date = null): ?string {
		$fromCurrency = strtoupper($fromCurrency);
		$toCurrency = strtoupper($toCurrency);
		if ($fromCurrency === $toCurrency) {
			return MoneyCalculator::plain($amount);
		}

		$fromRate = $this->getEffectiveRate($fromCurrency, $userId, $date);
		$toRate = $this->getEffectiveRate($toCurrency, $userId, $date);
		if ($fromRate === null || $toRate === null) {
			return null;
		}

		return bcmul(MoneyCalculator::plain($amount), bcdiv($toRate, $fromRate, 10), 10);
	}

	/**
	 * Convert an amount to the user's base currency, returning a float.
	 *
	 * @param string|float $amount The amount to convert
	 * @param string $fromCurrency Source currency code
	 * @param string $userId User ID
	 * @param string|null $date Date for historical rate lookup
	 * @return float Converted amount as float
	 */
	public function convertToBaseFloat($amount, string $fromCurrency, string $userId, ?string $date = null): float {
		return (float)$this->convertToBase($amount, $fromCurrency, $userId, $date);
	}

	/**
	 * Get the user's base/default currency.
	 */
	public function getBaseCurrency(string $userId): string {
		$this->dropStaleMemo();
		return $this->baseCurrencies[$userId]
			??= ($this->settingService->get($userId, 'default_currency') ?? 'GBP');
	}

	/**
	 * A user's settings or manual rates were written: what this service
	 * memoized is read again. Reports convert a row at a time, and reading
	 * the base currency and the manual rates for every conversion cost about
	 * 3,000 queries for an all-time summary (T6-7).
	 */
	public static function userDataChanged(): void {
		self::$userDataVersion++;
	}

	private function dropStaleMemo(): void {
		if ($this->memoVersion !== self::$userDataVersion) {
			$this->baseCurrencies = [];
			$this->manualRates = [];
			$this->memoVersion = self::$userDataVersion;
		}
	}

	/**
	 * Check if accounts use multiple currencies (i.e., conversion is needed).
	 *
	 * @param Account[] $accounts
	 * @return bool True if accounts have mixed currencies
	 */
	public function needsConversion(array $accounts): bool {
		$currencies = [];
		foreach ($accounts as $account) {
			$currency = $account->getCurrency() ?: 'USD';
			$currencies[$currency] = true;
			if (count($currencies) > 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build an accountId → currency lookup map.
	 *
	 * @param Account[] $accounts
	 * @return array<int, string>
	 */
	public function getAccountCurrencyMap(array $accounts): array {
		$map = [];
		foreach ($accounts as $account) {
			$map[$account->getId()] = $account->getCurrency() ?: 'USD';
		}
		return $map;
	}

	/**
	 * Check if a single account's currency differs from the user's base currency.
	 */
	public function accountNeedsConversion(string $accountCurrency, string $userId): bool {
		return strtoupper($accountCurrency) !== strtoupper($this->getBaseCurrency($userId));
	}

	/**
	 * Check if a currency can be converted to the user's base currency.
	 * Returns true if both rates are available.
	 */
	public function canConvert(string $fromCurrency, string $userId): bool {
		$baseCurrency = strtoupper($this->getBaseCurrency($userId));
		$fromCurrency = strtoupper($fromCurrency);

		if ($fromCurrency === $baseCurrency) {
			return true;
		}

		$fromRate = $this->getEffectiveRate($fromCurrency, $userId);
		$toRate = $this->getEffectiveRate($baseCurrency, $userId);

		return $fromRate !== null && $toRate !== null;
	}

	/**
	 * Get the effective rate for a currency, checking manual overrides first.
	 * Manual rates (per-user standing rates) take priority over automatic rates.
	 *
	 * @param string $currency Currency code (uppercase)
	 * @param string $userId User ID for manual rate lookup
	 * @param string|null $date Date for automatic rate fallback
	 * @return string|null Rate per EUR, or null if unavailable
	 */
	private function getEffectiveRate(string $currency, string $userId, ?string $date = null): ?string {
		if ($currency === 'EUR') {
			return '1.0000000000';
		}

		// Check for user's manual rate override (standing rate, ignores date)
		$manual = $this->manualRate($currency, $userId);
		if ($manual !== null) {
			return $manual;
		}

		// Fall back to automatic rate (FloatRates/CoinGecko/ECB)
		return $this->exchangeRateService->getRateLocal($currency, $date);
	}

	/**
	 * The user's standing rate for a currency, or null when they set none.
	 * Memoized per user and currency, the absence included.
	 */
	private function manualRate(string $currency, string $userId): ?string {
		$this->dropStaleMemo();
		if (!array_key_exists($currency, $this->manualRates[$userId] ?? [])) {
			$manual = $this->manualRateMapper->findByUserAndCurrency($userId, $currency);
			$rate = null;
			if ($manual !== null) {
				$rate = $manual->getRatePerEur();
				// Normalize in case of scientific notation from SQLite
				if (is_float($rate) || (is_string($rate) && stripos($rate, 'e') !== false)) {
					$rate = number_format((float)$rate, 10, '.', '');
				} else {
					$rate = (string)$rate;
				}
			}
			$this->manualRates[$userId][$currency] = $rate;
		}

		return $this->manualRates[$userId][$currency];
	}
}
