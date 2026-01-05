<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class AccountController extends Controller {
    private AccountService $service;
    private ValidationService $validationService;
    private AuditService $auditService;
    private string $userId;

    public function __construct(
        IRequest $request,
        AccountService $service,
        ValidationService $validationService,
        AuditService $auditService,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $accounts = $this->service->findAll($this->userId);
            return new DataResponse($accounts);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $account = $this->service->find($id, $this->userId);
            return new DataResponse($account);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(): DataResponse {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);


            if (!$data || !is_array($data)) {
                return new DataResponse(['error' => 'Invalid JSON data or empty request'], Http::STATUS_BAD_REQUEST);
            }

            // Validate required fields with detailed error messages
            $name = trim($data['name'] ?? '');
            if (empty($name)) {
                return new DataResponse(['error' => 'Account name is required and cannot be empty'], Http::STATUS_BAD_REQUEST);
            }

            $type = trim($data['type'] ?? '');
            if (empty($type)) {
                return new DataResponse(['error' => 'Account type is required and cannot be empty'], Http::STATUS_BAD_REQUEST);
            }

            // Validate account type
            $typeValidation = $this->validationService->validateAccountType($type);
            if (!$typeValidation['valid']) {
                return new DataResponse(['error' => 'Invalid account type: ' . $typeValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            // Validate currency if provided
            $currency = strtoupper(trim($data['currency'] ?? 'USD'));
            $currencyValidation = $this->validationService->validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                return new DataResponse(['error' => 'Invalid currency: ' . $currencyValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            // Parse numeric fields safely
            $balance = 0.0;
            if (isset($data['balance']) && $data['balance'] !== '' && $data['balance'] !== null) {
                $balance = (float) $data['balance'];
            }

            $interestRate = null;
            if (isset($data['interestRate']) && $data['interestRate'] !== '' && $data['interestRate'] !== null) {
                $interestRate = (float) $data['interestRate'];
            }

            $creditLimit = null;
            if (isset($data['creditLimit']) && $data['creditLimit'] !== '' && $data['creditLimit'] !== null) {
                $creditLimit = (float) $data['creditLimit'];
            }

            $overdraftLimit = null;
            if (isset($data['overdraftLimit']) && $data['overdraftLimit'] !== '' && $data['overdraftLimit'] !== null) {
                $overdraftLimit = (float) $data['overdraftLimit'];
            }

            // Validate optional banking fields if provided
            $institution = !empty($data['institution']) ? trim($data['institution']) : null;
            $accountNumber = !empty($data['accountNumber']) ? trim($data['accountNumber']) : null;
            $routingNumber = !empty($data['routingNumber']) ? trim($data['routingNumber']) : null;
            $sortCode = !empty($data['sortCode']) ? trim($data['sortCode']) : null;
            $iban = !empty($data['iban']) ? trim($data['iban']) : null;
            $swiftBic = !empty($data['swiftBic']) ? trim($data['swiftBic']) : null;
            $accountHolderName = !empty($data['accountHolderName']) ? trim($data['accountHolderName']) : null;
            $openingDate = !empty($data['openingDate']) ? $data['openingDate'] : null;

            // Validate banking fields if provided
            if ($routingNumber !== null) {
                $routingValidation = $this->validationService->validateRoutingNumber($routingNumber);
                if (!$routingValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid routing number: ' . $routingValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $routingNumber = $routingValidation['formatted'];
            }

            if ($sortCode !== null) {
                $sortValidation = $this->validationService->validateSortCode($sortCode);
                if (!$sortValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid sort code: ' . $sortValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $sortCode = $sortValidation['formatted'];
            }

            if ($iban !== null) {
                $ibanValidation = $this->validationService->validateIban($iban);
                if (!$ibanValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid IBAN: ' . $ibanValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $iban = $ibanValidation['formatted'];
            }

            if ($swiftBic !== null) {
                $swiftValidation = $this->validationService->validateSwiftBic($swiftBic);
                if (!$swiftValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid SWIFT/BIC: ' . $swiftValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $swiftBic = $swiftValidation['formatted'];
            }

            // Create the account
            $account = $this->service->create(
                $this->userId,
                $name,
                $typeValidation['formatted'],
                $balance,
                $currencyValidation['formatted'],
                $institution,
                $accountNumber,
                $routingNumber,
                $sortCode,
                $iban,
                $swiftBic,
                $accountHolderName,
                $openingDate,
                $interestRate,
                $creditLimit,
                $overdraftLimit
            );

            // Audit log the account creation
            $this->auditService->logAccountCreated($this->userId, $account->getId(), $name);

            return new DataResponse($account, Http::STATUS_CREATED);

        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            // Get JSON data from request body
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
            }

            // Filter out null values and prepare updates
            $updates = array_filter([
                'name' => $data['name'] ?? null,
                'type' => $data['type'] ?? null,
                'balance' => isset($data['balance']) ? (float) $data['balance'] : null,
                'currency' => $data['currency'] ?? null,
                'institution' => $data['institution'] ?? null,
                'accountNumber' => $data['accountNumber'] ?? null,
                'routingNumber' => $data['routingNumber'] ?? null,
                'sortCode' => $data['sortCode'] ?? null,
                'iban' => $data['iban'] ?? null,
                'swiftBic' => $data['swiftBic'] ?? null,
                'accountHolderName' => $data['accountHolderName'] ?? null,
                'openingDate' => $data['openingDate'] ?? null,
                'interestRate' => isset($data['interestRate']) ? (float) $data['interestRate'] : null,
                'creditLimit' => isset($data['creditLimit']) ? (float) $data['creditLimit'] : null,
                'overdraftLimit' => isset($data['overdraftLimit']) ? (float) $data['overdraftLimit'] : null,
            ], fn($value) => $value !== null);

            $account = $this->service->update($id, $this->userId, $updates);

            // Audit log the update
            $this->auditService->logAccountUpdated($this->userId, $id, $updates);

            return new DataResponse($account);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            // Get account name before deletion for audit log
            $account = $this->service->find($id, $this->userId);
            $accountName = $account->getName();

            $this->service->delete($id, $this->userId);

            // Audit log the deletion
            $this->auditService->logAccountDeleted($this->userId, $id, $accountName);

            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Reveal full (unmasked) sensitive account details.
     * Requires password confirmation and logs the access.
     *
     * @NoAdminRequired
     */
    #[PasswordConfirmationRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function reveal(int $id): DataResponse {
        try {
            $account = $this->service->find($id, $this->userId);

            // Check if account has sensitive data to reveal
            if (!$account->hasSensitiveData()) {
                return new DataResponse([
                    'error' => 'This account has no sensitive banking data to reveal'
                ], Http::STATUS_BAD_REQUEST);
            }

            // Audit log the reveal action
            $this->auditService->logAccountRevealed(
                $this->userId,
                $id,
                $account->getPopulatedSensitiveFields()
            );

            // Return full unmasked data
            return new DataResponse($account->toArrayFull());
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getSummary($this->userId);
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function validateIban(string $iban): DataResponse {
        $result = $this->validationService->validateIban($iban);
        return new DataResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function validateRoutingNumber(string $routingNumber): DataResponse {
        $result = $this->validationService->validateRoutingNumber($routingNumber);
        return new DataResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function validateSortCode(string $sortCode): DataResponse {
        $result = $this->validationService->validateSortCode($sortCode);
        return new DataResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function validateSwiftBic(string $swiftBic): DataResponse {
        $result = $this->validationService->validateSwiftBic($swiftBic);
        return new DataResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function getBankingInstitutions(): DataResponse {
        $institutions = $this->validationService->getBankingInstitutions();
        return new DataResponse($institutions);
    }

    /**
     * @NoAdminRequired
     */
    public function getBankingFieldRequirements(string $currency): DataResponse {
        $requirements = $this->validationService->getBankingFieldRequirements($currency);
        return new DataResponse($requirements);
    }

    /**
     * @NoAdminRequired
     */
    public function getBalanceHistory(int $id, int $days = 30): DataResponse {
        try {
            $history = $this->service->getBalanceHistory($id, $this->userId, $days);
            return new DataResponse($history);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function reconcile(int $id, float $statementBalance): DataResponse {
        try {
            $result = $this->service->reconcile($id, $this->userId, $statementBalance);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }
}