<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ReconciliationConflictException;
use OCA\Budget\Service\ReconciliationService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Statement reconciliation sessions for an account.
 */
class ReconciliationController extends Controller {
    use ApiErrorHandlerTrait;
    use SharedAccessTrait;

    protected string $userId;

    public function __construct(
        IRequest $request,
        private ReconciliationService $service,
        ValidationService $validationService,
        GranularShareService $granularShareService,
        private IL10N $l,
        string $userId,
        LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * @NoAdminRequired
     */
    public function getSession(int $id): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $state = $this->service->getActiveSession($id, $this->getEffectiveUserId());
            return new DataResponse($state ?? ['session' => null]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load reconciliation session'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function start(int $id, float $statementBalance, string $statementDate): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $state = $this->service->startSession($id, $this->getEffectiveUserId(), $statementBalance, $statementDate);
            return new DataResponse($state, Http::STATUS_CREATED);
        } catch (ReconciliationConflictException $e) {
            return new DataResponse([
                'error' => $this->l->t('A reconciliation is already in progress for this account'),
                'existing' => $e->existingState,
            ], Http::STATUS_CONFLICT);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, ?float $statementBalance = null, ?string $statementDate = null): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $state = $this->service->updateSession($id, $this->getEffectiveUserId(), $statementBalance, $statementDate);
            return new DataResponse($state);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * Tick or untick a batch of transactions.
     * @NoAdminRequired
     */
    public function tick(int $id, array $transactionIds, bool $ticked = true): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $state = $this->service->tick($id, $this->getEffectiveUserId(), $transactionIds, $ticked);
            return new DataResponse($state);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function complete(int $id): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $result = $this->service->complete($id, $this->getEffectiveUserId());
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function cancel(int $id): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $this->service->cancel($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function history(int $id, int $limit = 20, int $offset = 0): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $history = $this->service->getHistory($id, $this->getEffectiveUserId(), $limit, $offset);
            return new DataResponse($history);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load reconciliation history'));
        }
    }
}
