<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\DebtScenario;
use OCA\Budget\Db\DebtScenarioMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractCrudService<DebtScenario>
 */
class DebtScenarioService extends AbstractCrudService {
	private DebtPayoffService $payoffService;
	private LoggerInterface $logger;

	public function __construct(
		DebtScenarioMapper $mapper,
		DebtPayoffService $payoffService,
		LoggerInterface $logger,
	) {
		$this->mapper = $mapper;
		$this->payoffService = $payoffService;
		$this->logger = $logger;
	}

	public function create(string $userId, array $params): DebtScenario {
		$scenario = new DebtScenario();
		$scenario->setUserId($userId);
		$scenario->setName($params['name']);
		$scenario->setStrategy($params['strategy'] ?? 'avalanche');
		$scenario->setExtraPayment((float)($params['extraPayment'] ?? 0));
		$scenario->setLumpSum((float)($params['lumpSum'] ?? 0));
		$scenario->setLumpSumMonth((int)($params['lumpSumMonth'] ?? 1));
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

		// Snapshot current total debt for progress tracking
		if (!empty($params['selectedDebtIds'])) {
			$debts = $this->payoffService->getDebts($userId);
			$total = 0;
			foreach ($debts as $debt) {
				if (in_array($debt->getId(), $params['selectedDebtIds'])) {
					$total += abs((float)$debt->getBalance());
				}
			}
			$scenario->setOriginalTotalDebt($total);
		} else {
			$summary = $this->payoffService->getSummary($userId);
			$scenario->setOriginalTotalDebt(abs($summary['totalBalance']));
		}

		$this->setTimestamps($scenario, true);

		return $this->mapper->insert($scenario);
	}

	protected function beforeUpdate(Entity $entity, array $updates, string $userId): void {
		// JSON-encode array fields before applyUpdates sets them
	}

	/**
	 * Override update to handle JSON encoding of array fields.
	 */
	public function update(int $id, string $userId, array $updates): Entity {
		// Pre-process JSON fields
		if (array_key_exists('selectedDebtIds', $updates)) {
			$updates['selectedDebtIds'] = $updates['selectedDebtIds'] !== null
				? json_encode($updates['selectedDebtIds'])
				: null;
		}
		if (array_key_exists('rateOverrides', $updates)) {
			$updates['rateOverrides'] = $updates['rateOverrides'] !== null
				? json_encode($updates['rateOverrides'])
				: null;
		}

		return parent::update($id, $userId, $updates);
	}

	/**
	 * Activate a scenario, deactivating all others first. Re-snapshots the current total debt.
	 *
	 * @throws DoesNotExistException
	 */
	public function activate(int $id, string $userId): DebtScenario {
		$this->mapper->deactivateAll($userId);

		$scenario = $this->mapper->find($id, $userId);

		// Snapshot current total debt, respecting selectedDebtIds if set
		$parsedIds = $scenario->getParsedSelectedDebtIds();
		if (!empty($parsedIds)) {
			$debts = $this->payoffService->getDebts($userId);
			$total = 0;
			foreach ($debts as $debt) {
				if (in_array($debt->getId(), $parsedIds)) {
					$total += abs((float)$debt->getBalance());
				}
			}
			$scenario->setOriginalTotalDebt($total);
		} else {
			$summary = $this->payoffService->getSummary($userId);
			$scenario->setOriginalTotalDebt(abs($summary['totalBalance']));
		}
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
				$scenario = $this->mapper->find((int)$scenarioId, $userId);
				$plan = $this->calculate((int)$scenarioId, $userId);
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
