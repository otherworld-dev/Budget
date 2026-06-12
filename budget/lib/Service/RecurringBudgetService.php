<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Computes the predictable monthly amount each category is committed to through
 * active recurring bills and recurring income (#269).
 *
 * The budget view uses these figures as an automatic fallback: a (sub-)category
 * with no manually-set budget shows its committed recurring total as the limit,
 * so the "fixed" part of a budget is derived from the recurring items while the
 * "variable" part can still be typed in manually (which always wins).
 *
 * Amounts are normalized to a monthly figure regardless of the item's
 * frequency; the frontend converts that to the category's budget period.
 */
class RecurringBudgetService {
    private BillService $billService;
    private RecurringIncomeService $recurringIncomeService;
    private Bill\FrequencyCalculator $frequencyCalculator;

    public function __construct(
        BillService $billService,
        RecurringIncomeService $recurringIncomeService,
        Bill\FrequencyCalculator $frequencyCalculator
    ) {
        $this->billService = $billService;
        $this->recurringIncomeService = $recurringIncomeService;
        $this->frequencyCalculator = $frequencyCalculator;
    }

    /**
     * Monthly-normalized recurring total per category id.
     *
     * Bills are summed into their category (split-template bills distribute to
     * each split's category); recurring income is summed into its category.
     *
     * @param string $userId
     * @return array<int, float> categoryId => monthly amount
     */
    public function getMonthlyBudgetsByCategory(string $userId): array {
        $totals = [];

        $today = date('Y-m-d');
        foreach ($this->billService->findActive($userId) as $bill) {
            // A bill that hasn't started yet (or has already ended) is not a
            // current commitment — counting it would double up during #268's
            // "end bill A, start replacement B later" overlap window.
            if ($bill->getStartDate() !== null && $bill->getStartDate() > $today) {
                continue;
            }
            if ($bill->getEndDate() !== null && $bill->getEndDate() < $today) {
                continue;
            }
            $factor = $this->monthlyFactor($bill->getFrequency(), $bill->getCustomRecurrencePattern());
            if ($factor == 0.0) {
                continue; // e.g. one-time bills: not a recurring commitment
            }
            $splits = $bill->getSplitTemplateArray();
            if (!empty($splits)) {
                foreach ($splits as $split) {
                    $catId = isset($split['categoryId']) ? (int)$split['categoryId'] : 0;
                    $amount = isset($split['amount']) ? (float)$split['amount'] : 0.0;
                    if ($catId > 0 && $amount != 0.0) {
                        $totals[$catId] = ($totals[$catId] ?? 0.0) + $amount * $factor;
                    }
                }
            } elseif ($bill->getCategoryId()) {
                $catId = (int)$bill->getCategoryId();
                $totals[$catId] = ($totals[$catId] ?? 0.0) + (float)$bill->getAmount() * $factor;
            }
        }

        foreach ($this->recurringIncomeService->findActive($userId) as $income) {
            if (!$income->getCategoryId()) {
                continue;
            }
            $factor = $this->monthlyFactor($income->getFrequency());
            if ($factor == 0.0) {
                continue;
            }
            $catId = (int)$income->getCategoryId();
            $totals[$catId] = ($totals[$catId] ?? 0.0) + (float)$income->getAmount() * $factor;
        }

        foreach ($totals as $id => $amount) {
            $totals[$id] = round($amount, 2);
        }

        return $totals;
    }

    /**
     * Convert a monthly amount into the equivalent for a budget period.
     * Mirrors the Budget view's conversion so every surface that applies the
     * recurring fallback (alerts, budget report, frontend) agrees.
     */
    public function convertMonthlyToPeriod(float $monthly, string $period): float {
        return match ($period) {
            'weekly' => $monthly * 12 / 52,
            'quarterly' => $monthly * 3,
            'yearly' => $monthly * 12,
            default => $monthly,
        };
    }

    /**
     * Factor converting an amount at the given frequency into a monthly amount.
     * Delegates to FrequencyCalculator so every frequency the app supports
     * (incl. semi-monthly, semi-annually, daily, custom patterns) is handled
     * by one shared implementation.
     */
    private function monthlyFactor(string $frequency, ?string $customPattern = null): float {
        if ($frequency === 'one-time') {
            // A one-off is not a recurring monthly commitment: counting it
            // every month until it's marked paid would inflate the budget.
            return 0.0;
        }
        if ($frequency === 'custom') {
            return $this->frequencyCalculator->getCustomOccurrencesPerYear($customPattern) / 12.0;
        }
        return $this->frequencyCalculator->getMonthlyEquivalentFromValues(1.0, $frequency);
    }
}
