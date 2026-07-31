<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Exception\CategoryInUseException;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CategoryController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    /**
     * Fields a write-shared recipient may change on a category they don't own:
     * cosmetic fields plus sortOrder (so they can reorder shared categories, #328).
     * Remaining structural fields (type, parentId), budget fields, and the scope
     * flags (excludedFromReports/excludedFromBudget — they alter the OWNER's
     * aggregates) stay owner-only. Any field not listed here is stripped from a
     * recipient's update, so the restriction is fail-closed.
     */
    private const RECIPIENT_WRITABLE_FIELDS = ['name', 'icon', 'color', 'sortOrder'];

    private CategoryService $service;
    private ValidationService $validationService;
    private RecurringBudgetService $recurringBudgetService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $service,
        ValidationService $validationService,
        GranularShareService $granularShareService,
        RecurringBudgetService $recurringBudgetService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->recurringBudgetService = $recurringBudgetService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(?string $type = null): DataResponse {
        try {
            if ($type) {
                $categories = $this->service->findByType($this->userId, $type);
            } else {
                $categories = $this->service->findAll($this->userId);
            }

            // Always merge shared categories (marked with _shared flag)
            $shared = $this->granularShareService->getSharedCategories($this->userId);
            if (!empty($shared)) {
                if ($type) {
                    $shared = array_filter($shared, fn($c) => ($c['type'] ?? '') === $type);
                }
                $categories = array_merge(
                    array_map(fn($c) => $c->jsonSerialize(), $categories),
                    array_values($shared)
                );
            }

            return new DataResponse($categories);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve categories'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function tree(): DataResponse {
        try {
            $tree = $this->service->getCategoryTree($this->userId);

            // Always merge shared categories (marked with _shared flag)
            $shared = $this->granularShareService->getSharedCategories($this->userId);
            if (!empty($shared)) {
                // Index by ID and build parent-child hierarchy
                $byId = [];
                foreach ($shared as $cat) {
                    $cat['children'] = [];
                    $byId[$cat['id']] = $cat;
                }

                $sharedTree = [];
                foreach ($byId as $id => &$cat) {
                    $parentId = $cat['parentId'] ?? null;
                    if ($parentId && isset($byId[$parentId])) {
                        $byId[$parentId]['children'][] = &$cat;
                    } else {
                        $sharedTree[] = &$cat;
                    }
                }
                unset($cat);

                $tree = array_merge($tree, $sharedTree);
            }

            return new DataResponse($tree);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category tree'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function transactionCounts(): DataResponse {
        try {
            $counts = $this->service->getCategoryTransactionCounts($this->getEffectiveUserId(), $this->getVisibleAccountIds());
            return new DataResponse($counts);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transaction counts'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $category = $this->service->find($id, $this->getEffectiveUserId());
            return new DataResponse($category);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        string $type,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        int $sortOrder = 0,
        bool $excludedFromReports = false,
        bool $excludedFromBudget = false
    ): DataResponse {
        try {
            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate type
            $validTypes = ['income', 'expense'];
            if (!in_array($type, $validTypes, true)) {
                return new DataResponse(['error' => $this->l->t('Invalid category type. Must be income or expense')], Http::STATUS_BAD_REQUEST);
            }

            // Validate optional fields
            if ($icon !== null) {
                $iconValidation = $this->validationService->validateIcon($icon);
                if (!$iconValidation['valid']) {
                    return new DataResponse(['error' => $iconValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $icon = $iconValidation['sanitized'];
            }

            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            $category = $this->service->create(
                $this->getEffectiveUserId(),
                $name,
                $type,
                $parentId,
                $icon,
                $color,
                $budgetAmount,
                $sortOrder,
                $excludedFromReports,
                $excludedFromBudget
            );
            return new DataResponse($category, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?string $type = null,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        ?string $budgetPeriod = null,
        ?int $sortOrder = null
    ): DataResponse {
        try {
            $this->requireWriteAccess('category', $id);

            $updates = [];

            // Validate name if provided
            if ($name !== null) {
                $nameValidation = $this->validationService->validateName($name, false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Validate type if provided
            if ($type !== null) {
                $validTypes = ['income', 'expense'];
                if (!in_array($type, $validTypes, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid category type. Must be income or expense')], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $type;
            }

            // Validate icon if provided
            if ($icon !== null) {
                $iconValidation = $this->validationService->validateIcon($icon);
                if (!$iconValidation['valid']) {
                    return new DataResponse(['error' => $iconValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['icon'] = $iconValidation['sanitized'];
            }

            // Validate color if provided
            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['color'] = $colorValidation['sanitized'];
            }

            // Validate budgetPeriod if provided
            if ($budgetPeriod !== null) {
                $validPeriods = ['monthly', 'weekly', 'yearly', 'quarterly'];
                if (!in_array($budgetPeriod, $validPeriods, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid budget period. Must be monthly, weekly, yearly, or quarterly')], Http::STATUS_BAD_REQUEST);
                }
                $updates['budgetPeriod'] = $budgetPeriod;
            }

            // Handle parentId — use request params to distinguish "not sent" from "explicitly null"
            $params = $this->request->getParams();
            if (array_key_exists('parentId', $params)) {
                $updates['parentId'] = $params['parentId'] !== null ? (int) $params['parentId'] : null;
            }
            if ($budgetAmount !== null) {
                $updates['budgetAmount'] = $budgetAmount;
            }
            if ($sortOrder !== null) {
                $updates['sortOrder'] = $sortOrder;
            }
            if (array_key_exists('excludedFromReports', $params)) {
                $updates['excludedFromReports'] = (bool) $params['excludedFromReports'];
            }
            if (array_key_exists('excludedFromBudget', $params)) {
                $updates['excludedFromBudget'] = (bool) $params['excludedFromBudget'];
            }
            if (array_key_exists('budgetRollover', $params)) {
                $updates['budgetRollover'] = (bool) $params['budgetRollover'];
            }

            // Write access is confirmed above. Resolve the actual owner so the
            // owner-scoped service lookup succeeds for write-shared categories.
            // Recipients are restricted to cosmetic fields plus sortOrder (so they
            // can reorder shared categories, #328); type, parentId, budgets and
            // report-scope stay owner-only.
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id)
                ?? $this->userId;
            if ($owner !== $this->userId) {
                $updates = array_intersect_key($updates, array_flip(self::RECIPIENT_WRITABLE_FIELDS));
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $category = $this->service->update($id, $owner, $updates);
            return new DataResponse($category);
        } catch (ReadOnlyShareException $e) {
            // Read-only share violation → 403 with a clear message.
            return $this->handleError($e, $this->l->t('Failed to update category'), Http::STATUS_FORBIDDEN, ['categoryId' => $id]);
        } catch (\Exception $e) {
            // Surface curated service-level validation messages (e.g. duplicate
            // name, self-parent) to the user.
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    /**
     * Reorder a category relative to a target sibling (drag-and-drop). Renumbers
     * the sibling group so the order is deterministic. Own categories support all
     * positions; write-shared categories may be reordered (above/below) but not
     * nested (reparenting is owner-only) (#328).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function reorder(int $id): DataResponse {
        try {
            $params = $this->request->getParams();
            $targetId = isset($params['targetId']) ? (int) $params['targetId'] : 0;
            $position = $params['position'] ?? 'below';
            if (!in_array($position, ['above', 'below', 'child'], true)) {
                return new DataResponse(['error' => $this->l->t('Invalid position')], Http::STATUS_BAD_REQUEST);
            }

            $this->requireWriteAccess('category', $id);
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id) ?? $this->userId;

            // Reparenting is structural — owner-only. Recipients may only reorder.
            if ($position === 'child' && $owner !== $this->userId) {
                return new DataResponse(['error' => $this->l->t('Shared categories cannot be nested')], Http::STATUS_FORBIDDEN);
            }

            $category = $this->service->reorderCategory($id, $owner, $targetId, $position);
            return new DataResponse($category);
        } catch (ReadOnlyShareException $e) {
            return $this->handleError($e, $this->l->t('Failed to reorder category'), Http::STATUS_FORBIDDEN, ['categoryId' => $id]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to reorder category'), Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id, bool $reassign = false): DataResponse {
        try {
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id);
            if ($owner === null) {
                return new DataResponse(
                    ['error' => $this->l->t('%1$s not found', [$this->l->t('Category')])],
                    Http::STATUS_NOT_FOUND
                );
            }
            // Only the owner may delete a category — recipients (even with write
            // access) can edit it but not remove it. Deletion cascades to the
            // owner's child categories, budget snapshots, and tag metadata.
            if ($owner !== $this->userId) {
                return new DataResponse(
                    ['error' => $this->l->t('Only the category owner can delete it')],
                    Http::STATUS_FORBIDDEN
                );
            }

            // reassign=true moves the category's (and descendants') transactions
            // to No Category first, so it can be deleted without hand-recategorizing (#332).
            if ($reassign) {
                $this->service->deleteWithReassign($id, $this->userId);
            } else {
                $this->service->delete($id, $this->userId);
            }
            return new DataResponse(['status' => 'success']);
        } catch (CategoryInUseException $e) {
            // Machine-readable code lets the client offer to reassign and retry.
            return new DataResponse(
                ['error' => $e->getMessage(), 'code' => 'has_transactions'],
                Http::STATUS_CONFLICT
            );
        } catch (\Exception $e) {
            // Surface validation messages (e.g., "has transactions assigned") to the user
            return $this->handleValidationError($e);
        }
    }

    /**
     * Ids of categories this user muted from their own reports
     *
     * @NoAdminRequired
     */
    public function reportMutes(): DataResponse {
        try {
            return new DataResponse($this->service->getMutedCategoryIds($this->getEffectiveUserId()));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load report preferences'));
        }
    }

    /**
     * Hide/show a category in THIS user's reports only. Works for any category
     * the user can see (own or shared with them); never touches the owner's
     * excluded-from-reports flag.
     *
     * @NoAdminRequired
     */
    public function setReportMute(int $id, bool $muted = true): DataResponse {
        try {
            $userId = $this->getEffectiveUserId();
            $visibleIds = $this->granularShareService->getVisibleCategoryIds($userId);
            if (!in_array($id, $visibleIds, true)) {
                return new DataResponse(['error' => $this->l->t('Category not found')], Http::STATUS_NOT_FOUND);
            }
            $this->service->setReportMute($id, $userId, $muted);
            return new DataResponse(['categoryId' => $id, 'muted' => $muted]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update report preference'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function allSpending(string $startDate, string $endDate, string $transactionType = 'debit', ?bool $excludeShared = null): DataResponse {
        try {
            // Validate transaction type
            if (!in_array($transactionType, ['debit', 'credit'], true)) {
                $transactionType = 'debit';
            }
            $visibleAccountIds = $this->getEffectiveAccountIds((bool) $excludeShared);
            $spending = $this->service->getAllCategorySpending($this->userId, $startDate, $endDate, $visibleAccountIds, $transactionType);
            return new DataResponse($spending);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category spending'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function spending(int $id, string $startDate, string $endDate): DataResponse {
        try {
            // Resolve the owner so a shared category reads the owner's data, not
            // the recipient's (whose owner-scoped queries would find nothing) (#328).
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id);
            if ($owner === null) {
                return new DataResponse(['error' => $this->l->t('%1$s not found', [$this->l->t('Category')])], Http::STATUS_NOT_FOUND);
            }
            $spending = $this->service->getCategorySpending($id, $owner, $startDate, $endDate);
            return new DataResponse(['spending' => $spending]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category spending'), Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function details(int $id, ?string $startDate = null, ?string $endDate = null, ?int $accountId = null): DataResponse {
        try {
            // Resolve the owner so a shared category reads the owner's data, not
            // the recipient's (whose owner-scoped queries would find nothing) (#328).
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id);
            if ($owner === null) {
                return new DataResponse(['error' => $this->l->t('%1$s not found', [$this->l->t('Category')])], Http::STATUS_NOT_FOUND);
            }
            $details = $this->service->getCategoryDetails($id, $owner, $startDate, $endDate, $accountId);
            return new DataResponse($details);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function transactions(int $id, int $limit = 5): DataResponse {
        try {
            // Resolve the owner so a shared category reads the owner's data, not
            // the recipient's (whose owner-scoped queries would find nothing) (#328).
            $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $id);
            if ($owner === null) {
                return new DataResponse(['error' => $this->l->t('%1$s not found', [$this->l->t('Category')])], Http::STATUS_NOT_FOUND);
            }
            $transactions = $this->service->getCategoryTransactions($id, $owner, $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }

    /**
     * Create a budget snapshot for a given month.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createSnapshot(string $month): DataResponse {
        try {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return new DataResponse(['error' => $this->l->t('Invalid month format. Use YYYY-MM')], Http::STATUS_BAD_REQUEST);
            }

            $snapshots = $this->service->createBudgetSnapshot($this->userId, $month);
            return new DataResponse([
                'status' => 'success',
                'month' => $month,
                'count' => count($snapshots),
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * Delete a budget snapshot for a given month.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function deleteSnapshot(string $month): DataResponse {
        try {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return new DataResponse(['error' => $this->l->t('Invalid month format. Use YYYY-MM')], Http::STATUS_BAD_REQUEST);
            }

            $this->service->deleteBudgetSnapshot($this->userId, $month);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete budget snapshot'));
        }
    }

    /**
     * Get all snapshot months for the user.
     * @NoAdminRequired
     */
    public function snapshotMonths(): DataResponse {
        try {
            $months = $this->service->getSnapshotMonths($this->userId);
            return new DataResponse($months);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve snapshot months'));
        }
    }

    /**
     * Get resolved effective budgets for a given month.
     * @NoAdminRequired
     */
    public function effectiveBudgets(string $month): DataResponse {
        try {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return new DataResponse(['error' => $this->l->t('Invalid month format. Use YYYY-MM')], Http::STATUS_BAD_REQUEST);
            }

            $hasSnapshot = $this->service->hasSnapshot($this->userId, $month);
            $budgets = $this->service->resolveEffectiveBudgets($this->userId, $month);
            return new DataResponse([
                'month' => $month,
                'hasSnapshot' => $hasSnapshot,
                'budgets' => $budgets,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve effective budgets'));
        }
    }

    /**
     * Get the monthly-normalized recurring total committed per category, derived
     * from active recurring bills and recurring income (#269). The budget view
     * uses these as an automatic fallback for categories with no manual budget.
     * @NoAdminRequired
     */
    public function recurringBudgets(): DataResponse {
        try {
            $budgets = $this->recurringBudgetService->getMonthlyBudgetsByCategory($this->userId);
            return new DataResponse(['budgets' => $budgets]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve recurring budgets'));
        }
    }

    /**
     * Update a single category budget within a snapshot.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateSnapshotBudget(string $month, int $categoryId, ?float $amount = null, ?string $period = null): DataResponse {
        try {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return new DataResponse(['error' => $this->l->t('Invalid month format. Use YYYY-MM')], Http::STATUS_BAD_REQUEST);
            }

            if ($period !== null) {
                $validPeriods = ['monthly', 'weekly', 'yearly', 'quarterly'];
                if (!in_array($period, $validPeriods, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid budget period')], Http::STATUS_BAD_REQUEST);
                }
            }

            $snapshot = $this->service->updateSnapshotBudget($this->userId, $categoryId, $month, $amount, $period);
            return new DataResponse($snapshot);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }
}