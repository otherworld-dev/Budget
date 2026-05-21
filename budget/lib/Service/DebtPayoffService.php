<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\DebtScenarioMapper;
use OCA\Budget\Db\TransactionMapper;

/**
 * Service for calculating debt payoff strategies.
 * Supports snowball (smallest balance first) and avalanche (highest interest first) methods.
 */
class DebtPayoffService {
    /**
     * Liability account types considered as debts.
     */
    private const LIABILITY_TYPES = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

    /**
     * Maximum months to simulate (40 years).
     */
    private const MAX_MONTHS = 480;

    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private ?DebtScenarioMapper $scenarioMapper;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        ?DebtScenarioMapper $scenarioMapper = null
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->scenarioMapper = $scenarioMapper;
    }

    /**
     * Get all debt accounts for a user.
     *
     * @return Account[]
     */
    public function getDebts(string $userId): array {
        $accounts = $this->accountMapper->findAll($userId);
        return array_filter($accounts, function (Account $account) {
            return $this->isDebt($account);
        });
    }

    /**
     * Get debt summary for a user.
     */
    public function getSummary(string $userId): array {
        $debts = $this->getDebts($userId);

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $totalBalance = '0.00';
        $totalMinimumPayment = '0.00';
        $highestRate = 0.0;
        $lowestBalance = PHP_FLOAT_MAX;

        foreach ($debts as $debt) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = (float) $debt->getBalance();
            $futureChange = $futureChanges[$debt->getId()] ?? 0;
            $balance = abs($storedBalance - $futureChange);
            if ($balance <= 0) {
                continue;
            }

            // Use MoneyCalculator for precise accumulation
            $totalBalance = MoneyCalculator::add($totalBalance, (string) $balance);

            $minPayment = (string) ($debt->getMinimumPayment() ?? 0);
            $totalMinimumPayment = MoneyCalculator::add($totalMinimumPayment, $minPayment);

            $rate = (float) ($debt->getInterestRate() ?? 0);
            if ($rate > $highestRate) {
                $highestRate = $rate;
            }
            if ($balance < $lowestBalance) {
                $lowestBalance = $balance;
            }
        }

        // Count active debts (those with balance > 0 after adjustment)
        $activeDebtCount = 0;
        foreach ($debts as $debt) {
            $storedBal = (float) $debt->getBalance();
            $futureChg = $futureChanges[$debt->getId()] ?? 0;
            if (abs($storedBal - $futureChg) > 0) {
                $activeDebtCount++;
            }
        }

        return [
            'totalBalance' => MoneyCalculator::toFloat($totalBalance),
            'totalMinimumPayment' => MoneyCalculator::toFloat($totalMinimumPayment),
            'debtCount' => $activeDebtCount,
            'highestInterestRate' => round($highestRate, 2),
            'lowestBalance' => $lowestBalance === PHP_FLOAT_MAX ? 0 : round($lowestBalance, 2),
        ];
    }

    /**
     * Calculate payoff plan using the specified strategy.
     *
     * @param string $userId User ID
     * @param string $strategy 'avalanche' or 'snowball'
     * @param float|null $extraPayment Extra monthly payment beyond minimums
     * @param int[]|null $selectedDebtIds Limit simulation to these debt account IDs (null = all)
     * @param float $lumpSum One-time extra payment applied in $lumpSumMonth
     * @param int $lumpSumMonth Month number (1-based) in which to apply the lump sum
     * @param array<string,float>|null $rateOverrides Map of debt ID (string) to override APR percentage
     */
    public function calculatePayoffPlan(
        string $userId,
        string $strategy = 'avalanche',
        ?float $extraPayment = null,
        ?array $selectedDebtIds = null,
        float $lumpSum = 0,
        int $lumpSumMonth = 1,
        ?array $rateOverrides = null
    ): array {
        $debts = $this->getDebts($userId);
        $extraPayment = $extraPayment ?? 0;

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        // Filter to debts with balance > 0 (after adjustment)
        $activeDebts = array_filter($debts, function($d) use ($futureChanges) {
            $storedBalance = (float) $d->getBalance();
            $futureChange = $futureChanges[$d->getId()] ?? 0;
            return abs($storedBalance - $futureChange) > 0;
        });

        if (empty($activeDebts)) {
            return [
                'strategy' => $strategy,
                'extraPayment' => $extraPayment,
                'debts' => [],
                'timeline' => [],
                'totalMonths' => 0,
                'totalInterest' => 0,
                'totalPaid' => 0,
                'payoffDate' => null,
            ];
        }

        // Build debt data structure
        $debtData = [];
        foreach ($activeDebts as $debt) {
            // Calculate balance as of today
            $storedBalance = (float) $debt->getBalance();
            $futureChange = $futureChanges[$debt->getId()] ?? 0;
            $balance = abs($storedBalance - $futureChange);
            $minimumPayment = (float) ($debt->getMinimumPayment() ?? 25); // Default $25 minimum
            $interestRate = (float) ($debt->getInterestRate() ?? 0) / 100; // Convert percentage to decimal

            // Minimum payment should be at least enough to cover monthly interest + some principal
            $monthlyInterest = $balance * ($interestRate / 12);
            if ($minimumPayment <= $monthlyInterest && $interestRate > 0) {
                $minimumPayment = $monthlyInterest + 10; // Ensure progress
            }

            $debtData[] = [
                'id' => $debt->getId(),
                'name' => $debt->getName(),
                'type' => $debt->getType(),
                'balance' => $balance,
                'originalBalance' => $balance,
                'minimumPayment' => $minimumPayment,
                'interestRate' => $interestRate,
                'monthlyRate' => $interestRate / 12,
                'interestPaid' => 0,
                'paidOff' => false,
                'payoffMonth' => null,
            ];
        }

        // Filter to selected debts if specified
        if ($selectedDebtIds !== null) {
            $debtData = array_values(array_filter($debtData, function ($debt) use ($selectedDebtIds) {
                return in_array($debt['id'], $selectedDebtIds);
            }));
        }

        // Apply rate overrides
        if ($rateOverrides !== null) {
            foreach ($debtData as &$debt) {
                $idStr = (string)$debt['id'];
                if (isset($rateOverrides[$idStr])) {
                    $debt['interestRate'] = (float)$rateOverrides[$idStr] / 100;
                    $debt['monthlyRate'] = $debt['interestRate'] / 12;
                }
            }
            unset($debt);
        }

        if (empty($debtData)) {
            return [
                'strategy' => $strategy,
                'extraPayment' => $extraPayment,
                'debts' => [],
                'timeline' => [],
                'totalMonths' => 0,
                'totalInterest' => 0,
                'totalPaid' => 0,
                'payoffDate' => null,
            ];
        }

        // Sort based on strategy
        $debtData = $this->sortByStrategy($debtData, $strategy);

        // Simulate payoff
        $timeline = [];
        $month = 0;
        $totalInterest = 0;
        $totalPaid = 0;

        while ($this->hasActiveDebts($debtData) && $month < self::MAX_MONTHS) {
            $month++;
            $monthData = ['month' => $month, 'payments' => [], 'debtsPaidOff' => []];
            $monthlyExtra = $extraPayment;
            if ($lumpSum > 0 && $month === $lumpSumMonth) {
                $monthlyExtra += $lumpSum;
            }
            $availableExtra = $monthlyExtra;

            // Apply interest and minimum payments first
            foreach ($debtData as &$debt) {
                if ($debt['paidOff']) {
                    continue;
                }

                // Apply monthly interest
                $interest = $debt['balance'] * $debt['monthlyRate'];
                $debt['balance'] += $interest;
                $debt['interestPaid'] += $interest;
                $totalInterest += $interest;

                // Apply minimum payment
                $payment = min($debt['minimumPayment'], $debt['balance']);
                $debt['balance'] -= $payment;
                $totalPaid += $payment;

                $monthData['payments'][] = [
                    'debtId' => $debt['id'],
                    'name' => $debt['name'],
                    'payment' => round($payment, 2),
                    'interest' => round($interest, 2),
                    'remainingBalance' => round($debt['balance'], 2),
                    'type' => 'minimum',
                ];

                // Check if paid off
                if ($debt['balance'] <= 0.01) {
                    $debt['paidOff'] = true;
                    $debt['payoffMonth'] = $month;
                    $debt['balance'] = 0;
                    $monthData['debtsPaidOff'][] = $debt['name'];
                    // Freed minimum payment goes to extra pool
                    $availableExtra += $debt['minimumPayment'];
                }
            }
            unset($debt);

            // Apply extra payments to priority debt (first non-paid-off)
            foreach ($debtData as &$debt) {
                if ($debt['paidOff'] || $availableExtra <= 0) {
                    continue;
                }

                $extraToApply = min($availableExtra, $debt['balance']);
                if ($extraToApply > 0) {
                    $debt['balance'] -= $extraToApply;
                    $totalPaid += $extraToApply;
                    $availableExtra -= $extraToApply;

                    $monthData['payments'][] = [
                        'debtId' => $debt['id'],
                        'name' => $debt['name'],
                        'payment' => round($extraToApply, 2),
                        'remainingBalance' => round($debt['balance'], 2),
                        'type' => 'extra',
                    ];

                    if ($debt['balance'] <= 0.01) {
                        $debt['paidOff'] = true;
                        $debt['payoffMonth'] = $month;
                        $debt['balance'] = 0;
                        if (!in_array($debt['name'], $monthData['debtsPaidOff'])) {
                            $monthData['debtsPaidOff'][] = $debt['name'];
                        }
                        $availableExtra += $debt['minimumPayment'];
                    }
                }
                break; // Only apply extra to one debt at a time
            }
            unset($debt);

            // Re-sort after each payoff for proper prioritization
            if (!empty($monthData['debtsPaidOff'])) {
                $debtData = $this->sortByStrategy($debtData, $strategy);
            }

            $timeline[] = $monthData;
        }

        // Calculate payoff date
        $payoffDate = null;
        if ($month < self::MAX_MONTHS) {
            $payoffDate = date('Y-m-d', strtotime("+{$month} months"));
        }

        // Build debt results
        $debtResults = [];
        foreach ($debtData as $debt) {
            $debtResults[] = [
                'id' => $debt['id'],
                'name' => $debt['name'],
                'type' => $debt['type'],
                'originalBalance' => round($debt['originalBalance'], 2),
                'interestRate' => round($debt['interestRate'] * 100, 2),
                'interestPaid' => round($debt['interestPaid'], 2),
                'payoffMonth' => $debt['payoffMonth'],
            ];
        }

        return [
            'strategy' => $strategy,
            'strategyName' => $strategy === 'avalanche' ? 'Debt Avalanche' : 'Debt Snowball',
            'strategyDescription' => $strategy === 'avalanche'
                ? 'Pay highest interest rate debts first (saves most money)'
                : 'Pay smallest balance debts first (quick wins for motivation)',
            'extraPayment' => $extraPayment,
            'debts' => $debtResults,
            'totalMonths' => $month,
            'totalInterest' => round($totalInterest, 2),
            'totalPaid' => round($totalPaid, 2),
            'payoffDate' => $payoffDate,
            'timeline' => $timeline,
        ];
    }

    /**
     * Compare both strategies to help user decide.
     */
    public function compareStrategies(string $userId, ?float $extraPayment = null): array {
        $avalanche = $this->calculatePayoffPlan($userId, 'avalanche', $extraPayment);
        $snowball = $this->calculatePayoffPlan($userId, 'snowball', $extraPayment);

        // Remove timeline from comparison (too verbose)
        unset($avalanche['timeline'], $snowball['timeline']);

        $interestSaved = $snowball['totalInterest'] - $avalanche['totalInterest'];
        $timeDifference = $snowball['totalMonths'] - $avalanche['totalMonths'];

        return [
            'avalanche' => $avalanche,
            'snowball' => $snowball,
            'comparison' => [
                'interestSavedByAvalanche' => round($interestSaved, 2),
                'monthsFasterByAvalanche' => $timeDifference,
                'recommendation' => $interestSaved > 50
                    ? 'avalanche'
                    : ($interestSaved < -50 ? 'snowball' : 'either'),
                'explanation' => $this->getRecommendationExplanation($interestSaved, $timeDifference),
            ],
        ];
    }

    /**
     * Compare actual current debt balance against the projected balance from the active scenario.
     */
    public function getProgressVsActive(string $userId): array {
        if ($this->scenarioMapper === null) {
            return ['hasActiveScenario' => false];
        }

        $scenario = $this->scenarioMapper->findActive($userId);
        if ($scenario === null) {
            return ['hasActiveScenario' => false];
        }

        $monthsElapsed = max(0, (int)round(
            (time() - strtotime($scenario->getCreatedAt())) / (30.44 * 86400)
        ));

        // Resolve selected debt IDs — empty array means "all", so pass null
        $parsedIds = $scenario->getParsedSelectedDebtIds();
        $selectedDebtIds = !empty($parsedIds) ? $parsedIds : null;

        // Resolve rate overrides — empty array means none
        $parsedOverrides = $scenario->getParsedRateOverrides();
        $rateOverrides = !empty($parsedOverrides) ? $parsedOverrides : null;

        // Calculate plan using scenario params
        $plan = $this->calculatePayoffPlan(
            $userId,
            $scenario->getStrategy(),
            $scenario->getExtraPayment(),
            $selectedDebtIds,
            $scenario->getLumpSum(),
            $scenario->getLumpSumMonth(),
            $rateOverrides
        );

        // Find projected balance at current month from timeline
        $projectedBalance = 0;
        if ($monthsElapsed > 0 && $monthsElapsed <= count($plan['timeline'])) {
            $entry = $plan['timeline'][$monthsElapsed - 1] ?? null;
            if ($entry) {
                $balancesByDebt = [];
                foreach ($entry['payments'] as $payment) {
                    $balancesByDebt[$payment['debtId']] = $payment['remainingBalance'];
                }
                $projectedBalance = array_sum($balancesByDebt);
            }
        } elseif ($monthsElapsed === 0) {
            // Just started — projected is original total
            $projectedBalance = $scenario->getOriginalTotalDebt();
        }
        // If past the end of the timeline, projected is 0 (fully paid off)

        // Get actual current total debt
        $summary = $this->getSummary($userId);
        $actualBalance = abs($summary['totalBalance']);

        $difference = $projectedBalance - $actualBalance;
        $tolerance = $scenario->getOriginalTotalDebt() * 0.05;

        if ($difference > $tolerance) {
            $status = 'ahead';
        } elseif ($difference < -$tolerance) {
            $status = 'behind';
        } else {
            $status = 'on_track';
        }

        return [
            'hasActiveScenario' => true,
            'scenarioName' => $scenario->getName(),
            'monthsElapsed' => $monthsElapsed,
            'projectedBalance' => round($projectedBalance, 2),
            'actualBalance' => round($actualBalance, 2),
            'difference' => round($difference, 2),
            'status' => $status,
            'projectedPayoffDate' => $plan['payoffDate'],
            'originalTotalDebt' => $scenario->getOriginalTotalDebt(),
            'totalMonths' => $plan['totalMonths'],
        ];
    }

    /**
     * Check if account is a debt.
     */
    private function isDebt(Account $account): bool {
        return in_array($account->getType(), self::LIABILITY_TYPES, true);
    }

    /**
     * Sort debts by strategy.
     */
    private function sortByStrategy(array $debtData, string $strategy): array {
        usort($debtData, function ($a, $b) use ($strategy) {
            // Paid-off debts go to end
            if ($a['paidOff'] && !$b['paidOff']) {
                return 1;
            }
            if (!$a['paidOff'] && $b['paidOff']) {
                return -1;
            }

            if ($strategy === 'avalanche') {
                // Highest interest rate first
                return $b['interestRate'] <=> $a['interestRate'];
            } else {
                // Snowball: Lowest balance first
                return $a['balance'] <=> $b['balance'];
            }
        });

        return $debtData;
    }

    /**
     * Check if there are still debts to pay.
     */
    private function hasActiveDebts(array $debtData): bool {
        foreach ($debtData as $debt) {
            if (!$debt['paidOff']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get explanation for recommendation.
     */
    private function getRecommendationExplanation(float $interestSaved, int $timeDifference): string {
        if ($interestSaved > 500) {
            return 'Strongly recommended unless you need quick wins.';
        } elseif ($interestSaved > 50) {
            return 'Both methods work, but avalanche is more cost-effective.';
        } elseif ($interestSaved < -50) {
            return 'Snowball saves more due to your debt structure. Go with snowball!';
        } else {
            return 'Both methods are nearly identical for your debts. Choose based on preference.';
        }
    }
}
