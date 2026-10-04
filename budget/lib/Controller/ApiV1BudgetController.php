<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Budgets over the public REST API (v1). Read-only: a client can see how
 * much of a month's budget is left, and setting budgets stays in the web UI.
 */
class ApiV1BudgetController extends OCSController {
	use ApiErrorHandlerTrait;

	private string $userId;

	public function __construct(
		IRequest $request,
		private BudgetStatusService $service,
		private CurrencyConversionService $conversionService,
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
	 * A month's budget (#767): each budgeted category and the expense
	 * totals, the same figures the web Budget page shows for that month.
	 *
	 * `month` (YYYY-MM) is read by hand, like transactions/recent's `limit`,
	 * so a malformed value is a 400 rather than a type error. Omitted or
	 * empty, it is the budget month running today.
	 */
	#[NoAdminRequired]
	public function status(): DataResponse {
		$month = $this->request->getParam('month');
		if ($month === null || $month === '') {
			$month = null;
		} elseif (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid month format. Use YYYY-MM')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$status = $this->service->forMonth($this->userId, $month);
			// Accounts in other currencies are converted to it, as on the Budget page
			$status['currency'] = $this->conversionService->getBaseCurrency($this->userId);

			return new DataResponse(ApiSerializer::budgetStatus($status));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve effective budgets'));
		}
	}
}
