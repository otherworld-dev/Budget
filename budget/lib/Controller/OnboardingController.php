<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\OnboardingService;
use OCA\Budget\Service\SampleDataService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The dashboard's first-run checklist and its "Try with sample data".
 */
class OnboardingController extends Controller {
	use ApiErrorHandlerTrait;

	public function __construct(
		IRequest $request,
		private OnboardingService $onboardingService,
		private SampleDataService $sampleDataService,
		private AuditService $auditService,
		private IL10N $l,
		private string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
	}

	/**
	 * Whether to show the checklist, and which of its steps are done.
	 *
	 * @NoAdminRequired
	 */
	public function state(): DataResponse {
		try {
			return new DataResponse($this->onboardingService->getState($this->userId));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load the getting started checklist'));
		}
	}

	/**
	 * Hide the checklist for good.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function dismiss(): DataResponse {
		try {
			$this->onboardingService->dismiss($this->userId);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to hide the getting started checklist'));
		}
	}

	/**
	 * Fill an empty budget with sample data. Refused (409) once the user has
	 * an account of their own, so it can never mix with real data.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 3, period: 300)]
	public function loadSampleData(): DataResponse {
		try {
			if ($this->sampleDataService->isLoaded($this->userId) || $this->sampleDataService->hasData($this->userId)) {
				return new DataResponse([
					'error' => $this->l->t('Sample data can only be added to an empty budget.'),
				], Http::STATUS_CONFLICT);
			}

			$counts = $this->sampleDataService->loadForUser($this->userId);
			$this->auditService->log($this->userId, 'sample_data_loaded', 'setup', 0, $counts);

			return new DataResponse(['success' => true, 'created' => $counts], Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to add the sample data'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Remove the sample data, and everything else in the user's budget,
	 * through the factory reset. Only while sample data is loaded, and only
	 * with confirmed=true.
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 3, period: 300)]
	public function clearSampleData(): DataResponse {
		try {
			if (!$this->request->getParam('confirmed', false)) {
				return new DataResponse([
					'error' => $this->l->t('Clearing the sample data requires confirmed=true. It deletes everything in your budget.'),
				], Http::STATUS_BAD_REQUEST);
			}
			if (!$this->sampleDataService->isLoaded($this->userId)) {
				return new DataResponse([
					'error' => $this->l->t('No sample data is loaded.'),
				], Http::STATUS_CONFLICT);
			}

			$counts = $this->sampleDataService->clearForUser($this->userId);
			$this->auditService->log($this->userId, 'sample_data_cleared', 'setup', 0, ['deletedCounts' => $counts]);

			return new DataResponse(['success' => true, 'deletedCounts' => $counts]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to clear the sample data'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
