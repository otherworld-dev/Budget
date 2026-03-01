<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Service\ExchangeRateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to fetch daily exchange rates from ECB and CoinGecko.
 * Runs once per day.
 */
class ExchangeRateUpdateJob extends TimedJob {
    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run once per day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $exchangeRateService = Server::get(ExchangeRateService::class);
        $logger = Server::get(LoggerInterface::class);

        try {
            $exchangeRateService->fetchLatestRates();
            $logger->info(
                'Exchange rate update job completed successfully',
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $logger->error(
                'Exchange rate update job failed: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }
}
