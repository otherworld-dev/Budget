<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Service\ShareService;

/**
 * Trait for controllers that support joint budget sharing.
 *
 * If the current user has accepted a share from another user, all data
 * operations transparently use the owner's userId. This means both users
 * see and edit the same budget — true joint ownership.
 *
 * Controllers using this trait must:
 * 1. Have a `$userId` property (the authenticated Nextcloud user)
 * 2. Have a `ShareService` injected and call `setShareService()` in constructor
 * 3. Use `$this->getEffectiveUserId()` instead of `$this->userId` for data access
 */
trait SharedAccessTrait {
    private ?ShareService $shareService = null;
    private ?string $resolvedEffectiveUserId = null;

    protected function setShareService(ShareService $shareService): void {
        $this->shareService = $shareService;
    }

    /**
     * Get the effective user ID for all data operations.
     *
     * If this user has accepted a share, returns the owner's userId so both
     * users operate on the same data. Result is cached per request.
     */
    protected function getEffectiveUserId(): string {
        if ($this->resolvedEffectiveUserId !== null) {
            return $this->resolvedEffectiveUserId;
        }

        if ($this->shareService !== null) {
            $acceptedOwners = $this->shareService->getAcceptedOwnerIds($this->userId);
            if (!empty($acceptedOwners)) {
                // Use the first accepted share's owner — a user joins one shared budget
                $this->resolvedEffectiveUserId = $acceptedOwners[0];
                return $this->resolvedEffectiveUserId;
            }
        }

        $this->resolvedEffectiveUserId = $this->userId;
        return $this->resolvedEffectiveUserId;
    }
}
