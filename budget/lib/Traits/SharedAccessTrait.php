<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;

/**
 * Trait for controllers that support granular budget sharing.
 *
 * Provides convenience methods to get visible entity IDs and check
 * write permissions. Controllers use these instead of raw userId scoping.
 *
 * Controllers using this trait must:
 * 1. Have a `$userId` property (the authenticated Nextcloud user)
 * 2. Inject GranularShareService and call `setGranularShareService()` in constructor
 */
trait SharedAccessTrait {
    protected ?GranularShareService $granularShareService = null;

    protected function setGranularShareService(GranularShareService $service): void {
        $this->granularShareService = $service;
    }

    /**
     * Returns the authenticated user's own ID.
     * Kept for backward compatibility — no longer swaps to another user.
     */
    protected function getEffectiveUserId(): string {
        return $this->userId;
    }

    /** @return int[] */
    protected function getVisibleAccountIds(): array {
        return $this->granularShareService->getVisibleAccountIds($this->userId);
    }

    /**
     * Account-id scope for reports/dashboard aggregates. When $excludeShared is
     * true, scope to the user's OWN accounts only (drop accounts shared to them);
     * otherwise own + shared. Lets a report/tile opt out of shared-account data
     * without any SQL-layer change — the choke points just receive a narrower set.
     *
     * @return int[]
     */
    protected function getEffectiveAccountIds(bool $excludeShared = false): array {
        return $excludeShared
            ? $this->granularShareService->getOwnAccountIds($this->userId)
            : $this->granularShareService->getVisibleAccountIds($this->userId);
    }

    /**
     * Resolve a report's account scope from a legacy single accountId and an
     * optional multi-select accountIds array (#299). Returns
     * [effectiveAccountId, visibleAccountIds] to pass to a report service:
     *  - multi-select: scope is the selected accounts intersected with the ones
     *    the user can actually see; the single accountId is cleared.
     *  - single accountId: unchanged.
     *  - neither: all visible accounts.
     *
     * When $excludeShared is true the "visible" baseline is the user's own
     * accounts only, so a multi-select is also intersected against own accounts.
     *
     * @param int[]|null $accountIds
     * @return array{0: ?int, 1: int[]}
     */
    protected function resolveAccountScope(?int $accountId, ?array $accountIds, bool $excludeShared = false): array {
        $visible = $this->getEffectiveAccountIds($excludeShared);
        if (!empty($accountIds)) {
            $selected = array_values(array_intersect(
                array_map('intval', $accountIds),
                $visible
            ));
            // Fall back to all visible accounts if the selection resolves to
            // nothing accessible, rather than scoping to an empty set.
            return [null, $selected !== [] ? $selected : $visible];
        }
        // A single accountId outside the effective baseline (e.g. a shared account
        // while excludeShared is on, or one no longer accessible) is dropped so the
        // report falls back to the visible set instead of an empty/erroring scope.
        if ($accountId !== null && !in_array($accountId, $visible, true)) {
            $accountId = null;
        }
        return [$accountId, $visible];
    }

    /** @return int[] */
    protected function getVisibleCategoryIds(): array {
        return $this->granularShareService->getVisibleCategoryIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleBillIds(): array {
        return $this->granularShareService->getVisibleBillIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleRecurringIncomeIds(): array {
        return $this->granularShareService->getVisibleRecurringIncomeIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleSavingsGoalIds(): array {
        return $this->granularShareService->getVisibleSavingsGoalIds($this->userId);
    }

    /**
     * Check write permission. Throws ReadOnlyShareException if denied.
     */
    protected function requireWriteAccess(string $entityType, int $entityId): void {
        $this->granularShareService->requireWriteAccess($this->userId, $entityType, $entityId);
    }

    /**
     * Check if user can access a specific entity.
     */
    protected function canAccessEntity(string $entityType, int $entityId): bool {
        return $this->granularShareService->canAccess($this->userId, $entityType, $entityId);
    }
}
