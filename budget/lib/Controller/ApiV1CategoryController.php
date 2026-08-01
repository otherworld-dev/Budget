<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Categories over the public REST API (v1). Read-only — a capture client
 * picks from the tree the user already built in the web UI rather than
 * growing it from a phone.
 *
 * The list is flat with parentId set; clients that want a tree build it
 * themselves, which keeps pagination and diffing trivial.
 */
class ApiV1CategoryController extends OCSController {
    use ApiErrorHandlerTrait;

    /**
     * Categories are typed 'income'/'expense', not the 'credit'/'debit' a
     * transaction carries. The two vocabularies are separate on purpose and
     * v1 passes each through unchanged rather than inventing a third.
     */
    private const TYPES = ['income', 'expense'];

    private string $userId;

    public function __construct(
        IRequest $request,
        private CategoryService $service,
        private GranularShareService $granularShareService,
        private IL10N $l,
        ?string $userId,
        LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->setLogger($logger);
        // Null until the security middleware rejects the request — see
        // ApiV1Controller for why this must not be typed non-null.
        $this->userId = $userId ?? '';
    }

    /**
     * @param string|null $type Restrict to 'expense' or 'income'.
     */
    #[NoAdminRequired]
    public function index(?string $type = null): DataResponse {
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            return new DataResponse(
                ['error' => $this->l->t('Invalid category type. Must be income or expense')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $categories = $type !== null
                ? $this->service->findByType($this->userId, $type)
                : $this->service->findAll($this->userId);

            $shared = $this->granularShareService->getSharedCategories($this->userId);
            if ($type !== null) {
                $shared = array_filter($shared, static fn ($c) => ($c['type'] ?? '') === $type);
            }

            $all = array_merge($categories, array_values($shared));

            return new DataResponse(ApiSerializer::map($all, [ApiSerializer::class, 'category']));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve categories'));
        }
    }
}
