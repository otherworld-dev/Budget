<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * Factory for creating bank sync providers by identifier.
 */
class ProviderFactory {
    /** @var array<string, BankSyncProviderInterface> */
    private array $providers = [];

    public function __construct(
        SimpleFINProvider $simplefin,
        GoCardlessProvider $gocardless
    ) {
        $this->providers['simplefin'] = $simplefin;
        $this->providers['gocardless'] = $gocardless;
    }

    public function getProvider(string $identifier): BankSyncProviderInterface {
        if (!isset($this->providers[$identifier])) {
            throw new \InvalidArgumentException("Unknown bank sync provider: {$identifier}");
        }
        return $this->providers[$identifier];
    }

    /**
     * @return array<string, array{id: string, name: string}>
     */
    public function getAvailableProviders(): array {
        $result = [];
        foreach ($this->providers as $provider) {
            $result[] = [
                'id' => $provider->getIdentifier(),
                'name' => $provider->getDisplayName(),
            ];
        }
        return $result;
    }
}
