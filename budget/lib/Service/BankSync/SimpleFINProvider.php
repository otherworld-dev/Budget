<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * SimpleFIN Bridge provider for US bank integration.
 *
 * Authentication flow:
 * 1. User gets a setup token from https://beta-bridge.simplefin.org
 * 2. App base64-decodes the token to get a claim URL
 * 3. App POSTs to the claim URL to get an Access URL (with embedded Basic Auth)
 * 4. Access URL is stored encrypted and used for all subsequent requests
 *
 * Rate limit: 24 requests/day per connection.
 */
class SimpleFINProvider implements BankSyncProviderInterface {
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger
    ) {
    }

    public function getIdentifier(): string {
        return 'simplefin';
    }

    public function getDisplayName(): string {
        return 'SimpleFIN Bridge';
    }

    public function initializeConnection(array $params): array {
        $setupToken = $params['setupToken'] ?? null;
        if (!$setupToken) {
            throw new \InvalidArgumentException('Setup token is required');
        }

        // Decode the setup token to get the claim URL
        $claimUrl = base64_decode($setupToken, true);
        if ($claimUrl === false || !filter_var($claimUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid setup token');
        }

        // Claim the token to get the access URL
        $client = $this->clientService->newClient();
        try {
            $response = $client->post($claimUrl);
            $accessUrl = $response->getBody();

            if (empty($accessUrl) || !filter_var($accessUrl, FILTER_VALIDATE_URL)) {
                throw new \Exception('Invalid access URL received from SimpleFIN');
            }
        } catch (\Exception $e) {
            $this->logger->error('SimpleFIN token claim failed: ' . $e->getMessage(), ['app' => 'budget']);
            throw new \Exception('Failed to claim SimpleFIN token: ' . $e->getMessage());
        }

        // Fetch initial accounts to verify the connection works
        $accountData = $this->fetchAccounts($accessUrl);

        return [
            'credentials' => $accessUrl,
            'accounts' => $accountData['accounts'],
        ];
    }

    public function fetchAccounts(string $credentials): array {
        $accessUrl = $credentials;

        // Parse the access URL to extract Basic Auth credentials
        $parsed = parse_url($accessUrl);
        if (!$parsed || empty($parsed['user'])) {
            throw new \Exception('Invalid SimpleFIN access URL');
        }

        $baseUrl = sprintf('%s://%s%s',
            $parsed['scheme'],
            $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : ''),
            rtrim($parsed['path'] ?? '', '/')
        );
        $username = $parsed['user'];
        $password = $parsed['pass'] ?? '';

        $client = $this->clientService->newClient();
        try {
            $response = $client->get($baseUrl . '/accounts', [
                'auth' => [$username, $password],
                'timeout' => 30,
            ]);
            $data = json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('SimpleFIN fetch failed: ' . $e->getMessage(), ['app' => 'budget']);
            throw new \Exception('Failed to fetch accounts from SimpleFIN');
        }

        if (!is_array($data) || !isset($data['accounts'])) {
            throw new \Exception('Unexpected response format from SimpleFIN');
        }

        // Normalize to common format
        $accounts = [];
        foreach ($data['accounts'] as $account) {
            $transactions = [];
            foreach ($account['transactions'] ?? [] as $tx) {
                $amount = (string) ($tx['amount'] ?? '0');
                $transactions[] = [
                    'id' => $tx['id'] ?? hash('sha256', ($tx['posted'] ?? '') . ($tx['amount'] ?? '') . ($tx['description'] ?? '')),
                    'date' => isset($tx['posted']) ? date('Y-m-d', (int) $tx['posted']) : date('Y-m-d'),
                    'amount' => $amount,
                    'description' => $tx['description'] ?? '',
                    'vendor' => null,
                ];
            }

            $accounts[] = [
                'id' => $account['id'] ?? '',
                'name' => $account['name'] ?? 'Unknown Account',
                'currency' => strtoupper($account['currency'] ?? 'USD'),
                'balance' => (string) ($account['balance'] ?? '0'),
                'transactions' => $transactions,
            ];
        }

        return ['accounts' => $accounts];
    }

    public function requiresReauthorization(string $credentials): bool {
        // SimpleFIN access URLs are long-lived until revoked by the user
        return false;
    }
}
