<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TagSetController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private TagSetService $service;
    private ValidationService $validationService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        TagSetService $service,
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
     * Resolve the user id whose data a tag-set operation should run against for
     * a given category: the caller for their own category, or the owner for a
     * category shared with them. Tag sets belong to the category, so operations
     * must run under the category owner (#328). Throws DoesNotExistException if
     * the user can't access the category; for writes, throws ReadOnlyShare
     * exception (→ 403) unless they have write access.
     *
     * @throws DoesNotExistException
     */
    private function categoryOwner(int $categoryId, bool $requireWrite): string {
        $owner = $this->granularShareService->resolveOwner($this->userId, 'category', $categoryId);
        if ($owner === null) {
            throw new DoesNotExistException('Category not accessible');
        }
        if ($requireWrite && $owner !== $this->userId) {
            $this->requireWriteAccess('category', $categoryId);
        }
        return $owner;
    }

    /**
     * Same as categoryOwner(), resolving the category from a tag set id.
     *
     * @throws DoesNotExistException
     */
    private function tagSetCategoryOwner(int $tagSetId, bool $requireWrite): string {
        $categoryId = $this->service->getTagSetCategoryId($tagSetId);
        if ($categoryId === null) {
            throw new DoesNotExistException('Tag set not found');
        }
        return $this->categoryOwner($categoryId, $requireWrite);
    }

    /**
     * @NoAdminRequired
     */
    public function index(?int $categoryId = null): DataResponse {
        try {
            if ($categoryId !== null) {
                // Shared category → read the owner's tag sets (#328).
                $owner = $this->categoryOwner($categoryId, false);
                $tagSets = $this->service->getCategoryTagSetsWithTags($categoryId, $owner);
            } else {
                // Load all tag sets with their tags for reports filtering
                $tagSets = $this->service->getAllTagSetsWithTags($this->getEffectiveUserId());
            }
            return new DataResponse($tagSets);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve tag sets'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $owner = $this->tagSetCategoryOwner($id, false);
            $tagSet = $this->service->getTagSetWithTags($id, $owner);
            return new DataResponse($tagSet);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Tag set'), ['tagSetId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(): DataResponse {
        try {
            // Read JSON body
            $data = $this->request->getParams();

            if (!isset($data['categoryId']) || !isset($data['name'])) {
                return new DataResponse(['error' => $this->l->t('Category ID and name are required')], Http::STATUS_BAD_REQUEST);
            }

            $categoryId = (int)$data['categoryId'];
            $name = $data['name'];
            $description = $data['description'] ?? null;
            $sortOrder = $data['sortOrder'] ?? 0;

            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Shared category → create under the owner if the caller has write access (#328).
            $owner = $this->categoryOwner($categoryId, true);
            $tagSet = $this->service->create(
                $owner,
                $categoryId,
                $name,
                $description,
                $sortOrder
            );
            return new DataResponse($tagSet, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create tag set'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            // Read JSON body
            $data = $this->request->getParams();
            $updates = [];

            // Validate name if provided
            if (isset($data['name'])) {
                $nameValidation = $this->validationService->validateName($data['name'], false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Handle other fields
            if (isset($data['description'])) {
                $updates['description'] = $data['description'];
            }
            if (isset($data['sortOrder'])) {
                $updates['sortOrder'] = (int)$data['sortOrder'];
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $owner = $this->tagSetCategoryOwner($id, true);
            $tagSet = $this->service->update($id, $owner, $updates);
            return new DataResponse($tagSet);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update tag set'), Http::STATUS_BAD_REQUEST, ['tagSetId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $owner = $this->tagSetCategoryOwner($id, true);
            $this->service->delete($id, $owner);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete tag set'), Http::STATUS_BAD_REQUEST, ['tagSetId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getTags(int $tagSetId): DataResponse {
        try {
            $owner = $this->tagSetCategoryOwner($tagSetId, false);
            $tagSet = $this->service->getTagSetWithTags($tagSetId, $owner);
            return new DataResponse($tagSet->getTags());
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve tags'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function createTag(int $tagSetId): DataResponse {
        try {
            // Read JSON body
            $data = $this->request->getParams();

            if (!isset($data['name'])) {
                return new DataResponse(['error' => $this->l->t('Name is required')], Http::STATUS_BAD_REQUEST);
            }

            $name = $data['name'];
            $color = $data['color'] ?? null;
            $sortOrder = $data['sortOrder'] ?? 0;

            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate color if provided
            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            $owner = $this->tagSetCategoryOwner($tagSetId, true);
            $tag = $this->service->createTag(
                $tagSetId,
                $owner,
                $name,
                $color,
                $sortOrder
            );
            return new DataResponse($tag, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create tag'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateTag(int $tagSetId, int $tagId): DataResponse {
        try {
            // Read JSON body
            $data = $this->request->getParams();
            $updates = [];

            // Validate name if provided
            if (isset($data['name'])) {
                $nameValidation = $this->validationService->validateName($data['name'], false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Validate color if provided
            if (isset($data['color'])) {
                $colorValidation = $this->validationService->validateColor($data['color']);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['color'] = $colorValidation['sanitized'];
            }

            // Handle other fields
            if (isset($data['sortOrder'])) {
                $updates['sortOrder'] = (int)$data['sortOrder'];
            }
            if (array_key_exists('hidden', $data)) {
                $updates['hidden'] = filter_var($data['hidden'], FILTER_VALIDATE_BOOLEAN);
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $owner = $this->tagSetCategoryOwner($tagSetId, true);
            $tag = $this->service->updateTag($tagId, $owner, $updates);
            return new DataResponse($tag);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update tag'), Http::STATUS_BAD_REQUEST, ['tagId' => $tagId]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroyTag(int $tagSetId, int $tagId): DataResponse {
        try {
            $owner = $this->tagSetCategoryOwner($tagSetId, true);
            $this->service->deleteTag($tagId, $owner);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete tag'), Http::STATUS_BAD_REQUEST, ['tagId' => $tagId]);
        }
    }

    // ============================================
    // Global Tags (flat, no tag set)
    // ============================================

    /**
     * @NoAdminRequired
     */
    public function getGlobalTags(): DataResponse {
        try {
            $tags = $this->service->getGlobalTags($this->getEffectiveUserId());
            return new DataResponse($tags);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve global tags'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function createGlobalTag(): DataResponse {
        try {
            $data = $this->request->getParams();

            if (!isset($data['name'])) {
                return new DataResponse(['error' => $this->l->t('Name is required')], Http::STATUS_BAD_REQUEST);
            }

            $nameValidation = $this->validationService->validateName($data['name'], true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            $color = null;
            if (isset($data['color'])) {
                $colorValidation = $this->validationService->validateColor($data['color']);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            $tag = $this->service->createGlobalTag($this->getEffectiveUserId(), $nameValidation['sanitized'], $color);
            return new DataResponse($tag, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create global tag'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateGlobalTag(int $tagId): DataResponse {
        try {
            $data = $this->request->getParams();
            $updates = [];

            if (isset($data['name'])) {
                $nameValidation = $this->validationService->validateName($data['name'], false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            if (isset($data['color'])) {
                $colorValidation = $this->validationService->validateColor($data['color']);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['color'] = $colorValidation['sanitized'];
            }

            if (array_key_exists('hidden', $data)) {
                $updates['hidden'] = filter_var($data['hidden'], FILTER_VALIDATE_BOOLEAN);
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $tag = $this->service->updateGlobalTag($tagId, $this->getEffectiveUserId(), $updates);
            return new DataResponse($tag);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update global tag'), Http::STATUS_BAD_REQUEST, ['tagId' => $tagId]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroyGlobalTag(int $tagId): DataResponse {
        try {
            $this->service->deleteGlobalTag($tagId, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete global tag'), Http::STATUS_BAD_REQUEST, ['tagId' => $tagId]);
        }
    }
}
