<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCP\IL10N;

/**
 * A promotional interest rate (for example 0% on a balance transfer) that
 * runs between two dates and then falls back to the account's normal rate.
 * Static and pure so the debt payoff projection, the interest accrual and
 * the reminder job all read the window the same way.
 */
final class PromoRate {
	/** How far ahead of the end date the reminder fires. */
	public const WARNING_DAYS = 30;

	private const MAX_RATE = 999.9999;

	/**
	 * True when a complete promo is set and $date falls in [start, end).
	 * Dates are Y-m-d, so a string compare is a date compare.
	 */
	public static function applies(?float $promoRate, ?string $start, ?string $end, string $date): bool {
		if ($promoRate === null || $start === null || $end === null) {
			return false;
		}
		return $start <= $date && $date < $end;
	}

	/** The annual percentage rate in force on $date. */
	public static function rateOn(Account $account, string $date): float {
		if (self::applies($account->getPromoRate(), $account->getPromoStart(), $account->getPromoEnd(), $date)) {
			return (float)$account->getPromoRate();
		}
		return (float)($account->getInterestRate() ?? 0.0);
	}

	/**
	 * Nothing set is valid. Anything set needs all three parts, in order.
	 * The messages reach the account form as they are, so they are translated.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function validate(IL10N $l, ?float $rate, ?string $start, ?string $end): void {
		if ($rate === null && $start === null && $end === null) {
			return;
		}
		if ($rate === null) {
			throw new \InvalidArgumentException($l->t('A promotional rate is needed along with its dates.'));
		}
		if ($rate < 0 || $rate > self::MAX_RATE) {
			throw new \InvalidArgumentException($l->t('The promotional rate must be between 0 and 999.9999.'));
		}
		if ($start === null) {
			throw new \InvalidArgumentException($l->t('A promotional rate needs a start date.'));
		}
		if ($end === null) {
			throw new \InvalidArgumentException($l->t('A promotional rate needs an end date.'));
		}
		foreach ([$start, $end] as $date) {
			if (!self::isDate($date)) {
				throw new \InvalidArgumentException($l->t('Promotional dates must be in YYYY-MM-DD format.'));
			}
		}
		if ($start >= $end) {
			throw new \InvalidArgumentException($l->t('The promotional rate must start before it ends.'));
		}
	}

	/** True when a promo is set, still running at $today, and ends within $days. */
	public static function endsWithin(Account $account, string $today, int $days): bool {
		$end = $account->getPromoEnd();
		if ($account->getPromoRate() === null || $end === null) {
			return false;
		}
		$limit = (new \DateTimeImmutable($today))->modify('+' . $days . ' days')->format('Y-m-d');
		return $today < $end && $end <= $limit;
	}

	private static function isDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $d !== false && $d->format('Y-m-d') === $value;
	}
}
