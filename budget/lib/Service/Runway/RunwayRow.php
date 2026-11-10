<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

/**
 * One transaction as the runway calculator sees it: already in the base
 * currency, with just the facts that decide whether it counts.
 */
final class RunwayRow {
	/**
	 * @param string $date Y-m-d
	 * @param string $amount Signed, base currency
	 * @param string $status cleared|pending|scheduled
	 * @param ?int $linkedAccountId Account of the linked partner row, null if unlinked
	 * @param list<array{categoryId: ?int, amount: string}> $splits Base currency, non-empty only for split parents
	 * @param string $description Labels the row when it's applied as a future event
	 */
	public function __construct(
		public int $id,
		public string $date,
		public string $amount,
		public ?int $categoryId,
		public string $status,
		public bool $excludedFromForecast,
		public bool $pensionLeg,
		public ?int $linkedAccountId,
		public ?int $billId,
		public array $splits = [],
		public string $description = '',
	) {
	}
}
