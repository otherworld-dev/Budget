<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * A provider that lets the user pick their bank from a list before connecting.
 *
 * Exists so the controller can ask "can this provider list banks?" instead of
 * special-casing each provider class.
 */
interface InstitutionListingProviderInterface {
	/**
	 * List the banks the user can connect. Entries may carry extra
	 * provider-specific keys.
	 *
	 * @param string $userId Nextcloud user, for providers whose API keys are stored per user
	 * @param array $params Provider-specific filters (e.g. country)
	 * @return list<array{id: string, name: string, logo: ?string, country: string}>
	 * @throws \Exception on API failure
	 */
	public function listInstitutions(string $userId, array $params): array;
}
