<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SharedExpenseService;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class SharedExpenseController extends Controller {
    use SharedAccessTrait;

    private SharedExpenseService $service;
    private IUserManager $userManager;
    private IL10N $l;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        SharedExpenseService $service,
        GranularShareService $granularShareService,
        IUserManager $userManager,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userManager = $userManager;
        $this->l = $l;
        $this->userId = $userId;
        $this->logger = $logger;
        $this->setGranularShareService($granularShareService);
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
            $contacts = $this->service->getContacts($this->getEffectiveUserId());
            return new DataResponse(array_map(fn($c) => $c->jsonSerialize(), $contacts));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get contacts', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get contacts')],
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
            $params = $this->request->getParams();
            $nextcloudUserId = $params['nextcloudUserId'] ?? null;

            // If linking to a Nextcloud user, validate they exist and auto-fill name
            if ($nextcloudUserId) {
                $ncUser = $this->userManager->get($nextcloudUserId);
                if ($ncUser === null) {
                    return new DataResponse(
                        ['error' => $this->l->t('Nextcloud user not found')],
                        Http::STATUS_BAD_REQUEST
                    );
                }
                // Use display name if no name provided
                if (empty($name) || $name === $nextcloudUserId) {
                    $name = $ncUser->getDisplayName() ?: $nextcloudUserId;
                }
            }

            $contact = $this->service->createContact($this->getEffectiveUserId(), $name, $email, $nextcloudUserId);
            return new DataResponse($contact->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create contact', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to create contact')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Search Nextcloud users by display name or username.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function searchUsers(string $query = ''): DataResponse {
        // Allow wildcard '*' to list all users, or require 2+ chars for search
        $searchQuery = ($query === '*') ? '' : $query;
        if ($query !== '*' && strlen($query) < 2) {
            return new DataResponse([]);
        }

        $results = [];
        $seen = [];

        // Search by username
        $users = $this->userManager->search($searchQuery, 50);
        foreach ($users as $user) {
            if (!isset($seen[$user->getUID()])) {
                $results[] = [
                    'uid' => $user->getUID(),
                    'displayName' => $user->getDisplayName(),
                ];
                $seen[$user->getUID()] = true;
            }
        }

        // Also search by display name
        $displayUsers = $this->userManager->searchDisplayName($searchQuery, 50);
        foreach ($displayUsers as $user) {
            if (!isset($seen[$user->getUID()])) {
                $results[] = [
                    'uid' => $user->getUID(),
                    'displayName' => $user->getDisplayName(),
                ];
                $seen[$user->getUID()] = true;
            }
        }

        // Remove current user from results
        $currentUserId = $this->getEffectiveUserId();
        $results = array_values(array_filter($results, fn($r) => $r['uid'] !== $currentUserId));

        return new DataResponse($results);
    }

    /**
     * Update a contact.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateContact(int $id, string $name, ?string $email = null): DataResponse {
        try {
            $contact = $this->service->updateContact($id, $this->getEffectiveUserId(), $name, $email);
            return new DataResponse($contact->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to update contact', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to update contact')],
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
            $this->service->deleteContact($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete contact', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to delete contact')],
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
            $details = $this->service->getContactDetails($id, $this->getEffectiveUserId());
            return new DataResponse($details);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get contact details', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get contact details')],
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
            $summary = $this->service->getBalanceSummary($this->getEffectiveUserId());
            return new DataResponse($summary);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get balances', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get balances')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get transaction IDs that have been shared with contacts.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function sharedTransactionIds(): DataResponse {
        try {
            $statuses = $this->service->getSharedTransactionStatuses($this->getEffectiveUserId());
            return new DataResponse($statuses);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get shared transaction statuses', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get shared transactions')],
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
                $this->getEffectiveUserId(),
                $transactionId,
                $contactId,
                $amount,
                $notes
            );
            return new DataResponse($share->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $this->l->t('This transaction is already shared with this contact')],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to share expense', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to share expense')],
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
            $share = $this->service->splitFiftyFifty($this->getEffectiveUserId(), $transactionId, $contactId, $notes);
            return new DataResponse($share->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $this->l->t('This transaction is already shared with this contact')],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to split 50/50', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to split expense')],
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
            $shares = $this->service->getSharesByTransaction($transactionId, $this->getEffectiveUserId());
            return new DataResponse(array_map(fn($s) => $s->jsonSerialize(), $shares));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get transaction shares', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get shares')],
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
            $share = $this->service->updateExpenseShare($id, $this->getEffectiveUserId(), $amount, $notes);
            return new DataResponse($share->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to update share', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to update share')],
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
            $share = $this->service->markShareSettled($id, $this->getEffectiveUserId());
            return new DataResponse($share->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark share settled', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to mark settled')],
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
            $this->service->deleteExpenseShare($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete share', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to delete share')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // ==================== Settlement Endpoints ====================

    /**
     * Settle selected expense shares.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function settleSelected(array $shareIds, string $date, ?string $notes = null): DataResponse {
        try {
            if (empty($shareIds)) {
                return new DataResponse(
                    ['error' => $this->l->t('Please select at least one expense to settle')],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $settlement = $this->service->settleSelectedShares($this->getEffectiveUserId(), $shareIds, $date, $notes);
            return new DataResponse($settlement->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to settle selected shares', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to settle expenses')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

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
                $this->getEffectiveUserId(),
                $contactId,
                $amount,
                $date,
                $notes
            );
            return new DataResponse($settlement->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record settlement', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to record settlement')],
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
            $settlement = $this->service->settleWithContact($this->getEffectiveUserId(), $contactId, $date, $notes);
            return new DataResponse($settlement->jsonSerialize(), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to settle with contact', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to settle')],
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
            $settlements = $this->service->getSettlements($this->getEffectiveUserId());
            return new DataResponse(array_map(fn($s) => $s->jsonSerialize(), $settlements));
        } catch (\Exception $e) {
            $this->logger->error('Failed to get settlements', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to get settlements')],
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
            $this->service->deleteSettlement($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete settlement', [
                'exception' => $e,
                'userId' => $this->getEffectiveUserId(),
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to delete settlement')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
