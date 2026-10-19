<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Recurring income 2.54.0 left due on a receipt it had already booked. The
 * upgrade settles it once (migration 125) and Repair Data lists and settles
 * any found later, a restored backup's included. The rule lives here so the
 * two can't drift apart.
 */
class LegacyIncomeRows {
	/**
	 * How many days before its expected date a receipt is still that
	 * occurrence's: the window RecurringIncomeService matches a credit to an
	 * expected date with (expectedWindowDays()).
	 */
	private const WINDOW_DAYS = [
		'daily' => 1,
		'weekly' => 3,
		'biweekly' => 6,
		'semi-monthly' => 7,
	];
	private const DEFAULT_WINDOW_DAYS = 15;

	private const NOTE_PREFIX = 'Auto-generated from income: ';

	public function __construct(
		private IDBConnection $db,
		private FrequencyCalculator $frequencyCalculator,
	) {
	}

	/**
	 * Incomes received early under 2.54.0 and never moved on (#399).
	 *
	 * 2.54.0's Mark Received worked the next date out from the day the money
	 * came in, without moving past the occurrence it was for, and dated the
	 * credit on that occurrence. Received a day early, the income stayed due
	 * on the date its credit was already booked for, and the next Mark
	 * Received booked the money again. Such an income is active, its account
	 * holds a credit it booked ("Auto-generated from income: <name>") dated
	 * exactly its next expected date and written before that date, and its
	 * last received date is on or before that date, within the income's
	 * window. Settling it moves it on to the following occurrence.
	 *
	 * @param string|null $userId one user's incomes; null for everyone's
	 * @return list<array{incomeId: int, userId: string, name: string, accountId: int, transactionId: int,
	 *                    amount: float, lastReceivedDate: string, nextExpectedDate: string, settledNextExpectedDate: string}>
	 */
	public function stuckEarlyReceipts(?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.user_id', 'i.name', 'i.frequency', 'i.expected_day', 'i.expected_month',
			'i.start_date', 'i.account_id', 'i.last_received_date', 'i.next_expected_date')
			->selectAlias('t.id', 'transaction_id')
			->selectAlias('t.amount', 'transaction_amount')
			->selectAlias('t.date', 'transaction_date')
			->selectAlias('t.notes', 'transaction_notes')
			->selectAlias('t.created_at', 'transaction_created_at')
			->from('budget_recurring_income', 'i')
			->innerJoin('i', 'budget_transactions', 't', $qb->expr()->andX(
				$qb->expr()->eq('t.account_id', 'i.account_id'),
				$qb->expr()->eq('t.date', 'i.next_expected_date')
			))
			->where($qb->expr()->eq('i.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			// 2.54.0 completed a one-time income when it was received
			->andWhere($qb->expr()->neq('i.frequency', $qb->createNamedParameter('one-time')))
			->andWhere($qb->expr()->isNotNull('i.last_received_date'))
			->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('credit')))
			->andWhere($qb->expr()->like('t.notes', $qb->createNamedParameter(self::NOTE_PREFIX . '%')))
			->andWhere($qb->expr()->isNull('t.import_id'))
			->orderBy('i.id', 'ASC')
			->addOrderBy('t.id', 'ASC');
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('i.user_id', $qb->createNamedParameter($userId)));
		}

		$found = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$incomeId = (int)$row['id'];
			if (isset($found[$incomeId]) || !$this->isStuck($row)) {
				continue;
			}
			$expected = self::day($row['next_expected_date']);
			$found[$incomeId] = [
				'incomeId' => $incomeId,
				'userId' => (string)$row['user_id'],
				'name' => (string)$row['name'],
				'accountId' => (int)$row['account_id'],
				'transactionId' => (int)$row['transaction_id'],
				'amount' => (float)$row['transaction_amount'],
				'lastReceivedDate' => self::day($row['last_received_date']),
				'nextExpectedDate' => $expected,
				'settledNextExpectedDate' => $this->frequencyCalculator->calculateNextDueDate(
					(string)$row['frequency'],
					$row['expected_day'] === null ? null : (int)$row['expected_day'],
					$row['expected_month'] === null ? null : (int)$row['expected_month'],
					$expected,
					null,
					true,
					$row['start_date'] === null || $row['start_date'] === '' ? null : self::day($row['start_date'])
				),
			];
		}
		$result->closeCursor();

		return array_values($found);
	}

	/**
	 * Move one income found by stuckEarlyReceipts() on to its next expected
	 * date. Nothing else about it changes, and only while it is still due on
	 * the date it was found on, so a second run, or a receipt or skip in
	 * between, leaves it as it is.
	 *
	 * @param array{incomeId: int, userId: string, name: string, accountId: int, transactionId: int,
	 *              amount: float, lastReceivedDate: string, nextExpectedDate: string, settledNextExpectedDate: string} $stuck
	 * @return bool whether the income moved
	 */
	public function settle(array $stuck): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('budget_recurring_income')
			->set('next_expected_date', $qb->createNamedParameter($stuck['settledNextExpectedDate']))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($stuck['incomeId'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($stuck['userId'])))
			->andWhere($qb->expr()->eq('next_expected_date', $qb->createNamedParameter($stuck['nextExpectedDate'])))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $qb->executeStatement() === 1;
	}

	/**
	 * Every condition, checked on the row itself: the query only narrows the
	 * candidates down.
	 */
	private function isStuck(array $row): bool {
		if ($row['next_expected_date'] === null || $row['last_received_date'] === null || $row['transaction_created_at'] === null) {
			return false;
		}
		$expected = self::day($row['next_expected_date']);
		$received = self::day($row['last_received_date']);
		if (self::day($row['transaction_date']) !== $expected
			|| (string)$row['transaction_notes'] !== self::NOTE_PREFIX . $row['name']
			// Written before its date: booked ahead of the money's own date.
			// Received on the day, or auto-created once it came, 2.54.0 moved
			// the income on itself
			|| self::day($row['transaction_created_at']) >= $expected
			|| $received > $expected) {
			return false;
		}
		$window = self::WINDOW_DAYS[$row['frequency']] ?? self::DEFAULT_WINDOW_DAYS;
		$daysEarly = (int)(new \DateTimeImmutable($received))->diff(new \DateTimeImmutable($expected))->format('%a');
		return $daysEarly <= $window;
	}

	/** A date as Y-m-d, whatever time of day the database returns with it */
	private static function day(mixed $value): string {
		return substr((string)$value, 0, 10);
	}
}
