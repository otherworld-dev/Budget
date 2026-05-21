<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\DebtScenarioService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DebtScenarioController extends Controller {
    private DebtScenarioService $service;
    private IL10N $l;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        DebtScenarioService $service,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->l = $l;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * List all debt scenarios.
     *
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $scenarios = $this->service->findAll($this->userId);
            return new DataResponse(array_values($scenarios));
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve debt scenarios', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to retrieve debt scenarios')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a new debt scenario.
     *
     * @NoAdminRequired
     */
    public function create(
        string $name,
        string $strategy = 'avalanche',
        float $extraPayment = 0,
        float $lumpSum = 0,
        int $lumpSumMonth = 1,
        ?array $selectedDebtIds = null,
        ?array $rateOverrides = null
    ): DataResponse {
        try {
            $scenario = $this->service->create($this->userId, [
                'name' => $name,
                'strategy' => $strategy,
                'extraPayment' => $extraPayment,
                'lumpSum' => $lumpSum,
                'lumpSumMonth' => $lumpSumMonth,
                'selectedDebtIds' => $selectedDebtIds,
                'rateOverrides' => $rateOverrides,
            ]);
            return new DataResponse($scenario, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create debt scenario', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to create debt scenario')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update an existing debt scenario.
     *
     * @NoAdminRequired
     */
    public function update(
        int $id,
        ?string $name = null,
        ?string $strategy = null,
        ?float $extraPayment = null,
        ?float $lumpSum = null,
        ?int $lumpSumMonth = null,
        ?array $selectedDebtIds = null,
        ?array $rateOverrides = null
    ): DataResponse {
        try {
            $params = [];
            if ($name !== null) {
                $params['name'] = $name;
            }
            if ($strategy !== null) {
                $params['strategy'] = $strategy;
            }
            if ($extraPayment !== null) {
                $params['extraPayment'] = $extraPayment;
            }
            if ($lumpSum !== null) {
                $params['lumpSum'] = $lumpSum;
            }
            if ($lumpSumMonth !== null) {
                $params['lumpSumMonth'] = $lumpSumMonth;
            }
            if ($selectedDebtIds !== null) {
                $params['selectedDebtIds'] = $selectedDebtIds;
            }
            if ($rateOverrides !== null) {
                $params['rateOverrides'] = $rateOverrides;
            }

            $scenario = $this->service->update($id, $this->userId, $params);
            return new DataResponse($scenario);
        } catch (DoesNotExistException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Scenario not found')],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to update debt scenario', [
                'exception' => $e,
                'userId' => $this->userId,
                'scenarioId' => $id,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to update debt scenario')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a debt scenario.
     *
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse([]);
        } catch (DoesNotExistException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Scenario not found')],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete debt scenario', [
                'exception' => $e,
                'userId' => $this->userId,
                'scenarioId' => $id,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to delete debt scenario')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Activate a debt scenario.
     *
     * @NoAdminRequired
     */
    public function activate(int $id): DataResponse {
        try {
            $scenario = $this->service->activate($id, $this->userId);
            return new DataResponse($scenario);
        } catch (DoesNotExistException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Scenario not found')],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to activate debt scenario', [
                'exception' => $e,
                'userId' => $this->userId,
                'scenarioId' => $id,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to activate debt scenario')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Calculate the payoff plan for a scenario.
     *
     * @NoAdminRequired
     */
    public function calculate(int $id): DataResponse {
        try {
            $plan = $this->service->calculate($id, $this->userId);
            return new DataResponse($plan);
        } catch (DoesNotExistException $e) {
            return new DataResponse(
                ['error' => $this->l->t('Scenario not found')],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate debt scenario plan', [
                'exception' => $e,
                'userId' => $this->userId,
                'scenarioId' => $id,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to calculate debt scenario plan')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare multiple scenarios by comma-separated IDs.
     *
     * @NoAdminRequired
     */
    public function compare(string $ids): DataResponse {
        try {
            $scenarioIds = array_map('intval', array_filter(explode(',', $ids), 'strlen'));

            if (empty($scenarioIds)) {
                return new DataResponse(
                    ['error' => $this->l->t('No scenario IDs provided')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $results = $this->service->compareScenarios($this->userId, $scenarioIds);
            return new DataResponse($results);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare debt scenarios', [
                'exception' => $e,
                'userId' => $this->userId,
                'ids' => $ids,
            ]);
            return new DataResponse(
                ['error' => $this->l->t('Failed to compare debt scenarios')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
