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
    public function create(
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $dueDay = null,
        ?int $dueMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $autoDetectPattern = null,
        ?string $notes = null,
        ?int $reminderDays = null
    ): DataResponse {
        try {
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
            if ($autoDetectPattern !== null) {
                $patternValidation = $this->validationService->validatePattern($autoDetectPattern, false);
                if (!$patternValidation['valid']) {
                    return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $autoDetectPattern = $patternValidation['sanitized'];
            }

            // Validate notes if provided
            if ($notes !== null) {
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
                $reminderDays
            );
            return new DataResponse($bill, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create bill');
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
            if (isset($data['dueDay']) && $data['dueDay'] !== null) {
                if ($data['dueDay'] < 1 || $data['dueDay'] > 31) {
                    return new DataResponse(['error' => 'Due day must be between 1 and 31'], Http::STATUS_BAD_REQUEST);
                }
                $updates['dueDay'] = $data['dueDay'];
            }

            // Validate dueMonth if provided
            if (isset($data['dueMonth']) && $data['dueMonth'] !== null) {
                if ($data['dueMonth'] < 1 || $data['dueMonth'] > 12) {
                    return new DataResponse(['error' => 'Due month must be between 1 and 12'], Http::STATUS_BAD_REQUEST);
                }
                $updates['dueMonth'] = $data['dueMonth'];
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
            if (isset($data['categoryId'])) {
                $updates['categoryId'] = $data['categoryId'];
            }
            if (isset($data['accountId'])) {
                $updates['accountId'] = $data['accountId'];
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
            $bill = $this->service->markPaid($id, $this->userId, $paidDate);
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
}
