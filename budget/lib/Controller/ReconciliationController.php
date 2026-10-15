<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ReconciliationConflictException;
use OCA\Budget\Service\ReconciliationService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
	use InputValidationTrait;
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
	 * The user account $id belongs to, after checking this user may use it.
	 *
	 * The service scopes the account and its sessions to the user it is
	 * given, and an account shared with this user belongs to its owner: as
	 * themselves, someone with write access found nothing, and the toast
	 * showed the failed query. Reconciling needs write access; the history
	 * is readable by anyone the account is shared with. An account the user
	 * can't see at all is not found, rather than read-only.
	 *
	 * @throws DoesNotExistException
	 * @throws ReadOnlyShareException
	 */
	private function accountOwner(int $id, bool $write): string {
		$owner = $this->granularShareService->resolveOwner($this->userId, 'account', $id);
		if ($owner === null) {
			throw new DoesNotExistException('Account ' . $id . ' is not accessible to ' . $this->userId);
		}
		if ($write && $owner !== $this->userId) {
			$this->requireWriteAccess('account', $id);
		}
		return $owner;
	}

	/**
	 * A failed action as the client should see it: a refusal from the
	 * service keeps its reason, an account that isn't there is a 404, and
	 * anything else gets $fallback, never the exception's own text (a
	 * failed lookup's message is its SQL).
	 */
	private function failure(\Exception $e, string $fallback): DataResponse {
		if ($e instanceof DoesNotExistException) {
			return $this->handleNotFoundError($e, $this->l->t('Account'));
		}
		if ($e instanceof \InvalidArgumentException) {
			return $this->handleValidationError($e);
		}
		return $this->handleError($e, $fallback);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getSession(int $id): DataResponse {
		try {
			$state = $this->service->getActiveSession($id, $this->accountOwner($id, true));
			return new DataResponse($state ?? ['session' => null]);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to load reconciliation session'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function start(int $id, float $statementBalance, string $statementDate): DataResponse {
		try {
			$state = $this->service->startSession($id, $this->accountOwner($id, true), $statementBalance, $statementDate);
			return new DataResponse($state, Http::STATUS_CREATED);
		} catch (ReconciliationConflictException $e) {
			return new DataResponse([
				'error' => $this->l->t('A reconciliation is already in progress for this account'),
				'existing' => $e->existingState,
			], Http::STATUS_CONFLICT);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to start reconciliation'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function update(int $id, ?float $statementBalance = null, ?string $statementDate = null): DataResponse {
		try {
			$state = $this->service->updateSession($id, $this->accountOwner($id, true), $statementBalance, $statementDate);
			return new DataResponse($state);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to update reconciliation'));
		}
	}

	/**
	 * Tick or untick a batch of transactions.
	 * @NoAdminRequired
	 */
	public function tick(int $id, array $transactionIds, bool $ticked = true): DataResponse {
		try {
			$state = $this->service->tick($id, $this->accountOwner($id, true), $transactionIds, $ticked);
			return new DataResponse($state);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to update reconciliation'));
		}
	}

	/**
	 * Tick everything dated on or before the statement date.
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function tickAll(int $id): DataResponse {
		try {
			$state = $this->service->tickAllUpToStatementDate($id, $this->accountOwner($id, true));
			return new DataResponse($state);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to update reconciliation'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function complete(int $id): DataResponse {
		try {
			$result = $this->service->complete($id, $this->accountOwner($id, true));
			return new DataResponse($result);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to complete reconciliation'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function cancel(int $id): DataResponse {
		try {
			$this->service->cancel($id, $this->accountOwner($id, true));
			return new DataResponse(['status' => 'success']);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to update reconciliation'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function history(int $id, int $limit = 20, int $offset = 0): DataResponse {
		try {
			$history = $this->service->getHistory($id, $this->accountOwner($id, false), $limit, $offset);
			return new DataResponse($history);
		} catch (\Exception $e) {
			return $this->failure($e, $this->l->t('Failed to load reconciliation history'));
		}
	}
}
