<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\DebtPayoffService;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DebtController extends Controller {
    use SharedAccessTrait;

    private DebtPayoffService $service;
    private IL10N $l;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        DebtPayoffService $service,
        ShareService $shareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->l = $l;
        $this->userId = $userId;
        $this->logger = $logger;
        $this->setShareService($shareService);
    }

    /**
     * Get all debt accounts.
     *
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $debts = $this->service->getDebts($this->getEffectiveUserId());
            return new DataResponse(array_values($debts));
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve debts', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to retrieve debts')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get debt summary.
     *
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getSummary($this->getEffectiveUserId());
            return new DataResponse($summary);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve debt summary', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to retrieve debt summary')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Calculate payoff plan with specified strategy.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function payoffPlan(string $strategy = 'avalanche', ?float $extraPayment = null): DataResponse {
        try {
            // Validate strategy
            if (!in_array($strategy, ['avalanche', 'snowball'], true)) {
                return new DataResponse(
                    ['error' => $this->l->t('Invalid strategy. Must be "avalanche" or "snowball"')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate extra payment
            if ($extraPayment !== null && $extraPayment < 0) {
                return new DataResponse(
                    ['error' => $this->l->t('Extra payment cannot be negative')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $plan = $this->service->calculatePayoffPlan($this->getEffectiveUserId(), $strategy, $extraPayment);
            return new DataResponse($plan);
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate payoff plan', [
                'exception' => $e,
                'userId' => $this->userId,
                'strategy' => $strategy,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to calculate payoff plan')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare avalanche and snowball strategies.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compare(?float $extraPayment = null): DataResponse {
        try {
            // Validate extra payment
            if ($extraPayment !== null && $extraPayment < 0) {
                return new DataResponse(
                    ['error' => $this->l->t('Extra payment cannot be negative')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $comparison = $this->service->compareStrategies($this->getEffectiveUserId(), $extraPayment);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare strategies', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to compare strategies')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
