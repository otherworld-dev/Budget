<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContribution;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Scheduled (recurring) pension contributions (#251). Creates the due
 * contribution — and, when a source account is set, the linked bank transfer
 * (#304) — then advances the schedule.
 */
class PensionRecurringService {
	public function __construct(
		private PensionRecurringContributionMapper $recurringMapper,
		private PensionAccountMapper $pensionMapper,
		private PensionService $pensionService,
		private FrequencyCalculator $frequencyCalculator,
	) {
	}

	/**
	 * @return PensionRecurringContribution[]
	 * @throws DoesNotExistException
	 */
	public function findByPension(int $pensionId, string $userId): array {
		$this->pensionMapper->find($pensionId, $userId); // verify ownership
		return $this->recurringMapper->findByPension($pensionId, $userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function create(
		int $pensionId,
		string $userId,
		float $amount,
		string $frequency,
		?int $sourceAccountId,
		bool $autoPostEnabled,
		string $nextDueDate,
		?string $note = null,
	): PensionRecurringContribution {
		$this->pensionMapper->find($pensionId, $userId); // verify ownership

		$recur = new PensionRecurringContribution();
		$recur->setUserId($userId);
		$recur->setPensionId($pensionId);
		$recur->setAmount($amount);
		$recur->setFrequency($frequency);
		$recur->setSourceAccountId($sourceAccountId);
		$recur->setAutoPostEnabled($autoPostEnabled);
		$recur->setNextDueDate($nextDueDate);
		$recur->setIsActive(true);
		$recur->setNote($note);
		$now = date('Y-m-d H:i:s');
		$recur->setCreatedAt($now);
		$recur->setUpdatedAt($now);

		return $this->recurringMapper->insert($recur);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function update(int $recurId, string $userId, array $fields): PensionRecurringContribution {
		$recur = $this->recurringMapper->find($recurId, $userId);

		if (array_key_exists('amount', $fields) && $fields['amount'] !== null) {
			$recur->setAmount((float)$fields['amount']);
		}
		if (array_key_exists('frequency', $fields) && $fields['frequency'] !== null) {
			$recur->setFrequency((string)$fields['frequency']);
		}
		if (array_key_exists('sourceAccountId', $fields)) {
			$recur->setSourceAccountId($fields['sourceAccountId'] !== null ? (int)$fields['sourceAccountId'] : null);
		}
		if (array_key_exists('autoPostEnabled', $fields) && $fields['autoPostEnabled'] !== null) {
			$recur->setAutoPostEnabled(filter_var($fields['autoPostEnabled'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('nextDueDate', $fields) && $fields['nextDueDate'] !== null) {
			$recur->setNextDueDate((string)$fields['nextDueDate']);
		}
		if (array_key_exists('isActive', $fields) && $fields['isActive'] !== null) {
			$recur->setIsActive(filter_var($fields['isActive'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('note', $fields)) {
			$recur->setNote($fields['note'] !== null ? (string)$fields['note'] : null);
		}

		$recur->setUpdatedAt(date('Y-m-d H:i:s'));
		return $this->recurringMapper->update($recur);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $recurId, string $userId): void {
		$recur = $this->recurringMapper->find($recurId, $userId);
		$this->recurringMapper->delete($recur);
	}

	/**
	 * Auto-post a due schedule (called by the background job). Posts for the
	 * scheduled date and advances. Never throws — returns a result array.
	 *
	 * @return array{success: bool, recurring?: PensionRecurringContribution, message?: string}
	 */
	public function processAutoPost(int $recurId, string $userId): array {
		try {
			$recur = $this->recurringMapper->find($recurId, $userId);
			return ['success' => true, 'recurring' => $this->post($recur, $userId, $recur->getNextDueDate())];
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Manually post a schedule now (the #251 "by hand" path).
	 *
	 * @throws DoesNotExistException
	 */
	public function postNow(int $recurId, string $userId): PensionRecurringContribution {
		$recur = $this->recurringMapper->find($recurId, $userId);
		return $this->post($recur, $userId, date('Y-m-d'));
	}

	/**
	 * Create the contribution (with transfer if a source account is set) for the
	 * given date, then advance next_due_date.
	 */
	private function post(PensionRecurringContribution $recur, string $userId, string $postDate): PensionRecurringContribution {
		$note = $recur->getNote();
		$amount = (float)$recur->getAmount();
		$pensionId = $recur->getPensionId();

		if ($recur->getSourceAccountId() !== null) {
			$this->pensionService->createContributionWithTransfer($pensionId, $userId, $amount, $postDate, (int)$recur->getSourceAccountId(), $note);
		} else {
			$this->pensionService->createContribution($pensionId, $userId, $amount, $postDate, $note);
		}

		$current = $recur->getNextDueDate();
		$dueDay = (int)date('j', strtotime($current));
		$dueMonth = (int)date('n', strtotime($current));
		$next = $this->frequencyCalculator->calculateNextDueDate(
			$recur->getFrequency(),
			$dueDay,
			$dueMonth,
			$current,
			null,
			true
		);

		$recur->setNextDueDate($next);
		$recur->setLastPostedDate($postDate);
		$recur->setUpdatedAt(date('Y-m-d H:i:s'));
		return $this->recurringMapper->update($recur);
	}
}
