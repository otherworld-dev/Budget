<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\InterestService;
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
    private InterestService $interestService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        AccountService $service,
        ValidationService $validationService,
        AuditService $auditService,
        GranularShareService $granularShareService,
        InterestService $interestService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->interestService = $interestService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
        $this->setGranularShareService($granularShareService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            // Own accounts with balance adjustments
            $accounts = $this->service->findAllWithCurrentBalances($this->userId);

            // Merge in shared accounts
            $shared = $this->granularShareService->getSharedAccounts($this->userId);
            if (!empty($shared)) {
                $accounts = array_merge($accounts, $shared);
            }

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
            if (!$this->canAccessEntity('account', $id)) {
                return new DataResponse(['error' => $this->l->t('Account not found')], Http::STATUS_NOT_FOUND);
            }
            // Try own account first, fall back to shared
            try {
                $account = $this->service->findWithCurrentBalance($id, $this->userId);
            } catch (\Exception $e) {
                $account = $this->service->findByIdsAsArrays([$id])[0] ?? null;
                if (!$account) throw $e;
            }
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
            $this->requireWriteAccess('account', $id);

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
            if (isset($data['compoundingFrequency'])) {
                $validFreqs = ['simple', 'daily', 'monthly', 'yearly'];
                if (in_array($data['compoundingFrequency'], $validFreqs, true)) {
                    $updates['compoundingFrequency'] = $data['compoundingFrequency'];
                }
            }

            // Handle interest enable/disable transitions
            $interestToggled = false;
            if (isset($data['interestEnabled'])) {
                $newEnabled = (bool) $data['interestEnabled'];
                $updates['interestEnabled'] = $newEnabled;
                $interestToggled = true;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $account = $this->service->update($id, $this->getEffectiveUserId(), $updates);

            // Handle interest enable/disable after account update
            if ($interestToggled) {
                $newEnabled = $updates['interestEnabled'];
                if ($newEnabled) {
                    $compounding = $data['compoundingFrequency'] ?? $account->getCompoundingFrequency() ?? 'daily';
                    $this->interestService->enableInterest($id, $this->getEffectiveUserId(), $compounding);
                } else {
                    $this->interestService->disableInterest($id, $this->getEffectiveUserId());
                }
                // Reload account after interest state change
                $account = $this->service->find($id, $this->getEffectiveUserId());
            }

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
            $this->requireWriteAccess('account', $id);

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
            // Only account owners can reveal sensitive data — never shared users
            $ownIds = $this->granularShareService->getOwnAccountIds($this->userId);
            if (!in_array($id, $ownIds, true)) {
                return new DataResponse(['error' => $this->l->t('Account not found')], Http::STATUS_NOT_FOUND);
            }
            $account = $this->service->find($id, $this->userId);

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
            $this->requireWriteAccess('account', $id);
            $result = $this->service->reconcile($id, $this->getEffectiveUserId(), $statementBalance);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to reconcile account'), Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }

    // ── Interest Accrual ────────────────────────────────────────

    /**
     * Get full interest details for an account (live calculation + rate history).
     * @NoAdminRequired
     */
    public function getInterestDetails(int $id): DataResponse {
        try {
            $result = $this->interestService->calculateAccruedInterest($id, $this->getEffectiveUserId());
            $result['rateHistory'] = $this->interestService->getRateHistory($id, $this->getEffectiveUserId());
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
        }
    }

    /**
     * Get interest rate history for an account.
     * @NoAdminRequired
     */
    public function getInterestRates(int $id): DataResponse {
        try {
            $rates = $this->interestService->getRateHistory($id, $this->getEffectiveUserId());
            return new DataResponse($rates);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Account'), ['accountId' => $id]);
        }
    }

    /**
     * Add a new interest rate change for an account.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function addInterestRate(int $id): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $params = $this->request->getParams();
            $rate = (float) ($params['rate'] ?? 0);
            $compoundingFrequency = $params['compoundingFrequency'] ?? 'daily';
            $effectiveDate = $params['effectiveDate'] ?? date('Y-m-d');

            if ($rate < 0 || $rate > 999.9999) {
                return new DataResponse(['error' => $this->l->t('Interest rate must be between 0 and 999.9999')], Http::STATUS_BAD_REQUEST);
            }

            $validFreqs = ['simple', 'daily', 'monthly', 'yearly'];
            if (!in_array($compoundingFrequency, $validFreqs, true)) {
                return new DataResponse(['error' => $this->l->t('Invalid compounding frequency')], Http::STATUS_BAD_REQUEST);
            }

            $interestRate = $this->interestService->addRateChange($id, $this->getEffectiveUserId(), $rate, $compoundingFrequency, $effectiveDate);
            return new DataResponse($interestRate);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to add interest rate'), Http::STATUS_BAD_REQUEST, ['accountId' => $id]);
        }
    }

    /**
     * Delete an interest rate change.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteInterestRate(int $id, int $rateId): DataResponse {
        try {
            $this->requireWriteAccess('account', $id);
            $this->interestService->deleteRateChange($rateId, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete interest rate'), Http::STATUS_BAD_REQUEST, ['accountId' => $id, 'rateId' => $rateId]);
        }
    }
}