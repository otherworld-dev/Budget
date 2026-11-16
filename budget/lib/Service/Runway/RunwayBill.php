<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

/**
 * An active, non-transfer, fixed-amount bill paid from a chosen account,
 * as the runway calculator needs it for smoothing and pre-booking checks.
 */
final class RunwayBill {
	/**
	 * @param string $frequency A Frequency value
	 * @param string $monthlyAmount Positive, base currency
	 */
	public function __construct(
		public int $id,
		public string $frequency,
		public string $monthlyAmount,
		public ?int $categoryId,
	) {
	}
}
