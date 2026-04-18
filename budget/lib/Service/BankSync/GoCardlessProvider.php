<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * GoCardless Bank Account Data (formerly Nordigen) provider for UK/European banks.
 *
 * Authentication flow:
 * 1. User signs up at bankaccountdata.gocardless.com, gets Secret ID + Secret Key
 * 2. App uses these to get an access token from GoCardless API
 * 3. User selects their bank (institution), app creates a requisition
 * 4. User is redirected to their bank to authorize access
 * 5. After authorization, app can fetch account data
 *
 * PSD2 requirement: Bank connections expire after 90 days, user must re-authorize.
 */
class GoCardlessProvider implements BankSyncProviderInterface {
    private const BASE_URL = 'https://bankaccountdata.gocardless.com/api/v2';

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger
    ) {
    }

    public function getIdentifier(): string {
        return 'gocardless';
    }

    public function getDisplayName(): string {
        return 'GoCardless (UK/Europe)';
    }

    public function initializeConnection(array $params): array {
        $secretId = $params['secretId'] ?? null;
        $secretKey = $params['secretKey'] ?? null;
        $institutionId = $params['institutionId'] ?? null;
        $redirectUrl = $params['redirectUrl'] ?? null;

        if (!$secretId || !$secretKey) {
            throw new \InvalidArgumentException('Secret ID and Secret Key are required');
        }

        // Get access token
        $accessToken = $this->getAccessToken($secretId, $secretKey);

        // Store credentials as JSON
        $credentials = json_encode([
            'secretId' => $secretId,
            'secretKey' => $secretKey,
            'accessToken' => $accessToken['access'],
            'refreshToken' => $accessToken['refresh'] ?? null,
            'tokenExpires' => time() + ($accessToken['access_expires'] ?? 86400),
        ]);

        // If institution specified, create a requisition for bank authorization
        $accounts = [];
        if ($institutionId) {
            $requisition = $this->createRequisition($accessToken['access'], $institutionId, $redirectUrl ?? '');
            $credentials = json_encode(array_merge(
                json_decode($credentials, true),
                ['requisitionId' => $requisition['id']]
            ));

            // Return the authorization link for the user to visit
            return [
                'credentials' => $credentials,
                'accounts' => [],
                'authorizationUrl' => $requisition['link'],
                'requisitionId' => $requisition['id'],
            ];
        }

        return [
            'credentials' => $credentials,
            'accounts' => $accounts,
        ];
    }

    public function fetchAccounts(string $credentials): array {
        $creds = json_decode($credentials, true);
        if (!$creds) {
            throw new \Exception('Invalid credentials format');
        }

        // Refresh token if expired
        $accessToken = $creds['accessToken'];
        $updatedCredentials = null;
        if (isset($creds['tokenExpires']) && time() > $creds['tokenExpires']) {
            $newToken = $this->getAccessToken($creds['secretId'], $creds['secretKey']);
            $accessToken = $newToken['access'];
            $creds['accessToken'] = $accessToken;
            $creds['tokenExpires'] = time() + ($newToken['access_expires'] ?? 86400);
            if (isset($newToken['refresh'])) {
                $creds['refreshToken'] = $newToken['refresh'];
            }
            $updatedCredentials = json_encode($creds);
        }

        // Get requisition details to find linked accounts
        $requisitionId = $creds['requisitionId'] ?? null;
        if (!$requisitionId) {
            return ['accounts' => []];
        }

        $requisition = $this->getRequisition($accessToken, $requisitionId);
        $accountIds = $requisition['accounts'] ?? [];

        $accounts = [];
        foreach ($accountIds as $accountId) {
            try {
                $details = $this->getAccountDetails($accessToken, $accountId);
                $balances = $this->getAccountBalances($accessToken, $accountId);
                $transactions = $this->getAccountTransactions($accessToken, $accountId);

                $balance = '0';
                if (!empty($balances['balances'])) {
                    // Prefer 'expected' or 'interimAvailable' balance
                    foreach ($balances['balances'] as $bal) {
                        $balance = $bal['balanceAmount']['amount'] ?? '0';
                        if (($bal['balanceType'] ?? '') === 'expected') {
                            break;
                        }
                    }
                }

                $normalizedTx = [];
                $bookedTx = $transactions['transactions']['booked'] ?? [];
                foreach ($bookedTx as $tx) {
                    $amount = $tx['transactionAmount']['amount'] ?? '0';
                    $normalizedTx[] = [
                        'id' => $tx['transactionId'] ?? $tx['internalTransactionId'] ?? hash('sha256', ($tx['bookingDate'] ?? '') . ($tx['transactionAmount']['amount'] ?? '') . ($tx['remittanceInformationUnstructured'] ?? '')),
                        'date' => $tx['bookingDate'] ?? $tx['valueDate'] ?? date('Y-m-d'),
                        'amount' => $amount,
                        'description' => $tx['remittanceInformationUnstructured']
                            ?? $tx['remittanceInformationUnstructuredArray'][0]
                            ?? $tx['additionalInformation']
                            ?? '',
                        'vendor' => $tx['creditorName'] ?? $tx['debtorName'] ?? null,
                    ];
                }

                $accounts[] = [
                    'id' => $accountId,
                    'name' => $details['account']['name']
                        ?? $details['account']['product']
                        ?? $details['account']['iban']
                        ?? 'Account',
                    'currency' => $details['account']['currency']
                        ?? $balances['balances'][0]['balanceAmount']['currency']
                        ?? 'GBP',
                    'balance' => $balance,
                    'transactions' => $normalizedTx,
                ];
            } catch (\Exception $e) {
                $this->logger->warning("GoCardless: failed to fetch account {$accountId}: " . $e->getMessage(), ['app' => 'budget']);
            }
        }

        $result = ['accounts' => $accounts];
        if ($updatedCredentials !== null) {
            $result['updatedCredentials'] = $updatedCredentials;
        }
        return $result;
    }

    public function requiresReauthorization(string $credentials): bool {
        $creds = json_decode($credentials, true);
        if (!$creds || !isset($creds['requisitionId'])) {
            return true;
        }

        try {
            $accessToken = $creds['accessToken'];
            if (isset($creds['tokenExpires']) && time() > $creds['tokenExpires']) {
                $newToken = $this->getAccessToken($creds['secretId'], $creds['secretKey']);
                $accessToken = $newToken['access'];
            }

            $requisition = $this->getRequisition($accessToken, $creds['requisitionId']);
            $status = $requisition['status'] ?? '';

            // GoCardless statuses: CR (created), LN (linked), EX (expired), RJ (rejected), SA (suspended)
            return !in_array($status, ['LN'], true);
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Get an access token for API calls (e.g. institution listing).
     */
    public function getToken(string $secretId, string $secretKey): string {
        $tokenData = $this->getAccessToken($secretId, $secretKey);
        return $tokenData['access'];
    }

    /**
     * Get available institutions (banks) for a country.
     *
     * @return array List of institutions with id, name, logo
     */
    public function getInstitutions(string $accessToken, string $country = 'GB'): array {
        $client = $this->clientService->newClient();
        $response = $client->get(self::BASE_URL . '/institutions/?country=' . urlencode($country), [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 30,
        ]);

        $institutions = json_decode($response->getBody(), true);
        if (!is_array($institutions)) {
            return [];
        }

        return array_map(fn($inst) => [
            'id' => $inst['id'],
            'name' => $inst['name'],
            'logo' => $inst['logo'] ?? null,
            'countries' => $inst['countries'] ?? [],
        ], $institutions);
    }

    // ── Private API methods ────────────────────────────────

    private function getAccessToken(string $secretId, string $secretKey): array {
        $client = $this->clientService->newClient();
        $response = $client->post(self::BASE_URL . '/token/new/', [
            'json' => [
                'secret_id' => $secretId,
                'secret_key' => $secretKey,
            ],
            'timeout' => 30,
        ]);

        $data = json_decode($response->getBody(), true);
        if (!$data || !isset($data['access'])) {
            throw new \Exception('Failed to obtain GoCardless access token');
        }

        return $data;
    }

    private function createRequisition(string $accessToken, string $institutionId, string $redirectUrl): array {
        $client = $this->clientService->newClient();
        $response = $client->post(self::BASE_URL . '/requisitions/', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'json' => [
                'institution_id' => $institutionId,
                'redirect' => $redirectUrl,
            ],
            'timeout' => 30,
        ]);

        $data = json_decode($response->getBody(), true);
        if (!$data || !isset($data['id'])) {
            throw new \Exception('Failed to create GoCardless requisition');
        }

        return $data;
    }

    private function getRequisition(string $accessToken, string $requisitionId): array {
        $client = $this->clientService->newClient();
        $response = $client->get(self::BASE_URL . '/requisitions/' . urlencode($requisitionId) . '/', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 30,
        ]);

        return json_decode($response->getBody(), true) ?? [];
    }

    private function getAccountDetails(string $accessToken, string $accountId): array {
        $client = $this->clientService->newClient();
        $response = $client->get(self::BASE_URL . '/accounts/' . urlencode($accountId) . '/details/', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 30,
        ]);

        return json_decode($response->getBody(), true) ?? [];
    }

    private function getAccountBalances(string $accessToken, string $accountId): array {
        $client = $this->clientService->newClient();
        $response = $client->get(self::BASE_URL . '/accounts/' . urlencode($accountId) . '/balances/', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 30,
        ]);

        return json_decode($response->getBody(), true) ?? [];
    }

    private function getAccountTransactions(string $accessToken, string $accountId): array {
        $client = $this->clientService->newClient();
        $response = $client->get(self::BASE_URL . '/accounts/' . urlencode($accountId) . '/transactions/', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 30,
        ]);

        return json_decode($response->getBody(), true) ?? [];
    }
}
