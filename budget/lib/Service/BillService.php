<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Manages bill CRUD operations and summary calculations.
 */
class BillService {
    private BillMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;
    private RecurringBillDetector $recurringDetector;
    private TransactionService $transactionService;
    private IL10N $l;
    private AccountMapper $accountMapper;
    private CurrencyConversionService $currencyConversion;
    private TransactionSplitService $splitService;
    private LoggerInterface $logger;
    private ?AutoShareService $autoShareService;

    public function __construct(
        BillMapper $mapper,
        FrequencyCalculator $frequencyCalculator,
        RecurringBillDetector $recurringDetector,
        TransactionService $transactionService,
        IL10N $l,
        AccountMapper $accountMapper,
        CurrencyConversionService $currencyConversion,
        TransactionSplitService $splitService,
        LoggerInterface $logger,
        ?AutoShareService $autoShareService = null
    ) {
        $this->mapper = $mapper;
        $this->frequencyCalculator = $frequencyCalculator;
        $this->recurringDetector = $recurringDetector;
        $this->transactionService = $transactionService;
        $this->l = $l;
        $this->accountMapper = $accountMapper;
        $this->currencyConversion = $currencyConversion;
        $this->splitService = $splitService;
        $this->logger = $logger;
        $this->autoShareService = $autoShareService;
    }

    /** Amount types whose figure is resolved from the destination card at payment time (#347) */
    private const DYNAMIC_AMOUNT_TYPES = ['statement', 'current_balance', 'minimum_payment'];

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Bill {
        return $this->mapper->find($id, $userId);
    }

    /**
     * A dynamic amount type needs a card-like transfer destination to
     * resolve against.
     */
    private function validateAmountType(string $amountType, bool $isTransfer, ?int $destinationAccountId): void {
        if ($amountType !== 'fixed' && !in_array($amountType, self::DYNAMIC_AMOUNT_TYPES, true)) {
            throw new \InvalidArgumentException($this->l->t('Invalid amount type'));
        }
        if ($amountType === 'fixed') {
            return;
        }
        if (!$isTransfer || $destinationAccountId === null) {
            throw new \InvalidArgumentException($this->l->t('This amount type requires a transfer with a destination account'));
        }
        $destinationType = $this->accountMapper->findById($destinationAccountId)->getType();
        if (!in_array($destinationType, ['credit_card', 'line_of_credit'], true)) {
            throw new \InvalidArgumentException($this->l->t('This amount type is only available for transfers to a credit card'));
        }
    }

    /**
     * Resolve the payable amount for a dynamic-amount bill (#347).
     * $dueDate is the statement cut-off (null = use $paidDate); $paidDate is
     * when the money moves. Returns null for fixed-amount bills.
     */
    private function resolveDynamicAmount(string $amountType, ?int $destinationAccountId, ?string $dueDate, string $paidDate): ?float {
        if (!in_array($amountType, self::DYNAMIC_AMOUNT_TYPES, true) || $destinationAccountId === null) {
            return null;
        }
        if ($amountType === 'statement') {
            // What was owed at the due date; later charges roll to the next statement
            return $this->transactionService->getStatementAmountForAccount($destinationAccountId, $dueDate ?? $paidDate);
        }
        $owedNow = $this->transactionService->getStatementAmountForAccount($destinationAccountId, $paidDate);
        if ($amountType === 'current_balance') {
            return $owedNow;
        }
        // minimum_payment: the card's stored minimum, never more than is owed
        $minimum = (float) ($this->accountMapper->findById($destinationAccountId)->getMinimumPayment() ?? 0);
        return min($minimum, $owedNow);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }

    /**
     * Find existing transactions that might match a bill payment.
     * Used to avoid creating duplicate transactions when marking a bill paid.
     *
     * @return array Scored candidates [{transaction, score, matchReasons}]
     */
    public function findMatchingTransactions(int $billId, string $userId): array {
        $bill = $this->find($billId, $userId);

        if (!$bill->getAccountId()) {
            return [];
        }

        $dueDate = $bill->getNextDueDate() ?? date('Y-m-d');

        return $this->transactionService->findBillPaymentCandidates(
            $bill->getAccountId(),
            $bill->getName(),
            (float) $bill->getAmount(),
            $dueDate
        );
    }

    /**
     * Bills whose most recent payment has no recorded transaction (#274).
     *
     * Marking a bill paid without creating or linking a transaction leaves
     * the account balance out of step with the bank. This returns recent
     * occurrences (last $sinceDays days) so the Bills page can surface them.
     * Deliberate skips (Skip button) never set the last-paid date and are
     * not flagged.
     */
    public function findUnrecordedPayments(string $userId, int $sinceDays = 60): array {
        $cutoff = date('Y-m-d', strtotime("-{$sinceDays} days"));
        $candidates = [];
        foreach ($this->mapper->findAll($userId) as $bill) {
            $lastPaid = $bill->getLastPaidDate();
            if ($lastPaid !== null && $lastPaid >= $cutoff) {
                $candidates[] = $bill;
            }
        }
        if (empty($candidates)) {
            return [];
        }

        $billIds = array_map(static fn(Bill $b) => $b->getId(), $candidates);
        $txDatesByBill = [];
        foreach ($this->transactionService->findRecordedBillTransactions($billIds) as $tx) {
            $txDatesByBill[$tx->getBillId()][] = $tx->getDate();
        }

        $currencyMap = $this->buildCurrencyMap($userId);
        $unrecorded = [];
        foreach ($candidates as $bill) {
            if ($this->hasRecordedPayment($bill->getLastPaidDate(), $txDatesByBill[$bill->getId()] ?? [])) {
                continue;
            }
            $unrecorded[] = [
                'billId' => $bill->getId(),
                'name' => $bill->getName(),
                'amount' => (float) $bill->getAmount(),
                'lastPaidDate' => $bill->getLastPaidDate(),
                'accountId' => $bill->getAccountId(),
                'currency' => $bill->getAccountId() !== null
                    ? ($currencyMap[$bill->getAccountId()] ?? null)
                    : null,
            ];
        }

        usort($unrecorded, static fn($a, $b) => strcmp($b['lastPaidDate'], $a['lastPaidDate']));
        return $unrecorded;
    }

    /**
     * Record the missing transaction for a payment that was marked paid
     * without one (#274). Creates a cleared transaction (both legs for
     * transfers) dated the bill's last paid date and linked to the bill.
     */
    public function recordMissedPayment(int $id, string $userId): array {
        $bill = $this->find($id, $userId);

        $lastPaid = $bill->getLastPaidDate();
        if ($lastPaid === null) {
            throw new \InvalidArgumentException($this->l->t('This bill has never been marked as paid'));
        }
        if ($bill->getAccountId() === null) {
            throw new \InvalidArgumentException($this->l->t('Assign an account to the bill first'));
        }

        // The bill may reference an account that has since been deleted — give an
        // actionable message instead of a raw lookup failure surfacing as the
        // generic "Failed to record the payment" (#333, #334).
        try {
            $this->accountMapper->findById($bill->getAccountId());
        } catch (DoesNotExistException $e) {
            throw new \InvalidArgumentException($this->l->t('The account linked to this bill no longer exists. Edit the bill and choose an account.'));
        }

        // Idempotence guard: refuse when a payment transaction already exists
        $existingDates = array_map(
            static fn($tx) => $tx->getDate(),
            $this->transactionService->findRecordedBillTransactions([$id])
        );
        if ($this->hasRecordedPayment($lastPaid, $existingDates)) {
            throw new \InvalidArgumentException($this->l->t('A transaction for this payment already exists'));
        }

        $transaction = $this->transactionService->createFromBill($userId, $bill, $lastPaid, 'cleared');
        $this->applySplitTemplate($bill, $transaction, $userId);

        return ['transaction' => $transaction];
    }

