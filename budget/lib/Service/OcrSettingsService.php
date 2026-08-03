<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-wide configuration for receipt scanning (#534).
 *
 * Receipt images are read by an OCR backend, and the choice of backend is an
 * administrator's to make once for the instance rather than each user's to
 * make for themselves: the credential belongs to whoever pays for it, and
 * "which company sees my receipts" is a property of the server, not of the
 * person holding the phone.
 *
 * The overriding rule here is that an unconfigured instance makes no external
 * calls, ever. The provider defaults to 'none' and every consumer is expected
 * to check isConfigured() before doing anything at all — there is deliberately
 * no fallback backend to quietly land on.
 */
class OcrSettingsService {
    /** No OCR. The default, and what an instance that never touches this stays on. */
    public const PROVIDER_NONE = 'none';

    /** Whatever AI backend the admin already chose in Nextcloud's own settings. */
    public const PROVIDER_NEXTCLOUD = 'nextcloud';

    /** Any OpenAI-compatible endpoint — a LAN Ollama box, or a hosted service. */
    public const PROVIDER_CUSTOM = 'custom';

    /** Otherworld's hosted relay, authenticated with a license key (#537). */
    public const PROVIDER_RELAY = 'relay';

    public const PROVIDERS = [
        self::PROVIDER_NONE,
        self::PROVIDER_NEXTCLOUD,
        self::PROVIDER_CUSTOM,
        self::PROVIDER_RELAY,
    ];

    private const KEY_PROVIDER = 'ocr_provider';
    private const KEY_ENDPOINT = 'ocr_endpoint';
    private const KEY_API_KEY = 'ocr_api_key';
    private const KEY_MODEL = 'ocr_model';

    /** Sent to the relay so the hosted side can pick a backend. */
    public const RELAY_ENDPOINT = 'https://ocr.otherworld.dev/v1';

    public function __construct(
        private IConfig $config,
        private EncryptionService $encryption,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    public function getProvider(): string {
        $provider = $this->config->getAppValue(Application::APP_ID, self::KEY_PROVIDER, self::PROVIDER_NONE);

        // An unrecognised value (hand-edited config, downgrade from a later
        // version that knew more providers) must read as off, not as a
        // half-configured provider that something downstream tries to call.
        return in_array($provider, self::PROVIDERS, true) ? $provider : self::PROVIDER_NONE;
    }

    public function getEndpoint(): string {
        if ($this->getProvider() === self::PROVIDER_RELAY) {
            return self::RELAY_ENDPOINT;
        }

        return $this->config->getAppValue(Application::APP_ID, self::KEY_ENDPOINT, '');
    }

    public function getModel(): string {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_MODEL, '');
    }

    /**
     * The stored credential in the clear, for the code that makes the call.
     *
     * Never hand the result to a controller response — see getSettings(),
     * which reports only whether a key exists.
     */
    public function getApiKey(): string {
        $stored = $this->config->getAppValue(Application::APP_ID, self::KEY_API_KEY, '');

        return $stored === '' ? '' : ($this->encryption->decrypt($stored) ?? '');
    }

    public function hasApiKey(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_API_KEY, '') !== '';
    }

    /**
     * Whether receipt scanning can actually run right now.
     *
     * This is what gates the feature everywhere — the API discovery endpoint's
     * receiptOcr flag, the web upload button, and the extraction endpoint
     * itself. A provider that is selected but missing its endpoint or key is
     * not configured; it would only fail later, further from the admin who
     * could fix it.
     */
    public function isConfigured(): bool {
        return match ($this->getProvider()) {
            self::PROVIDER_NEXTCLOUD => $this->isNextcloudAiAvailable(),
            self::PROVIDER_CUSTOM => $this->getEndpoint() !== '' && $this->getModel() !== '',
            self::PROVIDER_RELAY => $this->hasApiKey(),
            default => false,
        };
    }

    /**
     * Whether this server has an AI backend that can actually read an image.
     *
     * Resolved through the container rather than injected, because the
     * TaskProcessing manager is a moving target across the Nextcloud versions
     * this app supports and a missing class must degrade to "not available"
     * instead of breaking dependency injection for the whole service.
     *
     * A non-empty task-type list is not enough: a server whose only AI
     * integration is speech-to-text or translation would pass that test and
     * then fail on the first receipt. Reading a photo needs an image-to-text
     * task type specifically (core:image2text:ocr, or another image2text
     * variant), so that is what is required here.
     */
    public function isNextcloudAiAvailable(): bool {
        if (!interface_exists(\OCP\TaskProcessing\IManager::class)) {
            return false;
        }

        try {
            $manager = $this->container->get(\OCP\TaskProcessing\IManager::class);

            foreach (array_keys($manager->getAvailableTaskTypes()) as $typeId) {
                if (str_starts_with((string)$typeId, 'core:image2text')) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->debug('TaskProcessing unavailable: ' . $e->getMessage(), ['app' => Application::APP_ID]);

            return false;
        }
    }

    /**
     * The admin-facing view of these settings.
     *
     * The API key is reported as a boolean and never echoed back. A key that
     * has been saved cannot be read out again through the web UI — an admin
     * who has lost it re-enters it, which is a smaller problem than a
     * credential sitting in a JSON response.
     */
    public function getSettings(): array {
        return [
            'provider' => $this->getProvider(),
            // Always the STORED endpoint, even while the relay (which has its
            // own fixed URL) is selected. The admin form round-trips this
            // value back on save, so masking it here would make switching to
            // the relay and back silently erase a working custom endpoint.
            'endpoint' => $this->config->getAppValue(Application::APP_ID, self::KEY_ENDPOINT, ''),
            'model' => $this->getModel(),
            'apiKeySet' => $this->hasApiKey(),
            'configured' => $this->isConfigured(),
            'nextcloudAiAvailable' => $this->isNextcloudAiAvailable(),
        ];
    }

    /**
     * Apply a partial update from the admin form.
     *
     * All values are validated before ANY of them is written. A rejected
     * request must leave the configuration exactly as it was: the controller
     * answers 400, the form says nothing was saved, and both have to be
     * telling the truth — an update that stores the provider and then throws
     * on the endpoint would leave a half-switched setup behind an error
     * message claiming the opposite.
     *
     * @param array $values Any of provider, endpoint, model, apiKey.
     * @throws \InvalidArgumentException When a value is unusable, so the
     *                                   controller can answer 400 rather than
     *                                   storing something that fails later.
     */
    public function update(array $values): void {
        $writes = [];

        if (array_key_exists('provider', $values)) {
            $provider = (string)$values['provider'];
            if (!in_array($provider, self::PROVIDERS, true)) {
                throw new \InvalidArgumentException('Unknown OCR provider');
            }
            $writes[self::KEY_PROVIDER] = $provider;
        }

        if (array_key_exists('endpoint', $values)) {
            $endpoint = trim((string)$values['endpoint']);
            if ($endpoint !== '' && !$this->isUsableEndpoint($endpoint)) {
                throw new \InvalidArgumentException('The endpoint must be a valid http:// or https:// URL');
            }
            $writes[self::KEY_ENDPOINT] = $endpoint;
        }

        if (array_key_exists('model', $values)) {
            $writes[self::KEY_MODEL] = trim((string)$values['model']);
        }

        if (array_key_exists('apiKey', $values)) {
            $key = trim((string)$values['apiKey']);
            // An empty string clears the credential; that is the only way to
            // remove one, since the form can never show what is stored.
            $writes[self::KEY_API_KEY] = $key === '' ? '' : ($this->encryption->encrypt($key) ?? '');
        }

        foreach ($writes as $configKey => $value) {
            $this->config->setAppValue(Application::APP_ID, $configKey, $value);
        }
    }

    /**
     * A LAN address is expressly allowed: the server makes the call, so an
     * Ollama box that is unreachable from the internet works fine and is the
     * most private option available. Only the scheme and host are checked.
     */
    private function isUsableEndpoint(string $endpoint): bool {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
