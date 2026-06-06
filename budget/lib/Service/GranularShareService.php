<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCP\IL10N;

/**
 * Central service for granular per-entity sharing.
 *
 * Answers questions like "which accounts can this user see?" and
 * "can this user write to bill #42?" — with per-request caching.
 */
class GranularShareService {
    private ShareMapper $shareMapper;
    private ShareItemMapper $shareItemMapper;
    private AccountMapper $accountMapper;
    private BillMapper $billMapper;
    private CategoryMapper $categoryMapper;
    private RecurringIncomeMapper $recurringIncomeMapper;
    private SavingsGoalMapper $savingsGoalMapper;
    private IL10N $l;

    /** @var array<string, mixed> Per-request cache */
    private array $cache = [];

    public function __construct(
        ShareMapper $shareMapper,
        ShareItemMapper $shareItemMapper,
        AccountMapper $accountMapper,
        BillMapper $billMapper,
        CategoryMapper $categoryMapper,
        RecurringIncomeMapper $recurringIncomeMapper,
        SavingsGoalMapper $savingsGoalMapper,
        IL10N $l
    ) {
        $this->shareMapper = $shareMapper;
        $this->shareItemMapper = $shareItemMapper;
        $this->accountMapper = $accountMapper;
        $this->billMapper = $billMapper;
        $this->categoryMapper = $categoryMapper;
        $this->recurringIncomeMapper = $recurringIncomeMapper;
        $this->savingsGoalMapper = $savingsGoalMapper;
        $this->l = $l;
    }

    // ==========================================
    // Visibility — own + shared entity IDs
    // ==========================================

    /**
     * Get all account IDs visible to a user (own + shared from accepted shares).
     *
     * @return int[]
     */
    public function getVisibleAccountIds(string $userId): array {
        return $this->getVisibleIds($userId, ShareItem::TYPE_ACCOUNT);
    }

    /**
     * @return int[]
     */
    public function getVisibleCategoryIds(string $userId): array {
        return $this->getVisibleIds($userId, ShareItem::TYPE_CATEGORY);
    }

    /**
     * @return int[]
     */
    public function getVisibleBillIds(string $userId): array {
        return $this->getVisibleIds($userId, ShareItem::TYPE_BILL);
    }

    /**
     * @return int[]
     */
    public function getVisibleRecurringIncomeIds(string $userId): array {
        return $this->getVisibleIds($userId, ShareItem::TYPE_RECURRING_INCOME);
    }

    /**
     * @return int[]
     */
    public function getVisibleSavingsGoalIds(string $userId): array {
        return $this->getVisibleIds($userId, ShareItem::TYPE_SAVINGS_GOAL);
    }

    /**
     * Get only the shared entity IDs (not own) for a user.
     * Useful for cross-user budget aggregation.
     *
     * @return int[]
     */
    public function getSharedAccountIds(string $userId): array {
        return $this->getSharedIds($userId, ShareItem::TYPE_ACCOUNT);
    }

    /**
     * @return int[]
     */
    public function getSharedCategoryIds(string $userId): array {
        return $this->getSharedIds($userId, ShareItem::TYPE_CATEGORY);
    }

    /**
     * @return int[]
     */
    public function getSharedSavingsGoalIds(string $userId): array {
        return $this->getSharedIds($userId, ShareItem::TYPE_SAVINGS_GOAL);
    }

    // ==========================================
    // Permissions
    // ==========================================

    /**
     * Check if a user can access an entity (own or shared).
     */
    public function canAccess(string $userId, string $entityType, int $entityId): bool {
        $visibleIds = $this->getVisibleIds($userId, $entityType);
        return in_array($entityId, $visibleIds, true);
    }

