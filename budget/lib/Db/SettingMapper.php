<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\CurrencyConversionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Every write here tells CurrencyConversionService, which memoizes each
 * user's base currency for the request.
 *
 * @template-extends QBMapper<Setting>
 */
class SettingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_settings', Setting::class);
	}

	public function insert(Entity $entity): Entity {
		CurrencyConversionService::userDataChanged();
		return parent::insert($entity);
	}

	public function update(Entity $entity): Entity {
		CurrencyConversionService::userDataChanged();
		return parent::update($entity);
	}

	public function delete(Entity $entity): Entity {
		CurrencyConversionService::userDataChanged();
		return parent::delete($entity);
	}

	/**
	 * Find all settings for a user
	 *
	 * @param string $userId
	 * @return Setting[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}

	/**
	 * Find a specific setting by key
	 *
	 * @param string $userId
	 * @param string $key
	 * @return Setting
	 * @throws DoesNotExistException
	 */
	public function findByKey(string $userId, string $key): Setting {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Reverse lookup: find the setting row holding a given key/value pair —
	 * used to resolve the owning user of a calendar-feed token.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByKeyValue(string $key, string $value): Setting {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Delete a setting by key
	 *
	 * @param string $userId
	 * @param string $key
	 * @return int Number of deleted rows
	 */
	public function deleteByKey(string $userId, string $key): int {
		CurrencyConversionService::userDataChanged();
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all settings for a user
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		CurrencyConversionService::userDataChanged();
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
