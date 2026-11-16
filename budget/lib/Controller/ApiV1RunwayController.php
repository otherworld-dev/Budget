<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Runway\RunwayService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The runway forecast over the public REST API (v1). Read-only, and the
 * same figures as the dashboard tile; choosing accounts, the window and
 * one-offs stays in the web UI.
 */
class ApiV1RunwayController extends OCSController {
	use ApiErrorHandlerTrait;

	private string $userId;

	public function __construct(
		IRequest $request,
		private RunwayService $service,
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
	 * How long the money lasts at the current pace and on budget. Takes no
	 * parameters: the setup is the user's own, saved from the dashboard.
	 */
	#[NoAdminRequired]
	public function show(): DataResponse {
		try {
			return new DataResponse(ApiSerializer::runway($this->service->get($this->userId)));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve the runway'));
		}
	}
}
