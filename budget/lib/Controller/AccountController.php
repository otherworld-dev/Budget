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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AccountController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private AccountService $service;
    private ValidationService $validationService;
    private AuditService $auditService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        AccountService $service,
        ValidationService $validationService,
        AuditService $auditService,
        ShareService $shareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->l = $l;
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
            $accounts = $this->service->findAllWithCurrentBalances($this->getEffectiveUserId());
            return new DataResponse($accounts);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve accounts'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $account = $this->service->findWithCurrentBalance($id, $this->getEffectiveUserId());
            return new DataResponse($account);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
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
                return new DataResponse(['error' => $this->l->t('Invalid JSON data or empty request')], Http::STATUS_BAD_REQUEST);
            }

            // Validate required fields with length checks
            $nameValidation = $this->validationService->validateName($data['name'] ?? null, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            $type = trim($data['type'] ?? '');
            if (empty($type)) {
                return new DataResponse(['error' => $this->l->t('Account type is required and cannot be empty')], Http::STATUS_BAD_REQUEST);
            }

            // Validate account type
            $typeValidation = $this->validationService->validateAccountType($type);
            if (!$typeValidation['valid']) {
                return new DataResponse(['error' => $this->l->t('Invalid account type: %1$s', [$typeValidation['error']])], Http::STATUS_BAD_REQUEST);
            }

            // Validate currency if provided
            $currency = strtoupper(trim($data['currency'] ?? 'USD'));
            $currencyValidation = $this->validationService->validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                return new DataResponse(['error' => $this->l->t('Invalid currency: %1$s', [$currencyValidation['error']])], Http::STATUS_BAD_REQUEST);
            }

            // Validate optional string fields for length
            $institution = !empty($data['institution']) ? trim($data['institution']) : null;
            if ($institution !== null) {
                $instValidation = $this->validationService->validateStringLength($institution, $this->l->t('Institution'), ValidationService::MAX_NAME_LENGTH);
                if (!$instValidation['valid']) {
                    return new DataResponse(['error' => $instValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $institution = $instValidation['sanitized'];
            }

            $accountHolderName = !empty($data['accountHolderName']) ? trim($data['accountHolderName']) : null;
            if ($accountHolderName !== null) {
                $holderValidation = $this->validationService->validateStringLength($accountHolderName, $this->l->t('Account holder name'), ValidationService::MAX_NAME_LENGTH);
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
            $walletAddress = !empty($data['walletAddress']) ? trim($data['walletAddress']) : null;
            $accountHolderName = !empty($data['accountHolderName']) ? trim($data['accountHolderName']) : null;
            $openingDate = !empty($data['openingDate']) ? $data['openingDate'] : null;

            // Validate banking fields if provided
            if ($routingNumber !== null) {
                $routingValidation = $this->validationService->validateRoutingNumber($routingNumber);
                if (!$routingValidation['valid']) {
                    return new DataResponse(['error' => $this->l->t('Invalid routing number: %1$s', [$routingValidation['error']])], Http::STATUS_BAD_REQUEST);
                }
                $routingNumber = $routingValidation['formatted'];
            }

            if ($sortCode !== null) {
                $sortValidation = $this->validationService->validateSortCode($sortCode);
                if (!$sortValidation['valid']) {
                    return new DataResponse(['error' => $this->l->t('Invalid sort code: %1$s', [$sortValidation['error']])], Http::STATUS_BAD_REQUEST);
                }
                $sortCode = $sortValidation['formatted'];
            }

            if ($iban !== null) {
                $ibanValidation = $this->validationService->validateIban($iban);
                if (!$ibanValidation['valid']) {
                    return new DataResponse(['error' => $this->l->t('Invalid IBAN: %1$s', [$ibanValidation['error']])], Http::STATUS_BAD_REQUEST);
                }
                $iban = $ibanValidation['formatted'];
            }

            if ($swiftBic !== null) {
                $swiftValidation = $this->validationService->validateSwiftBic($swiftBic);
                if (!$swiftValidation['valid']) {
                    return new DataResponse(['error' => $this->l->t('Invalid SWIFT/BIC: %1$s', [$swiftValidation['error']])], Http::STATUS_BAD_REQUEST);
                }
                $swiftBic = $swiftValidation['formatted'];
            }

            // Create the account
            $account = $this->service->create(
                $this->getEffectiveUserId(),
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
                $minimumPayment,
                $walletAddress
            );

            // Audit log the account creation
            $this->auditService->logAccountCreated($this->getEffectiveUserId(), $account->getId(), $name);

            return new DataResponse($account, Http::STATUS_CREATED);

        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create account'));
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

            if (!$data || !is_array($data)) {
                return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
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
                    return new DataResponse(['error' => $this->l->t('Invalid account type: %1$s', [$typeValidation['error']])], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $typeValidation['formatted'];
            }

            // Validate currency if provided
            if (isset($data['currency'])) {
                $currencyValidation = $this->validationService->validateCurrency($data['currency']);
                if (!$currencyValidation['valid']) {
                    return new DataResponse(['error' => $this->l->t('Invalid currency: %1$s', [$currencyValidation['error']])], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => $this->l->t('Invalid routing number: %1$s', [$routingValidation['error']])], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => $this->l->t('Invalid sort code: %1$s', [$sortValidation['error']])], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => $this->l->t('Invalid IBAN: %1$s', [$ibanValidation['error']])], Http::STATUS_BAD_REQUEST);
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
                        return new DataResponse(['error' => $this->l->t('Invalid SWIFT/BIC: %1$s', [$swiftValidation['error']])], Http::STATUS_BAD_REQUEST);
                    }
                    $updates['swiftBic'] = $swiftValidation['formatted'];
                }
            } elseif (array_key_exists('swiftBic', $data) && $data['swiftBic'] === '') {
                $updates['swiftBic'] = null;
            }

            // Handle wallet address (encrypted, skip if masked)
            if (isset($data['walletAddress']) && $data['walletAddress'] !== '') {
                $value = trim($data['walletAddress']);
                if (strpos($value, '...') === false && strpos($value, '[DECRYPTION FAILED]') === false) {
                    $updates['walletAddress'] = $value;
                }
            } elseif (array_key_exists('walletAddress', $data) && $data['walletAddress'] === '') {
                $updates['walletAddress'] = null;
            }

            // Balance is not updatable via edit — it is managed by TransactionService
            // to prevent corruption from the adjusted (display) balance being written back.
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
            if (isset($data['openingBalance']) && $data['openingBalance'] !== '') {
                $updates['openingBalance'] = (float) $data['openingBalance'];
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $account = $this->service->update($id, $this->getEffectiveUserId(), $updates);

            // Audit log the update
            $this->auditService->logAccountUpdated($this->getEffectiveUserId(), $id, $updates);

            return new DataResponse($account);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update account'), Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            // Get account name before deletion for audit log
            $account = $this->service->find($id, $this->getEffectiveUserId());
            $accountName = $account->getName();

            $this->service->delete($id, $this->getEffectiveUserId());

            // Audit log the deletion
            $this->auditService->logAccountDeleted($this->getEffectiveUserId(), $id, $accountName);

            return new DataResponse(['status' => 'success']);
        } catch (DoesNotExistException $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
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
            $account = $this->service->find($id, $this->getEffectiveUserId());

            // Check if account has sensitive data to reveal
            if (!$account->hasSensitiveData()) {
                return new DataResponse([
                    'error' => $this->l->t('This account has no sensitive banking data to reveal')
                ], Http::STATUS_BAD_REQUEST);
            }

            // Audit log the reveal action
            $this->auditService->logAccountRevealed(
                $this->getEffectiveUserId(),
                $id,
                $account->getPopulatedSensitiveFields()
            );

            // Return full unmasked data
            return new DataResponse($account->toArrayFull());
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getSummary($this->getEffectiveUserId());
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve account summary'));
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
            $history = $this->service->getBalanceHistory($id, $this->getEffectiveUserId(), $days);
            return new DataResponse($history);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function reconcile(int $id, float $statementBalance): DataResponse {
        try {
            $result = $this->service->reconcile($id, $this->getEffectiveUserId(), $statementBalance);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to reconcile account'), Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }
}