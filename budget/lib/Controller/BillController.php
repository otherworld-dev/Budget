<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\BillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class BillController extends Controller {
    private BillService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        BillService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Create a new bill
     * @NoAdminRequired
     */
    public function create(
        string $name,
        float $amount,
        string $frequency = 'monthly',
        ?int $dueDay = null,
        ?int $dueMonth = null,
        ?int $categoryId = null,
        ?int $accountId = null,
        ?string $autoDetectPattern = null,
        ?string $notes = null
    ): DataResponse {
        try {
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
                $notes
            );
            return new DataResponse($bill, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Update a bill
     * @NoAdminRequired
     */
    public function update(int $id): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
            }

            $bill = $this->service->update($id, $this->userId, $data);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Delete a bill
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Mark a bill as paid
     * @NoAdminRequired
     */
    public function markPaid(int $id, ?string $paidDate = null): DataResponse {
        try {
            $bill = $this->service->markPaid($id, $this->userId, $paidDate);
            return new DataResponse($bill);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Create bills from detected patterns
     * @NoAdminRequired
     */
    public function createFromDetected(): DataResponse {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data) || !isset($data['bills'])) {
                return new DataResponse(['error' => 'Invalid data format'], Http::STATUS_BAD_REQUEST);
            }

            $created = $this->service->createFromDetected($this->userId, $data['bills']);
            return new DataResponse([
                'created' => count($created),
                'bills' => $created,
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
