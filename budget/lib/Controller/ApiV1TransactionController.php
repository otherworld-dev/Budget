<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Transactions over the public REST API (v1) — the capture half of the
 * surface: read recent activity, and record a new transaction with an
 * optional receipt photo.
 *
 * Editing and deleting are deliberately absent. A capture client only ever
 * appends, and every field it could get wrong is fixable in the web UI, so
 * keeping them out of v1 keeps the contract small enough to actually hold
 * still.
 */
class ApiV1TransactionController extends OCSController {
    use ApiErrorHandlerTrait;
    use SharedAccessTrait;

    /** Page size ceiling. Requests above it are clamped, not rejected. */
    public const MAX_LIMIT = 200;
    public const DEFAULT_LIMIT = 50;

    /** Read by SharedAccessTrait. */
    protected string $userId;

    public function __construct(
        IRequest $request,
        private TransactionService $service,
        private AttachmentService $attachmentService,
        private ValidationService $validationService,
        GranularShareService $granularShareService,
        private IL10N $l,
        ?string $userId,
        LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->setLogger($logger);
        $this->setGranularShareService($granularShareService);
        // Null until the security middleware rejects the request — see
        // ApiV1Controller for why this must not be typed non-null.
        $this->userId = $userId ?? '';
    }

    /**
     * Most recent transactions first, across every account the user can see.
     *
     * @param int|null $accountId Restrict to one account.
     * @param int|null $categoryId Restrict to one category.
     * @param string|null $dateFrom Inclusive lower bound, YYYY-MM-DD.
     * @param string|null $dateTo Inclusive upper bound, YYYY-MM-DD.
     * @param string|null $search Free-text match on description/vendor.
     */
    #[NoAdminRequired]
    public function index(
        ?int $accountId = null,
        ?int $categoryId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): DataResponse {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        foreach (['dateFrom' => $dateFrom, 'dateTo' => $dateTo] as $field => $value) {
            if ($value !== null && !$this->validationService->validateDate($value, $field, false)['valid']) {
                return new DataResponse(
                    ['error' => $this->l->t('Invalid date. Use the YYYY-MM-DD format')],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        try {
            $filters = [
                'accountId' => $accountId,
                'category' => $categoryId !== null ? (string)$categoryId : null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'search' => $search,
                'sort' => 'date',
                'direction' => 'desc',
            ];

            $result = $this->service->findWithFilters(
                $this->userId,
                $filters,
                $limit,
                $offset,
                $this->getEffectiveAccountIds()
            );

            return new DataResponse([
                'transactions' => ApiSerializer::map($result['transactions'], [ApiSerializer::class, 'transaction']),
                'total' => (int)$result['total'],
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transactions'));
        }
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse {
        try {
            $transaction = $this->service->findForAccounts($id, $this->getEffectiveAccountIds());

            return new DataResponse(ApiSerializer::transaction($transaction));
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transaction'));
        }
    }

    /**
     * Record a transaction.
     *
     * @param string $date YYYY-MM-DD. A future date is stored as scheduled.
     * @param float $amount Always positive; $type carries the direction.
     * @param string $type 'debit' (money out) or 'credit' (money in).
     */
    #[NoAdminRequired]
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
        ?string $notes = null,
    ): DataResponse {
        $fields = [
            'description' => $this->validationService->validateDescription($description, true),
            'date' => $this->validationService->validateDate($date, $this->l->t('Date'), true),
        ];
        foreach (['vendor' => $vendor, 'reference' => $reference, 'notes' => $notes] as $name => $value) {
            if ($value !== null) {
                $fields[$name] = match ($name) {
                    'vendor' => $this->validationService->validateVendor($value),
                    'reference' => $this->validationService->validateReference($value),
                    'notes' => $this->validationService->validateNotes($value),
                };
            }
        }

        foreach ($fields as $result) {
            if (!$result['valid']) {
                return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
            }
        }

        if (!in_array($type, ['credit', 'debit'], true)) {
            return new DataResponse(
                ['error' => $this->l->t('Invalid transaction type. Must be credit or debit')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // A shared account still belongs to whoever created it, so the row
            // must be written under the owner's id — writing it under the
            // acting user's id would orphan it from the account's ledger.
            $effectiveUserId = $this->userId;
            if (!in_array($accountId, $this->granularShareService->getOwnAccountIds($this->userId), true)) {
                $this->requireWriteAccess('account', $accountId);
                $effectiveUserId = $this->service->findAccountById($accountId)->getUserId();
            }

            $transaction = $this->service->create(
                $effectiveUserId,
                $accountId,
                $date,
                $fields['description']['sanitized'],
                $amount,
                $type,
                $categoryId,
                isset($fields['vendor']) ? $fields['vendor']['sanitized'] : null,
                isset($fields['reference']) ? $fields['reference']['sanitized'] : null,
                isset($fields['notes']) ? $fields['notes']['sanitized'] : null,
            );

            return new DataResponse(ApiSerializer::transaction($transaction), Http::STATUS_CREATED);
        } catch (DoesNotExistException $e) {
            return $this->notFound($this->l->t('Account not found'));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create transaction'));
        }
    }

    /**
     * Receipts attached to a transaction.
     *
     * Receipts live in the owner's Files, which a share recipient cannot
     * resolve, so these two endpoints are owner-only — a shared transaction
     * returns 404 here even though it is readable through show().
     */
    #[NoAdminRequired]
    public function receipts(int $id): DataResponse {
        try {
            $attachments = $this->attachmentService->listForTransaction($id, $this->userId);

            return new DataResponse(ApiSerializer::map($attachments, [ApiSerializer::class, 'attachment']));
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load attachments'));
        }
    }

    /**
     * Upload a receipt photo as multipart/form-data under the field `file`.
     * The file is stored in the user's own Files under Budget/Receipts/<year>.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function uploadReceipt(int $id): DataResponse {
        $uploadedFile = $this->request->getUploadedFile('file');
        if (!$uploadedFile) {
            return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $attachment = $this->attachmentService->upload($id, $this->userId, $uploadedFile);

            return new DataResponse(ApiSerializer::attachment($attachment), Http::STATUS_CREATED);
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to upload receipt'));
        }
    }

    private function notFound(?string $message = null): DataResponse {
        return new DataResponse(
            ['error' => $message ?? $this->l->t('Transaction not found')],
            Http::STATUS_NOT_FOUND
        );
    }
}