    /**
     * Whether any of the given transaction dates plausibly records a payment
     * made on $paidDate. Linked payments can be dated a few days off the
     * paid date (the matching dialog offers a ±7 day window), so allow slack.
     */
    private function hasRecordedPayment(?string $paidDate, array $txDates): bool {
        if ($paidDate === null) {
            return false;
        }
        foreach ($txDates as $txDate) {
            if (abs(strtotime($txDate) - strtotime($paidDate)) <= 14 * 86400) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a map of accountId => currency for the user's accounts.
     */
    private function buildCurrencyMap(string $userId): array {
        $accounts = $this->accountMapper->findAll($userId);
        $map = [];
        foreach ($accounts as $account) {
            $map[$account->getId()] = $account->getCurrency() ?: null;
        }
        return $map;
    }

    /**
     * Set the non-persisted currency property on each bill from its linked account.
     *
     * @param Bill[] $bills
     * @return Bill[]
     */
    public function enrichBillsWithCurrency(array $bills, string $userId): array {
        $currencyMap = $this->buildCurrencyMap($userId);
        $baseCurrency = $this->currencyConversion->getBaseCurrency($userId);
        foreach ($bills as $bill) {
            $accountId = $bill->getAccountId();
            $bill->setCurrency($accountId !== null && isset($currencyMap[$accountId])
                ? $currencyMap[$accountId]
                : $baseCurrency);
        }
        return $bills;
    }

    public function findByType(string $userId, ?bool $isTransfer = null, ?bool $isActive = null): array {
        return $this->mapper->findByType($userId, $isTransfer, $isActive);
    }

    public function findOverdue(string $userId): array {
        return $this->mapper->findOverdue($userId);
    }

    public function findDueThisMonth(string $userId): array {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        return $this->mapper->findDueInRange($userId, $startDate, $endDate);
    }

    /**
     * Find upcoming bills (including overdue) sorted by due date.
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        $overdue = $this->mapper->findOverdue($userId);
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $upcoming = $this->mapper->findDueInRange($userId, $startDate, $endDate);

        $allBills = array_merge($overdue, $upcoming);

        // Remove duplicates
        $seen = [];
        $uniqueBills = [];
        foreach ($allBills as $bill) {
            $id = $bill->getId();
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $uniqueBills[] = $bill;
            }
        }

        // Sort by next due date
        usort($uniqueBills, function($a, $b) {
            $dateA = $a->getNextDueDate() ?? '9999-12-31';
            $dateB = $b->getNextDueDate() ?? '9999-12-31';
            return strcmp($dateA, $dateB);
        });

        return $uniqueBills;
    }

    public function create(
        string $userId,
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $dueDay = null,
        ?int $dueMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $autoDetectPattern = null,
        ?string $description = null,
        ?string $notes = null,
        ?int $reminderDays = null,
        ?string $customRecurrencePattern = null,
        bool $createTransaction = true,
        ?string $transactionDate = null,
        bool $autoPayEnabled = false,
        bool $isTransfer = false,
        ?int $destinationAccountId = null,
        ?string $transferDescriptionPattern = null,
        array $tagIds = [],
        ?string $endDate = null,
        ?int $remainingPayments = null,
        ?array $splitTemplate = null,
        ?string $startDate = null,
        bool $excludedFromForecast = false,
        string $amountType = 'fixed'
    ): Bill {
        // Validate auto-pay requires account
        if ($autoPayEnabled && $accountId === null) {
            throw new \InvalidArgumentException($this->l->t('Auto-pay requires an account to be set'));
        }

        // Validate transfer requires destination account
        if ($isTransfer && $destinationAccountId === null) {
            throw new \InvalidArgumentException($this->l->t('Transfer requires a destination account'));
        }

        // Validate transfer cannot have same source and destination
        if ($isTransfer && $accountId !== null && $accountId === $destinationAccountId) {
            throw new \InvalidArgumentException($this->l->t('Cannot transfer to the same account'));
        }

        $this->validateAmountType($amountType, $isTransfer, $destinationAccountId);
        if ($amountType !== 'fixed') {
            // Initial estimate: resolved against the card right now. Refined to
            // the actual amount on every markPaid().
            $amount = $this->resolveDynamicAmount($amountType, $destinationAccountId, null, date('Y-m-d')) ?? $amount;
        }

        $bill = new Bill();
        $bill->setUserId($userId);
        $bill->setName($name);
        $bill->setAmount($amount);
        $bill->setAmountType($amountType);
        $bill->setFrequency($frequency);
        $bill->setDueDay($dueDay);
        $bill->setDueMonth($dueMonth);
        $bill->setCategoryId($categoryId);
        $bill->setAccountId($accountId);
        $bill->setAutoDetectPattern($autoDetectPattern);
        $bill->setDescription($description);
        $bill->setIsActive(true);
        $bill->setNotes($notes);
        $bill->setReminderDays($reminderDays);
        $bill->setCustomRecurrencePattern($customRecurrencePattern);
        $bill->setAutoPayEnabled($autoPayEnabled);
        $bill->setAutoPayFailed(false);
        $bill->setIsTransfer($isTransfer);
        $bill->setDestinationAccountId($destinationAccountId);
        $bill->setTransferDescriptionPattern($transferDescriptionPattern);
        $bill->setTagIdsArray($tagIds);
        $bill->setStartDate($startDate);
        $bill->setEndDate($endDate);
        $bill->setRemainingPayments($remainingPayments);
        $bill->setExcludedFromForecast($excludedFromForecast);
        $bill->setCreateTransaction($createTransaction);
        if ($splitTemplate !== null) {
            $bill->setSplitTemplateArray($splitTemplate);
            // When splits define the categories, clear the bill-level category
            $bill->setCategoryId(null);
        }
        $bill->setCreatedAt(date('Y-m-d H:i:s'));

        // startDate doubles as the recurrence anchor for weekly/biweekly:
        // occurrences fall on startDate + n*interval, so the week parity is
        // fixed by the user's chosen first payment date, not by when the
        // bill happened to be created (#364)
        $nextDue = $this->frequencyCalculator->calculateNextDueDate($frequency, $dueDay, $dueMonth, null, $customRecurrencePattern, false, $startDate);
        $nextDue = $this->applyStartDateFloor($nextDue, $startDate, $frequency, $dueDay, $dueMonth, $customRecurrencePattern);
        $bill->setNextDueDate($nextDue);

        $bill = $this->mapper->insert($bill);

        // Create future transaction if requested and bill has account
        if ($createTransaction && $accountId !== null) {
            try {
                $transaction = $this->transactionService->createFromBill(
                    $userId,
                    $bill,
                    $transactionDate
                );
                $this->applySplitTemplate($bill, $transaction, $userId);
            } catch (\Exception $e) {
                // Log error but don't fail bill creation
                $this->logger->warning("Failed to create transaction for bill {$bill->getId()}: {$e->getMessage()}");
            }
        }

        if ($this->autoShareService !== null) {
            $this->autoShareService->autoShareNewEntity($userId, ShareItem::TYPE_BILL, $bill->getId());
        }

        return $bill;
    }

    public function update(int $id, string $userId, array $updates): Bill {
        $bill = $this->find($id, $userId);
        $needsRecalculation = false;
        $dbUpdates = [];

        // Validate auto-pay requires account when enabling
        if (isset($updates['autoPayEnabled']) && $updates['autoPayEnabled'] === true) {
            $currentAccountId = $updates['accountId'] ?? $bill->getAccountId();
            if ($currentAccountId === null) {
                throw new \InvalidArgumentException($this->l->t('Auto-pay requires an account to be set'));
            }
        }

        // Auto-disable auto-pay if account is being removed
        if (array_key_exists('accountId', $updates) && $updates['accountId'] === null) {
            $updates['autoPayEnabled'] = false;
            $updates['autoPayFailed'] = false;
        }

        // Dynamic amount types: validate and refresh the estimate (#347)
        if (array_key_exists('isTransfer', $updates) && !$updates['isTransfer']
            && ($updates['amountType'] ?? $bill->getAmountType() ?? 'fixed') !== 'fixed') {
            // No longer a transfer — nothing to resolve the amount against
            $updates['amountType'] = 'fixed';
        }
        if (isset($updates['amountType']) && $updates['amountType'] !== 'fixed') {
            $isTransfer = $updates['isTransfer'] ?? ($bill->getIsTransfer() ?? false);
            $destinationId = $updates['destinationAccountId'] ?? $bill->getDestinationAccountId();
            $this->validateAmountType($updates['amountType'], (bool) $isTransfer, $destinationId);
            $updates['amount'] = $this->resolveDynamicAmount($updates['amountType'], $destinationId, null, date('Y-m-d'));
        } elseif (isset($updates['amountType'])) {
            $this->validateAmountType($updates['amountType'], true, null);
        }

        foreach ($updates as $key => $value) {
            // Track if schedule-related fields actually changed (not just
            // present). is_callable, not method_exists: the Bill entity's
            // getters are magic (__call), which method_exists cannot see —
            // schedule changes then only fired the recalculation through the
            // date-consistency fallback below, which now deliberately
            // tolerates paid-ahead/overdue dates (#364 review).
            if (in_array($key, ['frequency', 'dueDay', 'dueMonth', 'customRecurrencePattern', 'startDate'])) {
                $getter = 'get' . ucfirst($key);
                if (is_callable([$bill, $getter]) && $bill->$getter() != $value) {
                    $needsRecalculation = true;
                }
            }

            // Convert camelCase to snake_case for database column names
            $columnName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
            $dbUpdates[$columnName] = $value;
        }

        // A stored mark-unpaid snapshot captures the bill's amount and
        // schedule state; restoring it after a deliberate change to any of
        // those would silently revert the edit (or restore a next-due-date
        // computed under the old schedule). A material edit therefore spends
        // the snapshot; name/notes/reminder-style edits keep it (#365 review).
        if ($bill->getPaidUndoState() !== null && $bill->getPaidUndoState() !== '') {
            $materialKeys = ['amount', 'amountType', 'frequency', 'dueDay', 'dueMonth',
                'customRecurrencePattern', 'startDate', 'accountId', 'destinationAccountId', 'isTransfer'];
            foreach ($materialKeys as $key) {
                if (!array_key_exists($key, $updates)) {
                    continue;
                }
                $getter = 'get' . ucfirst($key);
                $current = $bill->$getter();
                // Legacy rows hold NULL where the form sends the default
                if ($key === 'amountType') {
                    $current = $current ?? 'fixed';
                } elseif ($key === 'isTransfer') {
                    $current = $current ?? false;
                }
                if ($current != $updates[$key]) {
                    $dbUpdates['paid_undo_state'] = null;
                    $bill->setPaidUndoState(null);
                    break;
                }
            }
        }

        // Recalculate next due date if schedule fields changed, OR if
        // nextDueDate is inconsistent with the current schedule (catches
        // stale dates from edits on older versions)
        if (!$needsRecalculation && $bill->getIsActive() && $bill->getNextDueDate()) {
            // With a startDate anchor the expectation is computed FROM THE
            // ANCHOR — recomputing "from today" made every unrelated edit in
            // an off-parity week flip a biweekly bill's fortnight (#364).
            // An incoming startDate (set OR cleared) participates, so
            // re-anchoring a bill snaps next due onto the new fortnight.
            $effectiveAnchor = array_key_exists('startDate', $updates)
                ? $updates['startDate']
                : $bill->getStartDate();
            $expect = fn(?string $fromDate): string => $this->frequencyCalculator->calculateNextDueDate(
                $updates['frequency'] ?? $bill->getFrequency(),
                $updates['dueDay'] ?? $bill->getDueDay(),
                $updates['dueMonth'] ?? $bill->getDueMonth(),
                $fromDate,
                $updates['customRecurrencePattern'] ?? $bill->getCustomRecurrencePattern(),
                false,
                $effectiveAnchor
            );

            $storedDue = $bill->getNextDueDate();
            $lastPaid = $bill->getLastPaidDate();
            $today = date('Y-m-d');

            // The stored date is CONSISTENT when it matches the schedule
            // given the bill's payment state — not just "first occurrence
            // from today", which cannot represent a bill legitimately paid
            // ahead or overdue (#364/#365 review):
            //  1. the plain expectation — first occurrence on/after today;
            //  2. the advanced-past-payment expectation — a bill paid on its
            //     due day stores due + one interval, which the calculator's
            //     strictly-after-fromDate semantics reproduce;
            //  3. a due/overdue occurrence not yet paid — snapping it forward
            //     on an unrelated edit would silently un-overdue the bill;
            //  4. a paid bill's future date that sits on the schedule (the
            //     first occurrence strictly after the day before it) —
            //     paid-ahead and skipped-ahead dates are deliberate.
            // Anything else is a stale date and gets recalculated.
            $isConsistent = $storedDue === $expect(null);
            if (!$isConsistent && $lastPaid !== null) {
                $isConsistent = $storedDue === $expect($lastPaid);
            }
            if (!$isConsistent && $storedDue <= $today && ($lastPaid === null || $storedDue > $lastPaid)) {
                $isConsistent = true;
            }
            if (!$isConsistent && $lastPaid !== null && $storedDue > $today) {
                $dayBefore = (new \DateTime($storedDue))->modify('-1 day')->format('Y-m-d');
                $isConsistent = $storedDue === $expect($dayBefore);
            }
            if (!$isConsistent) {
                $needsRecalculation = true;
            }
        }

        if ($needsRecalculation) {
            // Apply updates to get current state for calculation
            foreach ($updates as $key => $value) {
                if (property_exists($bill, $key)) {
                    $setter = 'set' . ucfirst($key);
                    $bill->$setter($value);
                }
            }

            // Recalculate from today (not from the old nextDueDate) since
            // the schedule parameters changed; a startDate anchors the
            // weekly/biweekly parity (#364)
            $nextDue = $this->frequencyCalculator->calculateNextDueDate(
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth(),
                null, // recalculate from today
                $bill->getCustomRecurrencePattern(),
                false,
                $bill->getStartDate()
            );
            $nextDue = $this->applyStartDateFloor(
                $nextDue,
                $bill->getStartDate(),
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth(),
                $bill->getCustomRecurrencePattern()
            );
            $dbUpdates['next_due_date'] = $nextDue;
        }

        // Apply all updates directly to database
        if (!empty($dbUpdates)) {
            $this->mapper->updateFields($id, $userId, $dbUpdates);
        }

        // Toggling "create future transaction" takes effect immediately:
        // off removes the pending placeholder, on creates one for the next
        // occurrence (instead of only changing behaviour at the next payment)
        if (isset($updates['createTransaction'])) {
            $wasEnabled = $bill->getCreateTransaction() ?? true;
            $nowEnabled = (bool) $updates['createTransaction'];
            if ($wasEnabled && !$nowEnabled) {
                $this->transactionService->deleteScheduledBillTransactions($id);
            } elseif (!$wasEnabled && $nowEnabled) {
                $fresh = $this->find($id, $userId);
                if ($fresh->getIsActive() && $fresh->getAccountId() !== null && $fresh->getNextDueDate() !== null) {
                    try {
                        $nextTransaction = $this->transactionService->createFromBill($userId, $fresh, null);
                        $this->applySplitTemplate($fresh, $nextTransaction, $userId);
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to create next transaction after enabling pre-booking on bill {$id}: {$e->getMessage()}");
                    }
                }
            }
        }

        // Switching to a dynamic amount type: replace the pre-created
        // placeholder so it carries the freshly resolved estimate (#347)
        if (isset($updates['amountType']) && $updates['amountType'] !== 'fixed'
            && ($bill->getAmountType() ?? 'fixed') !== $updates['amountType']) {
            $fresh = $this->find($id, $userId);
            if (($fresh->getCreateTransaction() ?? true) && $fresh->getIsActive()
                && $fresh->getAccountId() !== null && $fresh->getNextDueDate() !== null) {
                $this->transactionService->deleteScheduledBillTransactions($id);
                try {
                    $nextTransaction = $this->transactionService->createFromBill($userId, $fresh, null);
                    $this->applySplitTemplate($fresh, $nextTransaction, $userId);
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to refresh placeholder after switching bill {$id} to statement amount: {$e->getMessage()}");
                }
            }
        }

        // Reload from database to ensure we return the actual saved state
        return $this->find($id, $userId);
    }

    public function delete(int $id, string $userId): void {
        $bill = $this->find($id, $userId);
        // Remove scheduled transactions before deleting the bill
        $this->transactionService->deleteScheduledBillTransactions($id);
        $this->mapper->delete($bill);
    }

    /**
     * Mark a bill as paid and advance to next due date.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @param string|null $paidDate Date bill was paid (defaults to the bill's current due date)
     * @param bool $createNextTransaction Whether to create transaction for next occurrence
     * @param int|null $existingTransactionId Link an existing transaction instead of creating a new one
     * @return array Updated bill with undo data
     */
    public function markPaid(int $id, string $userId, ?string $paidDate = null, bool $createNextTransaction = true, ?int $existingTransactionId = null): array {
        $bill = $this->find($id, $userId);

        // Capture previous state for undo support
        $previousState = [
            'lastPaidDate' => $bill->getLastPaidDate(),
            'nextDueDate' => $bill->getNextDueDate(),
            'remainingPayments' => $bill->getRemainingPayments(),
            'isActive' => $bill->getIsActive(),
            'autoPayFailed' => $bill->getAutoPayFailed(),
            'amount' => $bill->getAmount(),
        ];
        $createdTransactionIds = [];
        // The next-occurrence placeholder is tracked separately from the
        // payment legs: by the time the snapshot is used it may have
        // materialised into a real (possibly reconciled) ledger row, and the
        // revert must then leave it alone (#365 review).
        $scheduledTransactionIds = [];
        // A pre-existing transaction LINKED to record the payment (dialog
        // choice or import auto-match) is unlinked on revert, never deleted.
        $linkedTransactionId = null;
        $hadScheduledTransaction = false;
        $linkedExistingTransaction = false;
        $paymentTransactionRecorded = false;

        // Reset auto-pay failed flag on successful manual payment
        if ($bill->getAutoPayFailed()) {
            $bill->setAutoPayFailed(false);
        }

        // Use today's date as the paid/transaction date so the payment appears
        // immediately in the account balance, regardless of when the bill was due.
        $paidDate = $paidDate ?? date('Y-m-d');
        $bill->setLastPaidDate($paidDate);

        // Dynamic-amount bills resolve their amount now: what the card calls
        // for at this payment. Persisting it into the bill keeps lists,
        // summaries and the next placeholder showing a real number, and the
        // next placeholder inherits it as the estimate (#347).
        $statementAmount = null;
        if ($bill->getIsTransfer() ?? false) {
            $statementAmount = $this->resolveDynamicAmount(
                $bill->getAmountType() ?? 'fixed',
                $bill->getDestinationAccountId(),
                $bill->getNextDueDate(),
                $paidDate
            );
            if ($statementAmount !== null) {
                $bill->setAmount($statementAmount);
            }
        }

        // Handle transaction: either link existing or create new
        if ($existingTransactionId !== null && $bill->getAccountId() !== null) {
            // User chose to link an existing transaction instead of creating a new one
            try {
                // Clear any pre-existing scheduled transactions for this bill
                $this->transactionService->deleteScheduledBillTransactions($bill->getId());

                // Link the existing transaction to this bill
                $this->transactionService->update($existingTransactionId, $userId, [
                    'billId' => $bill->getId(),
                ]);
                $linkedExistingTransaction = true;
                $linkedTransactionId = $existingTransactionId;
            } catch (\Exception $e) {
                $this->logger->warning("Failed to link existing transaction {$existingTransactionId} to bill {$id}: {$e->getMessage()}");
            }
        } elseif ($createNextTransaction && $bill->getAccountId() !== null) {
            try {
                // Clear pre-existing scheduled transaction(s), or create new cleared one
                $transaction = $this->transactionService->clearScheduledBillTransaction($userId, $bill->getId(), $paidDate, $statementAmount);
                if ($transaction) {
                    $hadScheduledTransaction = true;
                } else {
                    $transaction = $this->transactionService->createFromBill($userId, $bill, $paidDate, 'cleared');
                }
                $createdTransactionIds[] = $transaction->getId();
                $paymentTransactionRecorded = true;
                // For transfers, also track the linked deposit transaction
                if ($transaction->getLinkedTransactionId()) {
                    $createdTransactionIds[] = $transaction->getLinkedTransactionId();
                }

                // Apply split template if defined
                $this->applySplitTemplate($bill, $transaction, $userId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to create transaction for bill {$id}: {$e->getMessage()}");
            }
        }

        // Auto-deactivate one-time bills after payment
        if ($bill->getFrequency() === 'one-time') {
            $bill->setIsActive(false);
            $bill->setNextDueDate(null);
        } else {
            $nextDue = $this->frequencyCalculator->calculateNextDueDate(
                $bill->getFrequency(),
                $bill->getDueDay(),
                $bill->getDueMonth(),
                $bill->getNextDueDate(),
                $bill->getCustomRecurrencePattern(),
                true // Always force advance — the bill was just paid
            );
            $bill->setNextDueDate($nextDue);

            // Decrement remaining payments if set
            $remaining = $bill->getRemainingPayments();
            if ($remaining !== null) {
                $remaining--;
                $bill->setRemainingPayments($remaining);
                if ($remaining <= 0) {
                    $bill->setIsActive(false);
                    $bill->setNextDueDate(null);
                }
            }

            // Deactivate if next due date exceeds end date
            $endDate = $bill->getEndDate();
            if ($endDate !== null && $bill->getNextDueDate() !== null && $bill->getNextDueDate() > $endDate) {
                $bill->setIsActive(false);
                $bill->setNextDueDate(null);
            }
        }

        $bill = $this->mapper->update($bill);

        // Auto-create transaction for next occurrence if bill has account
        // Skip for deactivated bills (one-time, end date reached, remaining payments exhausted)
        // and bills that opted out of pre-created transactions (null = legacy rows, treated as opted in)
        if ($createNextTransaction && ($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null) {
            try {
                $nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
                $scheduledTransactionIds[] = $nextTransaction->getId();
                // For transfers, also track the linked deposit transaction
                if ($nextTransaction->getLinkedTransactionId()) {
                    $scheduledTransactionIds[] = $nextTransaction->getLinkedTransactionId();
                }
                $this->applySplitTemplate($bill, $nextTransaction, $userId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to create next transaction for bill {$id}: {$e->getMessage()}");
            }
        }

        // Persist the undo payload so "mark as unpaid" survives page reloads
        // and covers auto-pay / import-match payments too (#365). Overwrites
        // any previous snapshot: only the latest payment is revertible.
        // The payment itself is already committed — a DB error here may only
        // cost the durable undo, never fail the payment.
        $bill->setPaidUndoState(json_encode([
            'previousState' => $previousState,
            'createdTransactionIds' => $createdTransactionIds,
            'scheduledTransactionIds' => $scheduledTransactionIds,
            'linkedTransactionId' => $linkedTransactionId,
            'hadScheduledTransaction' => $hadScheduledTransaction,
            'paidDate' => $paidDate,
        ]));
        try {
            $bill = $this->mapper->update($bill);
        } catch (\Exception $e) {
            // A snapshot that failed to persist must not be advertised as a
            // working Mark Unpaid; the toast-based undo still has the ids below.
            $bill->setPaidUndoState(null);
            $this->logger->warning("Failed to persist the undo snapshot for bill {$id}: {$e->getMessage()}");
        }

        return [
            'bill' => $bill,
            'previousState' => $previousState,
            // The toast-based undo path deletes everything it is given right
            // away, so it keeps receiving payment legs and placeholder alike.
            'createdTransactionIds' => array_merge($createdTransactionIds, $scheduledTransactionIds),
            'hadScheduledTransaction' => $hadScheduledTransaction,
            'linkedExistingTransaction' => $linkedExistingTransaction,
            // False = the bill was marked paid but NO money movement was
            // recorded (no account on the bill, creation failed, or the user
            // neither created nor linked a transaction). The UI must surface
            // this — silently recording nothing made app balances drift from
            // real bank balances (#89, #274).
            'paymentTransactionRecorded' => $paymentTransactionRecorded || $linkedExistingTransaction,
            // Non-null only for statement bills: the amount resolved for
            // this payment (#347)
            'statementAmount' => $statementAmount,
        ];
    }

    /**
     * Undo a mark-paid action, restoring the bill to its previous state
     * and deleting any transactions that were created.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @param array $previousState Previous bill field values to restore
     * @param int[] $createdTransactionIds Transaction IDs created by markPaid to delete
     * @param bool $hadScheduledTransaction Whether a scheduled transaction existed before markPaid
     * @param int[] $scheduledTransactionIds Next-occurrence placeholder IDs — deleted only while still 'scheduled'
     * @param int|null $linkedTransactionId Pre-existing transaction linked by markPaid — unlinked, never deleted
     * @return Bill Restored bill
     */
    public function undoPaid(int $id, string $userId, array $previousState, array $createdTransactionIds, bool $hadScheduledTransaction = false, array $scheduledTransactionIds = [], ?int $linkedTransactionId = null): Bill {
        $bill = $this->find($id, $userId);

        // Delete the transactions markPaid recorded. They were created under
        // the ACCOUNT owner (#334) — for a bill on a shared account that is
        // not the acting user, and deleting under the acting user missed
        // every row: the revert left the payment in the ledger while the
        // bill claimed unpaid.
        foreach ($createdTransactionIds as $transactionId) {
            try {
                $this->transactionService->deleteAsAccountOwner((int) $transactionId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to delete transaction {$transactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
            }
        }

        // The next-occurrence placeholder may have materialised into a real
        // (possibly reconciled) ledger row since the payment — remove it only
        // while it is still a scheduled placeholder (#365 review).
        foreach ($scheduledTransactionIds as $transactionId) {
            try {
                $this->transactionService->deleteAsAccountOwner((int) $transactionId, true);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to delete scheduled transaction {$transactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
            }
        }

        // A linked pre-existing transaction was never created by markPaid:
        // it predates the payment and survives the revert — only the bill
        // linkage is undone.
        if ($linkedTransactionId !== null) {
            try {
                $this->transactionService->unlinkBillAsAccountOwner($linkedTransactionId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to unlink transaction {$linkedTransactionId} during undo-paid for bill {$id}: {$e->getMessage()}");
            }
        }

        // Restore previous bill state
        $bill->setLastPaidDate($previousState['lastPaidDate'] ?? null);
        $bill->setNextDueDate($previousState['nextDueDate'] ?? null);
        $bill->setIsActive($previousState['isActive'] ?? true);
        if (array_key_exists('remainingPayments', $previousState)) {
            $bill->setRemainingPayments($previousState['remainingPayments']);
        }
        if (array_key_exists('autoPayFailed', $previousState)) {
            $bill->setAutoPayFailed($previousState['autoPayFailed'] ?? false);
        }
        // Statement bills overwrite the amount on markPaid — restore it.
        // Older clients round-trip undo data without this key.
        if (array_key_exists('amount', $previousState) && $previousState['amount'] !== null) {
            $bill->setAmount((float) $previousState['amount']);
        }

        // The payment was reverted — its persisted undo snapshot is spent (#365)
        $bill->setPaidUndoState(null);

        $bill = $this->mapper->update($bill);

        // Only recreate scheduled transaction if one existed before markPaid
        if ($hadScheduledTransaction && $bill->getIsActive() && $bill->getAccountId() !== null && $bill->getNextDueDate() !== null) {
            try {
                $nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
                $this->applySplitTemplate($bill, $nextTransaction, $userId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to recreate scheduled transaction after undo-paid for bill {$id}: {$e->getMessage()}");
            }
        }

        return $bill;
    }

    /**
     * Durable "mark as unpaid": revert the last markPaid from the snapshot
     * persisted on the bill, so the revert works long after the undo toast is
     * gone — across reloads, and for auto-paid or import-matched bills that
     * never showed a toast at all (#365).
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @return Bill Restored bill
     * @throws \InvalidArgumentException when no payment snapshot is stored
     */
    public function markUnpaid(int $id, string $userId): Bill {
        $bill = $this->find($id, $userId);

        $raw = $bill->getPaidUndoState();
        $decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_array($decoded['previousState'] ?? null)) {
            throw new \InvalidArgumentException($this->l->t('This bill has no recorded payment to undo'));
        }

        // A corrupt blob must fail exactly like a missing snapshot — the
        // controller's catches do not cover a TypeError from array_map (#365
        // review). Older snapshots carry the placeholder inside
        // createdTransactionIds and no linked id; both stay valid.
        $createdIds = $decoded['createdTransactionIds'] ?? [];
        $scheduledIds = $decoded['scheduledTransactionIds'] ?? [];
        $linkedId = $decoded['linkedTransactionId'] ?? null;
        $allNumeric = static fn(array $ids): bool => array_filter($ids, static fn($v) => !is_numeric($v)) === [];
        if (!is_array($createdIds) || !is_array($scheduledIds)
            || !$allNumeric($createdIds) || !$allNumeric($scheduledIds)
            || ($linkedId !== null && !is_numeric($linkedId))) {
            throw new \InvalidArgumentException($this->l->t('This bill has no recorded payment to undo'));
        }

        // undoPaid deletes the created transactions tolerantly, restores the
        // snapshot fields (including a statement amount that cannot be
        // re-derived, #347) and clears the spent snapshot.
        return $this->undoPaid(
            $id,
            $userId,
            $decoded['previousState'],
            array_map('intval', $createdIds),
            (bool) ($decoded['hadScheduledTransaction'] ?? false),
            array_map('intval', $scheduledIds),
            $linkedId !== null ? (int) $linkedId : null
        );
    }

    /**
     * Skip a bill payment, advancing to the next due date without creating a transaction.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @return Bill Updated bill
     */
    public function skipPayment(int $id, string $userId): array {
        $bill = $this->find($id, $userId);

        if ($bill->getFrequency() === 'one-time') {
            throw new \InvalidArgumentException($this->l->t('Cannot skip a one-time bill'));
        }

        if (!$bill->getIsActive()) {
            throw new \InvalidArgumentException($this->l->t('Cannot skip an inactive bill'));
        }

        $previousNextDueDate = $bill->getNextDueDate();

        // Delete any pre-existing scheduled transaction for the skipped occurrence
        $this->transactionService->deleteScheduledBillTransactions($id);

        $nextDue = $this->frequencyCalculator->calculateNextDueDate(
            $bill->getFrequency(),
            $bill->getDueDay(),
            $bill->getDueMonth(),
            $bill->getNextDueDate(),
            $bill->getCustomRecurrencePattern(),
            true // forceAdvance: always advance one cycle, even if not yet overdue
        );
        $bill->setNextDueDate($nextDue);

        // Deactivate if next due date exceeds end date
        $endDate = $bill->getEndDate();
        if ($endDate !== null && $bill->getNextDueDate() !== null && $bill->getNextDueDate() > $endDate) {
            $bill->setIsActive(false);
            $bill->setNextDueDate(null);
        }

        // The skip moved next_due_date — a mark-unpaid snapshot restored
        // after it would bring back a pre-skip date, so it is spent (#365 review)
        $bill->setPaidUndoState(null);

        $bill = $this->mapper->update($bill);

        // Create scheduled transaction for the new next occurrence,
        // unless the bill opted out of pre-created transactions
        if (($bill->getCreateTransaction() ?? true) && $bill->getIsActive() && $bill->getAccountId() !== null) {
            try {
                $nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
                $this->applySplitTemplate($bill, $nextTransaction, $userId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to create next transaction after skipping bill {$id}: {$e->getMessage()}");
            }
        }

        return [
            'bill' => $bill,
            'previousNextDueDate' => $previousNextDueDate,
        ];
    }

    /**
     * Undo a skipped bill payment, reverting to the previous due date.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @param string $previousNextDueDate The due date to restore
     * @return Bill Updated bill
     */
    public function undoSkip(int $id, string $userId, string $previousNextDueDate): Bill {
        $bill = $this->find($id, $userId);

        // Delete scheduled transaction for the advanced (wrong) date
        $this->transactionService->deleteScheduledBillTransactions($id);

        // Restore previous due date and reactivate if needed
        $bill->setNextDueDate($previousNextDueDate);
        if (!$bill->getIsActive() && $bill->getFrequency() !== 'one-time') {
            $bill->setIsActive(true);
        }

        $bill = $this->mapper->update($bill);

        // Recreate scheduled transaction for the restored date
        if ($bill->getIsActive() && $bill->getAccountId() !== null) {
            try {
                $nextTransaction = $this->transactionService->createFromBill($userId, $bill, null);
                $this->applySplitTemplate($bill, $nextTransaction, $userId);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to recreate transaction after undoing skip for bill {$id}: {$e->getMessage()}");
            }
        }

        return $bill;
    }

    /**
     * Get monthly summary of bills.
     */
    public function getMonthlySummary(string $userId): array {
        $bills = $this->findActive($userId);
        $currencyMap = $this->buildCurrencyMap($userId);
        $baseCurrency = $this->currencyConversion->getBaseCurrency($userId);

        $total = 0.0;
        $dueThisMonth = 0;
        $overdue = 0;
        $paidThisMonth = 0;
        $byCategory = [];
        $byFrequency = [
            'daily' => 0.0,
            'weekly' => 0.0,
            'biweekly' => 0.0,
            'semi-monthly' => 0.0,
            'monthly' => 0.0,
            'quarterly' => 0.0,
            'semi-annually' => 0.0,
            'yearly' => 0.0,
            'one-time' => 0.0,
            'custom' => 0.0,
        ];

        $today = date('Y-m-d');
        $startOfMonth = date('Y-m-01');
        $endOfMonth = date('Y-m-t');

        foreach ($bills as $bill) {
            $monthlyAmount = $this->frequencyCalculator->getMonthlyEquivalent($bill);

            // Convert to base currency if the bill's account uses a different currency
            $billCurrency = ($bill->getAccountId() !== null && isset($currencyMap[$bill->getAccountId()]))
                ? $currencyMap[$bill->getAccountId()]
                : $baseCurrency;
            $convertedMonthly = $this->convertToBase($monthlyAmount, $billCurrency, $baseCurrency, $userId);
            $convertedAmount = $this->convertToBase($bill->getAmount(), $billCurrency, $baseCurrency, $userId);

            $total += $convertedMonthly;

            $freq = $bill->getFrequency();
            if (isset($byFrequency[$freq])) {
                $byFrequency[$freq] += $convertedAmount;
            }

            $catId = $bill->getCategoryId() ?? 0;
            if (!isset($byCategory[$catId])) {
                $byCategory[$catId] = 0.0;
            }
            $byCategory[$catId] += $convertedMonthly;

            // Check if due this month
            $nextDue = $bill->getNextDueDate();
            if ($nextDue && $nextDue >= $startOfMonth && $nextDue <= $endOfMonth) {
                $dueThisMonth++;
            }

            // Check if overdue
            if ($nextDue && $nextDue < $today) {
                $isPaid = $this->checkIfPaidInPeriod($bill, $startOfMonth, $endOfMonth);
                if (!$isPaid) {
                    $overdue++;
                }
            }

            // Check if paid this month
            if ($this->checkIfPaidInPeriod($bill, $startOfMonth, $endOfMonth)) {
                $paidThisMonth++;
            }
        }

        return [
            'totalMonthly' => $total,
            'monthlyTotal' => $total, // Alias for frontend compatibility
            'totalYearly' => $total * 12,
            'billCount' => count($bills),
            'dueThisMonth' => $dueThisMonth,
            'overdue' => $overdue,
            'paidThisMonth' => $paidThisMonth,
            'byCategory' => $byCategory,
            'byFrequency' => $byFrequency,
            'baseCurrency' => $baseCurrency,
        ];
    }

    /**
     * Apply a bill's split template to a newly created transaction.
     */
    private function applySplitTemplate(Bill $bill, $transaction, string $userId): void {
        $splits = $bill->getSplitTemplateArray();
        if (empty($splits) || $transaction === null) {
            return;
        }

        try {
            $splitData = array_map(function ($split) {
                return [
                    'categoryId' => isset($split['categoryId']) ? (int) $split['categoryId'] : null,
                    'amount' => (float) $split['amount'],
                    'description' => $split['description'] ?? null,
                ];
            }, $splits);

            // Splits are scoped to the transaction's account owner, which may
            // differ from the acting user when the bill points at a shared
            // account (#334).
            $ownerUserId = $this->accountMapper->findById($transaction->getAccountId())->getUserId();
            $this->splitService->splitTransaction($transaction->getId(), $ownerUserId, $splitData);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to apply split template to transaction {$transaction->getId()}: {$e->getMessage()}");
        }
    }

    /**
     * Convert an amount to base currency if needed. Returns unchanged if already base.
     */
    private function convertToBase(float $amount, ?string $fromCurrency, string $baseCurrency, string $userId): float {
        if ($fromCurrency === null || $fromCurrency === $baseCurrency) {
            return $amount;
        }
        return $this->currencyConversion->convertToBaseFloat($amount, $fromCurrency, $userId);
    }

    /**
     * Get bill status for current month showing paid/unpaid.
     */
    public function getBillStatusForMonth(string $userId, ?string $month = null): array {
        $month = $month ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $bills = $this->mapper->findDueInRange($userId, $startDate, $endDate);
        $result = [];

        foreach ($bills as $bill) {
            $isPaid = $this->checkIfPaidInPeriod($bill, $startDate, $endDate);

            $result[] = [
                'bill' => $bill,
                'isPaid' => $isPaid,
                'dueDate' => $bill->getNextDueDate(),
                'isOverdue' => !$isPaid && $bill->getNextDueDate() < date('Y-m-d'),
            ];
        }

        return $result;
    }

    /**
     * Auto-detect recurring bills from transaction history.
     */
    public function detectRecurringBills(string $userId, int $months = 6): array {
        return $this->recurringDetector->detectRecurringBills($userId, $months);
    }

    /**
     * Create bills from detected patterns.
     */
    public function createFromDetected(string $userId, array $detected): array {
        $created = [];

        foreach ($detected as $item) {
            $isTransfer = !empty($item['isTransfer']);
            $destinationAccountId = isset($item['destinationAccountId']) ? (int) $item['destinationAccountId'] : null;

            // Named arguments — create() has 25 parameters and positional
            // calls here previously drifted (a missing value pushed `false`
            // into the ?string customRecurrencePattern slot, 500-ing every
            // detect-and-add). Names keep this aligned.
            $bill = $this->create(
                userId: $userId,
                name: $item['suggestedName'] ?? $item['description'],
                amount: (float) $item['amount'],
                frequency: $item['frequency'],
                dueDay: isset($item['dueDay']) ? (int) $item['dueDay'] : null,
                categoryId: $isTransfer ? null : (isset($item['categoryId']) ? (int) $item['categoryId'] : null),
                accountId: isset($item['accountId']) ? (int) $item['accountId'] : null,
                autoDetectPattern: $item['autoDetectPattern'] ?? null,
                isTransfer: $isTransfer,
                destinationAccountId: $destinationAccountId,
            );
            $created[] = $bill;
        }

        return $created;
    }

    /**
     * Check if a transaction matches any bill's auto-detect pattern.
     */
    public function matchTransactionToBill(string $userId, string $description, float $amount): ?Bill {
        $bills = $this->findActive($userId);

        foreach ($bills as $bill) {
            $pattern = $bill->getAutoDetectPattern();
            if (empty($pattern)) {
                continue;
            }

            if (stripos($description, $pattern) !== false) {
                $billAmount = $bill->getAmount();
                if (abs($amount - $billAmount) <= $billAmount * 0.1) {
                    return $bill;
                }
            }
        }

        return null;
    }

    /**
     * Auto-mark bills paid from freshly imported transactions (#274).
     *
     * For each imported debit that matches an active bill's auto-detect
     * pattern (amount within 10%, account matching when the bill has one,
     * transaction date within the bill's current due window), the bill is
     * marked paid with the transaction LINKED — no duplicate money movement
     * is ever recorded. Marking paid advances the due date, so a second
     * same-period transaction in the batch falls outside the new window and
     * cannot double-advance the bill.
     *
     * @param \OCA\Budget\Db\Transaction[] $transactions freshly created rows
     * @return int number of bills marked paid
     */
    public function autoMatchPaidFromImport(string $userId, array $transactions): int {
        if (empty($transactions)) {
            return 0;
        }

        $bills = array_filter(
            $this->findActive($userId),
            fn(Bill $bill) => !($bill->getIsTransfer() ?? false)
                && !empty($bill->getAutoDetectPattern())
                && $bill->getNextDueDate() !== null
        );
        if (empty($bills)) {
            return 0;
        }

        $marked = 0;
        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'debit') {
                continue;
            }
            if (($transaction->getStatus() ?? 'cleared') === 'scheduled') {
                continue;
            }

            foreach ($bills as $key => $bill) {
                if (!$this->importedTransactionMatchesBill($bill, $transaction)) {
                    continue;
                }

                try {
                    $this->markPaid($bill->getId(), $userId, $transaction->getDate(), false, $transaction->getId());
                    $marked++;
                    // Reload: the due date advanced (or the bill deactivated),
                    // which is what keeps this loop from double-advancing
                    $bills[$key] = $this->find($bill->getId(), $userId);
                    if (!$bills[$key]->getIsActive() || $bills[$key]->getNextDueDate() === null) {
                        unset($bills[$key]);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        "Auto-match failed marking bill {$bill->getId()} paid from imported transaction {$transaction->getId()}: {$e->getMessage()}"
                    );
                }
                break; // one bill per transaction
            }
        }

        return $marked;
    }

    /**
     * Match an imported transaction against a bill: pattern in description
     * or vendor, amount within 10%, account agreement, and the transaction
     * date inside the bill's current due window (so historical re-imports
     * can't advance bills through future periods).
     */
    private function importedTransactionMatchesBill(Bill $bill, \OCA\Budget\Db\Transaction $transaction): bool {
        $pattern = (string) $bill->getAutoDetectPattern();
        $haystack = $transaction->getDescription() . ' ' . ($transaction->getVendor() ?? '');
        if (stripos($haystack, $pattern) === false) {
            return false;
        }

        $billAmount = (float) $bill->getAmount();
        if ($billAmount <= 0 || abs((float) $transaction->getAmount() - $billAmount) > $billAmount * 0.1) {
            return false;
        }

        if ($bill->getAccountId() !== null && $bill->getAccountId() !== $transaction->getAccountId()) {
            return false;
        }

        $dueDate = $bill->getNextDueDate();
        $daysOff = abs((strtotime($transaction->getDate()) - strtotime($dueDate)) / 86400);

        return $daysOff <= $this->dueDateToleranceDays($bill->getFrequency());
    }

    /**
     * How far an imported payment may sit from the due date and still count
     * as paying THIS occurrence — roughly half the recurrence interval,
     * capped so monthly+ bills accept payments up to two weeks early/late.
     */
    private function dueDateToleranceDays(?string $frequency): int {
        return match ($frequency) {
            'daily' => 1,
            'weekly' => 3,
            'biweekly' => 6,
            'semi-monthly' => 7,
            default => 15, // monthly, quarterly, semi-annually, yearly, custom, one-time
        };
    }

    /**
     * Attempt to auto-pay a bill and handle success/failure.
     *
     * @param int $id Bill ID
     * @param string $userId User ID
     * @return array ['success' => bool, 'message' => string, 'bill' => ?Bill]
     */
    public function processAutoPay(int $id, string $userId): array {
        try {
            $bill = $this->find($id, $userId);

            // Validate auto-pay is enabled and account exists
            if (!$bill->getAutoPayEnabled()) {
                return [
                    'success' => false,
                    'message' => $this->l->t('Auto-pay is not enabled for this bill'),
                    'bill' => null,
                ];
            }

            if ($bill->getAccountId() === null) {
                // Disable auto-pay and mark as failed
                $this->mapper->updateFields($id, $userId, [
                    'auto_pay_enabled' => false,
                    'auto_pay_failed' => true,
                ]);
                return [
                    'success' => false,
                    'message' => $this->l->t('Bill has no account associated'),
                    'bill' => $this->find($id, $userId),
                ];
            }

            // Mark bill as paid
            $result = $this->markPaid($id, $userId, null, true);

            return [
                'success' => true,
                'message' => $this->l->t('Bill auto-paid successfully'),
                'bill' => $result['bill'],
            ];

        } catch (\Exception $e) {
            // Mark auto-pay as failed and disable it
            try {
                $this->mapper->updateFields($id, $userId, [
                    'auto_pay_enabled' => false,
                    'auto_pay_failed' => true,
                ]);
                $bill = $this->find($id, $userId);
            } catch (\Exception $e2) {
                $bill = null;
            }

            return [
                'success' => false,
                'message' => 'Auto-pay failed: ' . $e->getMessage(),
                'bill' => $bill,
            ];
        }
    }

    private function checkIfPaidInPeriod(Bill $bill, string $startDate, string $endDate): bool {
        $lastPaid = $bill->getLastPaidDate();
        if (!$lastPaid) {
            return false;
        }
        return $lastPaid >= $startDate && $lastPaid <= $endDate;
    }

    /**
     * Get annual overview of bills showing which months each bill occurs
     *
     * @param string $userId User ID
     * @param int $year Year to generate overview for
     * @param bool $includeTransfers Include transfer bills
     * @param string $billStatus 'active', 'inactive', or 'all'
     * @param int|null $accountId Filter by account ID (source or destination)
     * @return array Bills with monthly occurrences and totals
     */
    public function getAnnualOverview(string $userId, int $year, bool $includeTransfers = false, string $billStatus = 'active', ?int $accountId = null): array {
        // Determine which bills to fetch based on status
        $bills = [];
        if ($billStatus === 'active') {
            $isActive = true;
        } elseif ($billStatus === 'inactive') {
            $isActive = false;
        } else {
            $isActive = null; // All bills
        }

        // Fetch bills with type filter
        if ($includeTransfers) {
            $bills = $this->mapper->findByType($userId, null, $isActive);
        } else {
            $bills = $this->mapper->findByType($userId, false, $isActive);
        }

        // Filter by account if specified (match source or destination account)
        if ($accountId !== null) {
            $bills = array_filter($bills, function ($bill) use ($accountId) {
                return $bill->getAccountId() === $accountId
                    || $bill->getDestinationAccountId() === $accountId;
            });
        }

        // Build currency map for conversion
        $currencyMap = $this->buildCurrencyMap($userId);
        $baseCurrency = $this->currencyConversion->getBaseCurrency($userId);

        // Calculate monthly occurrences for each bill
        $billsData = [];
        $monthlyTotals = array_fill(1, 12, 0.0);

        foreach ($bills as $bill) {
            $occurrences = $this->calculateMonthlyOccurrences($bill, $year);

            $billCurrency = ($bill->getAccountId() !== null && isset($currencyMap[$bill->getAccountId()]))
                ? $currencyMap[$bill->getAccountId()]
                : $baseCurrency;

            $billData = [
                'id' => $bill->getId(),
                'name' => $bill->getName(),
                'amount' => $bill->getAmount(),
                'currency' => $billCurrency,
                'frequency' => $bill->getFrequency(),
                'categoryId' => $bill->getCategoryId(),
                'accountId' => $bill->getAccountId(),
                'isActive' => $bill->getIsActive(),
                'isTransfer' => $bill->getIsTransfer() ?? false,
                'destinationAccountId' => $bill->getDestinationAccountId(),
                'lastPaidDate' => $bill->getLastPaidDate(),
                'nextDueDate' => $bill->getNextDueDate(),
                'occurrences' => $occurrences, // Array with month numbers as keys
            ];

            // Add converted amounts to monthly totals
            $convertedAmount = $this->convertToBase($bill->getAmount(), $billCurrency, $baseCurrency, $userId);
            foreach ($occurrences as $month => $occurs) {
                if ($occurs) {
                    $monthlyTotals[$month] += $convertedAmount;
                }
            }

            $billsData[] = $billData;
        }

        return [
            'year' => $year,
            'bills' => $billsData,
            'monthlyTotals' => $monthlyTotals,
            'baseCurrency' => $baseCurrency,
        ];
    }

    /**
     * Ensure the next due date is no earlier than the bill's start date. When a
     * bill starts in the future, its first occurrence should fall on/after the
     * start date rather than the next calendar occurrence from today (#268).
     *
     * Weekly/biweekly bills with a startDate never reach the recursive call:
     * their nextDue already came out of the calculator's anchor path, which by
     * construction never returns a date before the anchor (#364).
     */
    private function applyStartDateFloor(string $nextDue, ?string $startDate, string $frequency, ?int $dueDay, ?int $dueMonth, ?string $customPattern): string {
        if ($startDate === null || $startDate === '' || $startDate <= $nextDue) {
            return $nextDue;
        }
        $fromStart = $this->frequencyCalculator->calculateNextDueDate($frequency, $dueDay, $dueMonth, $startDate, $customPattern);
        return max($fromStart, $startDate);
    }

    /**
     * Calculate which months a bill occurs in for a given year
     *
     * @param Bill $bill The bill entity
     * @param int $year The year to calculate for
     * @return array Array with month numbers (1-12) as keys and boolean values
     */
    private function calculateMonthlyOccurrences(Bill $bill, int $year): array {
        $occurrences = array_fill(1, 12, false);
        $frequency = $bill->getFrequency();
        $dueDay = $bill->getDueDay();
        $dueMonth = $bill->getDueMonth();
        $customPattern = $bill->getCustomRecurrencePattern();

        switch ($frequency) {
            case 'daily':
            case 'weekly':
            case 'biweekly':
            case 'monthly':
                // Occurs every month
                for ($month = 1; $month <= 12; $month++) {
                    $occurrences[$month] = true;
                }
                break;

            case 'quarterly':
                // Quarterly bills occur every 3 months
                // Determine starting month (defaults to Jan, Apr, Jul, Oct)
                $startMonth = $dueMonth ?? 1;

                // Calculate which months it occurs in
                for ($month = $startMonth; $month <= 12; $month += 3) {
                    $occurrences[$month] = true;
                }

                // If startMonth is not 1, 4, 7, or 10, we need to wrap around
                // E.g., if startMonth is 2, then 2, 5, 8, 11
                break;

            case 'semi-annually':
                // Twice per year - every 6 months
                $startMonth = $dueMonth ?? 1;
                $occurrences[$startMonth] = true;
                if ($startMonth + 6 <= 12) {
                    $occurrences[$startMonth + 6] = true;
                }
                break;

            case 'yearly':
                // Only occurs in the specified month
                $month = $dueMonth ?? 1;
                $occurrences[$month] = true;
                break;

            case 'one-time':
                // One-time bills only occur in their specified month
                $month = $dueMonth ?? 1;
                if ($month >= 1 && $month <= 12) {
                    $occurrences[$month] = true;
                }
                break;

            case 'custom':
                // Parse custom pattern
                if ($customPattern) {
                    $pattern = json_decode($customPattern, true);
                    if (is_array($pattern) && isset($pattern['months'])) {
                        foreach ($pattern['months'] as $month) {
                            if ($month >= 1 && $month <= 12) {
                                $occurrences[$month] = true;
                            }
                        }
                    }
                }
                break;
        }

        // Apply start date constraint: remove occurrences before the start date
        $startDate = $bill->getStartDate();
        if ($startDate !== null && $startDate !== '') {
            $startYear = (int) date('Y', strtotime($startDate));
            $startMonth = (int) date('n', strtotime($startDate));

            for ($month = 1; $month <= 12; $month++) {
                if ($year < $startYear || ($year === $startYear && $month < $startMonth)) {
                    $occurrences[$month] = false;
                }
            }
        }

        // Apply end date constraint: remove occurrences after end date
        $endDate = $bill->getEndDate();
        if ($endDate !== null) {
            $endYear = (int) date('Y', strtotime($endDate));
            $endMonth = (int) date('n', strtotime($endDate));

            for ($month = 1; $month <= 12; $month++) {
                if ($year > $endYear || ($year === $endYear && $month > $endMonth)) {
                    $occurrences[$month] = false;
                }
            }
        }

        // Apply remaining payments constraint: cap number of future occurrences
        // But keep past months visible for historical calendar view
        $remaining = $bill->getRemainingPayments();
        if ($remaining !== null && $remaining >= 0) {
            $nextDueDate = $bill->getNextDueDate();
            $nextDueYear = $nextDueDate ? (int) date('Y', strtotime($nextDueDate)) : $year;
            $nextDueMonth = $nextDueDate ? (int) date('n', strtotime($nextDueDate)) : 1;

            $count = 0;
            for ($month = 1; $month <= 12; $month++) {
                if (!$occurrences[$month]) {
                    continue;
                }

                // Keep past months visible (they were already paid)
                if ($year < $nextDueYear || ($year === $nextDueYear && $month < $nextDueMonth)) {
                    continue;
                }

                $count++;
                if ($count > $remaining) {
                    $occurrences[$month] = false;
                }
            }
        }

        return $occurrences;
    }
}
