<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * A provider whose bank authorization ends with the bank sending the browser
 * back to Budget's `/bank-sync/callback` with a `state` and a `code`.
 *
 * initializeConnection() and, for a reauthorizable provider,
 * reinitializeConnection() keep the authorization's single-use `state` in
 * the credentials JSON, with `stateCreatedAt` (Unix time) for when it was
 * issued. BankSyncService matches the redirect's state, in constant time,
 * among the logged-in user's pending connections of such providers and
 * ignores a state older than its time limit, so a provider is only handed a
 * code for one of its own connections.
 */
interface CallbackAuthorizationProviderInterface {
	/**
	 * Exchange the code from the bank's redirect for access to the accounts.
	 * The returned credentials no longer hold the state, so it cannot be
	 * used twice.
	 *
	 * @param string $credentials Current stored credentials (decrypted)
	 * @param string $code The `code` the bank's redirect carried
	 * @return array{credentials: string, accounts: list<array>, validUntil?: ?string} Accounts as in
	 *                                                                                 BankSyncProviderInterface (balances may be null); validUntil, an RFC 3339 time, is when the
	 *                                                                                 bank's consent ends, shown on each account's mapping
	 * @throws \Exception when the provider refuses the code
	 */
	public function completeAuthorization(string $credentials, string $code): array;

	/**
	 * Whether the credentials hold a completed authorization, as opposed to
	 * a first one still waiting for the bank's redirect.
	 */
	public function isAuthorized(string $credentials): bool;

	/**
	 * End the access the old credentials held, after a re-authorization
	 * replaced it. Best effort: never throws and logs nothing, because it
	 * runs on the bank's redirect, whose URL (code, state) Nextcloud adds to
	 * every log entry.
	 */
	public function revokeReplacedAuthorization(string $oldCredentials, string $newCredentials): void;
}
