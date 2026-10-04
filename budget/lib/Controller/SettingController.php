<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception as DbException;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SettingController extends Controller {
	use ApiErrorHandlerTrait;
	use SharedAccessTrait;

	private $userId;
	private $mapper;
	private IL10N $l;

	// Default settings
	private const DEFAULTS = [
		'default_currency' => 'GBP',
		'date_format' => 'Y-m-d',
		'first_day_of_week' => '0', // Sunday
		'number_format_decimals' => '2',
		'number_format_decimal_sep' => '.',
		'number_format_thousands_sep' => ',',
		'notification_budget_alert' => 'true',
		'notification_forecast_warning' => 'true',
		'digest_enabled' => 'false',
		'digest_frequency' => 'weekly',
		'digest_email_enabled' => 'false',
		'anomaly_alerts_enabled' => 'true',
		'anomaly_threshold_percent' => '30',
		'anomaly_min_amount' => '50',
		'report_files_enabled' => 'false',
		'report_email_enabled' => 'false',
		'import_auto_apply_rules' => 'true',
		'import_skip_duplicates' => 'true',
		'receipt_folder' => AttachmentService::DEFAULT_RECEIPTS_FOLDER,
		'export_default_format' => 'csv',
		'budget_period' => 'monthly',
		'budget_start_day' => '1',
		'budget_alert_threshold' => '80',
		'budget_alert_scope' => 'all', // or 'manual': only budgets the user set (#389)
		'budget_alert_muted_categories' => '[]',
		'pension_target' => '500000',
		'pension_inflation_rate' => '0.025',
		'pension_projection_mode' => 'nominal',
		'dashboard_hero_config' => '{"order":["netWorth","income","expenses","savings","pension"],"visibility":{"netWorth":true,"income":true,"expenses":true,"savings":true,"pension":true}}',
		'dashboard_widgets_config' => '{"order":["trendChart","spendingChart","netWorthHistory","recentTransactions","accounts","budgetAlerts","upcomingBills","budgetProgress","savingsGoals","debtPayoff"],"visibility":{"trendChart":true,"spendingChart":true,"netWorthHistory":true,"recentTransactions":true,"accounts":true,"budgetAlerts":true,"upcomingBills":true,"budgetProgress":true,"savingsGoals":true,"debtPayoff":true}}',
		'dashboard_locked' => 'true', // Dashboard starts locked
	];

	/**
	 * Keys the web app saves beyond those with a default. Together with
	 * DEFAULTS they are the only keys a client may write: the rest belong to
	 * the app (the calendar feed token, the sample data flag, onboarding
	 * state, what has been notified), and any key at all was accepted, so
	 * a chosen feed token hijacked the public feed URL, or broke the feed of
	 * whoever already had it.
	 */
	private const CLIENT_KEYS = [
		'dashboard_grid_columns',
		'transaction_columns_visible',
		'whats_new_seen',
	];

	private static function isClientKey(string $key): bool {
		return array_key_exists($key, self::DEFAULTS) || in_array($key, self::CLIENT_KEYS, true);
	}

	private function refusedKey(string $key): DataResponse {
		return new DataResponse(
			['error' => $this->l->t('This setting can\'t be changed: %1$s', [$key])],
			Http::STATUS_BAD_REQUEST
		);
	}

	public function __construct(
		IRequest $request,
		SettingMapper $mapper,
		GranularShareService $granularShareService,
		IL10N $l,
		?string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId;
		$this->mapper = $mapper;
		$this->l = $l;
		$this->setLogger($logger);
		$this->setGranularShareService($granularShareService);
	}

	/**
	 * Get all settings for the current user
	 *
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			$settings = $this->mapper->findAll($this->getEffectiveUserId());

			// Convert to key-value array
			$settingsArray = [];
			foreach ($settings as $setting) {
				$settingsArray[$setting->getKey()] = $setting->getValue();
			}

			// Merge with defaults for any missing keys
			$settingsArray = array_merge(self::DEFAULTS, $settingsArray);

			return new DataResponse($settingsArray);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve settings'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get a specific setting by key
	 *
	 * @NoAdminRequired
	 */
	public function show(string $key): DataResponse {
		try {
			$setting = $this->mapper->findByKey($this->getEffectiveUserId(), $key);
			return new DataResponse([
				'key' => $setting->getKey(),
				'value' => $setting->getValue()
			]);
		} catch (DoesNotExistException $e) {
			// Return default if exists
			if (array_key_exists($key, self::DEFAULTS)) {
				return new DataResponse([
					'key' => $key,
					'value' => self::DEFAULTS[$key]
				]);
			}
			return new DataResponse(
				['error' => $this->l->t('Setting not found')],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve setting'), Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
		}
	}

	/**
	 * Update multiple settings at once
	 *
	 * @NoAdminRequired
	 */
	public function update(): DataResponse {
		try {
			$data = $this->request->getParams();
			$now = date('Y-m-d H:i:s');
			$updated = [];

			// Skip internal parameters
			$data = array_diff_key($data, array_flip(['_route', 'controller', 'action']));
			// All or nothing, so a refusal saves none of the rest
			foreach (array_keys($data) as $key) {
				if (!self::isClientKey((string)$key)) {
					return $this->refusedKey((string)$key);
				}
			}

			foreach ($data as $key => $value) {

				// Receipts folder: validated and normalised (#352); blank = default
				if ($key === AttachmentService::RECEIPT_FOLDER_KEY) {
					try {
						$value = trim((string)$value) === '' ? '' : AttachmentService::normalizeReceiptsFolder((string)$value);
					} catch (\InvalidArgumentException $e) {
						return new DataResponse(['error' => $this->l->t('Invalid receipts folder: %1$s', [$e->getMessage()])], Http::STATUS_BAD_REQUEST);
					}
				}

				$this->storeSetting($this->getEffectiveUserId(), (string)$key, (string)$value, $now);

				$updated[$key] = $value;
			}

			return new DataResponse([
				'message' => $this->l->t('Settings updated successfully'),
				'settings' => $updated
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update settings'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update a specific setting
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function updateKey(string $key): DataResponse {
		try {
			$value = $this->request->getParam('value');

			if ($value === null) {
				return new DataResponse(
					['error' => $this->l->t('Value parameter is required')],
					Http::STATUS_BAD_REQUEST
				);
			}
			if (!self::isClientKey($key)) {
				return $this->refusedKey($key);
			}

			if ($key === AttachmentService::RECEIPT_FOLDER_KEY) {
				try {
					$value = trim((string)$value) === '' ? '' : AttachmentService::normalizeReceiptsFolder((string)$value);
				} catch (\InvalidArgumentException $e) {
					return new DataResponse(['error' => $this->l->t('Invalid receipts folder: %1$s', [$e->getMessage()])], Http::STATUS_BAD_REQUEST);
				}
			}

			$now = date('Y-m-d H:i:s');

			$this->storeSetting($this->getEffectiveUserId(), $key, (string)$value, $now);

			return new DataResponse([
				'message' => $this->l->t('Setting updated successfully'),
				'key' => $key,
				'value' => $value
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update setting'), Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
		}
	}

	/**
	 * Create or update one setting. Two saves of a setting that has never
	 * been stored can overlap (the settings page saves on every change), and
	 * then both find nothing and both insert: the second hits the unique
	 * (user_id, key) index. That row is the one this save should have
	 * updated, so update it rather than failing with a 500.
	 */
	private function storeSetting(string $userId, string $key, string $value, string $now): void {
		try {
			$setting = $this->mapper->findByKey($userId, $key);
		} catch (DoesNotExistException $e) {
			$setting = new Setting();
			$setting->setUserId($userId);
			$setting->setKey($key);
			$setting->setValue($value);
			$setting->setCreatedAt($now);
			$setting->setUpdatedAt($now);
			try {
				$this->mapper->insert($setting);
				return;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$setting = $this->mapper->findByKey($userId, $key);
			}
		}
		$setting->setValue($value);
		$setting->setUpdatedAt($now);
		$this->mapper->update($setting);
	}

	/**
	 * Delete a specific setting (reset to default)
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(string $key): DataResponse {
		try {
			$deleted = $this->mapper->deleteByKey($this->getEffectiveUserId(), $key);

			if ($deleted === 0) {
				return new DataResponse(
					['error' => $this->l->t('Setting not found')],
					Http::STATUS_NOT_FOUND
				);
			}

			return new DataResponse([
				'message' => $this->l->t('Setting reset to default'),
				'key' => $key,
				'default_value' => self::DEFAULTS[$key] ?? null
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to reset setting'), Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
		}
	}

	/**
	 * Reset all settings to defaults
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 5, period: 60)]
	public function reset(): DataResponse {
		try {
			$deleted = $this->mapper->deleteAll($this->getEffectiveUserId());

			return new DataResponse([
				'message' => $this->l->t('All settings reset to defaults'),
				'deleted_count' => $deleted,
				'defaults' => self::DEFAULTS
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to reset settings'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get available options for settings
	 *
	 * @NoAdminRequired
	 */
	public function options(): DataResponse {
		// Generate currencies array from Currency enum
		$currencies = array_map(function (Currency $currency) {
			return [
				'code' => $currency->value,
				'name' => $currency->name(),
				'symbol' => $currency->symbol(),
			];
		}, Currency::cases());

		return new DataResponse([
			'currencies' => $currencies,
			'date_formats' => [
				['value' => 'Y-m-d', 'label' => 'YYYY-MM-DD (2025-10-12)'],
				['value' => 'm/d/Y', 'label' => 'MM/DD/YYYY (10/12/2025)'],
				['value' => 'd/m/Y', 'label' => 'DD/MM/YYYY (12/10/2025)'],
				['value' => 'd.m.Y', 'label' => 'DD.MM.YYYY (12.10.2025)'],
				['value' => 'M j, Y', 'label' => 'Mon D, YYYY (Oct 12, 2025)'],
			],
			'first_day_of_week' => [
				['value' => '0', 'label' => $this->l->t('Sunday')],
				['value' => '1', 'label' => $this->l->t('Monday')],
			],
			'budget_periods' => [
				['value' => 'weekly', 'label' => $this->l->t('Weekly')],
				['value' => 'monthly', 'label' => $this->l->t('Monthly')],
				['value' => 'quarterly', 'label' => $this->l->t('Quarterly')],
				['value' => 'yearly', 'label' => $this->l->t('Yearly')],
			],
			'export_formats' => [
				['value' => 'csv', 'label' => 'CSV'],
				['value' => 'json', 'label' => 'JSON'],
				['value' => 'pdf', 'label' => 'PDF'],
			],
		]);
	}
}
