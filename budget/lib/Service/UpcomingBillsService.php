<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;

/**
 * Bills overdue or due soon, the user's own and those shared with them,
 * for the public API (#767).
 *
 * BillService::findUpcoming() stays as the web's own list; this wraps it
 * and adds the shared bills it has never included.
 */
class UpcomingBillsService {
	public function __construct(
		private BillService $billService,
		private GranularShareService $granularShareService,
		private AccountMapper $accountMapper,
	) {
	}

	/**
	 * Active bills overdue or due within $days, overdue first and then by
	 * due date.
	 *
	 * @return array[] serialized bills plus `overdue`, and `accountName`
	 *                 when the bill's account is one the user can see
	 */
	public function upcoming(string $userId, int $days): array {
		$today = $this->today();
		$until = (new \DateTimeImmutable($today))->modify("+{$days} days")->format('Y-m-d');

		$own = array_map(
			static fn (Bill $bill) => $bill->jsonSerialize(),
			$this->billService->enrichBillsWithCurrency($this->billService->findUpcoming($userId, $days), $userId)
		);
		// findUpcoming()'s window: overdue, or due by $until
		$shared = array_values(array_filter(
			$this->granularShareService->getSharedBills($userId),
			static fn (array $bill) => !empty($bill['isActive'])
				&& ($bill['nextDueDate'] ?? null) !== null
				&& $bill['nextDueDate'] <= $until
		));
		$bills = array_merge($own, $this->billService->enrichSharedBillsWithCurrency($shared));

		$names = [];
		foreach ($this->accountMapper->findByIds($this->granularShareService->getVisibleAccountIds($userId)) as $account) {
			$names[$account->getId()] = $account->getName();
		}
		foreach ($bills as &$bill) {
			$bill['overdue'] = ($bill['nextDueDate'] ?? null) !== null && $bill['nextDueDate'] < $today;
			// A shared bill can post from an owner's account that was never shared
			$bill['accountName'] = $names[(int)($bill['accountId'] ?? 0)] ?? null;
		}
		unset($bill);

		usort($bills, static fn (array $a, array $b)
			=> [$a['nextDueDate'] ?? '9999-12-31', (int)$a['id']] <=> [$b['nextDueDate'] ?? '9999-12-31', (int)$b['id']]);

		return $bills;
	}

	/** Today (Y-m-d). Overridable in tests. */
	protected function today(): string {
		return date('Y-m-d');
	}
}
