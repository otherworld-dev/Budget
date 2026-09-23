<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\SavedReport;
use OCA\Budget\Db\SavedReportMapper;
use OCP\IL10N;

/**
 * Saved report configurations (#299): create, list, update and delete named,
 * re-runnable report definitions for a user.
 */
class SavedReportService {
	private const MAX_NAME_LENGTH = 100;
	private const MAX_CONFIG_BYTES = 8000;

	public function __construct(
		private SavedReportMapper $mapper,
		private IL10N $l,
	) {
	}

	/**
	 * @return SavedReport[]
	 */
	public function getAll(string $userId): array {
		return $this->mapper->findAllByUser($userId);
	}

	public function create(string $userId, string $name, array $config): SavedReport {
		$name = $this->validateName($userId, $name);

		$report = new SavedReport();
		$report->setUserId($userId);
		$report->setName($name);
		$report->setConfig($this->encodeConfig($config));
		$now = date('Y-m-d H:i:s');
		$report->setCreatedAt($now);
		$report->setUpdatedAt($now);

		return $this->mapper->insert($report);
	}

	public function update(int $id, string $userId, ?string $name = null, ?array $config = null): SavedReport {
		$report = $this->find($id, $userId);

		if ($name !== null) {
			$report->setName($this->validateName($userId, $name, $id));
		}
		if ($config !== null) {
			$report->setConfig($this->encodeConfig($config));
		}
		$report->setUpdatedAt(date('Y-m-d H:i:s'));

		return $this->mapper->update($report);
	}

	public function delete(int $id, string $userId): void {
		$report = $this->find($id, $userId);
		$this->mapper->delete($report);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	private function find(int $id, string $userId): SavedReport {
		return $this->mapper->findByIdForUser($id, $userId);
	}

	private function validateName(string $userId, string $name, ?int $excludeId = null): string {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException($this->l->t('Please enter a name for the report'));
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			$name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
		}
		if ($this->mapper->existsByName($userId, $name, $excludeId)) {
			throw new \InvalidArgumentException($this->l->t('A saved report with this name already exists'));
		}
		return $name;
	}

	private function encodeConfig(array $config): string {
		$json = json_encode($config);
		if ($json === false || strlen($json) > self::MAX_CONFIG_BYTES) {
			throw new \InvalidArgumentException($this->l->t('The report configuration is invalid or too large'));
		}
		return $json;
	}
}
