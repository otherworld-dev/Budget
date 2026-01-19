<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class SettingService {
    private SettingMapper $mapper;

    public function __construct(SettingMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * Get a setting value by key.
     * Returns null if the setting doesn't exist.
     *
     * @param string $userId
     * @param string $key
     * @return string|null
     */
    public function get(string $userId, string $key): ?string {
        try {
            $setting = $this->mapper->findByKey($userId, $key);
            return $setting->getValue();
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Set a setting value. Creates or updates the setting.
     *
     * @param string $userId
     * @param string $key
     * @param string $value
     * @return Setting
     */
    public function set(string $userId, string $key, string $value): Setting {
        try {
            $setting = $this->mapper->findByKey($userId, $key);
            $setting->setValue($value);
            $setting->setUpdatedAt(date('Y-m-d H:i:s'));
            return $this->mapper->update($setting);
        } catch (DoesNotExistException $e) {
            $setting = new Setting();
            $setting->setUserId($userId);
            $setting->setKey($key);
            $setting->setValue($value);
            $setting->setCreatedAt(date('Y-m-d H:i:s'));
            $setting->setUpdatedAt(date('Y-m-d H:i:s'));
            return $this->mapper->insert($setting);
        }
    }

    /**
     * Get all settings for a user as key-value pairs.
     *
     * @param string $userId
     * @return array<string, string>
     */
    public function getAll(string $userId): array {
        $settings = $this->mapper->findAll($userId);
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->getKey()] = $setting->getValue();
        }
        return $result;
    }

    /**
     * Delete a setting by key.
     *
     * @param string $userId
     * @param string $key
     * @return int Number of deleted rows
     */
    public function delete(string $userId, string $key): int {
        return $this->mapper->deleteByKey($userId, $key);
    }

    /**
     * Delete all settings for a user.
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        return $this->mapper->deleteAll($userId);
    }

    /**
     * Check if a setting exists.
     *
     * @param string $userId
     * @param string $key
     * @return bool
     */
    public function exists(string $userId, string $key): bool {
        try {
            $this->mapper->findByKey($userId, $key);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }
}
