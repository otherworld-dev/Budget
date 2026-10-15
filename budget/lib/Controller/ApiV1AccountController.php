<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Accounts over the public REST API (v1). Read-only: account setup stays in
 * the web UI, so a capture client (or an automation) can pick a destination
 * account and read balances but never create or reshape them.
 */
class ApiV1AccountController extends OCSController {
	use ApiErrorHandlerTrait;

	private string $userId;

	public function __construct(
		IRequest $request,
		private AccountService $service,
		private IL10N $l,
		?string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
		// Null until the security middleware rejects the request — see
		// ApiV1Controller for why this must not be typed non-null.
		$this->userId = $userId ?? '';
	}

	/**
	 * The user's own accounts plus every account shared with them, each
	 * flagged so a client can tell them apart, and every balance adjusted to
	 * today and converted the same way whoever owns the account.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		try {
			$accounts = array_merge(
				$this->service->findAllWithCurrentBalances($this->userId),
				$this->service->findSharedWithCurrentBalances($this->userId)
			);

			return new DataResponse(ApiSerializer::map($accounts, [ApiSerializer::class, 'account']));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve accounts'));
		}
	}
}
