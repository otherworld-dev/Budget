<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ImportFolder>
 */
class ImportFolderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_import_folders', ImportFolder::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): ImportFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return ImportFolder[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The user's folders that are switched on. NULL counts as on: the column
	 * has a default, but a restored row may not carry it.
	 *
	 * @return ImportFolder[]
	 */
	public function findEnabled(string $userId): array {
		return array_values(array_filter(
			$this->findAll($userId),
			static fn (ImportFolder $folder): bool => $folder->isEnabled()
		));
	}

	/**
	 * Switched-on folders importing into an account, whoever's they are.
	 * Guards closing the account.
	 *
	 * @return ImportFolder[]
	 */
	public function findEnabledByAccount(int $accountId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');

		return array_values(array_filter(
			$this->findEntities($qb),
			static fn (ImportFolder $folder): bool => $folder->isEnabled()
		));
	}

	public function deleteByAccount(int $accountId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByTemplate(int $templateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
