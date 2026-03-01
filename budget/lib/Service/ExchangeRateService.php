<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ExchangeRate;
use OCA\Budget\Db\ExchangeRateMapper;
use OCA\Budget\Enum\Currency;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches exchange rates from ECB (fiat) and CoinGecko (crypto).
 *
 * All rates are stored as "units of currency per 1 EUR" (ECB native format).
 * EUR itself has an implicit rate of 1.0.
 */
class ExchangeRateService {
    private const ECB_DAILY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    private const ECB_HIST_90D_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    private const COINGECKO_PRICE_URL = 'https://api.coingecko.com/api/v3/simple/price';
    private const COINGECKO_HISTORY_URL = 'https://api.coingecko.com/api/v3/coins';

    /**
     * Map Currency enum codes to CoinGecko API IDs.
     */
    private const CRYPTO_IDS = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'XRP' => 'ripple',
        'SOL' => 'solana',
        'ADA' => 'cardano',
        'DOGE' => 'dogecoin',
        'DOT' => 'polkadot',
        'LTC' => 'litecoin',
        'LINK' => 'chainlink',
        'AVAX' => 'avalanche-2',
        'UNI' => 'uniswap',
        'ATOM' => 'cosmos',
        'XLM' => 'stellar',
        'ALGO' => 'algorand',
        'NEAR' => 'near',
        'FIL' => 'filecoin',
        'APT' => 'aptos',
        'ARB' => 'arbitrum',
        'OP' => 'optimism',
        'USDT' => 'tether',
        'USDC' => 'usd-coin',
        'DAI' => 'dai',
        'BNB' => 'binancecoin',
        'MATIC' => 'matic-network',
        'SHIB' => 'shiba-inu',
    ];

    private ExchangeRateMapper $mapper;
    private IClientService $clientService;
    private LoggerInterface $logger;

    /** @var array<string, string> In-memory cache for the current request: currency => rate_per_eur */
    private array $rateCache = [];
    private ?string $rateCacheDate = null;

    public function __construct(
        ExchangeRateMapper $mapper,
        IClientService $clientService,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    /**
     * Fetch today's rates from both ECB and CoinGecko and store in DB.
     */
    public function fetchLatestRates(): void {
        $this->fetchEcbRates();
        $this->fetchCoinGeckoRates();
    }

    /**
     * Get the rate for a currency on a specific date.
     * Falls back to closest available rate if exact date not found.
     * Returns null only if no rate exists at all.
     */
    public function getRate(string $currency, ?string $date = null): ?string {
        $currency = strtoupper($currency);

        // EUR is always 1.0
        if ($currency === 'EUR') {
            return '1.0000000000';
        }

        $date = $date ?? date('Y-m-d');

        // Check in-memory cache
        if ($this->rateCacheDate === $date && isset($this->rateCache[$currency])) {
            return $this->rateCache[$currency];
        }

        // Check DB for exact date
        $rate = $this->mapper->findByDate($currency, $date);
        if ($rate !== null) {
            $rateStr = $this->normalizeRate($rate->getRatePerEur());
            $this->cacheRate($currency, $date, $rateStr);
            return $rateStr;
        }

        // Try to fetch if it's a recent date
        $this->fetchHistoricalRate($currency, $date);
        $rate = $this->mapper->findByDate($currency, $date);
        if ($rate !== null) {
            $rateStr = $this->normalizeRate($rate->getRatePerEur());
            $this->cacheRate($currency, $date, $rateStr);
            return $rateStr;
        }

        // Fall back to closest available rate
        $closest = $this->mapper->findClosest($currency, $date);
        if ($closest !== null) {
            $this->logger->debug(
                "Using closest rate for {$currency} on {$date}: found rate from {$closest->getDate()}",
                ['app' => 'budget']
            );
            return $this->normalizeRate($closest->getRatePerEur());
        }

        // Fall back to latest rate
        $latest = $this->mapper->findLatest($currency);
        if ($latest !== null) {
            $this->logger->warning(
                "No rate found for {$currency} near {$date}, using latest from {$latest->getDate()}",
                ['app' => 'budget']
            );
            return $this->normalizeRate($latest->getRatePerEur());
        }

        $this->logger->warning(
            "No exchange rate available for {$currency}",
            ['app' => 'budget']
        );
        return null;
    }

    /**
     * Get the rate for a currency using only DB/cache (no network calls).
     * Suitable for use during page loads where network latency is unacceptable.
     * Falls back to closest/latest rate in DB, returns null if no rate exists at all.
     */
    public function getRateLocal(string $currency, ?string $date = null): ?string {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return '1.0000000000';
        }

        $date = $date ?? date('Y-m-d');

        // Check in-memory cache
        if ($this->rateCacheDate === $date && isset($this->rateCache[$currency])) {
            return $this->rateCache[$currency];
        }

        // Check DB for exact date
        $rate = $this->mapper->findByDate($currency, $date);
        if ($rate !== null) {
            $rateStr = $this->normalizeRate($rate->getRatePerEur());
            $this->cacheRate($currency, $date, $rateStr);
            return $rateStr;
        }

        // Fall back to closest available rate (no network fetch)
        $closest = $this->mapper->findClosest($currency, $date);
        if ($closest !== null) {
            return $this->normalizeRate($closest->getRatePerEur());
        }

        // Fall back to latest rate
        $latest = $this->mapper->findLatest($currency);
        if ($latest !== null) {
            return $this->normalizeRate($latest->getRatePerEur());
        }

        return null;
    }

    /**
     * Bulk-load rates for multiple currencies on a specific date.
     * More efficient than individual getRate() calls.
     *
     * @param string[] $currencies
     * @return array<string, string> currency => rate_per_eur
     */
    public function getRates(array $currencies, ?string $date = null): array {
        $date = $date ?? date('Y-m-d');
        $rates = ['EUR' => '1.0000000000'];

        foreach ($currencies as $currency) {
            $currency = strtoupper($currency);
            if ($currency === 'EUR') {
                continue;
            }
            $rate = $this->getRate($currency, $date);
            if ($rate !== null) {
                $rates[$currency] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Get the most recent rate for a currency (regardless of date).
     */
    public function getLatestRate(string $currency): ?string {
        $currency = strtoupper($currency);
        if ($currency === 'EUR') {
            return '1.0000000000';
        }

        $rate = $this->mapper->findLatest($currency);
        return $rate !== null ? $this->normalizeRate($rate->getRatePerEur()) : null;
    }

    /**
     * Fetch daily rates from the European Central Bank.
     */
    public function fetchEcbRates(?string $url = null): void {
        $url = $url ?? self::ECB_DAILY_URL;

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url);
            $xml = $response->getBody();

            $this->parseAndStoreEcbXml($xml);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to fetch ECB exchange rates: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }

    /**
     * Fetch crypto rates from CoinGecko.
     */
    public function fetchCoinGeckoRates(): void {
        $ids = implode(',', array_values(self::CRYPTO_IDS));

        try {
            $client = $this->clientService->newClient();
            $response = $client->get(self::COINGECKO_PRICE_URL, [
                'query' => [
                    'ids' => $ids,
                    'vs_currencies' => 'eur',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!is_array($data)) {
                $this->logger->error('Invalid CoinGecko response', ['app' => 'budget']);
                return;
            }

            $today = date('Y-m-d');
            $idToCurrency = array_flip(self::CRYPTO_IDS);

            foreach ($data as $coinId => $prices) {
                if (!isset($prices['eur']) || !isset($idToCurrency[$coinId])) {
                    continue;
                }

                $eurPrice = (float) $prices['eur'];
                if ($eurPrice <= 0) {
                    continue;
                }

                // CoinGecko gives "1 coin = X EUR"
                // We store "units of currency per 1 EUR" = 1 / eurPrice
                // Use number_format to avoid scientific notation (e.g. 4.85E-6) which bcdiv rejects
                $ratePerEur = bcdiv('1', number_format($eurPrice, 10, '.', ''), 10);
                $currencyCode = $idToCurrency[$coinId];

                $this->mapper->upsert($currencyCode, $ratePerEur, $today, ExchangeRate::SOURCE_COINGECKO);
            }

            $this->logger->info(
                'CoinGecko rates updated for ' . count($data) . ' cryptocurrencies',
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to fetch CoinGecko rates: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }

    /**
     * Fetch a historical rate for a specific currency and date.
     * Uses ECB 90-day history for fiat, CoinGecko history for crypto.
     */
    public function fetchHistoricalRate(string $currency, string $date): void {
        // Don't try to fetch rates for future dates
        if (strtotime($date) > strtotime('today')) {
            return;
        }

        $currencyEnum = Currency::tryFrom($currency);
        if ($currencyEnum === null) {
            return;
        }

        if ($currencyEnum->isCrypto()) {
            $this->fetchCoinGeckoHistoricalRate($currency, $date);
        } else {
            $this->fetchEcbHistoricalRates($date);
        }
    }

    /**
     * Get the CoinGecko ID for a currency code.
     */
    public static function getCoinGeckoId(string $currency): ?string {
        return self::CRYPTO_IDS[strtoupper($currency)] ?? null;
    }

    /**
     * Parse ECB XML and store rates.
     */
    private function parseAndStoreEcbXml(string $xml): void {
        try {
            $doc = new \SimpleXMLElement($xml);
            $doc->registerXPathNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/euref');

            $cubes = $doc->xpath('//ecb:Cube[@time]');
            if (empty($cubes)) {
                // Try without namespace (some ECB feeds vary)
                $cubes = $doc->Cube->Cube ?? [];
            }

            $count = 0;
            foreach ($cubes as $dayCube) {
                $attrs = $dayCube->attributes();
                $date = (string) ($attrs['time'] ?? '');
                if (empty($date)) {
                    continue;
                }

                foreach ($dayCube->Cube as $rateCube) {
                    $rateAttrs = $rateCube->attributes();
                    $currency = (string) ($rateAttrs['currency'] ?? '');
                    $rate = (string) ($rateAttrs['rate'] ?? '');

                    if (!empty($currency) && !empty($rate)) {
                        $this->mapper->upsert($currency, $rate, $date, ExchangeRate::SOURCE_ECB);
                        $count++;
                    }
                }
            }

            $this->logger->info(
                "ECB rates stored: {$count} rate entries",
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to parse ECB XML: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }

    /**
     * Fetch ECB 90-day historical rates (covers most lookback needs).
     */
    private function fetchEcbHistoricalRates(string $date): void {
        // Only fetch if the date is within ~90 days
        $daysDiff = (strtotime('today') - strtotime($date)) / (24 * 60 * 60);
        if ($daysDiff > 95) {
            // For older dates, we'd need the full history file.
            // For now, the closest available rate fallback handles this.
            $this->logger->debug(
                "ECB historical rate for {$date} is too old for 90-day feed, using fallback",
                ['app' => 'budget']
            );
            return;
        }

        $this->fetchEcbRates(self::ECB_HIST_90D_URL);
    }

    /**
     * Fetch a historical crypto rate from CoinGecko.
     */
    private function fetchCoinGeckoHistoricalRate(string $currency, string $date): void {
        $coinId = self::getCoinGeckoId($currency);
        if ($coinId === null) {
            return;
        }

        // CoinGecko history endpoint uses dd-mm-yyyy format
        $formattedDate = date('d-m-Y', strtotime($date));
        $url = self::COINGECKO_HISTORY_URL . "/{$coinId}/history";

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url, [
                'query' => [
                    'date' => $formattedDate,
                    'localization' => 'false',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $eurPrice = $data['market_data']['current_price']['eur'] ?? null;

            if ($eurPrice !== null && (float) $eurPrice > 0) {
                $ratePerEur = bcdiv('1', number_format((float) $eurPrice, 10, '.', ''), 10);
                $this->mapper->upsert($currency, $ratePerEur, $date, ExchangeRate::SOURCE_COINGECKO);

                $this->logger->debug(
                    "CoinGecko historical rate stored for {$currency} on {$date}",
                    ['app' => 'budget']
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                "Failed to fetch CoinGecko historical rate for {$currency} on {$date}: " . $e->getMessage(),
                ['app' => 'budget']
            );
        }
    }

    /**
     * Cache a rate in memory for the current request.
     */
    private function cacheRate(string $currency, string $date, string $rate): void {
        // Only cache for one date at a time
        if ($this->rateCacheDate !== $date) {
            $this->rateCache = [];
            $this->rateCacheDate = $date;
        }
        $this->rateCache[$currency] = $rate;
    }

    /**
     * Normalize a rate value from the database into a plain decimal string.
     *
     * SQLite returns DECIMAL columns as float, which PHP may render in
     * scientific notation (e.g. 1.78E-5). bcmath rejects scientific notation,
     * so we convert to a fixed-point decimal string.
     *
     * @param mixed $rate The rate value (float or string from Entity getter)
     * @return string Plain decimal string safe for bcmath
     */
    private function normalizeRate($rate): string {
        if (is_float($rate) || (is_string($rate) && stripos($rate, 'e') !== false)) {
            return number_format((float) $rate, 10, '.', '');
        }
        return (string) $rate;
    }
}
