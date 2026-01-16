<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SettingController extends Controller {
    use ApiErrorHandlerTrait;

    private $userId;
    private $mapper;

    // Default settings
    private const DEFAULTS = [
        'default_currency' => 'GBP',
        'date_format' => 'Y-m-d',
        'first_day_of_week' => '0', // Sunday
        'number_format_decimals' => '2',
        'number_format_decimal_sep' => '.',
        'number_format_thousands_sep' => ',',
        'theme_preference' => 'system',
        'notification_budget_alert' => 'true',
        'notification_forecast_warning' => 'true',
        'import_auto_apply_rules' => 'true',
        'import_skip_duplicates' => 'true',
        'export_default_format' => 'csv',
        'budget_period' => 'monthly',
    ];

    public function __construct(
        IRequest $request,
        SettingMapper $mapper,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->userId = $userId;
        $this->mapper = $mapper;
        $this->setLogger($logger);
    }

    /**
     * Get all settings for the current user
     *
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $settings = $this->mapper->findAll($this->userId);

            // Convert to key-value array
            $settingsArray = [];
            foreach ($settings as $setting) {
                $settingsArray[$setting->getKey()] = $setting->getValue();
            }

            // Merge with defaults for any missing keys
            $settingsArray = array_merge(self::DEFAULTS, $settingsArray);

            return new DataResponse($settingsArray);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve settings', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific setting by key
     *
     * @NoAdminRequired
     */
    public function show(string $key): DataResponse {
        try {
            $setting = $this->mapper->findByKey($this->userId, $key);
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
                ['error' => 'Setting not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve setting', Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
        }
    }

    /**
     * Update multiple settings at once
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(): DataResponse {
        try {
            $data = $this->request->getParams();
            $now = date('Y-m-d H:i:s');
            $updated = [];

            foreach ($data as $key => $value) {
                // Skip internal parameters
                if (in_array($key, ['_route', 'controller', 'action'])) {
                    continue;
                }

                try {
                    // Try to find existing setting
                    $setting = $this->mapper->findByKey($this->userId, $key);
                    $setting->setValue((string)$value);
                    $setting->setUpdatedAt($now);
                    $this->mapper->update($setting);
                } catch (DoesNotExistException $e) {
                    // Create new setting
                    $setting = new Setting();
                    $setting->setUserId($this->userId);
                    $setting->setKey($key);
                    $setting->setValue((string)$value);
                    $setting->setCreatedAt($now);
                    $setting->setUpdatedAt($now);
                    $this->mapper->insert($setting);
                }

                $updated[$key] = $value;
            }

            return new DataResponse([
                'message' => 'Settings updated successfully',
                'settings' => $updated
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update settings', Http::STATUS_INTERNAL_SERVER_ERROR);
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
                    ['error' => 'Value parameter is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $now = date('Y-m-d H:i:s');

            try {
                // Try to find existing setting
                $setting = $this->mapper->findByKey($this->userId, $key);
                $setting->setValue((string)$value);
                $setting->setUpdatedAt($now);
                $this->mapper->update($setting);
            } catch (DoesNotExistException $e) {
                // Create new setting
                $setting = new Setting();
                $setting->setUserId($this->userId);
                $setting->setKey($key);
                $setting->setValue((string)$value);
                $setting->setCreatedAt($now);
                $setting->setUpdatedAt($now);
                $this->mapper->insert($setting);
            }

            return new DataResponse([
                'message' => 'Setting updated successfully',
                'key' => $key,
                'value' => $value
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update setting', Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
        }
    }

    /**
     * Delete a specific setting (reset to default)
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(string $key): DataResponse {
        try {
            $deleted = $this->mapper->deleteByKey($this->userId, $key);

            if ($deleted === 0) {
                return new DataResponse(
                    ['error' => 'Setting not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            return new DataResponse([
                'message' => 'Setting reset to default',
                'key' => $key,
                'default_value' => self::DEFAULTS[$key] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to reset setting', Http::STATUS_INTERNAL_SERVER_ERROR, ['key' => $key]);
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
            $deleted = $this->mapper->deleteAll($this->userId);

            return new DataResponse([
                'message' => 'All settings reset to defaults',
                'deleted_count' => $deleted,
                'defaults' => self::DEFAULTS
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to reset settings', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available options for settings
     *
     * @NoAdminRequired
     */
    public function options(): DataResponse {
        return new DataResponse([
            'currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
                ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$'],
                ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$'],
                ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥'],
                ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF'],
                ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥'],
                ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹'],
                ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$'],
            ],
            'date_formats' => [
                ['value' => 'Y-m-d', 'label' => 'YYYY-MM-DD (2025-10-12)'],
                ['value' => 'm/d/Y', 'label' => 'MM/DD/YYYY (10/12/2025)'],
                ['value' => 'd/m/Y', 'label' => 'DD/MM/YYYY (12/10/2025)'],
                ['value' => 'd.m.Y', 'label' => 'DD.MM.YYYY (12.10.2025)'],
                ['value' => 'M j, Y', 'label' => 'Mon D, YYYY (Oct 12, 2025)'],
            ],
            'first_day_of_week' => [
                ['value' => '0', 'label' => 'Sunday'],
                ['value' => '1', 'label' => 'Monday'],
            ],
            'theme_preferences' => [
                ['value' => 'system', 'label' => 'Follow Nextcloud theme'],
                ['value' => 'light', 'label' => 'Light theme'],
                ['value' => 'dark', 'label' => 'Dark theme'],
            ],
            'budget_periods' => [
                ['value' => 'weekly', 'label' => 'Weekly'],
                ['value' => 'monthly', 'label' => 'Monthly'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
                ['value' => 'yearly', 'label' => 'Yearly'],
            ],
            'export_formats' => [
                ['value' => 'csv', 'label' => 'CSV'],
                ['value' => 'json', 'label' => 'JSON'],
                ['value' => 'pdf', 'label' => 'PDF'],
            ],
        ]);
    }
}
