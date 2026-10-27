<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

use OCA\Budget\Service\EncryptionService;
use OCA\Budget\Service\SettingService;

/**
 * Per-user storage for the Enable Banking application ID and private key.
 *
 * The credentials belong to the user, not to a bank connection, so they are
 * entered once and reused for every connection. They are stored as a single
 * encrypted JSON blob in the user's settings so the private key never sits in
 * the database in plain text.
 */
class EnableBankingKeyStore {
	public const SETTING_KEY = 'enablebanking_keys';

	public function __construct(
		private SettingService $settings,
		private EncryptionService $encryption,
	) {
	}

	/**
	 * @return array{applicationId: string, privateKey: string}|null
	 */
	public function get(string $userId): ?array {
		$stored = $this->settings->get($userId, self::SETTING_KEY);
		if ($stored === null || $stored === '') {
			return null;
		}

		$json = $this->encryption->decrypt($stored);
		$data = $json === null ? null : json_decode($json, true);
		if (!is_array($data)
			|| !is_string($data['applicationId'] ?? null)
			|| !is_string($data['privateKey'] ?? null)
		) {
			return null;
		}

		return ['applicationId' => $data['applicationId'], 'privateKey' => $data['privateKey']];
	}

	/**
	 * @throws \InvalidArgumentException when the application ID is empty or the PEM is not a private key
	 */
	public function save(string $userId, string $applicationId, string $privateKeyPem): void {
		$applicationId = trim($applicationId);
		if ($applicationId === '') {
			throw new \InvalidArgumentException('Application ID is required');
		}
		if (!EnableBankingClient::isValidPrivateKey($privateKeyPem)) {
			throw new \InvalidArgumentException('Invalid private key');
		}

		$json = json_encode(['applicationId' => $applicationId, 'privateKey' => $privateKeyPem], JSON_THROW_ON_ERROR);
		$this->settings->set($userId, self::SETTING_KEY, (string)$this->encryption->encrypt($json));
	}

	public function delete(string $userId): void {
		$this->settings->delete($userId, self::SETTING_KEY);
	}

	/**
	 * Safe-to-expose summary for the settings UI: never contains the key.
	 *
	 * @return array{hasKeys: bool, applicationId: string|null}
	 */
	public function describe(string $userId): array {
		$keys = $this->get($userId);
		return [
			'hasKeys' => $keys !== null,
			'applicationId' => $keys['applicationId'] ?? null,
		];
	}
}
