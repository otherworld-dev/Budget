<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TransactionController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private TransactionService $service;
    private ValidationService $validationService;
    private string $userId;

    public function __construct(
        IRequest $request,
        TransactionService $service,
        ValidationService $validationService,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
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
        ?int $category = null,
        ?string $type = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $sort = 'date',
        ?string $direction = 'desc'
    ): DataResponse {
        try {
            $offset = ($page - 1) * $limit;

            $filters = [
                'accountId' => $accountId,
                'search' => $search,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'category' => $category,
                'type' => $type,
                'amountMin' => $amountMin,
                'amountMax' => $amountMax,
                'sort' => $sort,
                'direction' => $direction
            ];

            $result = $this->service->findWithFilters($this->userId, $filters, $limit, $offset);

            return new DataResponse([
                'transactions' => $result['transactions'],
                'total' => $result['total'],
                'page' => $page,
                'totalPages' => ceil($result['total'] / $limit)
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve transactions');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $transaction = $this->service->find($id, $this->userId);
            return new DataResponse($transaction);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Transaction', ['transactionId' => $id]);
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
            $dateValidation = $this->validationService->validateDate($date, 'Date', true);
            if (!$dateValidation['valid']) {
                return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            // Validate type
            $validTypes = ['credit', 'debit'];
            if (!in_array($type, $validTypes, true)) {
                return new DataResponse(['error' => 'Invalid transaction type. Must be credit or debit'], Http::STATUS_BAD_REQUEST);
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

            $transaction = $this->service->create(
                $this->userId,
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
            return $this->handleError($e, 'Failed to create transaction');
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
        ?string $vendor = null,
        ?string $reference = null,
        ?string $notes = null,
        ?bool $reconciled = null
    ): DataResponse {
        try {
            $updates = [];

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
                $dateValidation = $this->validationService->validateDate($date, 'Date', false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['date'] = $date;
            }

            // Validate type if provided
            if ($type !== null) {
                $validTypes = ['credit', 'debit'];
                if (!in_array($type, $validTypes, true)) {
                    return new DataResponse(['error' => 'Invalid transaction type. Must be credit or debit'], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $type;
            }

            // Validate optional string fields
            if ($vendor !== null) {
                $vendorValidation = $this->validationService->validateVendor($vendor);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['vendor'] = $vendorValidation['sanitized'];
            }

            if ($reference !== null) {
                $refValidation = $this->validationService->validateReference($reference);
                if (!$refValidation['valid']) {
                    return new DataResponse(['error' => $refValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['reference'] = $refValidation['sanitized'];
            }

            if ($notes !== null) {
                $notesValidation = $this->validationService->validateNotes($notes);
                if (!$notesValidation['valid']) {
                    return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['notes'] = $notesValidation['sanitized'];
            }

            // Handle non-string fields
            if ($amount !== null) {
                $updates['amount'] = $amount;
            }
            if ($categoryId !== null) {
                $updates['categoryId'] = $categoryId;
            }
            if ($reconciled !== null) {
                $updates['reconciled'] = $reconciled;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => 'No valid fields to update'], Http::STATUS_BAD_REQUEST);
            }

            $transaction = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($transaction);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update transaction', Http::STATUS_BAD_REQUEST, ['transactionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Transaction', ['transactionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function search(string $query, int $limit = 100): DataResponse {
        try {
            $transactions = $this->service->search($this->userId, $query, $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to search transactions');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function uncategorized(int $limit = 100): DataResponse {
        try {
            $transactions = $this->service->findUncategorized($this->userId, $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve uncategorized transactions');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function bulkCategorize(array $updates): DataResponse {
        try {
            $results = $this->service->bulkCategorize($this->userId, $updates);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to categorize transactions');
        }
    }

    /**
     * Get potential transfer matches for a transaction
     *
     * @NoAdminRequired
     */
    public function getMatches(int $id, int $dateWindow = 3): DataResponse {
        try {
            $matches = $this->service->findPotentialMatches($id, $this->userId, $dateWindow);
            return new DataResponse([
                'matches' => $matches,
                'count' => count($matches)
            ]);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Transaction', ['transactionId' => $id]);
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
            $result = $this->service->linkTransactions($id, $targetId, $this->userId);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to link transactions', Http::STATUS_BAD_REQUEST);
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
            $result = $this->service->unlinkTransaction($id, $this->userId);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to unlink transaction', Http::STATUS_BAD_REQUEST);
        }
    }
}