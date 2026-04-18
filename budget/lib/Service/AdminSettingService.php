<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCP\IConfig;

/**
 * Manages admin-level app settings via Nextcloud's IConfig (app values).
 * These are global settings that apply to all users, controlled by the admin.
 */
class AdminSettingService {
    public function __construct(
        private IConfig $config
    ) {
    }

    public function isBankSyncEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'bank_sync_enabled', 'false') === 'true';
    }

    public function setBankSyncEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, 'bank_sync_enabled', $enabled ? 'true' : 'false');
    }

    public function getAll(): array {
        return [
            'bankSyncEnabled' => $this->isBankSyncEnabled(),
        ];
    }
}
