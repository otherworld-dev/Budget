<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use OCA\Budget\Db\Auth;
use OCA\Budget\Db\AuthMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class AuthService {
    private AuthMapper $mapper;
    private SettingService $settingService;

    private const LOCKOUT_DURATION_MINUTES = 5;
    private const MAX_FAILED_ATTEMPTS = 5;

    public function __construct(
        AuthMapper $mapper,
        SettingService $settingService
    ) {
        $this->mapper = $mapper;
        $this->settingService = $settingService;
    }

    /**
     * Check if password protection is enabled for a user
     *
     * @param string $userId
     * @return bool
     */
    public function isPasswordProtectionEnabled(string $userId): bool {
        $enabled = $this->settingService->get($userId, 'password_protection_enabled');
        return $enabled === 'true';
    }

    /**
     * Check if a user has password protection set up
     *
     * @param string $userId
     * @return bool
     */
    public function hasPasswordProtection(string $userId): bool {
        try {
            $this->mapper->findByUserId($userId);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Set up password protection for a user
     *
     * @param string $userId
     * @param string $password
     * @return Auth
     */
    public function setupPassword(string $userId, string $password): Auth {
        // Validate password length
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters long');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Update existing
            $auth = $this->mapper->findByUserId($userId);
            $auth->setPasswordHash($passwordHash);
            $auth->setFailedAttempts(0);
            $auth->setLockedUntil(null);
            $auth->setSessionToken(null);
            $auth->setSessionExpiresAt(null);
            $auth->setUpdatedAt(date('Y-m-d H:i:s'));
            return $this->mapper->update($auth);
        } catch (DoesNotExistException $e) {
            // Create new
            $auth = new Auth();
            $auth->setUserId($userId);
            $auth->setPasswordHash($passwordHash);
            $auth->setFailedAttempts(0);
            $auth->setCreatedAt(date('Y-m-d H:i:s'));
            $auth->setUpdatedAt(date('Y-m-d H:i:s'));
            return $this->mapper->insert($auth);
        }
    }

    /**
     * Verify password and create session
     *
     * @param string $userId
     * @param string $password
     * @return array{success: bool, sessionToken?: string, error?: string}
     */
    public function verifyPassword(string $userId, string $password): array {
        try {
            $auth = $this->mapper->findByUserId($userId);
        } catch (DoesNotExistException $e) {
            return ['success' => false, 'error' => 'Password protection not set up'];
        }

        // Check if account is locked
        if ($this->isLocked($auth)) {
            $lockedUntil = new DateTime($auth->getLockedUntil());
            $now = new DateTime();
            $minutesRemaining = (int) ceil(($lockedUntil->getTimestamp() - $now->getTimestamp()) / 60);
            return [
                'success' => false,
                'error' => "Account locked. Try again in {$minutesRemaining} minute(s)."
            ];
        }

        // Verify password
        if (!password_verify($password, $auth->getPasswordHash())) {
            $this->recordFailedAttempt($auth);
            $remainingAttempts = self::MAX_FAILED_ATTEMPTS - $auth->getFailedAttempts();

            if ($remainingAttempts <= 0) {
                return [
                    'success' => false,
                    'error' => 'Too many failed attempts. Account locked for ' . self::LOCKOUT_DURATION_MINUTES . ' minutes.'
                ];
            }

            return [
                'success' => false,
                'error' => "Incorrect password. {$remainingAttempts} attempt(s) remaining."
            ];
        }

        // Password correct - reset failed attempts and create session
        $sessionToken = $this->createSession($auth, $userId);

        return [
            'success' => true,
            'sessionToken' => $sessionToken
        ];
    }

    /**
     * Validate a session token
     *
     * @param string $sessionToken
     * @return bool
     */
    public function isValidSession(string $sessionToken): bool {
        try {
            $auth = $this->mapper->findBySessionToken($sessionToken);

            // Check if session has expired
            if ($auth->getSessionExpiresAt()) {
                $expiresAt = new DateTime($auth->getSessionExpiresAt());
                $now = new DateTime();

                if ($now > $expiresAt) {
                    // Session expired
                    $this->clearSession($auth);
                    return false;
                }
            }

            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Get user ID from session token
     *
     * @param string $sessionToken
     * @return string|null
     */
    public function getUserIdFromSession(string $sessionToken): ?string {
        try {
            $auth = $this->mapper->findBySessionToken($sessionToken);

            // Validate session is not expired
            if ($this->isValidSession($sessionToken)) {
                return $auth->getUserId();
            }

            return null;
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Extend session expiration time
     *
     * @param string $sessionToken
     * @param string $userId
     * @return bool
     */
    public function extendSession(string $sessionToken, string $userId): bool {
        try {
            $auth = $this->mapper->findBySessionToken($sessionToken);

            if ($auth->getUserId() !== $userId) {
                return false;
            }

            $timeoutMinutes = (int) ($this->settingService->get($userId, 'session_timeout_minutes') ?? '30');
            $expiresAt = (new DateTime())->modify("+{$timeoutMinutes} minutes");

            $auth->setSessionExpiresAt($expiresAt->format('Y-m-d H:i:s'));
            $auth->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($auth);

            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Lock session (logout)
     *
     * @param string $userId
     * @return void
     */
    public function lockSession(string $userId): void {
        try {
            $auth = $this->mapper->findByUserId($userId);
            $this->clearSession($auth);
        } catch (DoesNotExistException $e) {
            // No auth record, nothing to lock
        }
    }

    /**
     * Disable password protection for a user
     *
     * @param string $userId
     * @param string $password
     * @return bool
     */
    public function disablePasswordProtection(string $userId, string $password): bool {
        try {
            $auth = $this->mapper->findByUserId($userId);

            // Verify password before disabling
            if (!password_verify($password, $auth->getPasswordHash())) {
                return false;
            }

            // Delete auth record
            $this->mapper->deleteByUserId($userId);

            // Update setting
            $this->settingService->set($userId, 'password_protection_enabled', 'false');

            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Change password
     *
     * @param string $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return bool
     */
    public function changePassword(string $userId, string $currentPassword, string $newPassword): bool {
        try {
            $auth = $this->mapper->findByUserId($userId);

            // Verify current password
            if (!password_verify($currentPassword, $auth->getPasswordHash())) {
                return false;
            }

            // Validate new password length
            if (strlen($newPassword) < 6) {
                throw new \InvalidArgumentException('Password must be at least 6 characters long');
            }

            // Update password
            $auth->setPasswordHash(password_hash($newPassword, PASSWORD_DEFAULT));
            $auth->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($auth);

            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Check if auth record is locked due to failed attempts
     *
     * @param Auth $auth
     * @return bool
     */
    private function isLocked(Auth $auth): bool {
        if ($auth->getLockedUntil() === null) {
            return false;
        }

        $lockedUntil = new DateTime($auth->getLockedUntil());
        $now = new DateTime();

        if ($now > $lockedUntil) {
            // Lock period expired, clear it
            $auth->setLockedUntil(null);
            $auth->setFailedAttempts(0);
            $auth->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($auth);
            return false;
        }

        return true;
    }

    /**
     * Record a failed login attempt
     *
     * @param Auth $auth
     * @return void
     */
    private function recordFailedAttempt(Auth $auth): void {
        $attempts = $auth->getFailedAttempts() + 1;
        $auth->setFailedAttempts($attempts);

        // Lock account if max attempts reached
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = (new DateTime())->modify('+' . self::LOCKOUT_DURATION_MINUTES . ' minutes');
            $auth->setLockedUntil($lockedUntil->format('Y-m-d H:i:s'));
        }

        $auth->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->mapper->update($auth);
    }

    /**
     * Create a new session for the user
     *
     * @param Auth $auth
     * @param string $userId
     * @return string Session token
     */
    private function createSession(Auth $auth, string $userId): string {
        // Generate session token
        $sessionToken = bin2hex(random_bytes(32));

        // Get timeout from settings
        $timeoutMinutes = (int) ($this->settingService->get($userId, 'session_timeout_minutes') ?? '30');
        $expiresAt = (new DateTime())->modify("+{$timeoutMinutes} minutes");

        // Reset failed attempts and update session
        $auth->setFailedAttempts(0);
        $auth->setLockedUntil(null);
        $auth->setSessionToken($sessionToken);
        $auth->setSessionExpiresAt($expiresAt->format('Y-m-d H:i:s'));
        $auth->setUpdatedAt(date('Y-m-d H:i:s'));

        $this->mapper->update($auth);

        return $sessionToken;
    }

    /**
     * Clear session data
     *
     * @param Auth $auth
     * @return void
     */
    private function clearSession(Auth $auth): void {
        $auth->setSessionToken(null);
        $auth->setSessionExpiresAt(null);
        $auth->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->mapper->update($auth);
    }
}