    /**
     * Check if a user can write to an entity.
     * Own entities are always writable. Shared entities depend on permission.
     */
    public function canWrite(string $userId, string $entityType, int $entityId): bool {
        // Own entities are always writable
        $ownIds = $this->getOwnIds($userId, $entityType);
        if (in_array($entityId, $ownIds, true)) {
            return true;
        }

        // Check shared permission
        $shares = $this->getAcceptedIncomingShares($userId);
        foreach ($shares as $share) {
            $permission = $this->shareItemMapper->getEntityPermission(
                $share->getId(),
                $entityType,
                $entityId
            );
            if ($permission === ShareItem::PERMISSION_WRITE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforce write access — throws ReadOnlyShareException if denied.
     */
    public function requireWriteAccess(string $userId, string $entityType, int $entityId): void {
        if (!$this->canWrite($userId, $entityType, $entityId)) {
            throw new ReadOnlyShareException();
        }
    }

    /**
     * Resolve the owner of an entity the user can access.
     *
     * Returns the user's own ID when they own the entity, the share owner's ID
     * when it has been shared with them via an accepted share, or null when the
     * entity is not accessible to the user. Used to perform owner-scoped lookups
     * on behalf of a recipient.
     */
    public function resolveOwner(string $userId, string $entityType, int $entityId): ?string {
        if (in_array($entityId, $this->getOwnIds($userId, $entityType), true)) {
            return $userId;
        }

        foreach ($this->getAcceptedIncomingShares($userId) as $share) {
            $ids = $this->shareItemMapper->findSharedEntityIds($share->getId(), $entityType);
            if (in_array($entityId, $ids, true)) {
                return $share->getOwnerUserId();
            }
        }

        return null;
    }

    // ==========================================
    // Share configuration (settings panel)
    // ==========================================

    /**
     * Get the full share configuration for display in the settings panel.
     *
     * @return array<string, array{ids: int[], permission: string}>
     */
    public function getShareConfig(int $shareId): array {
        $config = [];
        foreach (ShareItem::VALID_TYPES as $type) {
            $items = $this->shareItemMapper->findByShareIdAndType($shareId, $type);
            if (!empty($items)) {
                $ids = array_map(fn(ShareItem $item) => $item->getEntityId(), $items);
                // All items of the same type have the same permission (set by settings panel)
                $permission = $items[0]->getPermission();
                $config[$type] = [
                    'ids' => $ids,
                    'permission' => $permission,
                ];
            }
        }
        return $config;
    }

    /**
     * Replace all share items for a given share + entity type.
     * Called from the settings panel save action.
     *
     * @param int[] $entityIds
     */
    public function updateShareItems(
        string $ownerUserId,
        int $shareId,
        string $entityType,
        array $entityIds,
        string $permission
    ): void {
        // Validate entity type
        if (!in_array($entityType, ShareItem::VALID_TYPES, true)) {
            throw new \InvalidArgumentException($this->l->t('Invalid entity type: %1$s', [$entityType]));
        }

        // Validate permission
        if (!in_array($permission, [ShareItem::PERMISSION_READ, ShareItem::PERMISSION_WRITE], true)) {
            throw new \InvalidArgumentException($this->l->t('Invalid permission: %1$s', [$permission]));
        }

        // Verify the share belongs to this owner
        $share = $this->shareMapper->findById($shareId);
        if ($share->getOwnerUserId() !== $ownerUserId) {
            throw new \InvalidArgumentException($this->l->t('You are not the owner of this share'));
        }

        // Validate that entity IDs belong to the owner
        $ownIds = $this->getOwnIds($ownerUserId, $entityType);
        $invalidIds = array_diff($entityIds, $ownIds);
        if (!empty($invalidIds)) {
            throw new \InvalidArgumentException($this->l->t('Some entities do not belong to you'));
        }

        $this->shareItemMapper->replaceForShareAndType($shareId, $entityType, $entityIds, $permission);

        // Clear cache
        $this->cache = [];
    }

    /**
     * Get the user's own account IDs (not shared).
     * @return int[]
     */
    public function getOwnAccountIds(string $userId): array {
        return $this->getOwnIds($userId, ShareItem::TYPE_ACCOUNT);
    }

    // ==========================================
    // Entity fetching (for controllers)
    // ==========================================

    /**
     * Fetch shared accounts as arrays (masked).
     *
     * @return array[]
     */
    public function getSharedAccounts(string $userId): array {
        $ids = $this->getSharedIds($userId, ShareItem::TYPE_ACCOUNT);
        if (empty($ids)) return [];
        $accounts = $this->accountMapper->findByIds($ids);
        return array_map(fn($a) => array_merge($a->toArrayMasked(), ['_shared' => true]), $accounts);
    }

    /**
     * Fetch shared categories as serialized arrays.
     *
     * @return array[]
     */
    public function getSharedCategories(string $userId): array {
        $ids = $this->getSharedIds($userId, ShareItem::TYPE_CATEGORY);
        if (empty($ids)) return [];
        $categories = $this->categoryMapper->findByIdsUnscoped($ids);
        return array_map(fn($c) => array_merge($c->jsonSerialize(), ['_shared' => true]), array_values($categories));
    }

    /**
     * Fetch shared bills as serialized arrays.
     *
     * @return array[]
     */
    public function getSharedBills(string $userId): array {
        $ids = $this->getSharedIds($userId, ShareItem::TYPE_BILL);
        if (empty($ids)) return [];
        $bills = $this->billMapper->findByIds($ids);
        return array_map(fn($b) => array_merge($b->jsonSerialize(), ['_shared' => true]), $bills);
    }

    /**
     * Fetch shared recurring income as serialized arrays.
     *
     * @return array[]
     */
    public function getSharedRecurringIncome(string $userId): array {
        $ids = $this->getSharedIds($userId, ShareItem::TYPE_RECURRING_INCOME);
        if (empty($ids)) return [];
        $income = $this->recurringIncomeMapper->findByIds($ids);
        return array_map(fn($r) => array_merge($r->jsonSerialize(), ['_shared' => true]), $income);
    }

    /**
     * Fetch shared savings goals as serialized arrays.
     *
     * @return array[]
     */
    public function getSharedSavingsGoals(string $userId): array {
        $ids = $this->getSharedIds($userId, ShareItem::TYPE_SAVINGS_GOAL);
        if (empty($ids)) return [];
        $goals = $this->savingsGoalMapper->findByIds($ids);
        return array_map(fn($g) => array_merge($g->jsonSerialize(), ['_shared' => true]), $goals);
    }

    // ==========================================
    // Internal helpers
    // ==========================================

    /**
     * Get accepted incoming shares for a user (cached).
     *
     * @return Share[]
     */
    public function getAcceptedIncomingShares(string $userId): array {
        $cacheKey = "incoming:{$userId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $all = $this->shareMapper->findByRecipient($userId);
        $accepted = array_filter($all, fn(Share $s) => $s->getStatus() === Share::STATUS_ACCEPTED);
        $this->cache[$cacheKey] = array_values($accepted);
        return $this->cache[$cacheKey];
    }

    /**
     * Get visible IDs = own + shared (cached).
     *
     * @return int[]
     */
    private function getVisibleIds(string $userId, string $entityType): array {
        $cacheKey = "visible:{$userId}:{$entityType}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $ownIds = $this->getOwnIds($userId, $entityType);
        $sharedIds = $this->getSharedIds($userId, $entityType);

        $merged = array_values(array_unique(array_merge($ownIds, $sharedIds)));
        $this->cache[$cacheKey] = $merged;
        return $merged;
    }

    /**
     * Get the user's own entity IDs (cached).
     *
     * @return int[]
     */
    private function getOwnIds(string $userId, string $entityType): array {
        $cacheKey = "own:{$userId}:{$entityType}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $ids = match ($entityType) {
            ShareItem::TYPE_ACCOUNT => array_map(
                fn($a) => $a->getId(),
                $this->accountMapper->findAll($userId)
            ),
            ShareItem::TYPE_CATEGORY => array_map(
                fn($c) => $c->getId(),
                $this->categoryMapper->findAll($userId)
            ),
            ShareItem::TYPE_BILL => array_map(
                fn($b) => $b->getId(),
                $this->billMapper->findAll($userId)
            ),
            ShareItem::TYPE_RECURRING_INCOME => array_map(
                fn($r) => $r->getId(),
                $this->recurringIncomeMapper->findAll($userId)
            ),
            ShareItem::TYPE_SAVINGS_GOAL => array_map(
                fn($g) => $g->getId(),
                $this->savingsGoalMapper->findAll($userId)
            ),
            default => [],
        };

        $this->cache[$cacheKey] = $ids;
        return $ids;
    }

    /**
     * Get only the shared entity IDs from accepted incoming shares (cached).
     *
     * @return int[]
     */
    private function getSharedIds(string $userId, string $entityType): array {
        $cacheKey = "shared:{$userId}:{$entityType}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $sharedIds = [];
        $shares = $this->getAcceptedIncomingShares($userId);
        foreach ($shares as $share) {
            $ids = $this->shareItemMapper->findSharedEntityIds($share->getId(), $entityType);
            $sharedIds = array_merge($sharedIds, $ids);
        }

        $sharedIds = array_values(array_unique($sharedIds));
        $this->cache[$cacheKey] = $sharedIds;
        return $sharedIds;
    }
}
