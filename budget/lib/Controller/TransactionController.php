<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
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

class TransactionController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private TransactionService $service;
    private TransactionSplitService $splitService;
    private TransactionTagService $tagService;
    private ValidationService $validationService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        TransactionService $service,
        TransactionSplitService $splitService,
        TransactionTagService $tagService,
        ValidationService $validationService,
        GranularShareService $granularShareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->splitService = $splitService;
        $this->tagService = $tagService;
        $this->validationService = $validationService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(
        ?int $accountId = null,
        int $limit = 100,
        int $page = 1,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $createdAtFrom = null,
        ?string $createdAtTo = null,
        ?string $category = null,
        ?string $type = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $sort = 'date',
        ?string $direction = 'desc',
        ?string $status = null,
        ?array $tagIds = null
    ): DataResponse {
        try {
            $offset = ($page - 1) * $limit;

            $filters = [
                'accountId' => $accountId,
                'search' => $search,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'createdAtFrom' => $createdAtFrom,
                'createdAtTo' => $createdAtTo,
                'category' => $category,
                'type' => $type,
                'amountMin' => $amountMin,
                'amountMax' => $amountMax,
                'sort' => $sort,
                'direction' => $direction,
                'status' => $status,
                'tagIds' => $tagIds,
            ];

            $visibleAccountIds = $this->getVisibleAccountIds();
            $result = $this->service->findWithFilters($this->userId, $filters, $limit, $offset, $visibleAccountIds);

            $responseData = [
                'transactions' => $result['transactions'],
                'total' => $result['total'],
                'page' => $page,
                'totalPages' => ceil($result['total'] / $limit)
            ];

            if (isset($result['balanceBeforePage'])) {
                $responseData['balanceBeforePage'] = $result['balanceBeforePage'];
            }

            return new DataResponse($responseData);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transactions'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            // Try own first, fall back to shared accounts
            try {
                $transaction = $this->service->find($id, $this->userId);
            } catch (\Exception $e) {
                $transaction = $this->service->findForAccounts($id, $this->getVisibleAccountIds());
            }
            return new DataResponse($transaction);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['transactionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function create(
        int $accountId,
        string $date,
        string $description,
        float $amount,
        string $type,
        ?int $categoryId = null,
        ?string $vendor = null,
        ?string $reference = null,
        ?string $notes = null
    ): DataResponse {
        try {
            // Validate description (required)
            $descValidation = $this->validationService->validateDescription($description, true);
            if (!$descValidation['valid']) {
                return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $description = $descValidation['sanitized'];

            // Validate date
            $dateValidation = $this->validationService->validateDate($date, $this->l->t('Date'), true);
            if (!$dateValidation['valid']) {
                return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            // Validate type
            $validTypes = ['credit', 'debit'];
            if (!in_array($type, $validTypes, true)) {
                return new DataResponse(['error' => $this->l->t('Invalid transaction type. Must be credit or debit')], Http::STATUS_BAD_REQUEST);
            }

            // Validate optional fields
            if ($vendor !== null) {
                $vendorValidation = $this->validationService->validateVendor($vendor);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $vendor = $vendorValidation['sanitized'];
            }

            if ($reference !== null) {
                $refValidation = $this->validationService->validateReference($reference);
                if (!$refValidation['valid']) {
                    return new DataResponse(['error' => $refValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $reference = $refValidation['sanitized'];
            }

            if ($notes !== null) {
                $notesValidation = $this->validationService->validateNotes($notes);
                if (!$notesValidation['valid']) {
                    return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $notes = $notesValidation['sanitized'];
            }

            // For shared accounts, verify write access and resolve the account owner
            $effectiveUserId = $this->userId;
            if (!in_array($accountId, $this->granularShareService->getOwnAccountIds($this->userId))) {
                $this->requireWriteAccess('account', $accountId);
                // Find the account's actual owner for the service call
                $account = $this->service->findAccountById($accountId);
                $effectiveUserId = $account->getUserId();
            }

            $transaction = $this->service->create(
                $effectiveUserId,
                $accountId,
                $date,
                $description,
                $amount,
                $type,
                $categoryId,
                $vendor,
                $reference,
                $notes
            );
            return new DataResponse($transaction, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create transaction'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function update(
        int $id,
        ?string $date = null,
        ?string $description = null,
        ?float $amount = null,
        ?string $type = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $vendor = null,
        ?string $reference = null,
        ?string $notes = null,
        ?bool $reconciled = null,
        ?string $status = null
    ): DataResponse {
        try {
            $updates = [];
            $params = $this->request->getParams();

            // Validate description if provided
            if ($description !== null) {
                $descValidation = $this->validationService->validateDescription($description, false);
                if (!$descValidation['valid']) {
                    return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['description'] = $descValidation['sanitized'];
            }

            // Validate date if provided
            if ($date !== null) {
                $dateValidation = $this->validationService->validateDate($date, $this->l->t('Date'), false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['date'] = $date;
            }

            // Validate type if provided
            if ($type !== null) {
                $validTypes = ['credit', 'debit'];
                if (!in_array($type, $validTypes, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid transaction type. Must be credit or debit')], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $type;
            }

            // Nullable string fields: use getParams() to distinguish "sent as null"
            // from "not sent" (both map to null in method parameters).
            // The validators already handle null/empty → sanitized null.
            if (array_key_exists('vendor', $params)) {
                $vendorValidation = $this->validationService->validateVendor($params['vendor']);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['vendor'] = $vendorValidation['sanitized'];
            }

            if (array_key_exists('reference', $params)) {
                $refValidation = $this->validationService->validateReference($params['reference']);
                if (!$refValidation['valid']) {
                    return new DataResponse(['error' => $refValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['reference'] = $refValidation['sanitized'];
            }

            if (array_key_exists('notes', $params)) {
                $notesValidation = $this->validationService->validateNotes($params['notes']);
                if (!$notesValidation['valid']) {
                    return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['notes'] = $notesValidation['sanitized'];
            }

            // Handle non-string fields
            if ($amount !== null) {
                $updates['amount'] = $amount;
            }
            if (array_key_exists('categoryId', $params)) {
                $catValue = $params['categoryId'];
                $updates['categoryId'] = ($catValue === null || $catValue === '') ? null : (int)$catValue;
            }
            if ($accountId !== null) {
                $updates['accountId'] = $accountId;
            }
            if ($reconciled !== null) {
                $updates['reconciled'] = $reconciled;
            }
            if ($status !== null) {
                if (!in_array($status, ['cleared', 'scheduled'], true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid status. Must be cleared or scheduled')], Http::STATUS_BAD_REQUEST);
                }
                $updates['status'] = $status;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $transaction = $this->service->update($id, $this->getEffectiveUserId(), $updates);
            return new DataResponse($transaction);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update transaction'), Http::STATUS_BAD_REQUEST, ['transactionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['transactionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function search(string $query, int $limit = 100): DataResponse {
        try {
            $transactions = $this->service->search($this->getEffectiveUserId(), $query, $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to search transactions'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function uncategorized(int $limit = 100): DataResponse {
        try {
            $transactions = $this->service->findUncategorized($this->getEffectiveUserId(), $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve uncategorized transactions'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function bulkCategorize(array $updates): DataResponse {
        try {
            $results = $this->service->bulkCategorize($this->getEffectiveUserId(), $updates);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to categorize transactions'));
        }
    }

    /**
     * Get potential transfer matches for a transaction
     *
     * @NoAdminRequired
     */
    public function getMatches(int $id, int $dateWindow = 3): DataResponse {
        try {
            $matches = $this->service->findPotentialMatches($id, $this->getEffectiveUserId(), $dateWindow);
            return new DataResponse([
                'matches' => $matches,
                'count' => count($matches)
            ]);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['transactionId' => $id]);
        }
    }

    /**
     * Link two transactions as a transfer pair
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function link(int $id, int $targetId): DataResponse {
        try {
            $result = $this->service->linkTransactions($id, $targetId, $this->getEffectiveUserId());
            return new DataResponse($result);
        } catch (\Exception $e) {
            // Use validation error handler to show actual message (e.g., "already linked")
            return $this->handleValidationError($e);
        }
    }

    /**
     * Unlink a transaction from its transfer partner
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function unlink(int $id): DataResponse {
        try {
            $result = $this->service->unlinkTransaction($id, $this->getEffectiveUserId());
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to unlink transaction'), Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Bulk find and match transactions
     * Auto-links single matches, returns multiple matches for manual review
     *
     * @NoAdminRequired
     * @deprecated Use scanMatches + bulkLink instead
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function bulkMatch(int $dateWindow = 3): DataResponse {
        try {
            $result = $this->service->bulkFindAndMatch($this->getEffectiveUserId(), $dateWindow);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to bulk match transactions'));
        }
    }

    /**
     * Scan for potential transfer matches (read-only, no linking)
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function scanMatches(int $dateWindow = 3): DataResponse {
        try {
            $result = $this->service->scanForMatches($this->getEffectiveUserId(), $dateWindow);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to scan for matches'));
        }
    }

    /**
     * Bulk link transaction pairs
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function bulkLink(): DataResponse {
        try {
            $params = $this->request->getParams();
            $pairs = $params['pairs'] ?? [];

            if (empty($pairs) || !is_array($pairs)) {
                return new DataResponse(['error' => $this->l->t('No valid pairs provided')], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->service->bulkLinkTransactions($this->getEffectiveUserId(), $pairs);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to bulk link transactions'));
        }
    }

    /**
     * Bulk delete transactions
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function bulkDelete(array $ids): DataResponse {
        try {
            if (empty($ids)) {
                return new DataResponse(['error' => $this->l->t('No transaction IDs provided')], Http::STATUS_BAD_REQUEST);
            }

            $results = $this->service->bulkDelete($this->getEffectiveUserId(), $ids);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete transactions'));
        }
    }

    /**
     * Bulk update reconciled status
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function bulkReconcile(array $ids, bool $reconciled): DataResponse {
        try {
            if (empty($ids)) {
                return new DataResponse(['error' => $this->l->t('No transaction IDs provided')], Http::STATUS_BAD_REQUEST);
            }

            $results = $this->service->bulkReconcile($this->getEffectiveUserId(), $ids, $reconciled);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update reconcile status'));
        }
    }

    /**
     * Bulk edit transaction fields
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function bulkEdit(array $ids, array $updates): DataResponse {
        try {
            if (empty($ids)) {
                return new DataResponse(['error' => $this->l->t('No transaction IDs provided')], Http::STATUS_BAD_REQUEST);
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No update fields provided')], Http::STATUS_BAD_REQUEST);
            }

            // Validate allowed fields
            $allowedFields = ['categoryId', 'vendor', 'reference', 'notes'];
            $invalidFields = array_diff(array_keys($updates), $allowedFields);
            if (!empty($invalidFields)) {
                return new DataResponse([
                    'error' => $this->l->t('Invalid fields: %1$s', [implode(', ', $invalidFields)])
                ], Http::STATUS_BAD_REQUEST);
            }

            // Validate and sanitize string fields
            if (isset($updates['vendor'])) {
                $vendorValidation = $this->validationService->validateVendor($updates['vendor']);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['vendor'] = $vendorValidation['sanitized'];
            }

            if (isset($updates['reference'])) {
                $refValidation = $this->validationService->validateReference($updates['reference']);
                if (!$refValidation['valid']) {
                    return new DataResponse(['error' => $refValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['reference'] = $refValidation['sanitized'];
            }

            if (isset($updates['notes'])) {
                $notesValidation = $this->validationService->validateNotes($updates['notes']);
                if (!$notesValidation['valid']) {
                    return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['notes'] = $notesValidation['sanitized'];
            }

            $results = $this->service->bulkEdit($this->getEffectiveUserId(), $ids, $updates);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to bulk edit transactions'));
        }
    }

    /**
     * Get splits for a transaction
     *
     * @NoAdminRequired
     */
    public function getSplits(int $id): DataResponse {
        try {
            $splits = $this->splitService->getSplits($id, $this->getEffectiveUserId());
            return new DataResponse($splits);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['id' => $id]);
        }
    }

    /**
     * Split a transaction across multiple categories
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function split(int $id): DataResponse {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data || !isset($data['splits']) || !is_array($data['splits'])) {
                return new DataResponse(['error' => $this->l->t('Invalid splits data')], Http::STATUS_BAD_REQUEST);
            }

            // Validate each split
            foreach ($data['splits'] as $i => $split) {
                if (!isset($split['amount']) || !is_numeric($split['amount'])) {
                    return new DataResponse(['error' => $this->l->t('Split %1$s: amount is required', [$i])], Http::STATUS_BAD_REQUEST);
                }
                if ($split['amount'] <= 0) {
                    return new DataResponse(['error' => $this->l->t('Split %1$s: amount must be positive', [$i])], Http::STATUS_BAD_REQUEST);
                }
            }

            $splits = $this->splitService->splitTransaction($id, $this->getEffectiveUserId(), $data['splits']);
            return new DataResponse($splits, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['id' => $id]);
        }
    }

    /**
     * Remove splits from a transaction (unsplit)
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function unsplit(int $id, ?int $categoryId = null): DataResponse {
        try {
            $transaction = $this->splitService->unsplitTransaction($id, $this->getEffectiveUserId(), $categoryId);
            return new DataResponse($transaction);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['id' => $id]);
        }
    }

    /**
     * Update a specific split
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateSplit(int $id, int $splitId): DataResponse {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => $this->l->t('Invalid JSON data')], Http::STATUS_BAD_REQUEST);
            }

            $split = $this->splitService->updateSplit($splitId, $this->getEffectiveUserId(), $data);
            return new DataResponse($split);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Split'), ['splitId' => $splitId]);
        }
    }

    /**
     * Get tags for a transaction
     *
     * @NoAdminRequired
     */
    public function getTags(int $id): DataResponse {
        try {
            // Try own first, fall back to shared via visible accounts
            try {
                $tags = $this->tagService->getTransactionTags($id, $this->userId);
            } catch (\Exception $e) {
                // Verify the transaction is in a visible account
                $this->service->findForAccounts($id, $this->getVisibleAccountIds());
                // Fetch tags without user ownership check
                $tags = $this->tagService->getTransactionTagsUnscoped($id);
            }
            return new DataResponse($tags);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Transaction'), ['transactionId' => $id]);
        }
    }

    /**
     * Set tags for a transaction (replaces existing tags)
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function setTags(int $id): DataResponse {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!isset($data['tagIds']) || !is_array($data['tagIds'])) {
                return new DataResponse(['error' => $this->l->t('tagIds array is required')], Http::STATUS_BAD_REQUEST);
            }

            $tagIds = array_map('intval', $data['tagIds']);
            $transactionTags = $this->tagService->setTransactionTags($id, $this->getEffectiveUserId(), $tagIds);

            return new DataResponse([
                'status' => 'success',
                'transactionTags' => $transactionTags
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to set transaction tags'), Http::STATUS_BAD_REQUEST, ['transactionId' => $id]);
        }
    }

    /**
     * Clear all tags from a transaction
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function clearTags(int $id): DataResponse {
        try {
            $this->tagService->clearTransactionTags($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to clear transaction tags'), Http::STATUS_BAD_REQUEST, ['transactionId' => $id]);
        }
    }

    /**
     * Find suspected duplicate transactions
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function duplicates(): DataResponse {
        try {
            $groups = $this->service->findDuplicates($this->getEffectiveUserId());
            return new DataResponse([
                'groups' => $groups,
                'totalGroups' => count($groups),
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to find duplicates'));
        }
    }

}