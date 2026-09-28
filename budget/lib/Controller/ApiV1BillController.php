<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\UpcomingBillsService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Bills over the public REST API (v1). Read-only: marking a bill paid and
 * setting one up stay in the web UI.
 */
class ApiV1BillController extends OCSController {
	use ApiErrorHandlerTrait;

	public const DEFAULT_DAYS = 14;
	public const MAX_DAYS = 90;

	private string $userId;

	public function __construct(
		IRequest $request,
		private UpcomingBillsService $service,
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
	 * Bills overdue or due in the next `days` days (#767), the user's own
	 * and those shared with them. `days` is read by hand, like
	 * transactions/recent's `limit`: a nonsense value gets the default, and
	 * anything else is clamped to 1-90, never a 500.
	 */
	#[NoAdminRequired]
	public function upcoming(): DataResponse {
		$raw = $this->request->getParam('days');
		$days = is_numeric($raw) ? (int)$raw : self::DEFAULT_DAYS;
		$days = max(1, min($days, self::MAX_DAYS));

		try {
			return new DataResponse([
				'days' => $days,
				'bills' => ApiSerializer::map($this->service->upcoming($this->userId, $days), [ApiSerializer::class, 'bill']),
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve upcoming bills'));
		}
	}
}
