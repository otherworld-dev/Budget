<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Service\AuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Trait for controllers that require authentication when password protection is enabled
 */
trait RequiresAuthTrait {

    /**
     * Check if the request is authenticated when password protection is enabled
     *
     * @param string $userId
     * @param AuthService $authService
     * @return DataResponse|null Returns error response if auth fails, null if auth passes
     */
    protected function checkAuth(string $userId, AuthService $authService): ?DataResponse {
        // Check if password protection is enabled
        if (!$authService->isPasswordProtectionEnabled($userId)) {
            return null; // No password protection, allow access
        }

        // Password protection is enabled, check for valid session
        $sessionToken = $this->request->getHeader('X-Budget-Session-Token');

        if (!$sessionToken) {
            return new DataResponse([
                'error' => 'Authentication required',
                'authRequired' => true
            ], Http::STATUS_UNAUTHORIZED);
        }

        // Validate session token
        if (!$authService->isValidSession($sessionToken)) {
            return new DataResponse([
                'error' => 'Invalid or expired session',
                'authRequired' => true
            ], Http::STATUS_UNAUTHORIZED);
        }

        // Verify session belongs to this user
        $tokenUserId = $authService->getUserIdFromSession($sessionToken);
        if ($tokenUserId !== $userId) {
            return new DataResponse([
                'error' => 'Invalid session',
                'authRequired' => true
            ], Http::STATUS_UNAUTHORIZED);
        }

        // Auth passed, extend session
        $authService->extendSession($sessionToken, $userId);

        return null; // Auth successful
    }
}
