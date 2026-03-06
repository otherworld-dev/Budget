<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\EncryptionService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase {
	private EncryptionService $service;
	private ICrypto $crypto;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->service = new EncryptionService($this->crypto);
	}

	// ── encrypt ─────────────────────────────────────────────────────

	public function testEncryptValue(): void {
		$this->crypto->expects($this->once())
			->method('encrypt')
			->with('secret123')
			->willReturn('encrypted_payload');

		$result = $this->service->encrypt('secret123');
		$this->assertSame('enc:encrypted_payload', $result);
	}

	public function testEncryptNull(): void {
		$this->crypto->expects($this->never())->method('encrypt');
		$this->assertNull($this->service->encrypt(null));
	}

	public function testEncryptEmpty(): void {
		$this->crypto->expects($this->never())->method('encrypt');
		$this->assertSame('', $this->service->encrypt(''));
	}

	public function testEncryptAlreadyEncryptedSkipsDoubleEncrypt(): void {
		$this->crypto->expects($this->never())->method('encrypt');
		$result = $this->service->encrypt('enc:already_encrypted');
		$this->assertSame('enc:already_encrypted', $result);
	}

	// ── decrypt ─────────────────────────────────────────────────────

	public function testDecryptValue(): void {
		$this->crypto->expects($this->once())
			->method('decrypt')
			->with('encrypted_payload')
			->willReturn('secret123');

		$result = $this->service->decrypt('enc:encrypted_payload');
		$this->assertSame('secret123', $result);
	}

	public function testDecryptNull(): void {
		$this->crypto->expects($this->never())->method('decrypt');
		$this->assertNull($this->service->decrypt(null));
	}

	public function testDecryptEmpty(): void {
		$this->crypto->expects($this->never())->method('decrypt');
		$this->assertSame('', $this->service->decrypt(''));
	}

	public function testDecryptPlaintextReturnedAsIs(): void {
		// Legacy plaintext data without prefix
		$this->crypto->expects($this->never())->method('decrypt');
		$result = $this->service->decrypt('plaintext_value');
		$this->assertSame('plaintext_value', $result);
	}

	public function testDecryptFailureReturnsNull(): void {
		// The decrypt method catches exceptions and logs via \OC::$server
		// which isn't available in unit tests. Skip if OC class doesn't exist.
		if (!class_exists('\OC')) {
			$this->markTestSkipped('Requires Nextcloud runtime (OC class) for error logging');
		}

		$this->crypto->expects($this->once())
			->method('decrypt')
			->willThrowException(new \Exception('Decryption failed'));

		$result = $this->service->decrypt('enc:corrupted_data');
		$this->assertNull($result);
	}

	// ── isEncrypted ─────────────────────────────────────────────────

	public function testIsEncryptedTrue(): void {
		$this->assertTrue($this->service->isEncrypted('enc:something'));
	}

	public function testIsEncryptedFalse(): void {
		$this->assertFalse($this->service->isEncrypted('plaintext'));
	}

	public function testIsEncryptedNull(): void {
		$this->assertFalse($this->service->isEncrypted(null));
	}

	public function testIsEncryptedEmpty(): void {
		$this->assertFalse($this->service->isEncrypted(''));
	}

	public function testIsEncryptedPrefixOnly(): void {
		$this->assertTrue($this->service->isEncrypted('enc:'));
	}

	// ── encryptFields / decryptFields ───────────────────────────────

	public function testEncryptFieldsSelectiveEncryption(): void {
		$this->crypto->method('encrypt')->willReturnCallback(
			fn(string $v) => "ENC_{$v}"
		);

		$data = [
			'accountNumber' => '12345678',
			'iban' => 'GB29NWBK60161331926819',
			'name' => 'My Account',
		];

		$result = $this->service->encryptFields($data, ['accountNumber', 'iban']);

		$this->assertSame('enc:ENC_12345678', $result['accountNumber']);
		$this->assertSame('enc:ENC_GB29NWBK60161331926819', $result['iban']);
		$this->assertSame('My Account', $result['name']); // Not in field list
	}

	public function testEncryptFieldsSkipsMissingFields(): void {
		$this->crypto->expects($this->never())->method('encrypt');

		$data = ['name' => 'Test'];
		$result = $this->service->encryptFields($data, ['accountNumber']);

		$this->assertSame(['name' => 'Test'], $result);
	}

	public function testEncryptFieldsSkipsNonStringValues(): void {
		$this->crypto->expects($this->never())->method('encrypt');

		$data = ['accountNumber' => 12345]; // Integer, not string
		$result = $this->service->encryptFields($data, ['accountNumber']);

		$this->assertSame(12345, $result['accountNumber']);
	}

	public function testDecryptFieldsSelectiveDecryption(): void {
		$this->crypto->method('decrypt')->willReturnCallback(
			fn(string $v) => "DECRYPTED_{$v}"
		);

		$data = [
			'accountNumber' => 'enc:abc',
			'iban' => 'enc:def',
			'name' => 'My Account',
		];

		$result = $this->service->decryptFields($data, ['accountNumber', 'iban']);

		$this->assertSame('DECRYPTED_abc', $result['accountNumber']);
		$this->assertSame('DECRYPTED_def', $result['iban']);
		$this->assertSame('My Account', $result['name']);
	}

	public function testDecryptFieldsLegacyPlaintext(): void {
		$this->crypto->expects($this->never())->method('decrypt');

		$data = ['accountNumber' => 'plaintext_number'];
		$result = $this->service->decryptFields($data, ['accountNumber']);

		// Not prefixed with enc: → returned as-is
		$this->assertSame('plaintext_number', $result['accountNumber']);
	}

	// ── Static field lists ──────────────────────────────────────────

	public function testGetSensitiveFields(): void {
		$fields = EncryptionService::getSensitiveFields();
		$this->assertContains('accountNumber', $fields);
		$this->assertContains('iban', $fields);
		$this->assertContains('swiftBic', $fields);
		$this->assertContains('routingNumber', $fields);
		$this->assertContains('sortCode', $fields);
		$this->assertCount(5, $fields);
	}

	public function testGetSensitiveColumns(): void {
		$columns = EncryptionService::getSensitiveColumns();
		$this->assertContains('account_number', $columns);
		$this->assertContains('iban', $columns);
		$this->assertContains('swift_bic', $columns);
		$this->assertCount(5, $columns);
	}

	// ── Round-trip ──────────────────────────────────────────────────

	public function testEncryptDecryptRoundTrip(): void {
		$this->crypto->method('encrypt')->willReturn('cipher_text');
		$this->crypto->method('decrypt')->with('cipher_text')->willReturn('original');

		$encrypted = $this->service->encrypt('original');
		$this->assertSame('enc:cipher_text', $encrypted);

		$decrypted = $this->service->decrypt($encrypted);
		$this->assertSame('original', $decrypted);
	}
}
