<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GoalsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class GoalsController extends Controller {
    private GoalsService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        GoalsService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $goals = $this->service->findAll($this->userId);
            return new DataResponse($goals);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $goal = $this->service->find($id, $this->userId);
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(
        string $name,
        float $targetAmount,
        int $targetMonths,
        float $currentAmount = 0.0,
        string $description = '',
        string $targetDate = null
    ): DataResponse {
        try {
            $goal = $this->service->create(
                $this->userId,
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate
            );
            return new DataResponse($goal, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(
        int $id,
        string $name = null,
        float $targetAmount = null,
        int $targetMonths = null,
        float $currentAmount = null,
        string $description = null,
        string $targetDate = null
    ): DataResponse {
        try {
            $goal = $this->service->update(
                $id,
                $this->userId,
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate
            );
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['message' => 'Goal deleted successfully']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function progress(int $id): DataResponse {
        try {
            $progress = $this->service->getProgress($id, $this->userId);
            return new DataResponse($progress);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function forecast(int $id): DataResponse {
        try {
            $forecast = $this->service->getForecast($id, $this->userId);
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}