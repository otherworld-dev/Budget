<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync\EnableBanking;

use OCA\Budget\Db\Setting;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Service\EncryptionService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EnableBankingKeyStoreTest extends TestCase {
	private EnableBankingKeyStore $store;
	private SettingService&MockObject $settings;
	private string $privateKeyPem;

	/** @var array<string, string> in-memory stand-in for the settings table */
	private array $storage = [];

	protected function setUp(): void {
		$this->settings = $this->createMock(SettingService::class);
		$this->settings->method('get')->willReturnCallback(
			fn (string $userId, string $key) => $this->storage[$userId . '|' . $key] ?? null
		);
		$this->settings->method('set')->willReturnCallback(
			function (string $userId, string $key, string $value) {
				$this->storage[$userId . '|' . $key] = $value;
				return new Setting();
			}
		);
		$this->settings->method('delete')->willReturnCallback(
			function (string $userId, string $key): int {
				unset($this->storage[$userId . '|' . $key]);
				return 1;
			}
		);

		// Reversible fake so "stored value is not plain" is a meaningful assertion.
		$encryption = $this->createMock(EncryptionService::class);
		$encryption->method('encrypt')->willReturnCallback(
			fn (?string $v) => $v === null ? null : 'enc:' . base64_encode($v)
		);
		$encryption->method('decrypt')->willReturnCallback(
			fn (?string $v) => $v === null ? null : base64_decode(substr($v, 4))
		);

		$this->store = new EnableBankingKeyStore($this->settings, $encryption);

		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		openssl_pkey_export($key, $pem);
		$this->privateKeyPem = $pem;
	}

	public function testSaveEncryptsAndGetDecrypts(): void {
		$this->store->save('alice', 'app-1', $this->privateKeyPem);

		$stored = $this->storage['alice|' . EnableBankingKeyStore::SETTING_KEY];
		$this->assertStringNotContainsString('BEGIN PRIVATE KEY', $stored);
		$this->assertStringNotContainsString('app-1', $stored);
		$this->assertNull(json_decode($stored, true));

		$this->assertSame(
			['applicationId' => 'app-1', 'privateKey' => $this->privateKeyPem],
			$this->store->get('alice')
		);
	}

	public function testSaveRejectsInvalidKey(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid private key');
		$this->store->save('alice', 'app-1', 'not a key');
	}

	public function testSaveRejectsEmptyApplicationId(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Application ID is required');
		$this->store->save('alice', '   ', $this->privateKeyPem);
	}

	public function testGetReturnsNullWhenUnset(): void {
		$this->assertNull($this->store->get('alice'));
	}

	public function testKeysAreScopedPerUser(): void {
		$this->store->save('alice', 'app-1', $this->privateKeyPem);
		$this->assertNull($this->store->get('bob'));
	}

	public function testDeleteRemovesKeys(): void {
		$this->store->save('alice', 'app-1', $this->privateKeyPem);
		$this->store->delete('alice');
		$this->assertNull($this->store->get('alice'));
		$this->assertFalse($this->store->describe('alice')['hasKeys']);
	}

	public function testDescribeNeverReturnsKey(): void {
		$this->assertSame(['hasKeys' => false, 'applicationId' => null], $this->store->describe('alice'));

		$this->store->save('alice', 'app-1', $this->privateKeyPem);
		$described = $this->store->describe('alice');

		$this->assertSame(['hasKeys' => true, 'applicationId' => 'app-1'], $described);
		$this->assertStringNotContainsString('PRIVATE KEY', json_encode($described));
	}
}
