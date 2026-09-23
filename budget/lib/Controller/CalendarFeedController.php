<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\Bill\BillIcsService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Serves the bills ICS feed and manages its access token.
 *
 * The feed route is public (calendar clients can't do Nextcloud auth) and
 * authenticated solely by a 64-char secret token tied to one user. Failed
 * lookups are brute-force throttled.
 */
class CalendarFeedController extends Controller {
	use ApiErrorHandlerTrait;

	private const TOKEN_KEY = 'bills_feed_token';
	private const TOKEN_LENGTH = 64;

	public function __construct(
		IRequest $request,
		private BillIcsService $icsService,
		private SettingService $settingService,
		private SettingMapper $settingMapper,
		private ISecureRandom $random,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private ?string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
	}

	/**
	 * Token-authenticated public ICS feed of the owning user's bills.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'budgetCalendarFeed')]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function billsIcs(string $token = ''): Response {
		if (strlen($token) !== self::TOKEN_LENGTH) {
			return $this->throttledNotFound();
		}

		try {
			$setting = $this->settingMapper->findByKeyValue(self::TOKEN_KEY, $token);
		} catch (DoesNotExistException $e) {
			return $this->throttledNotFound();
		}

		$ics = $this->icsService->generateBillsFeed($setting->getUserId(), 12);

		$response = new DataDownloadResponse($ics, 'bills.ics', 'text/calendar; charset=utf-8');
		$response->cacheFor(3600);
		return $response;
	}

	/**
	 * Get (or lazily create) the current user's feed token and URLs.
	 * @NoAdminRequired
	 */
	public function info(): DataResponse {
		try {
			$token = $this->settingService->get($this->userId, self::TOKEN_KEY);
			if ($token === null || strlen($token) !== self::TOKEN_LENGTH) {
				$token = $this->generateToken();
			}
			return new DataResponse($this->buildUrls($token));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to get calendar feed'));
		}
	}

	/**
	 * Rotate the feed token (invalidates previously shared URLs).
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function regenerate(): DataResponse {
		try {
			$token = $this->generateToken();
			return new DataResponse($this->buildUrls($token));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to regenerate calendar feed token'));
		}
	}

	private function generateToken(): string {
		$token = $this->random->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->settingService->set($this->userId, self::TOKEN_KEY, $token);
		return $token;
	}

	private function buildUrls(string $token): array {
		$url = $this->urlGenerator->linkToRouteAbsolute('budget.calendarFeed.billsIcs') . '?token=' . $token;
		return [
			'url' => $url,
			'webcalUrl' => preg_replace('/^https?:/', 'webcal:', $url),
		];
	}

	private function throttledNotFound(): Response {
		$response = new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => 'budgetCalendarFeed']);
		return $response;
	}
}
