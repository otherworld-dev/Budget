<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeys;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Enable Banking specifics that don't fit the generic bank-sync API: the
 * user's application keys. The bank's redirect goes to the provider-neutral
 * BankSyncCallbackController.
 *
 * Nothing here ever logs or returns a session ID or the private key.
 */
class EnableBankingController extends Controller {
	use ApiErrorHandlerTrait;

	public function __construct(
		IRequest $request,
		private BankSyncService $syncService,
		private EnableBankingKeyStore $keyStore,
		private AdminSettingService $adminSettings,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		LoggerInterface $logger,
		// Nullable: the controller is constructed before the auth middleware
		// runs (see PageController); the routes still require login.
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
	}

	/**
	 * Whether keys are stored, the application ID, and the callback URL the
	 * user must register in their Enable Banking application. Never the key.
	 *
	 * @NoAdminRequired
	 */
	public function settings(): DataResponse {
		if ($r = $this->requireBankSync()) {
			return $r;
		}
		return new DataResponse($this->describe());
	}

	/**
	 * Store or replace the user's application ID and private key. Every
	 * Enable Banking connection of the user uses the new keys from then on.
	 *
	 * The private key is read from the request rather than taken as a method
	 * argument: on PHP 8.1 #[\SensitiveParameter] is ignored, and this frame
	 * would carry the PEM in the trace of any exception logged below it.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function saveSettings(string $applicationId = ''): DataResponse {
		if ($r = $this->requireBankSync()) {
			return $r;
		}

		try {
			$privateKey = $this->request->getParam('privateKey', '');
			$keys = new EnableBankingKeys($applicationId, is_string($privateKey) ? $privateKey : '');
			$this->keyStore->save((string)$this->userId, $keys);
		} catch (\InvalidArgumentException $e) {
			$message = $e->getMessage() === 'Application ID is required'
				? $this->l->t('Application ID is required')
				: $this->l->t('Invalid private key');
			return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to save Enable Banking keys'));
		}

		return new DataResponse($this->describe());
	}

	/**
	 * Remove the user's keys. Refused while an Enable Banking connection
	 * still exists: it could neither sync nor be revoked at the bank without
	 * them.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function deleteSettings(): DataResponse {
		if ($r = $this->requireBankSync()) {
			return $r;
		}

		$userId = (string)$this->userId;
		foreach ($this->syncService->getConnections($userId) as $entry) {
			if ($entry['connection']->getProvider() === 'enablebanking') {
				return new DataResponse(
					['error' => $this->l->t('Disconnect all Enable Banking connections before removing the keys')],
					Http::STATUS_CONFLICT
				);
			}
		}

		$this->keyStore->delete($userId);
		return new DataResponse($this->describe());
	}

	/** @return array{hasKeys: bool, applicationId: ?string, callbackUrl: string} */
	private function describe(): array {
		return $this->keyStore->describe((string)$this->userId) + [
			'callbackUrl' => $this->urlGenerator->linkToRouteAbsolute('budget.bankSyncCallback.callback'),
		];
	}

	/** Same gate as every other bank-sync endpoint (BankSyncController). */
	private function requireBankSync(): ?DataResponse {
		if (!$this->adminSettings->isBankSyncEnabled()) {
			return new DataResponse(
				['error' => $this->l->t('Bank sync is disabled by the administrator')],
				Http::STATUS_FORBIDDEN
			);
		}
		return null;
	}
}
