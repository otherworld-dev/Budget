<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class TransactionController extends Controller {
    private TransactionService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        TransactionService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
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
            $updates = array_filter([
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $type,
                'categoryId' => $categoryId,
                'vendor' => $vendor,
                'reference' => $reference,
                'notes' => $notes,
                'reconciled' => $reconciled,
            ], fn($value) => $value !== null);
            
            $transaction = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($transaction);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function bulkCategorize(array $updates): DataResponse {
        try {
            $results = $this->service->bulkCategorize($this->userId, $updates);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}