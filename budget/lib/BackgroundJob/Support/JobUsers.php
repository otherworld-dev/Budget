<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The users a background job visits: the distinct user_id values of one of
 * the app's tables, optionally narrowed to rows with given column values.
 *
 * One place for the enumeration the nightly jobs each used to spell out for
 * themselves. Lives outside lib/BackgroundJob/ proper because it is not a
 * job: every class directly in that folder must be registered in info.xml.
 */
class JobUsers {
    public function __construct(
        private IDBConnection $db
    ) {
    }

    /**
     * Distinct user ids in $table, sorted, keeping only rows whose columns
     * equal $equals (a bool compares as a boolean column).
     *
     * @param array<string, bool|int|string> $equals column => required value
     * @return string[]
     */
    public function from(string $table, array $equals = []): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')->from($table);
        foreach ($equals as $column => $value) {
            $type = match (true) {
                is_bool($value) => IQueryBuilder::PARAM_BOOL,
                is_int($value) => IQueryBuilder::PARAM_INT,
                default => IQueryBuilder::PARAM_STR,
            };
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)));
        }

        return $this->fetchUserIds($qb);
    }

    /**
     * Everyone who owns an account: everyone who uses the app.
     *
     * @return string[]
     */
    public function accountOwners(): array {
        return $this->from('budget_accounts');
    }

    /**
     * Users with any of $keys set to 'true' in their budget settings.
     *
     * @return string[]
     */
    public function withSettingEnabled(string ...$keys): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('budget_settings')
            ->where($qb->expr()->in('key', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('value', $qb->createNamedParameter('true')));

        return $this->fetchUserIds($qb);
    }

    /**
     * The users in any of the lists, each once, sorted.
     *
     * @param string[] ...$lists
     * @return string[]
     */
    public static function union(array ...$lists): array {
        $all = array_values(array_unique(array_merge([], ...$lists)));
        sort($all, SORT_STRING);
        return $all;
    }

    /**
     * @return string[]
     */
    private function fetchUserIds(IQueryBuilder $qb): array {
        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return self::union($userIds);
    }
}
