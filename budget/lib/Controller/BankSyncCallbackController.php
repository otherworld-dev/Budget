<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The page a bank sends the browser back to after the user authorized (or
 * refused) access, for every provider that completes its authorization
 * this way (CallbackAuthorizationProviderInterface). Users register this
 * URL with their provider, so it names no provider.
 *
 * Nothing here ever logs or returns the auth code or the state, and nothing
 * is logged at all: Nextcloud appends the request URL, query string
 * included, to every log entry.
 */
class BankSyncCallbackController extends Controller {
	public function __construct(
		IRequest $request,
		private BankSyncService $syncService,
		private AdminSettingService $adminSettings,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		// Nullable: the controller is constructed before the auth middleware
		// runs (see PageController); the route still requires login.
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * A top-level navigation from the bank carries no CSRF token, so the
	 * check is off; the `state` takes its place. It is only matched among the
	 * logged-in user's own pending connections, so a state alone authorizes
	 * nothing. Every failure shows the same generic text: why a state did not
	 * match is nobody's business but the server's.
	 *
	 * Post-Redirect-Get: the bank's redirect is processed once and answered
	 * with a redirect to this route carrying only `result`, which renders the
	 * page. A reload then neither re-sends the spent code (and reports a
	 * failure) nor keeps code and state in the address bar and history.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function callback(
		#[\SensitiveParameter]
		string $state = '',
		#[\SensitiveParameter]
		string $code = '',
		string $error = '',
		string $result = '',
	): Response {
		if ($state === '' && $code === '' && $error === '') {
			return $this->callbackPage($result === 'success');
		}

		$success = false;
		if ($this->adminSettings->isBankSyncEnabled() && $this->userId !== null && $state !== '') {
			if ($error !== '') {
				$this->failAuthorization($this->userId, $state, $error);
			} elseif ($code !== '') {
				$success = $this->completeAuthorization($this->userId, $state, $code);
			}
		}

		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'budget.bankSyncCallback.callback',
			['result' => $success ? 'success' : 'failed']
		));
	}

	private function callbackPage(bool $success): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'bank-sync-callback', [
			'success' => $success,
			'message' => $success
				? $this->l->t('Your bank is connected. You can close this tab, or go back to Budget to choose which accounts to sync.')
				: $this->l->t('The bank connection could not be completed. Please go back to Budget and try again.'),
			'backUrl' => $this->urlGenerator->linkToRoute('budget.page.index') . '#bank-sync',
		]);
	}

	private function completeAuthorization(string $userId, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): bool {
		try {
			$this->syncService->completeCallbackAuthorization($userId, $state, $code);
			return true;
		} catch (\Throwable) {
			// Not logged: Nextcloud adds the request URL, with code and
			// state, to every log entry. The service has kept the failure on
			// the connection (lastError, state cleared) where it matched one.
			return false;
		}
	}

	private function failAuthorization(string $userId, #[\SensitiveParameter] string $state, string $error): void {
		try {
			$this->syncService->failCallbackAuthorization($userId, $state, $error);
		} catch (\Throwable) {
			// Not logged, see completeAuthorization(). A stale state still
			// lets the card offer Re-authorize once it times out.
		}
	}
}
