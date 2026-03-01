<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExchangeRate>
 */
class ExchangeRateMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_exchange_rates', ExchangeRate::class);
    }

    /**
     * Find rate for a specific currency and date.
     */
    public function findByDate(string $currency, string $date): ?ExchangeRate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('currency', $qb->createNamedParameter($currency)))
            ->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all rates for a specific date.
     *
     * @return ExchangeRate[]
     */
    public function findAllByDate(string $date): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('date', $qb->createNamedParameter($date)));

        return $this->findEntities($qb);
    }

    /**
     * Find the most recent rate for a currency.
     */
    public function findLatest(string $currency): ?ExchangeRate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('currency', $qb->createNamedParameter($currency)))
            ->orderBy('date', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find the closest rate to a given date (for fallback).
     * Prefers the most recent rate on or before the given date,
     * falls back to the nearest rate after.
     */
    public function findClosest(string $currency, string $date): ?ExchangeRate {
        // Try on or before the date first
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('currency', $qb->createNamedParameter($currency)))
            ->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($date)))
            ->orderBy('date', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            // Fall through to try after the date
        }

        // Try after the date
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('currency', $qb->createNamedParameter($currency)))
            ->andWhere($qb->expr()->gt('date', $qb->createNamedParameter($date)))
            ->orderBy('date', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Insert or update a rate for a specific currency and date.
     */
    public function upsert(string $currency, string $ratePerEur, string $date, string $source): ExchangeRate {
        $existing = $this->findByDate($currency, $date);

        if ($existing !== null) {
            $existing->setRatePerEur($ratePerEur);
            $existing->setSource($source);
            $existing->setCreatedAt(date('Y-m-d H:i:s'));
            return $this->update($existing);
        }

        $entity = new ExchangeRate();
        $entity->setCurrency($currency);
        $entity->setRatePerEur($ratePerEur);
        $entity->setDate($date);
        $entity->setSource($source);
        $entity->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->insert($entity);
    }

    /**
     * Get all latest rates (most recent date for each currency).
     *
     * @return array<string, ExchangeRate> Keyed by currency code
     */
    public function findAllLatest(): array {
        // Get the most recent date that has rates
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('MAX(date) as max_date'))
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row || !$row['max_date']) {
            return [];
        }

        $rates = $this->findAllByDate($row['max_date']);
        $keyed = [];
        foreach ($rates as $rate) {
            $keyed[$rate->getCurrency()] = $rate;
        }

        return $keyed;
    }

    /**
     * Delete rates older than a given number of days.
     */
    public function deleteOlderThan(int $days): int {
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('date', $qb->createNamedParameter($cutoff)));

        return $qb->executeStatement();
    }
}
