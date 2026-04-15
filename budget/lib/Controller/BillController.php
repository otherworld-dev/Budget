<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BillController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private BillService $service;
    private ValidationService $validationService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        BillService $service,
        ValidationService $validationService,
        GranularShareService $granularShareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * Get all bills or transfers
     * @NoAdminRequired
     * @param string|bool|null $activeOnly Filter by active status (null = all)
     * @param string|bool|null $isTransfer Filter by type (null = all, true = only transfers, false = only bills)
     */
    public function index($activeOnly = false, $isTransfer = null): DataResponse {
        try {
            // Convert string parameters to boolean
            $activeOnlyBool = $this->toBool($activeOnly);
            $isTransferBool = $isTransfer === null ? null : $this->toBool($isTransfer);

            // Use mapper's findByType if filtering by transfer status
            if ($isTransferBool !== null) {
                $isActive = $activeOnlyBool ? true : null;
                $bills = $this->service->findByType($this->userId, $isTransferBool, $isActive);
            } elseif ($activeOnlyBool) {
                $bills = $this->service->findActive($this->userId);
            } else {
                $bills = $this->service->findAll($this->userId);
            }
            $bills = $this->service->enrichBillsWithCurrency($bills, $this->userId);

            // Merge shared bills
            $shared = $this->granularShareService->getSharedBills($this->userId);
            if (!empty($shared)) {
                $bills = array_merge(
                    array_map(fn($b) => $b->jsonSerialize(), $bills),
                    $shared
                );
                return new DataResponse($bills);
            }
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve bills'));
        }
    }

    /**
     * Convert string/bool to boolean
     */
    private function toBool($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get a single bill
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $bill = $this->service->find($id, $this->getEffectiveUserId());
            $this->service->enrichBillsWithCurrency([$bill], $this->getEffectiveUserId());
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Bill'), ['billId' => $id]);
        }
    }

    /**
     * Create a new bill
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
            }

            // Extract and validate required fields
            if (!isset($data['name']) || !isset($data['amount'])) {
                return new DataResponse(['error' => $this->l->t('Name and amount are required')], Http::STATUS_BAD_REQUEST);
            }

            $name = $data['name'];
            $amount = (float) $data['amount'];
            $frequency = $data['frequency'] ?? 'monthly';
            $dueDay = isset($data['dueDay']) ? (int) $data['dueDay'] : null;
            $dueMonth = isset($data['dueMonth']) ? (int) $data['dueMonth'] : null;
            $categoryId = isset($data['categoryId']) ? (int) $data['categoryId'] : null;
            $accountId = isset($data['accountId']) ? (int) $data['accountId'] : null;
            $autoDetectPattern = $data['autoDetectPattern'] ?? null;
            $notes = $data['notes'] ?? null;
            $reminderDays = isset($data['reminderDays']) ? (int) $data['reminderDays'] : null;
            $customRecurrencePattern = $data['customRecurrencePattern'] ?? null;
            $createTransaction = $data['createTransaction'] ?? false;
            $transactionDate = $data['transactionDate'] ?? null;
            $autoPayEnabled = $data['autoPayEnabled'] ?? false;
            $isTransfer = $data['isTransfer'] ?? false;
            $destinationAccountId = isset($data['destinationAccountId']) ? (int) $data['destinationAccountId'] : null;
            $transferDescriptionPattern = $data['transferDescriptionPattern'] ?? null;
            $tagIds = isset($data['tagIds']) && is_array($data['tagIds']) ? array_map('intval', $data['tagIds']) : [];
            $endDate = $data['endDate'] ?? null;
            $remainingPayments = isset($data['remainingPayments']) ? (int) $data['remainingPayments'] : null;
            $splitTemplate = isset($data['splitTemplate']) && is_array($data['splitTemplate']) ? $data['splitTemplate'] : null;

            // Validate split template if provided
            if ($splitTemplate !== null) {
                $splitValidation = $this->validateSplitTemplate($splitTemplate, $amount);
                if (!$splitValidation['valid']) {
                    return new DataResponse(['error' => $splitValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate auto-pay requires account
            if ($autoPayEnabled && $accountId === null) {
                return new DataResponse(
                    ['error' => $this->l->t('Auto-pay requires an account to be set')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate transfer requires destination account
            if ($isTransfer && $destinationAccountId === null) {
                return new DataResponse(
                    ['error' => $this->l->t('Transfer requires a destination account')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate transfer cannot have same source and destination
            if ($isTransfer && $accountId !== null && $accountId === $destinationAccountId) {
                return new DataResponse(
                    ['error' => $this->l->t('Cannot transfer to the same account')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate frequency
            $frequencyValidation = $this->validationService->validateFrequency($frequency);
            if (!$frequencyValidation['valid']) {
                return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $frequency = $frequencyValidation['formatted'];

            // Validate dueDay range
            if ($dueDay !== null && ($dueDay < 1 || $dueDay > 31)) {
                return new DataResponse(['error' => $this->l->t('Due day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
            }

            // Validate dueMonth range
            if ($dueMonth !== null && ($dueMonth < 1 || $dueMonth > 12)) {
                return new DataResponse(['error' => $this->l->t('Due month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
            }

            // Validate autoDetectPattern if provided
            if ($autoDetectPattern !== null && $autoDetectPattern !== '') {
                $patternValidation = $this->validationService->validatePattern($autoDetectPattern, false);
                if (!$patternValidation['valid']) {
                    return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $autoDetectPattern = $patternValidation['sanitized'];
            }

            // Validate notes if provided
            if ($notes !== null && $notes !== '') {
                $notesValidation = $this->validationService->validateNotes($notes);
                if (!$notesValidation['valid']) {
                    return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $notes = $notesValidation['sanitized'];
            }

            // Validate reminderDays if provided
            if ($reminderDays !== null && ($reminderDays < 0 || $reminderDays > 30)) {
                return new DataResponse(['error' => $this->l->t('Reminder days must be between 0 and 30')], Http::STATUS_BAD_REQUEST);
            }

            // Validate customRecurrencePattern if provided
            if ($customRecurrencePattern !== null && $customRecurrencePattern !== '' && $frequency === 'custom') {
                $patternValidation = $this->validateCustomPattern($customRecurrencePattern);
                if (!$patternValidation['valid']) {
                    return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate transactionDate if provided
            if ($transactionDate !== null && $transactionDate !== '') {
                $dateValidation = $this->validationService->validateDate($transactionDate, $this->l->t('Transaction date'), false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate endDate if provided
            if ($endDate !== null && $endDate !== '') {
                $endDateValidation = $this->validationService->validateDate($endDate, $this->l->t('End date'), false);
                if (!$endDateValidation['valid']) {
                    return new DataResponse(['error' => $endDateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            } else {
                $endDate = null;
            }

            // Validate remainingPayments if provided
            if ($remainingPayments !== null && $remainingPayments < 1) {
                return new DataResponse(['error' => $this->l->t('Remaining payments must be at least 1')], Http::STATUS_BAD_REQUEST);
            }

            $bill = $this->service->create(
                $this->getEffectiveUserId(),
                $name,
                $amount,
                $frequency,
                $dueDay,
                $dueMonth,
                $categoryId,
                $accountId,
                $autoDetectPattern,
                $notes,
                $reminderDays,
                $customRecurrencePattern,
                $createTransaction,
                $transactionDate,
                $autoPayEnabled,
                $isTransfer,
                $destinationAccountId,
                $transferDescriptionPattern,
                $tagIds,
                $endDate,
                $remainingPayments,
                $splitTemplate
            );

            return new DataResponse($bill, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create bill'));
        }
    }

    /**
     * Update a bill
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            $this->requireWriteAccess('bill', $id);

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
            }

            $updates = [];

            // Validate name if provided
            if (isset($data['name'])) {
                $nameValidation = $this->validationService->validateName($data['name'], false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Validate frequency if provided
            if (isset($data['frequency'])) {
                $frequencyValidation = $this->validationService->validateFrequency($data['frequency']);
                if (!$frequencyValidation['valid']) {
                    return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['frequency'] = $frequencyValidation['formatted'];
            }

            // Validate dueDay if provided
            if (array_key_exists('dueDay', $data)) {
                if ($data['dueDay'] !== null) {
                    if ($data['dueDay'] < 1 || $data['dueDay'] > 31) {
                        return new DataResponse(['error' => $this->l->t('Due day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['dueDay'] = (int) $data['dueDay'];
                } else {
                    $updates['dueDay'] = null;
                }
            }

            // Validate dueMonth if provided
            if (array_key_exists('dueMonth', $data)) {
                if ($data['dueMonth'] !== null) {
                    if ($data['dueMonth'] < 1 || $data['dueMonth'] > 12) {
                        return new DataResponse(['error' => $this->l->t('Due month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['dueMonth'] = (int) $data['dueMonth'];
                } else {
                    $updates['dueMonth'] = null;
                }
            }

            // Validate autoDetectPattern if provided
            if (isset($data['autoDetectPattern'])) {
                if ($data['autoDetectPattern'] !== null && $data['autoDetectPattern'] !== '') {
                    $patternValidation = $this->validationService->validatePattern($data['autoDetectPattern'], false);
                    if (!$patternValidation['valid']) {
                        return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['autoDetectPattern'] = $patternValidation['sanitized'];
                } else {
                    $updates['autoDetectPattern'] = null;
                }
            }

            // Validate notes if provided
            if (isset($data['notes'])) {
                if ($data['notes'] !== null && $data['notes'] !== '') {
                    $notesValidation = $this->validationService->validateNotes($data['notes']);
                    if (!$notesValidation['valid']) {
                        return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['notes'] = $notesValidation['sanitized'];
                } else {
                    $updates['notes'] = null;
                }
            }

            // Handle other fields
            if (isset($data['amount'])) {
                $updates['amount'] = (float) $data['amount'];
            }
            if (array_key_exists('categoryId', $data)) {
                $updates['categoryId'] = $data['categoryId'] !== null ? (int) $data['categoryId'] : null;
            }
            if (array_key_exists('accountId', $data)) {
                $updates['accountId'] = $data['accountId'] !== null ? (int) $data['accountId'] : null;
            }
            if (isset($data['active'])) {
                $updates['active'] = (bool) $data['active'];
            }
            if (array_key_exists('reminderDays', $data)) {
                if ($data['reminderDays'] !== null) {
                    if ($data['reminderDays'] < 0 || $data['reminderDays'] > 30) {
                        return new DataResponse(['error' => $this->l->t('Reminder days must be between 0 and 30')], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['reminderDays'] = (int) $data['reminderDays'];
                } else {
                    $updates['reminderDays'] = null;
                }
            }
            if (array_key_exists('lastPaidDate', $data)) {
                $updates['lastPaidDate'] = $data['lastPaidDate'];
            }
            if (array_key_exists('customRecurrencePattern', $data)) {
                if ($data['customRecurrencePattern'] !== null && $data['customRecurrencePattern'] !== '') {
                    $patternValidation = $this->validateCustomPattern($data['customRecurrencePattern']);
                    if (!$patternValidation['valid']) {
                        return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['customRecurrencePattern'] = $data['customRecurrencePattern'];
                } else {
                    $updates['customRecurrencePattern'] = null;
                }
            }
            if (isset($data['autoPayEnabled'])) {
                $updates['autoPayEnabled'] = (bool) $data['autoPayEnabled'];
            }
            if (isset($data['autoPayFailed'])) {
                $updates['autoPayFailed'] = (bool) $data['autoPayFailed'];
            }
            if (isset($data['isTransfer'])) {
                $updates['isTransfer'] = (bool) $data['isTransfer'];
            }
            if (array_key_exists('destinationAccountId', $data)) {
                $updates['destinationAccountId'] = $data['destinationAccountId'] !== null ? (int) $data['destinationAccountId'] : null;
            }
            if (array_key_exists('transferDescriptionPattern', $data)) {
                $updates['transferDescriptionPattern'] = $data['transferDescriptionPattern'];
            }
            if (array_key_exists('tagIds', $data)) {
                $tagIds = is_array($data['tagIds']) ? array_map('intval', $data['tagIds']) : [];
                $updates['tagIds'] = empty($tagIds) ? null : json_encode(array_values($tagIds));
            }
            if (array_key_exists('endDate', $data)) {
                if ($data['endDate'] !== null && $data['endDate'] !== '') {
                    $endDateValidation = $this->validationService->validateDate($data['endDate'], $this->l->t('End date'), false);
                    if (!$endDateValidation['valid']) {
                        return new DataResponse(['error' => $endDateValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['endDate'] = $data['endDate'];
                } else {
                    $updates['endDate'] = null;
                }
            }
            if (array_key_exists('remainingPayments', $data)) {
                if ($data['remainingPayments'] !== null && $data['remainingPayments'] !== '') {
                    $remaining = (int) $data['remainingPayments'];
                    if ($remaining < 1) {
                        return new DataResponse(['error' => $this->l->t('Remaining payments must be at least 1')], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['remainingPayments'] = $remaining;
                } else {
                    $updates['remainingPayments'] = null;
                }
            }

            // Handle split template
            if (array_key_exists('splitTemplate', $data)) {
                if (!empty($data['splitTemplate']) && is_array($data['splitTemplate'])) {
                    $billAmount = $updates['amount'] ?? null;
                    if ($billAmount === null) {
                        // Get existing bill amount for validation
                        $existingBill = $this->service->find($id, $this->getEffectiveUserId());
                        $billAmount = $existingBill->getAmount();
                    }
                    $splitValidation = $this->validateSplitTemplate($data['splitTemplate'], $billAmount);
                    if (!$splitValidation['valid']) {
                        return new DataResponse(['error' => $splitValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['splitTemplate'] = json_encode(array_values($data['splitTemplate']));
                } else {
                    $updates['splitTemplate'] = null;
                }
            }

            // Validate transfer constraints if being updated
            if (isset($updates['isTransfer']) && $updates['isTransfer']) {
                $destinationId = $updates['destinationAccountId'] ?? null;
                if ($destinationId === null) {
                    // Check existing bill for destination account if not in updates
                    try {
                        $existingBill = $this->service->find($id, $this->getEffectiveUserId());
                        $destinationId = $existingBill->getDestinationAccountId();
                    } catch (\Exception $e) {
                        // Will be caught by outer try-catch
                    }
                }
                if ($destinationId === null) {
                    return new DataResponse(
                        ['error' => $this->l->t('Transfer requires a destination account')],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            // Validate cannot transfer to same account
            if (isset($updates['destinationAccountId']) || isset($updates['accountId'])) {
                $accountId = $updates['accountId'] ?? null;
                $destinationId = $updates['destinationAccountId'] ?? null;

                // If only one is being updated, get the other from existing bill
                if ($accountId === null || $destinationId === null) {
                    try {
                        $existingBill = $this->service->find($id, $this->getEffectiveUserId());
                        if ($accountId === null) {
                            $accountId = $existingBill->getAccountId();
                        }
                        if ($destinationId === null) {
                            $destinationId = $existingBill->getDestinationAccountId();
                        }
                    } catch (\Exception $e) {
                        // Will be caught by outer try-catch
                    }
                }

                if ($accountId !== null && $destinationId !== null && $accountId === $destinationId) {
                    return new DataResponse(
                        ['error' => $this->l->t('Cannot transfer to the same account')],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $bill = $this->service->update($id, $this->getEffectiveUserId(), $updates);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update bill'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Delete a bill
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->requireWriteAccess('bill', $id);
            $this->service->delete($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Bill'), ['billId' => $id]);
        }
    }

    /**
     * Mark a bill as paid
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function markPaid(int $id, ?string $paidDate = null): DataResponse {
        try {
            $this->requireWriteAccess('bill', $id);
            $params = $this->request->getParams();
            $createNextTransaction = (bool) ($params['createNextTransaction'] ?? false);

            $bill = $this->service->markPaid($id, $this->getEffectiveUserId(), $paidDate, $createNextTransaction);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to mark bill as paid'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Skip a bill payment, advancing to the next due date
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function skipPayment(int $id): DataResponse {
        try {
            $this->requireWriteAccess('bill', $id);
            $result = $this->service->skipPayment($id, $this->getEffectiveUserId());
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to skip bill payment'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Undo a skipped bill payment
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function undoSkip(int $id): DataResponse {
        try {
            $this->requireWriteAccess('bill', $id);
            $params = $this->request->getParams();
            $previousNextDueDate = $params['previousNextDueDate'] ?? null;

            if ($previousNextDueDate === null) {
                return new DataResponse(['error' => $this->l->t('Missing previous due date')], Http::STATUS_BAD_REQUEST);
            }

            $bill = $this->service->undoSkip($id, $this->getEffectiveUserId(), $previousNextDueDate);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to undo skip'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Get upcoming bills (next 30 days, sorted by due date)
     * @NoAdminRequired
     */
    public function upcoming(int $days = 30): DataResponse {
        try {
            $bills = $this->service->findUpcoming($this->getEffectiveUserId(), $days);
            $bills = $this->service->enrichBillsWithCurrency($bills, $this->getEffectiveUserId());
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve upcoming bills'));
        }
    }

    /**
     * Get bills due this month
     * @NoAdminRequired
     */
    public function dueThisMonth(): DataResponse {
        try {
            $bills = $this->service->findDueThisMonth($this->getEffectiveUserId());
            $bills = $this->service->enrichBillsWithCurrency($bills, $this->getEffectiveUserId());
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve bills due this month'));
        }
    }

    /**
     * Get overdue bills
     * @NoAdminRequired
     */
    public function overdue(): DataResponse {
        try {
            $bills = $this->service->findOverdue($this->getEffectiveUserId());
            $bills = $this->service->enrichBillsWithCurrency($bills, $this->getEffectiveUserId());
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve overdue bills'));
        }
    }

    /**
     * Get monthly summary of bills
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getMonthlySummary($this->getEffectiveUserId());
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve bill summary'));
        }
    }

    /**
     * Get bill status for a specific month (paid/unpaid)
     * @NoAdminRequired
     */
    public function statusForMonth(?string $month = null): DataResponse {
        try {
            $status = $this->service->getBillStatusForMonth($this->getEffectiveUserId(), $month);
            return new DataResponse($status);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve bill status'));
        }
    }

    /**
     * Auto-detect recurring bills from transaction history
     * @NoAdminRequired
     */
    public function detect(int $months = 6): DataResponse {
        try {
            $detected = $this->service->detectRecurringBills($this->getEffectiveUserId(), $months);
            return new DataResponse($detected);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to detect recurring bills'));
        }
    }

    /**
     * Create bills from detected patterns
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createFromDetected(): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data) || !isset($data['bills'])) {
                return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
            }

            $created = $this->service->createFromDetected($this->getEffectiveUserId(), $data['bills']);
            return new DataResponse([
                'created' => count($created),
                'bills' => $created,
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create bills from detected patterns'));
        }
    }

    /**
     * Get annual overview of bills
     * @NoAdminRequired
     */
    public function annualOverview(?int $year = null, $includeTransfers = 'false', ?string $billStatus = 'active', ?int $accountId = null): DataResponse {
        try {
            // Default to current year if not specified
            $year = $year ?? (int) date('Y');

            // Validate year
            if ($year < 2000 || $year > 2100) {
                return new DataResponse(['error' => $this->l->t('Invalid year')], Http::STATUS_BAD_REQUEST);
            }

            // Convert string parameters to boolean
            $includeTransfersBool = $this->toBool($includeTransfers);

            // Validate bill status
            $validStatuses = ['active', 'inactive', 'all'];
            if (!in_array($billStatus, $validStatuses)) {
                $billStatus = 'active';
            }

            $overview = $this->service->getAnnualOverview($this->getEffectiveUserId(), $year, $includeTransfersBool, $billStatus, $accountId);
            return new DataResponse($overview);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to generate annual overview'));
        }
    }

    /**
     * Export bills calendar as CSV or PDF.
     * @NoAdminRequired
     */
    public function exportCalendar(
        string $format = 'csv',
        ?int $year = null,
        $includeTransfers = 'false',
        ?string $billStatus = 'active',
        ?int $accountId = null
    ): DataDownloadResponse|DataResponse {
        try {
            $year = $year ?? (int) date('Y');
            if ($year < 2000 || $year > 2100) {
                return new DataResponse(['error' => $this->l->t('Invalid year')], Http::STATUS_BAD_REQUEST);
            }

            $includeTransfersBool = $this->toBool($includeTransfers);
            $validStatuses = ['active', 'inactive', 'all'];
            if (!in_array($billStatus, $validStatuses)) {
                $billStatus = 'active';
            }

            $data = $this->service->getAnnualOverview($this->getEffectiveUserId(), $year, $includeTransfersBool, $billStatus, $accountId);

            if ($format === 'pdf') {
                $result = $this->exportCalendarToPdf($data);
            } else {
                $result = $this->exportCalendarToCsv($data);
            }

            return new DataDownloadResponse(
                $result['stream'],
                $result['filename'],
                $result['contentType']
            );
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to export bills calendar'));
        }
    }

    private function exportCalendarToCsv(array $data): array {
        $csv = fopen('php://memory', 'w');
        $year = $data['year'] ?? date('Y');
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        // Header
        $header = ['Bill', 'Amount', 'Frequency'];
        foreach ($months as $m) {
            $header[] = $m;
        }
        $header[] = 'Annual Total';
        fputcsv($csv, $header);

        // Bill rows
        foreach ($data['bills'] ?? [] as $bill) {
            $row = [
                $bill['name'] ?? '',
                $bill['amount'] ?? 0,
                $bill['frequency'] ?? '',
            ];

            $annualTotal = 0;
            for ($m = 1; $m <= 12; $m++) {
                $occurs = !empty($bill['occurrences'][$m]);
                $row[] = $occurs ? number_format((float)($bill['amount'] ?? 0), 2) : '';
                if ($occurs) {
                    $annualTotal += (float)($bill['amount'] ?? 0);
                }
            }
            $row[] = number_format($annualTotal, 2);
            fputcsv($csv, $row);
        }

        // Monthly totals row
        $totalsRow = ['Total', '', ''];
        $grandTotal = 0;
        for ($m = 1; $m <= 12; $m++) {
            $total = $data['monthlyTotals'][$m] ?? 0;
            $totalsRow[] = number_format($total, 2);
            $grandTotal += $total;
        }
        $totalsRow[] = number_format($grandTotal, 2);
        fputcsv($csv, $totalsRow);

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return [
            'stream' => $content,
            'contentType' => 'text/csv',
            'filename' => "bills_calendar_{$year}_" . date('Y-m-d') . '.csv',
        ];
    }

    private function exportCalendarToPdf(array $data): array {
        if (!class_exists('TCPDF')) {
            return $this->exportCalendarToCsv($data);
        }

        $year = $data['year'] ?? date('Y');
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Nextcloud Budget');
        $pdf->SetTitle("Bills Calendar {$year}");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, "Bills Calendar {$year}", 0, 1, 'C');
        $pdf->Ln(3);

        // Column widths for landscape A4 (277mm usable)
        $nameW = 45;
        $amtW = 20;
        $monthW = 16;
        $totalW = 20;

        // Header
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($nameW, 5, 'Bill', 1, 0, 'L');
        $pdf->Cell($amtW, 5, 'Amount', 1, 0, 'R');
        foreach ($months as $m) {
            $pdf->Cell($monthW, 5, $m, 1, 0, 'C');
        }
        $pdf->Cell($totalW, 5, 'Annual', 1, 1, 'R');

        // Data rows
        $pdf->SetFont('helvetica', '', 7);
        foreach ($data['bills'] ?? [] as $bill) {
            $pdf->Cell($nameW, 5, $bill['name'] ?? '', 1, 0, 'L');
            $pdf->Cell($amtW, 5, number_format((float)($bill['amount'] ?? 0), 2), 1, 0, 'R');

            $annualTotal = 0;
            for ($m = 1; $m <= 12; $m++) {
                $occurs = !empty($bill['occurrences'][$m]);
                $pdf->Cell($monthW, 5, $occurs ? number_format((float)($bill['amount'] ?? 0), 2) : '', 1, 0, 'R');
                if ($occurs) {
                    $annualTotal += (float)($bill['amount'] ?? 0);
                }
            }
            $pdf->Cell($totalW, 5, number_format($annualTotal, 2), 1, 1, 'R');
        }

        // Totals row
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($nameW, 5, 'Total', 1, 0, 'L');
        $pdf->Cell($amtW, 5, '', 1, 0, 'R');
        $grandTotal = 0;
        for ($m = 1; $m <= 12; $m++) {
            $total = $data['monthlyTotals'][$m] ?? 0;
            $pdf->Cell($monthW, 5, number_format($total, 2), 1, 0, 'R');
            $grandTotal += $total;
        }
        $pdf->Cell($totalW, 5, number_format($grandTotal, 2), 1, 1, 'R');

        return [
            'stream' => $pdf->Output('', 'S'),
            'contentType' => 'application/pdf',
            'filename' => "bills_calendar_{$year}_" . date('Y-m-d') . '.pdf',
        ];
    }

    /**
     * Validate custom recurrence pattern JSON.
     *
     * @param string $pattern JSON pattern string
     * @return array ['valid' => bool, 'error' => string|null]
     */
    /**
     * Validate a split template array against the bill amount.
     */
    private function validateSplitTemplate(array $splits, float $billAmount): array {
        if (count($splits) < 2) {
            return ['valid' => false, 'error' => $this->l->t('Split template must have at least 2 splits')];
        }

        $total = 0.0;
        foreach ($splits as $split) {
            if (!isset($split['amount']) || !is_numeric($split['amount']) || (float) $split['amount'] <= 0) {
                return ['valid' => false, 'error' => $this->l->t('Each split must have a positive amount')];
            }
            $total += (float) $split['amount'];
        }

        if (abs($total - $billAmount) > 0.01) {
            return ['valid' => false, 'error' => $this->l->t('Split amounts must equal the bill amount')];
        }

        return ['valid' => true, 'error' => null];
    }

    private function validateCustomPattern(string $pattern): array {
        $decoded = json_decode($pattern, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'error' => $this->l->t('Invalid JSON in custom recurrence pattern'),
            ];
        }

        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'error' => $this->l->t('Custom recurrence pattern must be a JSON object'),
            ];
        }

        // Validate months pattern: {"months": [1, 6, 7]}
        if (isset($decoded['months'])) {
            if (!is_array($decoded['months'])) {
                return [
                    'valid' => false,
                    'error' => $this->l->t('Months must be an array'),
                ];
            }

            if (empty($decoded['months'])) {
                return [
                    'valid' => false,
                    'error' => $this->l->t('At least one month must be specified'),
                ];
            }

            foreach ($decoded['months'] as $month) {
                if (!is_int($month) || $month < 1 || $month > 12) {
                    return [
                        'valid' => false,
                        'error' => $this->l->t('Each month must be a number between 1 and 12'),
                    ];
                }
            }

            return ['valid' => true, 'error' => null];
        }

        // Validate dates pattern: {"dates": [{"month": 1, "day": 15}, ...]}
        if (isset($decoded['dates'])) {
            if (!is_array($decoded['dates'])) {
                return [
                    'valid' => false,
                    'error' => $this->l->t('Dates must be an array'),
                ];
            }

            if (empty($decoded['dates'])) {
                return [
                    'valid' => false,
                    'error' => $this->l->t('At least one date must be specified'),
                ];
            }

            foreach ($decoded['dates'] as $date) {
                if (!is_array($date) || !isset($date['month']) || !isset($date['day'])) {
                    return [
                        'valid' => false,
                        'error' => $this->l->t('Each date must have "month" and "day" fields'),
                    ];
                }

                if (!is_int($date['month']) || $date['month'] < 1 || $date['month'] > 12) {
                    return [
                        'valid' => false,
                        'error' => $this->l->t('Month must be a number between 1 and 12'),
                    ];
                }

                if (!is_int($date['day']) || $date['day'] < 1 || $date['day'] > 31) {
                    return [
                        'valid' => false,
                        'error' => $this->l->t('Day must be a number between 1 and 31'),
                    ];
                }
            }

            return ['valid' => true, 'error' => null];
        }

        return [
            'valid' => false,
            'error' => $this->l->t('Custom pattern must contain either "months" or "dates" field'),
        ];
    }
}
