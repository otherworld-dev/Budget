<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Enable Banking specifics that don't fit the generic bank-sync API: the page
 * the bank redirects back to, and the user's application keys.
 *
 * Nothing here ever logs or returns the auth code, the state, a session ID
 * or the private key.
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
	 * The bank's redirect after the user authorized (or refused) access.
	 *
	 * A top-level navigation from the bank carries no CSRF token, so the
	 * check is off; the `state` takes its place. It is only matched among the
	 * logged-in user's own pending connections, so a state alone authorizes
	 * nothing. Every failure shows the same generic text: why a state did not
	 * match is nobody's business but the server's.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function callback(string $state = '', string $code = '', string $error = ''): TemplateResponse {
		$success = false;
		if ($this->adminSettings->isBankSyncEnabled() && $this->userId !== null && $state !== '') {
			if ($error !== '') {
				$this->failAuthorization($this->userId, $state, $error);
			} elseif ($code !== '') {
				$success = $this->completeAuthorization($this->userId, $state, $code);
			}
		}

		return new TemplateResponse(Application::APP_ID, 'bank-sync-callback', [
			'success' => $success,
			'message' => $success
				? $this->l->t('Your bank is connected. You can close this tab, or go back to Budget to choose which accounts to sync.')
				: $this->l->t('The bank connection could not be completed. Please go back to Budget and try again.'),
			'backUrl' => $this->urlGenerator->linkToRoute('budget.page.index') . '#bank-sync',
		]);
	}

	private function completeAuthorization(string $userId, string $state, string $code): bool {
		try {
			$this->syncService->completeEnableBankingAuthorization($userId, $state, $code);
			return true;
		} catch (\Exception $e) {
			// Class only: a message could carry the code or a session ID
			$this->logger?->warning('Enable Banking: authorization callback failed: ' . $e::class, ['app' => 'budget']);
			return false;
		}
	}

	private function failAuthorization(string $userId, string $state, string $error): void {
		try {
			$this->syncService->failEnableBankingAuthorization($userId, $state, $error);
		} catch (\Exception $e) {
			$this->logger?->warning('Enable Banking: recording a failed authorization failed: ' . $e::class, ['app' => 'budget']);
		}
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
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function saveSettings(string $applicationId = '', string $privateKey = ''): DataResponse {
		if ($r = $this->requireBankSync()) {
			return $r;
		}

		try {
			$this->keyStore->save((string)$this->userId, $applicationId, $privateKey);
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
			'callbackUrl' => $this->urlGenerator->linkToRouteAbsolute('budget.enableBanking.callback'),
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
