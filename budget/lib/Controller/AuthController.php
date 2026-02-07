<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuthService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AuthController extends Controller {
    use ApiErrorHandlerTrait;

    private $userId;
    private AuthService $authService;
    private SettingService $settingService;

    public function __construct(
        IRequest $request,
        ?string $userId,
        AuthService $authService,
        SettingService $settingService,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->userId = $userId;
        $this->authService = $authService;
        $this->settingService = $settingService;
        $this->setLogger($logger);
    }

    /**
     * Get authentication status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function status(): DataResponse {
        try {
            $isEnabled = $this->authService->isPasswordProtectionEnabled($this->userId);
            $hasPassword = $this->authService->hasPasswordProtection($this->userId);

            $result = [
                'enabled' => $isEnabled,
                'hasPassword' => $hasPassword,
                'authenticated' => false,
            ];

            // Check if there's a valid session
            $sessionToken = $this->request->getHeader('X-Budget-Session-Token');
            if ($sessionToken && $this->authService->isValidSession($sessionToken)) {
                $tokenUserId = $this->authService->getUserIdFromSession($sessionToken);
                if ($tokenUserId === $this->userId) {
                    $result['authenticated'] = true;
                }
            }

            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to get auth status', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Set up password protection
     *
     * @NoAdminRequired
     * @UserRateLimit(limit=5, period=60)
     */
    public function setup(string $password): DataResponse {
        try {
            if (strlen($password) < 6) {
                return new DataResponse([
                    'error' => 'Password must be at least 6 characters long'
                ], Http::STATUS_BAD_REQUEST);
            }

            // Set up the password
            $this->authService->setupPassword($this->userId, $password);

            // Enable password protection in settings
            $this->settingService->set($this->userId, 'password_protection_enabled', 'true');

            // Create a session for the user
            $result = $this->authService->verifyPassword($this->userId, $password);

            return new DataResponse([
                'success' => true,
                'sessionToken' => $result['sessionToken'] ?? null
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to set up password protection', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify password and create session
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @UserRateLimit(limit=10, period=60)
     */
    public function verify(string $password): DataResponse {
        try {
            $result = $this->authService->verifyPassword($this->userId, $password);

            if ($result['success']) {
                return new DataResponse([
                    'success' => true,
                    'sessionToken' => $result['sessionToken']
                ]);
            } else {
                return new DataResponse([
                    'success' => false,
                    'error' => $result['error']
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to verify password', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lock the session (logout)
     *
     * @NoAdminRequired
     */
    public function lock(): DataResponse {
        try {
            $this->authService->lockSession($this->userId);
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to lock session', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extend session expiration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function extend(): DataResponse {
        try {
            $sessionToken = $this->request->getHeader('X-Budget-Session-Token');

            if (!$sessionToken) {
                return new DataResponse([
                    'error' => 'No session token provided'
                ], Http::STATUS_BAD_REQUEST);
            }

            $success = $this->authService->extendSession($sessionToken, $this->userId);

            if ($success) {
                return new DataResponse(['success' => true]);
            } else {
                return new DataResponse([
                    'error' => 'Invalid session'
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to extend session', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Disable password protection
     *
     * @NoAdminRequired
     * @UserRateLimit(limit=5, period=60)
     */
    public function disable(string $password): DataResponse {
        try {
            $success = $this->authService->disablePasswordProtection($this->userId, $password);

            if ($success) {
                return new DataResponse(['success' => true]);
            } else {
                return new DataResponse([
                    'error' => 'Incorrect password'
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to disable password protection', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Change password
     *
     * @NoAdminRequired
     * @UserRateLimit(limit=5, period=60)
     */
    public function changePassword(string $currentPassword, string $newPassword): DataResponse {
        try {
            if (strlen($newPassword) < 6) {
                return new DataResponse([
                    'error' => 'Password must be at least 6 characters long'
                ], Http::STATUS_BAD_REQUEST);
            }

            $success = $this->authService->changePassword($this->userId, $currentPassword, $newPassword);

            if ($success) {
                return new DataResponse(['success' => true]);
            } else {
                return new DataResponse([
                    'error' => 'Incorrect current password'
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'error' => $e->getMessage()
            ], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to change password', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unlock a shared budget by verifying owner's password
     * Creates a shared session that allows current user to access owner's budget
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @UserRateLimit(limit=10, period=60)
     */
    public function unlockShared(string $ownerUserId, string $password): DataResponse {
        try {
            $result = $this->authService->verifySharedPassword($this->userId, $ownerUserId, $password);

            if ($result['success']) {
                return new DataResponse([
                    'success' => true,
                    'sessionToken' => $result['sessionToken'],
                    'ownerUserId' => $ownerUserId
                ]);
            } else {
                return new DataResponse([
                    'success' => false,
                    'error' => $result['error']
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to unlock shared budget', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lock (end) a specific shared session
     *
     * @NoAdminRequired
     */
    public function lockShared(string $ownerUserId): DataResponse {
        try {
            $this->authService->lockSharedSession($this->userId, $ownerUserId);
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to lock shared session', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extend a shared session expiration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function extendShared(): DataResponse {
        try {
            $sessionToken = $this->request->getHeader('X-Budget-Shared-Session-Token');

            if (!$sessionToken) {
                return new DataResponse([
                    'error' => 'No shared session token provided'
                ], Http::STATUS_BAD_REQUEST);
            }

            $success = $this->authService->extendSharedSession($sessionToken, $this->userId);

            if ($success) {
                return new DataResponse(['success' => true]);
            } else {
                return new DataResponse([
                    'error' => 'Invalid shared session'
                ], Http::STATUS_UNAUTHORIZED);
            }
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to extend shared session', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all active shared sessions for the current user
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function sharedSessions(): DataResponse {
        try {
            $sessions = $this->authService->getActiveSharedSessions($this->userId);
            return new DataResponse($sessions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to get shared sessions', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
