<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ProjectService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Project budgets (#391): the web UI's own API. Not part of the public v1 API.
 */
class ProjectController extends Controller {
    use ApiErrorHandlerTrait;
    use SharedAccessTrait;

    private ProjectService $service;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        ProjectService $service,
        GranularShareService $granularShareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function index(): DataResponse {
        try {
            $own = $this->service->listOwn($this->userId);
            $shared = array_map(
                fn (array $project) => $this->markShared($project),
                $this->service->listByIds($this->granularShareService->getSharedProjectIds($this->userId))
            );
            return new DataResponse(array_merge($own, $shared));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load projects'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function show(int $id): DataResponse {
        try {
            $owner = $this->granularShareService->resolveOwner($this->userId, ShareItem::TYPE_PROJECT, $id);
            if ($owner === null) {
                return $this->notFound();
            }
            $project = $this->service->get($id, $owner);
            return new DataResponse($owner === $this->userId ? $project : $this->markShared($project));
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to load the project'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(
        string $name = '',
        ?int $categoryId = null,
        ?float $totalAmount = null,
        string $startDate = '',
        ?string $endDate = null,
        array $allocations = [],
        bool $excludeFromBudget = true
    ): DataResponse {
        try {
            $project = $this->service->create(
                $this->userId,
                compact('name', 'categoryId', 'totalAmount', 'startDate', 'endDate', 'allocations', 'excludeFromBudget')
            );
            return new DataResponse($project, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->refused($e);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to save the project'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?int $categoryId = null,
        ?float $totalAmount = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $allocations = null
    ): DataResponse {
        try {
            $owner = $this->granularShareService->resolveOwner($this->userId, ShareItem::TYPE_PROJECT, $id);
            if ($owner === null) {
                return $this->notFound();
            }
            $this->requireWriteAccess(ShareItem::TYPE_PROJECT, $id);

            $input = array_filter(
                compact('name', 'categoryId', 'totalAmount', 'startDate', 'allocations'),
                static fn ($value) => $value !== null
            );
            // An end date sent as null clears it; one not sent at all is kept
            if (array_key_exists('endDate', $this->request->getParams())) {
                $input['endDate'] = $endDate;
            }

            $project = $this->service->update($id, $owner, $input, $owner === $this->userId);
            return new DataResponse($owner === $this->userId ? $project : $this->markShared($project));
        } catch (\InvalidArgumentException $e) {
            return $this->refused($e);
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to save the project'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $owner = $this->granularShareService->resolveOwner($this->userId, ShareItem::TYPE_PROJECT, $id);
            if ($owner === null) {
                return $this->notFound();
            }
            if ($owner !== $this->userId) {
                return new DataResponse(
                    ['error' => $this->l->t('Only the owner can delete this project')],
                    Http::STATUS_FORBIDDEN
                );
            }
            $this->service->delete($id, $owner);
            return new DataResponse(['status' => 'deleted']);
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete the project'));
        }
    }

    private function markShared(array $project): array {
        return $project + [
            '_shared' => true,
            '_canWrite' => $this->granularShareService->canWrite($this->userId, ShareItem::TYPE_PROJECT, (int)$project['id']),
            '_sharedByName' => $this->granularShareService->ownerDisplayName((string)$project['userId']),
        ];
    }

    private function notFound(): DataResponse {
        return new DataResponse(
            ['error' => $this->l->t('%1$s not found', [$this->l->t('Project')])],
            Http::STATUS_NOT_FOUND
        );
    }

    private function refused(\InvalidArgumentException $e): DataResponse {
        $message = match ($e->getCode()) {
            ProjectService::ERR_NAME => $this->l->t('Enter a name for the project'),
            ProjectService::ERR_TOTAL => $this->l->t('The total must be more than zero'),
            ProjectService::ERR_CATEGORY => $this->l->t('Choose one of your own expense categories'),
            ProjectService::ERR_START_DATE => $this->l->t('Enter a start date for the project'),
            ProjectService::ERR_END_DATE => $this->l->t('The end date cannot be before the start date'),
            ProjectService::ERR_ALLOC_AMOUNT => $this->l->t('Every subcategory amount must be more than zero'),
            ProjectService::ERR_ALLOC_OUTSIDE => $this->l->t('Amounts can only go to subcategories of the project category'),
            ProjectService::ERR_ALLOC_DUPLICATE => $this->l->t('Each subcategory can only have one amount'),
            ProjectService::ERR_ALLOC_OVERLAP => $this->l->t('A subcategory and one of its own subcategories cannot both have amounts'),
            ProjectService::ERR_ALLOC_OVER_TOTAL => $this->l->t('The subcategory amounts add up to more than the total'),
            default => $this->l->t('Failed to save the project'),
        };
        return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
    }
}
