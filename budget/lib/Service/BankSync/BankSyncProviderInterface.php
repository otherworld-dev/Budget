<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * Interface for external bank data providers.
 *
 * Each provider handles authentication and data fetching for a specific
 * bank aggregation service. The app never sees bank credentials — providers
 * handle that. We only store provider-level access tokens/keys.
 *
 * Normalized account format returned by fetchAccounts():
 * [
 *     'accounts' => [
 *         [
 *             'id' => string,              // provider's unique account ID
 *             'name' => string,            // display name
 *             'currency' => string,        // 3-letter code
 *             'balance' => string,         // decimal string
 *             'transactions' => [
 *                 [
 *                     'id' => string,          // provider's unique transaction ID
 *                     'date' => string,        // YYYY-MM-DD
 *                     'amount' => string,      // signed decimal (negative = outflow)
 *                     'description' => string,
 *                     'vendor' => ?string,
 *                 ],
 *             ],
 *         ],
 *     ],
 * ]
 */
interface BankSyncProviderInterface {
    /**
     * Provider identifier (e.g. 'simplefin', 'gocardless').
     */
    public function getIdentifier(): string;

    /**
     * Human-readable display name.
     */
    public function getDisplayName(): string;

    /**
     * Initialize a new connection. Performs token exchange / API key validation.
     *
     * @param array $params Provider-specific parameters (e.g. setupToken, secretId/secretKey)
     * @return array{credentials: string, accounts: array} Credentials to store (encrypted) + initial account list
     * @throws \Exception on authentication failure
     */
    public function initializeConnection(array $params): array;

    /**
     * Fetch accounts and their recent transactions.
     *
     * @param string $credentials Provider-specific stored credentials (decrypted)
     * @return array Normalized account data (see interface docblock)
     * @throws \Exception on API failure
     */
    public function fetchAccounts(string $credentials): array;

    /**
     * Check if the connection needs re-authorization (e.g. GoCardless 90-day consent).
     *
     * @param string $credentials Provider-specific stored credentials
     * @return bool True if re-auth is needed
     */
    public function requiresReauthorization(string $credentials): bool;
}
