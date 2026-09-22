<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\MoneyCalculator;

/**
 * The Bills Calendar's projected balance for one account (#393): today's
 * balance carried from month to month through the rest of the year, taking
 * off the bills still due out of the account and adding the transfers and
 * recurring income still due into it. Each month starts where the one before
 * it ended, so a month shows what the account will actually hold by then,
 * not what today's balance would cover of that month on its own.
 */
class BalanceProjector {
    /** At most one occurrence a day for a year: a guard, should a schedule ever stop advancing */
    private const MAX_OCCURRENCES = 366;

    public function __construct(
        private FrequencyCalculator $frequencyCalculator,
    ) {
    }

    /**
     * What the account's recurring income is expected to pay in each month
     * from today to the end of today's year. An occurrence expected before
     * today has either arrived, and is in today's balance, or is late and
     * cannot be counted on, so neither is added. The amount is in the
     * account's currency, as the income pays into it.
     *
     * @param RecurringIncome[] $incomes the user's active recurring income
     * @return array<int, string> month => amount due
     */
    public function incomeByMonth(array $incomes, int $accountId, string $today, int $scale): array {
        $yearEnd = substr($today, 0, 4) . '-12-31';
        $due = [];
        foreach ($incomes as $income) {
            if ($income->getAccountId() !== $accountId || !$income->getIsActive()) {
                continue;
            }
            $date = $income->getNextExpectedDate();
            for ($i = 0; $date !== null && $date !== '' && $date <= $yearEnd && $i < self::MAX_OCCURRENCES; $i++) {
                if ($date >= $today) {
                    $month = (int) substr($date, 5, 2);
                    $due[$month] = MoneyCalculator::add($due[$month] ?? '0', (string) $income->getAmount(), $scale);
                }
                $next = $this->frequencyCalculator->calculateNextDueDate(
                    $income->getFrequency(),
                    $income->getExpectedDay(),
                    $income->getExpectedMonth(),
                    $date,
                    null,
                    true,
                    $income->getStartDate()
                );
                if ($next <= $date) {
                    break;
                }
                $date = $next;
            }
        }
        return $due;
    }

    /**
     * The running balance from $currentMonth to December. Months before it
     * are null: what was paid then is already in today's balance. A bill or
     * transfer still owed from an earlier month has not happened yet either,
     * so it is counted in the current month. Paid and unrecorded (moved
     * past, #333) occurrences are in the balance or no longer owed, so
     * neither counts. A transfer books the same amount into its destination
     * as it takes from its source, so an arriving one needs no conversion.
     *
     * @param array[] $billsData ungrouped calendar rows
     * @param array<int, string> $incomeByMonth from incomeByMonth()
     * @return array{balance: array<int, float|null>, flows: array<int, array{bills: float, transfersIn: float, income: float}|null>}
     */
    public function project(array $billsData, int $accountId, float $balance, array $incomeByMonth, int $currentMonth, int $scale): array {
        $flows = array_fill(1, 12, ['bills' => '0', 'transfersIn' => '0', 'income' => '0']);
        foreach ($billsData as $row) {
            $out = $row['accountId'] === $accountId;
            if (!$out && !($row['isTransfer'] && $row['destinationAccountId'] === $accountId)) {
                continue;
            }
            $key = $out ? 'bills' : 'transfersIn';
            $settled = array_flip(array_merge($row['paidMonths'], $row['unrecordedMonths']));
            foreach ($row['expectedAmounts'] as $month => $amount) {
                if (isset($settled[$month])) {
                    continue;
                }
                $into = max($month, $currentMonth);
                $flows[$into][$key] = MoneyCalculator::add($flows[$into][$key], (string) $amount, $scale);
            }
        }
        foreach ($incomeByMonth as $month => $amount) {
            $flows[$month]['income'] = MoneyCalculator::add($flows[$month]['income'], $amount, $scale);
        }

        $running = (string) $balance;
        $projected = [];
        $monthFlows = [];
        for ($month = 1; $month <= 12; $month++) {
            if ($month < $currentMonth) {
                $projected[$month] = null;
                $monthFlows[$month] = null;
                continue;
            }
            $f = $flows[$month];
            $running = MoneyCalculator::subtract($running, $f['bills'], $scale);
            $running = MoneyCalculator::add($running, $f['transfersIn'], $scale);
            $running = MoneyCalculator::add($running, $f['income'], $scale);
            $projected[$month] = MoneyCalculator::toFloat($running);
            $monthFlows[$month] = array_map([MoneyCalculator::class, 'toFloat'], $f);
        }
        return ['balance' => $projected, 'flows' => $monthFlows];
    }
}
