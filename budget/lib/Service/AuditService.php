<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AuditLog;
use OCA\Budget\Db\AuditLogMapper;
use OCP\IRequest;

/**
 * Audit logging service for security-sensitive operations.
 */
class AuditService {
    // Action types
    public const ACTION_ACCOUNT_CREATED = 'account_created';
    public const ACTION_ACCOUNT_UPDATED = 'account_updated';
    public const ACTION_ACCOUNT_DELETED = 'account_deleted';
    public const ACTION_ACCOUNT_REVEALED = 'account_revealed';
    public const ACTION_IMPORT_STARTED = 'import_started';
    public const ACTION_IMPORT_COMPLETED = 'import_completed';
    public const ACTION_IMPORT_FAILED = 'import_failed';
    public const ACTION_BULK_OPERATION = 'bulk_operation';

    // Entity types
    public const ENTITY_ACCOUNT = 'account';
    public const ENTITY_TRANSACTION = 'transaction';
    public const ENTITY_IMPORT = 'import';

    private AuditLogMapper $mapper;
    private ?IRequest $request;

    public function __construct(AuditLogMapper $mapper, ?IRequest $request = null) {
        $this->mapper = $mapper;
        $this->request = $request;
    }

    /**
     * Log an audit event.
     *
     * @param string $userId The user performing the action
     * @param string $action The action type (use class constants)
     * @param string|null $entityType The type of entity being acted upon
     * @param int|null $entityId The ID of the entity
     * @param array $details Additional details to log
     */
    public function log(
        string $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): AuditLog {
        $log = new AuditLog();
        $log->setUserId($userId);
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setCreatedAt(date('Y-m-d H:i:s'));

        // Capture request context if available
        if ($this->request !== null) {
            $log->setIpAddress($this->getClientIp());
            $log->setUserAgent($this->truncateUserAgent($this->request->getHeader('User-Agent')));
        }

        // Store details as JSON
        if (!empty($details)) {
            // Remove any sensitive data from details
            $sanitizedDetails = $this->sanitizeDetails($details);
            $log->setDetails(json_encode($sanitizedDetails));
        }

        return $this->mapper->insert($log);
    }

    /**
     * Log account creation.
     */
    public function logAccountCreated(string $userId, int $accountId, string $accountName): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_ACCOUNT_CREATED,
            self::ENTITY_ACCOUNT,
            $accountId,
            ['accountName' => $accountName]
        );
    }

    /**
     * Log account update.
     */
    public function logAccountUpdated(string $userId, int $accountId, array $changedFields): AuditLog {
        // Only log field names, not values (for sensitive data protection)
        return $this->log(
            $userId,
            self::ACTION_ACCOUNT_UPDATED,
            self::ENTITY_ACCOUNT,
            $accountId,
            ['changedFields' => array_keys($changedFields)]
        );
    }

    /**
     * Log account deletion.
     */
    public function logAccountDeleted(string $userId, int $accountId, string $accountName): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_ACCOUNT_DELETED,
            self::ENTITY_ACCOUNT,
            $accountId,
            ['accountName' => $accountName]
        );
    }

    /**
     * Log sensitive data reveal (viewing full account numbers).
     */
    public function logAccountRevealed(string $userId, int $accountId, array $fieldsRevealed): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_ACCOUNT_REVEALED,
            self::ENTITY_ACCOUNT,
            $accountId,
            ['fieldsRevealed' => $fieldsRevealed]
        );
    }

    /**
     * Log import start.
     */
    public function logImportStarted(string $userId, string $filename, string $format): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_IMPORT_STARTED,
            self::ENTITY_IMPORT,
            null,
            ['filename' => $filename, 'format' => $format]
        );
    }

    /**
     * Log import completion.
     */
    public function logImportCompleted(
        string $userId,
        int $accountId,
        int $importedCount,
        int $skippedCount
    ): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_IMPORT_COMPLETED,
            self::ENTITY_ACCOUNT,
            $accountId,
            ['imported' => $importedCount, 'skipped' => $skippedCount]
        );
    }

    /**
     * Log import failure.
     */
    public function logImportFailed(string $userId, string $filename, string $error): AuditLog {
        return $this->log(
            $userId,
            self::ACTION_IMPORT_FAILED,
            self::ENTITY_IMPORT,
            null,
            ['filename' => $filename, 'error' => $error]
        );
    }

    /**
     * Get audit logs for a user.
     *
     * @param string $userId
     * @param string|null $action
     * @param int $limit
     * @param int $offset
     * @return AuditLog[]
     */
    public function getLogsForUser(
        string $userId,
        ?string $action = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        return $this->mapper->findByUser($userId, $action, null, null, $limit, $offset);
    }

    /**
     * Get audit logs for a specific account.
     */
    public function getLogsForAccount(int $accountId, int $limit = 50): array {
        return $this->mapper->findByEntity(self::ENTITY_ACCOUNT, $accountId, $limit);
    }

    /**
     * Get client IP address, handling proxies.
     */
    private function getClientIp(): ?string {
        if ($this->request === null) {
            return null;
        }

        // Check for forwarded IP (behind proxy)
        $forwarded = $this->request->getHeader('X-Forwarded-For');
        if (!empty($forwarded)) {
            // Take the first IP in the chain
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }

        return $this->request->getRemoteAddress();
    }

    /**
     * Truncate user agent to fit database column.
     */
    private function truncateUserAgent(?string $userAgent): ?string {
        if ($userAgent === null) {
            return null;
        }
        return substr($userAgent, 0, 512);
    }

    /**
     * Remove any potentially sensitive data from details.
     */
    private function sanitizeDetails(array $details): array {
        $sensitiveKeys = [
            'password', 'secret', 'token', 'key',
            'accountNumber', 'routingNumber', 'iban', 'sortCode', 'swiftBic',
            'account_number', 'routing_number', 'sort_code', 'swift_bic',
        ];

        $sanitized = [];
        foreach ($details as $key => $value) {
            // Handle indexed arrays (integer keys) - pass through values
            if (is_int($key)) {
                if (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeDetails($value);
                } elseif (is_string($value) && in_array(strtolower($value), array_map('strtolower', $sensitiveKeys))) {
                    // Redact if the value itself is a sensitive field name
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = $value;
                }
            } elseif (in_array(strtolower((string)$key), array_map('strtolower', $sensitiveKeys))) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeDetails($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
