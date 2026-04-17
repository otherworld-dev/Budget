<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\InterestRate;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\AccountType;

/**
 * Calculates accrued interest on accounts using variable rate history
 * and multiple compounding strategies. Walks forward through transaction
 * history, applying the effective rate for each time interval.
 */
class InterestService {
    private const CALC_SCALE = 10; // High precision for intermediate calculations
    private const DAYS_IN_YEAR = 365;

    public function __construct(
        private InterestRateMapper $rateMapper,
        private TransactionMapper $transactionMapper,
        private AccountMapper $accountMapper
    ) {
    }

    /**
     * Calculate accrued interest for an account as of a given date.
     *
     * Walks forward from the account's opening date through each transaction
     * and rate change, computing interest for each interval.
     *
     * @return array{principal: float, accruedInterest: float, totalOwing: float, isLiability: bool}
     */
    public function calculateAccruedInterest(int $accountId, string $userId, ?string $asOfDate = null): array {
        $account = $this->accountMapper->find($accountId, $userId);
        $balance = (float) $account->getBalance();

        if (!$account->getInterestEnabled()) {
            return [
                'principal' => $balance,
                'accruedInterest' => 0.0,
                'totalOwing' => $balance,
                'isLiability' => AccountType::from($account->getType())->isLiability(),
            ];
        }

        $asOfDate = $asOfDate ?? date('Y-m-d');
        $openingDate = $account->getOpeningDate() ?? $account->getCreatedAt();
        if ($openingDate && strlen($openingDate) > 10) {
            $openingDate = substr($openingDate, 0, 10); // Truncate datetime to date
        }
        $openingDate = $openingDate ?? date('Y-m-d');
        $openingBalance = (string) ($account->getOpeningBalance() ?? 0);

        // Load rate periods and transactions
        $ratePeriods = $this->rateMapper->findByAccount($accountId, $userId);
        $transactions = $this->transactionMapper->findAllClearedByAccount($accountId);

        if (empty($ratePeriods)) {
            return [
                'principal' => $balance,
                'accruedInterest' => 0.0,
                'totalOwing' => $balance,
                'isLiability' => AccountType::from($account->getType())->isLiability(),
            ];
        }

        // Build a chronological list of events (transactions + rate changes)
        $events = [];
        foreach ($transactions as $tx) {
            $events[] = [
                'date' => $tx->getDate(),
                'type' => 'transaction',
                'amount' => (string) $tx->getAmount(),
                'txType' => $tx->getType(), // 'credit' or 'debit'
            ];
        }
        foreach ($ratePeriods as $rp) {
            $events[] = [
                'date' => $rp->getEffectiveDate(),
                'type' => 'rate_change',
                'rate' => (string) $rp->getRate(),
                'compounding' => $rp->getCompoundingFrequency(),
            ];
        }

        // Sort by date, then rate changes before transactions on the same day
        usort($events, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) return $cmp;
            // Rate changes apply before transactions on the same day
            $order = ['rate_change' => 0, 'transaction' => 1];
            return ($order[$a['type']] ?? 2) - ($order[$b['type']] ?? 2);
        });

        // Walk forward computing interest
        $principal = $openingBalance;
        $accruedInterest = '0.00';
        $currentRate = '0';
        $currentCompounding = 'daily';
        $currentDate = $openingDate;

        // Set initial rate from the first rate period that's <= opening date
        foreach ($ratePeriods as $rp) {
            if ($rp->getEffectiveDate() <= $openingDate) {
                $currentRate = (string) $rp->getRate();
                $currentCompounding = $rp->getCompoundingFrequency();
            }
        }

        foreach ($events as $event) {
            $eventDate = $event['date'];

            // Skip events before or on the opening date
            if ($eventDate <= $currentDate && $currentDate === $openingDate) {
                if ($event['type'] === 'rate_change') {
                    $currentRate = $event['rate'];
                    $currentCompounding = $event['compounding'];
                } elseif ($event['type'] === 'transaction') {
                    // Transactions on opening date adjust the starting principal
                    $principal = $this->applyTransaction($principal, $event);
                }
                continue;
            }

            // Skip events after the as-of date
            if ($eventDate > $asOfDate) {
                break;
            }

            // Calculate interest for the interval [currentDate, eventDate)
            if ($eventDate > $currentDate) {
                $days = $this->daysBetween($currentDate, $eventDate);
                if ($days > 0) {
                    // Interest accrues on the outstanding balance (principal + previously accrued interest)
                    $outstandingBalance = bcadd(MoneyCalculator::abs($principal, self::CALC_SCALE), $accruedInterest, self::CALC_SCALE);
                    $interest = $this->calculateIntervalInterest(
                        $outstandingBalance,
                        $currentRate,
                        $currentCompounding,
                        $days,
                        $currentDate,
                        $eventDate
                    );
                    $accruedInterest = bcadd($accruedInterest, $interest, self::CALC_SCALE);
                }
                $currentDate = $eventDate;
            }

            // Apply the event
            if ($event['type'] === 'rate_change') {
                $currentRate = $event['rate'];
                $currentCompounding = $event['compounding'];
            } elseif ($event['type'] === 'transaction') {
                $principal = $this->applyTransaction($principal, $event);
            }
        }

        // Calculate interest for remaining period [currentDate, asOfDate]
        if ($currentDate < $asOfDate) {
            $days = $this->daysBetween($currentDate, $asOfDate);
            if ($days > 0) {
                $outstandingBalance = bcadd(MoneyCalculator::abs($principal, self::CALC_SCALE), $accruedInterest, self::CALC_SCALE);
                $interest = $this->calculateIntervalInterest(
                    $outstandingBalance,
                    $currentRate,
                    $currentCompounding,
                    $days,
                    $currentDate,
                    $asOfDate
                );
                $accruedInterest = bcadd($accruedInterest, $interest, self::CALC_SCALE);
            }
        }

        // Round to 2 decimal places for final output
        $accruedInterest = bcadd($accruedInterest, '0', 2); // Truncate to 2dp
        $isLiability = AccountType::from($account->getType())->isLiability();
        $principalFloat = MoneyCalculator::toFloat($principal);
        $interestFloat = MoneyCalculator::toFloat($accruedInterest);

        // Total owing: for liabilities, interest adds to debt; for assets, adds to value
        $totalOwing = $isLiability
            ? $principalFloat - $interestFloat  // Liabilities: balance is negative, interest makes it more negative
            : $principalFloat + $interestFloat;  // Assets: interest adds value

        return [
            'principal' => $principalFloat,
            'accruedInterest' => $interestFloat,
            'totalOwing' => $totalOwing,
            'isLiability' => $isLiability,
        ];
    }

    /**
     * Calculate interest for a time interval based on compounding type.
     */
    private function calculateIntervalInterest(
        string $principal,
        string $annualRate,
        string $compounding,
        int $days,
        string $startDate,
        string $endDate
    ): string {
        if (bccomp($annualRate, '0', 4) === 0 || bccomp($principal, '0', 2) === 0) {
            return '0';
        }

        // Use absolute principal for interest calculation
        $absPrincipal = MoneyCalculator::abs($principal, self::CALC_SCALE);
        $rateDecimal = bcdiv($annualRate, '100', self::CALC_SCALE);

        return match ($compounding) {
            'simple' => $this->simpleInterest($absPrincipal, $rateDecimal, $days),
            'daily' => $this->dailyCompoundInterest($absPrincipal, $rateDecimal, $days),
            'monthly' => $this->monthlyCompoundInterest($absPrincipal, $rateDecimal, $startDate, $endDate),
            'yearly' => $this->yearlyCompoundInterest($absPrincipal, $rateDecimal, $startDate, $endDate),
            default => $this->dailyCompoundInterest($absPrincipal, $rateDecimal, $days),
        };
    }

    /**
     * Simple interest: P × r × days/365
     */
    private function simpleInterest(string $principal, string $rateDecimal, int $days): string {
        $dayFraction = bcdiv((string) $days, (string) self::DAYS_IN_YEAR, self::CALC_SCALE);
        return bcmul(bcmul($principal, $rateDecimal, self::CALC_SCALE), $dayFraction, self::CALC_SCALE);
    }

    /**
     * Daily compound interest: P × ((1 + r/365)^days - 1)
     */
    private function dailyCompoundInterest(string $principal, string $rateDecimal, int $days): string {
        $dailyRate = bcdiv($rateDecimal, (string) self::DAYS_IN_YEAR, self::CALC_SCALE);
        $onePlusRate = bcadd('1', $dailyRate, self::CALC_SCALE);

        // Use PHP's pow for the exponentiation then convert back to bcmath
        $compoundFactor = (string) pow((float) $onePlusRate, $days);
        $growth = bcsub($compoundFactor, '1', self::CALC_SCALE);

        return bcmul($principal, $growth, self::CALC_SCALE);
    }

    /**
     * Monthly compound interest: compound at each month boundary.
     */
    private function monthlyCompoundInterest(string $principal, string $rateDecimal, string $startDate, string $endDate): string {
        $monthlyRate = bcdiv($rateDecimal, '12', self::CALC_SCALE);
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $totalInterest = '0';

        while ($current < $end) {
            // Next month boundary
            $nextMonth = (clone $current)->modify('first day of next month');
            $periodEnd = ($nextMonth < $end) ? $nextMonth : $end;

            $daysInPeriod = (int) $current->diff($periodEnd)->days;
            $daysInMonth = (int) $current->format('t');

            // Pro-rate the monthly interest for partial months
            $fraction = bcdiv((string) $daysInPeriod, (string) $daysInMonth, self::CALC_SCALE);
            $periodInterest = bcmul(bcmul($principal, $monthlyRate, self::CALC_SCALE), $fraction, self::CALC_SCALE);

            $totalInterest = bcadd($totalInterest, $periodInterest, self::CALC_SCALE);

            // For compounding, add interest to principal at month boundary
            if ($periodEnd == $nextMonth) {
                $principal = bcadd($principal, $periodInterest, self::CALC_SCALE);
            }

            $current = $periodEnd;
        }

        return $totalInterest;
    }

    /**
     * Yearly compound interest: compound at each year boundary.
     */
    private function yearlyCompoundInterest(string $principal, string $rateDecimal, string $startDate, string $endDate): string {
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $totalInterest = '0';

        while ($current < $end) {
            $nextYear = new \DateTime($current->format('Y') . '-12-31');
            $nextYear->modify('+1 day'); // Jan 1 of next year
            $periodEnd = ($nextYear < $end) ? $nextYear : $end;

            $daysInPeriod = (int) $current->diff($periodEnd)->days;
            $daysInYear = (int) (new \DateTime($current->format('Y') . '-01-01'))
                ->diff(new \DateTime(((int) $current->format('Y') + 1) . '-01-01'))->days;

            $fraction = bcdiv((string) $daysInPeriod, (string) $daysInYear, self::CALC_SCALE);
            $periodInterest = bcmul(bcmul($principal, $rateDecimal, self::CALC_SCALE), $fraction, self::CALC_SCALE);

            $totalInterest = bcadd($totalInterest, $periodInterest, self::CALC_SCALE);

            // Compound at year boundary
            if ($periodEnd == $nextYear) {
                $principal = bcadd($principal, $periodInterest, self::CALC_SCALE);
            }

            $current = $periodEnd;
        }

        return $totalInterest;
    }

    /**
     * Apply a transaction to the running principal.
     */
    private function applyTransaction(string $principal, array $event): string {
        $amount = $event['amount'];
        if ($event['txType'] === 'credit') {
            return bcadd($principal, $amount, self::CALC_SCALE);
        }
        return bcsub($principal, $amount, self::CALC_SCALE);
    }

    /**
     * Get the number of days between two date strings.
     */
    private function daysBetween(string $from, string $to): int {
        $d1 = new \DateTime($from);
        $d2 = new \DateTime($to);
        return (int) $d1->diff($d2)->days;
    }

    // ── Rate Management ──────────────────────────────────────────

    /**
     * Enable interest tracking for an account. Creates the initial rate record.
     */
    public function enableInterest(int $accountId, string $userId, string $compoundingFrequency, ?string $effectiveDate = null): void {
        $account = $this->accountMapper->find($accountId, $userId);

        $rate = $account->getInterestRate() ?? 0;
        $effectiveDate = $effectiveDate ?? $account->getOpeningDate() ?? date('Y-m-d');
        if (strlen($effectiveDate) > 10) {
            $effectiveDate = substr($effectiveDate, 0, 10);
        }

        // Create initial rate record
        $interestRate = new InterestRate();
        $interestRate->setAccountId($accountId);
        $interestRate->setUserId($userId);
        $interestRate->setRate($rate);
        $interestRate->setCompoundingFrequency($compoundingFrequency);
        $interestRate->setEffectiveDate($effectiveDate);
        $interestRate->setCreatedAt(date('Y-m-d H:i:s'));
        $this->rateMapper->insert($interestRate);

        // Update account flags
        $account->setInterestEnabled(true);
        $account->setCompoundingFrequency($compoundingFrequency);
        $account->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->accountMapper->update($account);
    }

    /**
     * Disable interest tracking for an account. Clears rate history.
     */
    public function disableInterest(int $accountId, string $userId): void {
        $this->rateMapper->deleteByAccount($accountId);

        $account = $this->accountMapper->find($accountId, $userId);
        $account->setInterestEnabled(false);
        $account->setAccruedInterest(0);
        $account->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->accountMapper->update($account);
    }

    /**
     * Add a new rate change for an account.
     */
    public function addRateChange(int $accountId, string $userId, float $rate, string $compoundingFrequency, string $effectiveDate): InterestRate {
        $interestRate = new InterestRate();
        $interestRate->setAccountId($accountId);
        $interestRate->setUserId($userId);
        $interestRate->setRate($rate);
        $interestRate->setCompoundingFrequency($compoundingFrequency);
        $interestRate->setEffectiveDate($effectiveDate);
        $interestRate->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->rateMapper->insert($interestRate);
    }

    /**
     * Delete a rate change (cannot delete the last/only rate record).
     */
    public function deleteRateChange(int $rateId, string $userId): void {
        $rate = $this->rateMapper->find($rateId, $userId);
        $count = $this->rateMapper->countByAccount($rate->getAccountId());

        if ($count <= 1) {
            throw new \Exception('Cannot delete the only rate record. Disable interest tracking instead.');
        }

        $this->rateMapper->delete($rate);
    }

    /**
     * Get rate history for an account.
     *
     * @return InterestRate[]
     */
    public function getRateHistory(int $accountId, string $userId): array {
        return $this->rateMapper->findByAccount($accountId, $userId);
    }

    /**
     * Recalculate and update the cached accrued_interest column.
     * Called by the background job.
     */
    public function refreshAccruedInterestCache(int $accountId, string $userId): string {
        $result = $this->calculateAccruedInterest($accountId, $userId);
        $amount = sprintf('%.2f', $result['accruedInterest']);
        $this->accountMapper->updateAccruedInterest($accountId, $amount);
        return $amount;
    }
}
