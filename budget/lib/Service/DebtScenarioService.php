<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\DebtScenario;
use OCA\Budget\Db\DebtScenarioMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

class DebtScenarioService {
    private DebtScenarioMapper $mapper;
    private DebtPayoffService $payoffService;
    private LoggerInterface $logger;

    public function __construct(
        DebtScenarioMapper $mapper,
        DebtPayoffService $payoffService,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->payoffService = $payoffService;
        $this->logger = $logger;
    }

    /**
     * @return DebtScenario[]
     */
    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): DebtScenario {
        return $this->mapper->find($id, $userId);
    }

    public function create(string $userId, array $params): DebtScenario {
        $summary = $this->payoffService->getSummary($userId);

        $scenario = new DebtScenario();
        $scenario->setUserId($userId);
        $scenario->setName($params['name']);
        $scenario->setStrategy($params['strategy'] ?? 'avalanche');
        $scenario->setExtraPayment((float) ($params['extraPayment'] ?? 0));
        $scenario->setLumpSum((float) ($params['lumpSum'] ?? 0));
        $scenario->setLumpSumMonth((int) ($params['lumpSumMonth'] ?? 1));
        $scenario->setSelectedDebtIds(
            isset($params['selectedDebtIds']) && $params['selectedDebtIds'] !== null
                ? json_encode($params['selectedDebtIds'])
                : null
        );
        $scenario->setRateOverrides(
            isset($params['rateOverrides']) && $params['rateOverrides'] !== null
                ? json_encode($params['rateOverrides'])
                : null
        );
        $scenario->setIsActive(false);
        $scenario->setOriginalTotalDebt((float) ($summary['totalBalance'] ?? 0));

        $now = date('Y-m-d H:i:s');
        $scenario->setCreatedAt($now);
        $scenario->setUpdatedAt($now);

        return $this->mapper->insert($scenario);
    }

    /**
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $params): DebtScenario {
        $scenario = $this->mapper->find($id, $userId);

        if (array_key_exists('name', $params) && $params['name'] !== null) {
            $scenario->setName($params['name']);
        }
        if (array_key_exists('strategy', $params) && $params['strategy'] !== null) {
            $scenario->setStrategy($params['strategy']);
        }
        if (array_key_exists('extraPayment', $params)) {
            $scenario->setExtraPayment((float) ($params['extraPayment'] ?? 0));
        }
        if (array_key_exists('lumpSum', $params)) {
            $scenario->setLumpSum((float) ($params['lumpSum'] ?? 0));
        }
        if (array_key_exists('lumpSumMonth', $params)) {
            $scenario->setLumpSumMonth((int) ($params['lumpSumMonth'] ?? 1));
        }
        if (array_key_exists('selectedDebtIds', $params)) {
            $scenario->setSelectedDebtIds(
                $params['selectedDebtIds'] !== null
                    ? json_encode($params['selectedDebtIds'])
                    : null
            );
        }
        if (array_key_exists('rateOverrides', $params)) {
            $scenario->setRateOverrides(
                $params['rateOverrides'] !== null
                    ? json_encode($params['rateOverrides'])
                    : null
            );
        }

        $scenario->setUpdatedAt(date('Y-m-d H:i:s'));

        return $this->mapper->update($scenario);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $scenario = $this->mapper->find($id, $userId);
        $this->mapper->delete($scenario);
    }

    /**
     * Activate a scenario, deactivating all others first. Re-snapshots the current total debt.
     *
     * @throws DoesNotExistException
     */
    public function activate(int $id, string $userId): DebtScenario {
        $this->mapper->deactivateAll($userId);

        $scenario = $this->mapper->find($id, $userId);

        $summary = $this->payoffService->getSummary($userId);
        $scenario->setOriginalTotalDebt((float) ($summary['totalBalance'] ?? 0));
        $scenario->setIsActive(true);
        $scenario->setUpdatedAt(date('Y-m-d H:i:s'));

        return $this->mapper->update($scenario);
    }

    /**
     * Calculate the payoff plan for a scenario.
     *
     * @throws DoesNotExistException
     */
    public function calculate(int $id, string $userId): array {
        $scenario = $this->mapper->find($id, $userId);

        $selectedDebtIds = $scenario->getParsedSelectedDebtIds();
        $rateOverrides = $scenario->getParsedRateOverrides();

        return $this->payoffService->calculatePayoffPlan(
            $userId,
            $scenario->getStrategy(),
            $scenario->getExtraPayment() ?: null,
            $selectedDebtIds !== [] ? $selectedDebtIds : null,
            $scenario->getLumpSum(),
            $scenario->getLumpSumMonth(),
            $rateOverrides !== [] ? $rateOverrides : null
        );
    }

    /**
     * Compare multiple scenarios by ID.
     *
     * @param int[] $scenarioIds
     * @return array<int, array{scenario: DebtScenario, plan: array}>
     */
    public function compareScenarios(string $userId, array $scenarioIds): array {
        $results = [];
        foreach ($scenarioIds as $scenarioId) {
            try {
                $scenario = $this->mapper->find((int) $scenarioId, $userId);
                $plan = $this->calculate((int) $scenarioId, $userId);
                $results[] = [
                    'scenario' => $scenario,
                    'plan' => $plan,
                ];
            } catch (DoesNotExistException $e) {
                $this->logger->warning('Scenario not found during comparison', [
                    'scenarioId' => $scenarioId,
                    'userId' => $userId,
                ]);
            }
        }
        return $results;
    }
}
