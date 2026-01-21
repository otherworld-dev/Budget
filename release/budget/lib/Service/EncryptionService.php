<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\Security\ICrypto;

/**
 * Encryption service for sensitive banking data.
 * Uses Nextcloud's ICrypto which provides AES-CBC with HMAC (Encrypt-Then-MAC).
 */
class EncryptionService {
    private const ENCRYPTED_PREFIX = 'enc:';

    private ICrypto $crypto;

    public function __construct(ICrypto $crypto) {
        $this->crypto = $crypto;
    }

    /**
     * Encrypt a value if not null/empty.
     */
    public function encrypt(?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        // Already encrypted - don't double-encrypt
        if ($this->isEncrypted($value)) {
            return $value;
        }

        return self::ENCRYPTED_PREFIX . $this->crypto->encrypt($value);
    }

    /**
     * Decrypt a value if encrypted.
     */
    public function decrypt(?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        // Not encrypted - return as-is (handles legacy plaintext data)
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $encrypted = substr($value, strlen(self::ENCRYPTED_PREFIX));
        return $this->crypto->decrypt($encrypted);
    }

    /**
     * Check if a value is encrypted (has our prefix).
     */
    public function isEncrypted(?string $value): bool {
        if ($value === null || $value === '') {
            return false;
        }

        return str_starts_with($value, self::ENCRYPTED_PREFIX);
    }

    /**
     * Encrypt an array of fields by key.
     *
     * @param array $data The data array
     * @param array $fields List of field names to encrypt
     * @return array The data with specified fields encrypted
     */
    public function encryptFields(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->encrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Decrypt an array of fields by key.
     *
     * @param array $data The data array
     * @param array $fields List of field names to decrypt
     * @return array The data with specified fields decrypted
     */
    public function decryptFields(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->decrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Get the list of sensitive banking fields that should be encrypted.
     */
    public static function getSensitiveFields(): array {
        return [
            'accountNumber',
            'routingNumber',
            'sortCode',
            'iban',
            'swiftBic',
        ];
    }

    /**
     * Get the database column names for sensitive fields.
     */
    public static function getSensitiveColumns(): array {
        return [
            'account_number',
            'routing_number',
            'sort_code',
            'iban',
            'swift_bic',
        ];
    }
}
