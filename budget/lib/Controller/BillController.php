<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BillController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private BillService $service;
    private ValidationService $validationService;
    private string $userId;

    public function __construct(
        IRequest $request,
        BillService $service,
        ValidationService $validationService,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
    }

    /**
     * Get all bills
     * @NoAdminRequired
     */
    public function index(?bool $activeOnly = false): DataResponse {
        try {
            if ($activeOnly) {
                $bills = $this->service->findActive($this->userId);
            } else {
                $bills = $this->service->findAll($this->userId);
            }
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve bills');
        }
    }

    /**
     * Get a single bill
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $bill = $this->service->find($id, $this->userId);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Bill', ['billId' => $id]);
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
                return new DataResponse(['error' => 'Invalid request data'], Http::STATUS_BAD_REQUEST);
            }

            // Extract and validate required fields
            if (!isset($data['name']) || !isset($data['amount'])) {
                return new DataResponse(['error' => 'Name and amount are required'], Http::STATUS_BAD_REQUEST);
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

            // Validate auto-pay requires account
            if ($autoPayEnabled && $accountId === null) {
                return new DataResponse(
                    ['error' => 'Auto-pay requires an account to be set'],
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
                return new DataResponse(['error' => 'Due day must be between 1 and 31'], Http::STATUS_BAD_REQUEST);
            }

            // Validate dueMonth range
            if ($dueMonth !== null && ($dueMonth < 1 || $dueMonth > 12)) {
                return new DataResponse(['error' => 'Due month must be between 1 and 12'], Http::STATUS_BAD_REQUEST);
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
                return new DataResponse(['error' => 'Reminder days must be between 0 and 30'], Http::STATUS_BAD_REQUEST);
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
                $dateValidation = $this->validationService->validateDate($transactionDate, 'Transaction date', false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            $bill = $this->service->create(
                $this->userId,
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
                $autoPayEnabled
            );
            return new DataResponse($bill, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            // Log full error details for debugging
            error_log('BillController create error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return new DataResponse([
                'error' => 'Failed to create bill: ' . $e->getMessage(),
                'details' => $e->getTraceAsString()
            ], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Update a bill
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                return new DataResponse(['error' => 'Invalid request data'], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => 'Due day must be between 1 and 31'], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => 'Due month must be between 1 and 12'], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => 'Reminder days must be between 0 and 30'], Http::STATUS_BAD_REQUEST);
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

            if (empty($updates)) {
                return new DataResponse(['error' => 'No valid fields to update'], Http::STATUS_BAD_REQUEST);
            }

            $bill = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update bill', Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Delete a bill
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Bill', ['billId' => $id]);
        }
    }

    /**
     * Mark a bill as paid
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function markPaid(int $id, ?string $paidDate = null): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $createNextTransaction = $data['createNextTransaction'] ?? true;

            $bill = $this->service->markPaid($id, $this->userId, $paidDate, $createNextTransaction);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to mark bill as paid', Http::STATUS_BAD_REQUEST, ['billId' => $id]);
        }
    }

    /**
     * Get upcoming bills (next 30 days, sorted by due date)
     * @NoAdminRequired
     */
    public function upcoming(int $days = 30): DataResponse {
        try {
            $bills = $this->service->findUpcoming($this->userId, $days);
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve upcoming bills');
        }
    }

    /**
     * Get bills due this month
     * @NoAdminRequired
     */
    public function dueThisMonth(): DataResponse {
        try {
            $bills = $this->service->findDueThisMonth($this->userId);
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve bills due this month');
        }
    }

    /**
     * Get overdue bills
     * @NoAdminRequired
     */
    public function overdue(): DataResponse {
        try {
            $bills = $this->service->findOverdue($this->userId);
            return new DataResponse($bills);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve overdue bills');
        }
    }

    /**
     * Get monthly summary of bills
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getMonthlySummary($this->userId);
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve bill summary');
        }
    }

    /**
     * Get bill status for a specific month (paid/unpaid)
     * @NoAdminRequired
     */
    public function statusForMonth(?string $month = null): DataResponse {
        try {
            $status = $this->service->getBillStatusForMonth($this->userId, $month);
            return new DataResponse($status);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve bill status');
        }
    }

    /**
     * Auto-detect recurring bills from transaction history
     * @NoAdminRequired
     */
    public function detect(int $months = 6): DataResponse {
        try {
            $detected = $this->service->detectRecurringBills($this->userId, $months);
            return new DataResponse($detected);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to detect recurring bills');
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
                return new DataResponse(['error' => 'Invalid request data'], Http::STATUS_BAD_REQUEST);
            }

            $created = $this->service->createFromDetected($this->userId, $data['bills']);
            return new DataResponse([
                'created' => count($created),
                'bills' => $created,
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create bills from detected patterns');
        }
    }

    /**
     * Validate custom recurrence pattern JSON.
     *
     * @param string $pattern JSON pattern string
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateCustomPattern(string $pattern): array {
        $decoded = json_decode($pattern, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'error' => 'Invalid JSON in custom recurrence pattern',
            ];
        }

        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'error' => 'Custom recurrence pattern must be a JSON object',
            ];
        }

        // Validate months pattern: {"months": [1, 6, 7]}
        if (isset($decoded['months'])) {
            if (!is_array($decoded['months'])) {
                return [
                    'valid' => false,
                    'error' => 'Months must be an array',
                ];
            }

            if (empty($decoded['months'])) {
                return [
                    'valid' => false,
                    'error' => 'At least one month must be specified',
                ];
            }

            foreach ($decoded['months'] as $month) {
                if (!is_int($month) || $month < 1 || $month > 12) {
                    return [
                        'valid' => false,
                        'error' => 'Each month must be a number between 1 and 12',
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
                    'error' => 'Dates must be an array',
                ];
            }

            if (empty($decoded['dates'])) {
                return [
                    'valid' => false,
                    'error' => 'At least one date must be specified',
                ];
            }

            foreach ($decoded['dates'] as $date) {
                if (!is_array($date) || !isset($date['month']) || !isset($date['day'])) {
                    return [
                        'valid' => false,
                        'error' => 'Each date must have "month" and "day" fields',
                    ];
                }

                if (!is_int($date['month']) || $date['month'] < 1 || $date['month'] > 12) {
                    return [
                        'valid' => false,
                        'error' => 'Month must be a number between 1 and 12',
                    ];
                }

                if (!is_int($date['day']) || $date['day'] < 1 || $date['day'] > 31) {
                    return [
                        'valid' => false,
                        'error' => 'Day must be a number between 1 and 31',
                    ];
                }
            }

            return ['valid' => true, 'error' => null];
        }

        return [
            'valid' => false,
            'error' => 'Custom pattern must contain either "months" or "dates" field',
        ];
    }
}
