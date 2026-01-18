<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\SharedExpenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SharedExpenseController extends Controller {
    private SharedExpenseService $service;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        SharedExpenseService $service,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    // ==================== Contact Endpoints ====================

    /**
     * Get all contacts.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function contacts(): DataResponse {
        try {
            $contacts = $this->service->getContacts($this->userId);
            return new DataResponse(array_map(fn($c) => $c->jsonSerialize(), $contacts));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get contacts', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get contacts'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a new contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function createContact(string $name, ?string $email = null): DataResponse {
        try {
            $contact = $this->service->createContact($this->userId, $name, $email);
            return new DataResponse($contact->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create contact', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to create contact'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateContact(int $id, string $name, ?string $email = null): DataResponse {
        try {
            $contact = $this->service->updateContact($id, $this->userId, $name, $email);
            return new DataResponse($contact->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to update contact', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to update contact'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroyContact(int $id): DataResponse {
        try {
            $this->service->deleteContact($id, $this->userId);
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete contact', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to delete contact'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get detailed information for a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function contactDetails(int $id): DataResponse {
        try {
            $details = $this->service->getContactDetails($id, $this->userId);
            return new DataResponse($details);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get contact details', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get contact details'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // ==================== Balance Endpoints ====================

    /**
     * Get balance summary for all contacts.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function balances(): DataResponse {
        try {
            $summary = $this->service->getBalanceSummary($this->userId);
            return new DataResponse($summary);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get balances', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get balances'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // ==================== Expense Share Endpoints ====================

    /**
     * Share an expense with a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function shareExpense(
        int $transactionId,
        int $contactId,
        float $amount,
        ?string $notes = null
    ): DataResponse {
        try {
            $share = $this->service->shareExpense(
                $this->userId,
                $transactionId,
                $contactId,
                $amount,
                $notes
            );
            return new DataResponse($share->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to share expense', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to share expense'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Split a transaction 50/50 with a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function splitFiftyFifty(int $transactionId, int $contactId, ?string $notes = null): DataResponse {
        try {
            $share = $this->service->splitFiftyFifty($this->userId, $transactionId, $contactId, $notes);
            return new DataResponse($share->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to split 50/50', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to split expense'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get shares for a transaction.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function transactionShares(int $transactionId): DataResponse {
        try {
            $shares = $this->service->getSharesByTransaction($transactionId, $this->userId);
            return new DataResponse(array_map(fn($s) => $s->jsonSerialize(), $shares));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get transaction shares', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get shares'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update an expense share.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateShare(int $id, float $amount, ?string $notes = null): DataResponse {
        try {
            $share = $this->service->updateExpenseShare($id, $this->userId, $amount, $notes);
            return new DataResponse($share->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to update share', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to update share'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Mark a share as settled.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function markSettled(int $id): DataResponse {
        try {
            $share = $this->service->markShareSettled($id, $this->userId);
            return new DataResponse($share->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark share settled', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to mark settled'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete an expense share.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroyShare(int $id): DataResponse {
        try {
            $this->service->deleteExpenseShare($id, $this->userId);
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete share', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to delete share'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // ==================== Settlement Endpoints ====================

    /**
     * Record a settlement.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function recordSettlement(
        int $contactId,
        float $amount,
        string $date,
        ?string $notes = null
    ): DataResponse {
        try {
            $settlement = $this->service->recordSettlement(
                $this->userId,
                $contactId,
                $amount,
                $date,
                $notes
            );
            return new DataResponse($settlement->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record settlement', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to record settlement'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Settle all with a contact (mark all unsettled shares as settled).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function settleWithContact(int $contactId, string $date, ?string $notes = null): DataResponse {
        try {
            $settlement = $this->service->settleWithContact($this->userId, $contactId, $date, $notes);
            return new DataResponse($settlement->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to settle with contact', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to settle'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get all settlements.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function settlements(): DataResponse {
        try {
            $settlements = $this->service->getSettlements($this->userId);
            return new DataResponse(array_map(fn($s) => $s->jsonSerialize(), $settlements));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get settlements', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get settlements'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a settlement.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroySettlement(int $id): DataResponse {
        try {
            $this->service->deleteSettlement($id, $this->userId);
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete settlement', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to delete settlement'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
