<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ImportRuleController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private ImportRuleService $service;
    private ValidationService $validationService;
    private string $userId;

    private const VALID_FIELDS = ['description', 'vendor', 'reference', 'notes', 'amount'];
    private const VALID_MATCH_TYPES = ['contains', 'exact', 'starts_with', 'ends_with', 'regex'];

    public function __construct(
        IRequest $request,
        ImportRuleService $service,
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
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $rules = $this->service->findAll($this->userId);
            return new DataResponse($rules);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve import rules');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $rule = $this->service->find($id, $this->userId);
            return new DataResponse($rule);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Import rule', ['ruleId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        string $pattern,
        string $field,
        string $matchType,
        ?int $categoryId = null,
        ?string $vendorName = null,
        int $priority = 0,
        ?array $actions = null,
        bool $applyOnImport = true
    ): DataResponse {
        try {
            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate pattern (required)
            $patternValidation = $this->validationService->validatePattern($pattern, true);
            if (!$patternValidation['valid']) {
                return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $pattern = $patternValidation['sanitized'];

            // Validate field
            if (!in_array($field, self::VALID_FIELDS, true)) {
                return new DataResponse(['error' => 'Invalid field. Must be one of: ' . implode(', ', self::VALID_FIELDS)], Http::STATUS_BAD_REQUEST);
            }

            // Validate matchType
            if (!in_array($matchType, self::VALID_MATCH_TYPES, true)) {
                return new DataResponse(['error' => 'Invalid match type. Must be one of: ' . implode(', ', self::VALID_MATCH_TYPES)], Http::STATUS_BAD_REQUEST);
            }

            // Validate regex pattern if matchType is regex
            if ($matchType === 'regex') {
                if (@preg_match('/' . $pattern . '/', '') === false) {
                    return new DataResponse(['error' => 'Invalid regex pattern'], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate vendorName if provided
            if ($vendorName !== null) {
                $vendorValidation = $this->validationService->validateVendor($vendorName);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $vendorName = $vendorValidation['sanitized'];
            }

            // Validate actions vendor if provided
            if ($actions !== null && isset($actions['vendor'])) {
                $vendorValidation = $this->validationService->validateVendor($actions['vendor']);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $actions['vendor'] = $vendorValidation['sanitized'];
            }

            $rule = $this->service->create(
                $this->userId,
                $name,
                $pattern,
                $field,
                $matchType,
                $categoryId,
                $vendorName,
                $priority,
                $actions,
                $applyOnImport
            );
            return new DataResponse($rule, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create import rule');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?string $pattern = null,
        ?string $field = null,
        ?string $matchType = null,
        ?int $categoryId = null,
        ?string $vendorName = null,
        ?int $priority = null,
        ?bool $active = null,
        ?array $actions = null,
        ?bool $applyOnImport = null
    ): DataResponse {
        try {
            $updates = [];

            // Validate name if provided
            if ($name !== null) {
                $nameValidation = $this->validationService->validateName($name, false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Validate pattern if provided
            if ($pattern !== null) {
                $patternValidation = $this->validationService->validatePattern($pattern, false);
                if (!$patternValidation['valid']) {
                    return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['pattern'] = $patternValidation['sanitized'];
            }

            // Validate field if provided
            if ($field !== null) {
                if (!in_array($field, self::VALID_FIELDS, true)) {
                    return new DataResponse(['error' => 'Invalid field. Must be one of: ' . implode(', ', self::VALID_FIELDS)], Http::STATUS_BAD_REQUEST);
                }
                $updates['field'] = $field;
            }

            // Validate matchType if provided
            if ($matchType !== null) {
                if (!in_array($matchType, self::VALID_MATCH_TYPES, true)) {
                    return new DataResponse(['error' => 'Invalid match type. Must be one of: ' . implode(', ', self::VALID_MATCH_TYPES)], Http::STATUS_BAD_REQUEST);
                }
                $updates['matchType'] = $matchType;

                // If updating to regex, validate the pattern
                $patternToValidate = $updates['pattern'] ?? $pattern;
                if ($matchType === 'regex' && $patternToValidate !== null) {
                    if (@preg_match('/' . $patternToValidate . '/', '') === false) {
                        return new DataResponse(['error' => 'Invalid regex pattern'], Http::STATUS_BAD_REQUEST);
                    }
                }
            }

            // Validate vendorName if provided
            if ($vendorName !== null) {
                $vendorValidation = $this->validationService->validateVendor($vendorName);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['vendorName'] = $vendorValidation['sanitized'];
            }

            // Validate actions vendor if provided
            if ($actions !== null && isset($actions['vendor'])) {
                $vendorValidation = $this->validationService->validateVendor($actions['vendor']);
                if (!$vendorValidation['valid']) {
                    return new DataResponse(['error' => $vendorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $actions['vendor'] = $vendorValidation['sanitized'];
            }

            // Handle other fields
            if ($categoryId !== null) {
                $updates['categoryId'] = $categoryId;
            }
            if ($priority !== null) {
                $updates['priority'] = $priority;
            }
            if ($active !== null) {
                $updates['active'] = $active;
            }
            if ($actions !== null) {
                $updates['actions'] = $actions;
            }
            if ($applyOnImport !== null) {
                $updates['applyOnImport'] = $applyOnImport;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => 'No valid fields to update'], Http::STATUS_BAD_REQUEST);
            }

            $rule = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($rule);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update import rule', Http::STATUS_BAD_REQUEST, ['ruleId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Import rule', ['ruleId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function test(array $transactionData): DataResponse {
        try {
            $results = $this->service->testRules($this->userId, $transactionData);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to test import rules');
        }
    }

    /**
     * Preview rule application to existing transactions
     * @NoAdminRequired
     */
    public function preview(
        array $ruleIds = [],
        ?int $accountId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $uncategorizedOnly = false
    ): DataResponse {
        try {
            $filters = [
                'accountId' => $accountId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'uncategorizedOnly' => $uncategorizedOnly
            ];

            $results = $this->service->previewRuleApplication($this->userId, $ruleIds, $filters);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to preview rule application');
        }
    }

    /**
     * Apply rules to existing transactions
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function apply(
        array $ruleIds = [],
        ?int $accountId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $uncategorizedOnly = false
    ): DataResponse {
        try {
            $filters = [
                'accountId' => $accountId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'uncategorizedOnly' => $uncategorizedOnly
            ];

            $results = $this->service->applyRulesToTransactions($this->userId, $ruleIds, $filters);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to apply rules to transactions');
        }
    }
}