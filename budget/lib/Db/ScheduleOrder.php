<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The order the Bills, Transfers and Income lists come in (#399): by next
 * date, then by name, with the ones that have no next date (a paid one-time
 * bill, a received one-time income, an ended schedule) last. SQLite and
 * MySQL sort NULL first in ascending order and PostgreSQL last, so on most
 * instances the finished ones topped the list, above the overdue ones.
 */
final class ScheduleOrder {
	public static function byNextDate(IQueryBuilder $qb, string $column): void {
		$qb->orderBy($qb->createFunction('CASE WHEN ' . $qb->getColumnName($column) . ' IS NULL THEN 1 ELSE 0 END'), 'ASC')
			->addOrderBy($column, 'ASC')
			->addOrderBy('name', 'ASC');
	}
}
