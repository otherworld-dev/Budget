<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tags over the public REST API (v1), read only (discussion 420).
 *
 * The caller's own tags: the global ones, which can sit on any transaction,
 * and those in the tag sets of their categories, which can only sit on a
 * transaction in that category. Tags of people who share with the caller are
 * not listed here; they still come with the transactions they sit on.
 */
class ApiV1TagController extends OCSController {
	use ApiErrorHandlerTrait;

	private string $userId;

	public function __construct(
		IRequest $request,
		private TransactionTagService $tagService,
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
	 * Global tags first, then each tag set in its own order. Hidden tags are
	 * listed too, flagged, so a client can leave them out of its pickers and
	 * still name them.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		try {
			return new DataResponse(ApiSerializer::map(
				$this->tagService->tagDetailsForUser($this->userId),
				[ApiSerializer::class, 'tag']
			));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve tags'));
		}
	}
}
