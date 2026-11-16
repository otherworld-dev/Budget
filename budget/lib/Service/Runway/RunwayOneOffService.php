<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

use OCA\Budget\Db\RunwayOneOff;
use OCA\Budget\Db\RunwayOneOffMapper;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Planned one-offs for the runway forecast. Input is checked with the same
 * ValidationService the transaction forms use, so a one-off accepts what a
 * transaction would, with the extra rules that it is non-zero and has at
 * most two decimals.
 */
class RunwayOneOffService {
	public function __construct(
		private RunwayOneOffMapper $mapper,
		private ValidationService $validation,
		private ITimeFactory $time,
		private IL10N $l,
	) {
	}

	/**
	 * @return RunwayOneOff[] earliest first
	 */
	public function list(string $userId): array {
		return $this->mapper->findAll($userId);
	}

	/**
	 * @throws \InvalidArgumentException on invalid input
	 */
	public function create(string $userId, string $description, string $amount, string $date): RunwayOneOff {
		$oneOff = new RunwayOneOff();
		$oneOff->setUserId($userId);
		$oneOff->setDescription($this->checkDescription($description));
		$oneOff->setAmount($this->checkAmount($amount));
		$oneOff->setDate($this->checkDate($date));
		$oneOff->setCreatedAt($this->now());

		return $this->mapper->insert($oneOff);
	}

	/**
	 * Only the keys present in $changes (description, amount, date) are touched.
	 *
	 * @throws \InvalidArgumentException on invalid input
	 * @throws DoesNotExistException when the id isn't this user's
	 */
	public function update(string $userId, int $id, array $changes): RunwayOneOff {
		$oneOff = $this->mapper->find($id, $userId);

		if (array_key_exists('description', $changes)) {
			$oneOff->setDescription($this->checkDescription((string)$changes['description']));
		}
		if (array_key_exists('amount', $changes)) {
			$oneOff->setAmount($this->checkAmount((string)$changes['amount']));
		}
		if (array_key_exists('date', $changes)) {
			$oneOff->setDate($this->checkDate((string)$changes['date']));
		}
		$oneOff->setUpdatedAt($this->now());

		return $this->mapper->update($oneOff);
	}

	/**
	 * @throws DoesNotExistException when the id isn't this user's
	 */
	public function delete(string $userId, int $id): void {
		$this->mapper->delete($this->mapper->find($id, $userId));
	}

	private function checkDescription(string $description): string {
		$result = $this->validation->validateStringLength($description, $this->l->t('Description'), 255, 1, true);
		if (!$result['valid']) {
			throw new \InvalidArgumentException($result['error']);
		}

		return $result['sanitized'];
	}

	/**
	 * Signed, non-zero, at most two decimals, stored as a plain decimal
	 * string. A decimal comma ("864,27") is not numeric to the shared
	 * validator, so it is refused rather than read as 86427.
	 */
	private function checkAmount(string $amount): string {
		$amount = trim($amount);
		$result = $this->validation->validateAmount($amount, $this->l->t('Amount'));
		if (!$result['valid']) {
			throw new \InvalidArgumentException($result['error']);
		}
		if (preg_match('/^[+-]?\d+(\.\d{1,2})?$/', $amount) !== 1) {
			throw new \InvalidArgumentException($this->l->t('Amount must have at most two decimal places'));
		}
		$normalised = MoneyCalculator::add($amount, '0');
		if (MoneyCalculator::compare($normalised, '0') === 0) {
			throw new \InvalidArgumentException($this->l->t('Amount cannot be zero'));
		}

		return $normalised;
	}

	private function checkDate(string $date): string {
		$result = $this->validation->validateDate($date, $this->l->t('Date'), true);
		if (!$result['valid']) {
			throw new \InvalidArgumentException($result['error']);
		}

		return $result['formatted'];
	}

	private function now(): string {
		return date('Y-m-d H:i:s', $this->time->getTime());
	}
}
