<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AccountController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private AccountService $service;
    private ValidationService $validationService;
    private AuditService $auditService;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        AccountService $service,
        ValidationService $validationService,
        AuditService $auditService,
        ShareService $shareService,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setShareService($shareService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            // Get all accessible user IDs (own + shared budgets)
            $accessibleUserIds = $this->getAccessibleUserIds($this->userId);

            // Return accounts with balances adjusted to exclude future transactions
            // This now includes accounts from shared budgets
            $accounts = $this->service->findAllWithCurrentBalances($this->userId, $accessibleUserIds);

            // Enrich with owner information for shared accounts
            $enrichedAccounts = array_map(function ($account) {
                if ($account['userId'] !== $this->userId) {
                    // This account belongs to someone else (shared budget)
                    $ownerInfo = $this->getUserDisplayInfo($account['userId']);
                    $account['ownerDisplayName'] = $ownerInfo['displayName'];
                    $account['isShared'] = true;
                    $account['isReadOnly'] = true;
                }
else {
                    $account['isShared'] = false;
                    $account['isReadOnly'] = false;
                }
                return $account;
            }, $accounts);

            return new DataResponse($enrichedAccounts);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve accounts');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            // Return account with balance adjusted to exclude future transactions
            $account = $this->service->findWithCurrentBalance($id, $this->userId);

            // Enrich with ownership information
            $accountData = $account;
            if (is_array($accountData) && isset($accountData['userId'])) {
                $ownerId = $accountData['userId'];
                if ($ownerId !== $this->userId) {
                    $ownerInfo = $this->getUserDisplayInfo($ownerId);
                    $accountData['ownerDisplayName'] = $ownerInfo['displayName'];
                    $accountData['isShared'] = true;
                    $accountData['isReadOnly'] = true;
                } else {
                    $accountData['isShared'] = false;
                    $accountData['isReadOnly'] = false;
                }
            }

            return new DataResponse($accountData);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Account', ['accountId' => $id]);
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

            // Validate required fields with length checks
            $nameValidation = $this->validationService->validateName($data['name'] ?? null, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

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

            // Validate optional string fields for length
            $institution = !empty($data['institution']) ? trim($data['institution']) : null;
            if ($institution !== null) {
                $instValidation = $this->validationService->validateStringLength($institution, 'Institution', ValidationService::MAX_NAME_LENGTH);
                if (!$instValidation['valid']) {
                    return new DataResponse(['error' => $instValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $institution = $instValidation['sanitized'];
            }

            $accountHolderName = !empty($data['accountHolderName']) ? trim($data['accountHolderName']) : null;
            if ($accountHolderName !== null) {
                $holderValidation = $this->validationService->validateStringLength($accountHolderName, 'Account holder name', ValidationService::MAX_NAME_LENGTH);
                if (!$holderValidation['valid']) {
                    return new DataResponse(['error' => $holderValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $accountHolderName = $holderValidation['sanitized'];
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

            $minimumPayment = null;
            if (isset($data['minimumPayment']) && $data['minimumPayment'] !== '' && $data['minimumPayment'] !== null) {
                $minimumPayment = (float) $data['minimumPayment'];
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
                $overdraftLimit,
                $minimumPayment
            );

            // Audit log the account creation
            $this->auditService->logAccountCreated($this->userId, $account->getId(), $name);

            return new DataResponse($account, Http::STATUS_CREATED);

        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create account');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            // First fetch the account to check ownership
            $account = $this->service->find($id, $this->userId);

            // Enforce write access (prevents updates to shared read-only accounts)
            $this->enforceWriteAccess($this->userId, $account->getUserId());

            // Get JSON data from request body
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !is_array($data)) {
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

            // Validate type if provided
            if (isset($data['type'])) {
                $typeValidation = $this->validationService->validateAccountType($data['type']);
                if (!$typeValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid account type: ' . $typeValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $typeValidation['formatted'];
            }

            // Validate currency if provided
            if (isset($data['currency'])) {
                $currencyValidation = $this->validationService->validateCurrency($data['currency']);
                if (!$currencyValidation['valid']) {
                    return new DataResponse(['error' => 'Invalid currency: ' . $currencyValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['currency'] = $currencyValidation['formatted'];
            }

            // Validate string fields with length checks
            $stringFields = [
                'institution' => ValidationService::MAX_NAME_LENGTH,
                'accountHolderName' => ValidationService::MAX_NAME_LENGTH,
            ];

            foreach ($stringFields as $field => $maxLength) {
                if (isset($data[$field]) && $data[$field] !== '') {
                    $validation = $this->validationService->validateStringLength($data[$field], ucfirst($field), $maxLength);
                    if (!$validation['valid']) {
                        return new DataResponse(['error' => $validation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates[$field] = $validation['sanitized'];
                } elseif (array_key_exists($field, $data) && $data[$field] === '') {
                    $updates[$field] = null;
                }
            }

            // Validate banking fields if provided
            if (isset($data['routingNumber']) && $data['routingNumber'] !== '') {
                // Skip if masked (contains asterisks)
                if (strpos($data['routingNumber'], '*') === false && strpos($data['routingNumber'], '[DECRYPTION FAILED]') === false) {
                    $routingValidation = $this->validationService->validateRoutingNumber($data['routingNumber']);
                    if (!$routingValidation['valid']) {
                        return new DataResponse(['error' => 'Invalid routing number: ' . $routingValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['routingNumber'] = $routingValidation['formatted'];
                }
            } elseif (array_key_exists('routingNumber', $data) && $data['routingNumber'] === '') {
                $updates['routingNumber'] = null;
            }

            if (isset($data['sortCode']) && $data['sortCode'] !== '') {
                // Skip if masked (contains asterisks)
                if (strpos($data['sortCode'], '*') === false && strpos($data['sortCode'], '[DECRYPTION FAILED]') === false) {
                    $sortValidation = $this->validationService->validateSortCode($data['sortCode']);
                    if (!$sortValidation['valid']) {
                        return new DataResponse(['error' => 'Invalid sort code: ' . $sortValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['sortCode'] = $sortValidation['formatted'];
                }
            } elseif (array_key_exists('sortCode', $data) && $data['sortCode'] === '') {
                $updates['sortCode'] = null;
            }

            if (isset($data['iban']) && $data['iban'] !== '') {
                // Skip if masked (contains asterisks)
                if (strpos($data['iban'], '*') === false && strpos($data['iban'], '[DECRYPTION FAILED]') === false) {
                    $ibanValidation = $this->validationService->validateIban($data['iban']);
                    if (!$ibanValidation['valid']) {
                        return new DataResponse(['error' => 'Invalid IBAN: ' . $ibanValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['iban'] = $ibanValidation['formatted'];
                }
            } elseif (array_key_exists('iban', $data) && $data['iban'] === '') {
                $updates['iban'] = null;
            }

            if (isset($data['swiftBic']) && $data['swiftBic'] !== '') {
                // Skip if masked (contains asterisks)
                if (strpos($data['swiftBic'], '*') === false && strpos($data['swiftBic'], '[DECRYPTION FAILED]') === false) {
                    $swiftValidation = $this->validationService->validateSwiftBic($data['swiftBic']);
                    if (!$swiftValidation['valid']) {
                        return new DataResponse(['error' => 'Invalid SWIFT/BIC: ' . $swiftValidation['error']], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['swiftBic'] = $swiftValidation['formatted'];
                }
            } elseif (array_key_exists('swiftBic', $data) && $data['swiftBic'] === '') {
                $updates['swiftBic'] = null;
            }

            // Handle other fields
            if (isset($data['balance'])) {
                $updates['balance'] = (float) $data['balance'];
            }
            // Only update accountNumber if it's not a masked value (contains asterisks)
            if (isset($data['accountNumber'])) {
                $value = trim($data['accountNumber']);
                // Skip if empty or contains asterisks (masked value from frontend)
                if ($value === '') {
                    $updates['accountNumber'] = null;
                } elseif (strpos($value, '*') === false && strpos($value, '[DECRYPTION FAILED]') === false) {
                    // Only update if it's not masked
                    $updates['accountNumber'] = $value;
                }
                // If masked, skip updating - keep existing value
            }
            if (isset($data['openingDate'])) {
                $updates['openingDate'] = $data['openingDate'] ?: null;
            }
            if (isset($data['interestRate'])) {
                $updates['interestRate'] = $data['interestRate'] !== '' ? (float) $data['interestRate'] : null;
            }
            if (isset($data['creditLimit'])) {
                $updates['creditLimit'] = $data['creditLimit'] !== '' ? (float) $data['creditLimit'] : null;
            }
            if (isset($data['overdraftLimit'])) {
                $updates['overdraftLimit'] = $data['overdraftLimit'] !== '' ? (float) $data['overdraftLimit'] : null;
            }
            if (isset($data['minimumPayment'])) {
                $updates['minimumPayment'] = $data['minimumPayment'] !== '' ? (float) $data['minimumPayment'] : null;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => 'No valid fields to update'], Http::STATUS_BAD_REQUEST);
            }

            $account = $this->service->update($id, $this->userId, $updates);

            // Audit log the update
            $this->auditService->logAccountUpdated($this->userId, $id, $updates);

            return new DataResponse($account);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update account', Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            // Get account before deletion to check ownership and get name for audit log
            $account = $this->service->find($id, $this->userId);

            // Enforce write access (prevents deletion of shared read-only accounts)
            $this->enforceWriteAccess($this->userId, $account->getUserId());

            $accountName = $account->getName();

            $this->service->delete($id, $this->userId);

            // Audit log the deletion
            $this->auditService->logAccountDeleted($this->userId, $id, $accountName);

            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Account', ['accountId' => $id]);
        }
    }

    /**
     * Reveal full (unmasked) sensitive account details.
     * Requires password confirmation and logs the access.
     * Only available for owned accounts, not shared read-only accounts.
     *
     * @NoAdminRequired
     */
    #[PasswordConfirmationRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function reveal(int $id): DataResponse {
        try {
            $account = $this->service->find($id, $this->userId);

            // Enforce write access - only owners can reveal sensitive data
            $this->enforceWriteAccess($this->userId, $account->getUserId());

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
            return $this->handleNotFoundError($e, 'Account', ['accountId' => $id]);
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
            return $this->handleError($e, 'Failed to retrieve account summary');
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
            return $this->handleNotFoundError($e, 'Account', ['accountId' => $id]);
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
            return $this->handleError($e, 'Failed to reconcile account', Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }
}