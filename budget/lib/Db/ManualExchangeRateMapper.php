<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\CurrencyConversionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Every write here tells CurrencyConversionService, which memoizes each
 * user's manual rates for the request.
 *
 * @template-extends QBMapper<ManualExchangeRate>
 */
class ManualExchangeRateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_manual_rates', ManualExchangeRate::class);
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
	 * Find a manual rate for a specific user and currency.
	 */
	public function findByUserAndCurrency(string $userId, string $currency): ?ManualExchangeRate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('currency', $qb->createNamedParameter(strtoupper($currency))));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Get all manual rates for a user.
	 *
	 * @return ManualExchangeRate[]
	 */
	public function findAllByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('currency', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Insert or update a manual rate for a user and currency.
	 */
	public function upsert(string $userId, string $currency, string $ratePerEur): ManualExchangeRate {
		$existing = $this->findByUserAndCurrency($userId, $currency);

		if ($existing !== null) {
			$existing->setRatePerEur($ratePerEur);
			$existing->setUpdatedAt(date('Y-m-d H:i:s'));
			return $this->update($existing);
		}

		$entity = new ManualExchangeRate();
		$entity->setUserId($userId);
		$entity->setCurrency(strtoupper($currency));
		$entity->setRatePerEur($ratePerEur);
		$entity->setUpdatedAt(date('Y-m-d H:i:s'));

		return $this->insert($entity);
	}

	/**
	 * Remove a manual rate for a user and currency.
	 */
	public function deleteByUserAndCurrency(string $userId, string $currency): void {
		$entity = $this->findByUserAndCurrency($userId, $currency);
		if ($entity !== null) {
			$this->delete($entity);
		}
	}
}
