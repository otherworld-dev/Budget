<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob\Queued;

use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * One user's share of the daily digest run, queued by DigestJob: the digest
 * (when the user opted in) and the alert checks.
 *
 * Digest scheduling uses PERIOD KEYS, not timestamps: the current ISO week
 * (weekly) or month (monthly) is compared with the stored
 * `digest_last_period`; when it differs, the digest for the period that just
 * ended is sent and the key updated. A late run self-heals and a period is
 * never sent twice.
 *
 * The alert checks — unusual spending, budget thresholds and negative cash
 * flow forecasts — each DEFAULT ON, so the opt-out is an explicit 'false'
 * rather than a missing row, and each is guarded separately: one check
 * failing must not cost the user the others. Their suppression (per
 * category per month, per category per budget period, per month) lives in
 * the services themselves.
 *
 * Not registered in info.xml: a queued job runs once per argument it is
 * added with, and DigestJob adds it.
 */
class UserDigestJob extends QueuedJob {

	public function __construct(ITimeFactory $time) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$userId = is_array($argument) ? (string)($argument['userId'] ?? '') : '';
		if ($userId === '') {
			return;
		}

		$settingService = Server::get(SettingService::class);
		$logger = Server::get(LoggerInterface::class);

		if ($settingService->get($userId, 'digest_enabled') === 'true') {
			try {
				$frequency = $settingService->get($userId, 'digest_frequency') === 'monthly' ? 'monthly' : 'weekly';
				$currentPeriod = $frequency === 'monthly' ? date('Y-m') : date('o-\WW');

				if ($settingService->get($userId, 'digest_last_period') !== $currentPeriod) {
					Server::get(DigestService::class)->sendDigest($userId, $frequency);
					$settingService->set($userId, 'digest_last_period', $currentPeriod);
				}
			} catch (\Exception $e) {
				$logger->warning("Digest failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
			}
		}

		try {
			if ($settingService->get($userId, 'anomaly_alerts_enabled') !== 'false') {
				Server::get(AnomalyDetectionService::class)->detectAndNotify($userId);
			}
		} catch (\Exception $e) {
			$logger->warning("Anomaly detection failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
		}

		try {
			if ($settingService->get($userId, 'notification_budget_alert') !== 'false') {
				Server::get(BudgetAlertService::class)->notifyAlerts($userId);
			}
		} catch (\Exception $e) {
			$logger->warning("Budget alerts failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
		}

		try {
			if ($settingService->get($userId, 'notification_forecast_warning') !== 'false') {
				Server::get(ForecastWarningService::class)->checkAndNotify($userId);
			}
		} catch (\Exception $e) {
			$logger->warning("Forecast warning failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
		}
	}
}
