<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\EncryptionService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The property that matters most here is that an instance nobody configured
 * never reports itself as ready, because isConfigured() is what every caller
 * checks before sending a receipt image anywhere.
 */
class OcrSettingsServiceTest extends TestCase {
	private OcrSettingsService $service;
	private IConfig $config;
	private EncryptionService $encryption;
	private ContainerInterface $container;
	private IClient $httpClient;

	/** Stands in for the app's config table. */
	private array $stored = [];

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->encryption = $this->createMock(EncryptionService::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$this->config->method('getAppValue')
			->willReturnCallback(fn ($app, $key, $default = '') => $this->stored[$key] ?? $default);
		$this->config->method('setAppValue')
			->willReturnCallback(function ($app, $key, $value): void {
				$this->stored[$key] = $value;
			});

		// The real thing round-trips through ICrypto. This stand-in only has
		// to be reversible and to leave no plaintext behind, so the stored
		// value can be asserted against.
		$this->encryption->method('encrypt')
			->willReturnCallback(fn (?string $v) => $v === null || $v === '' ? $v : 'enc:' . base64_encode($v));
		$this->encryption->method('decrypt')
			->willReturnCallback(fn (?string $v) => is_string($v) && str_starts_with($v, 'enc:')
				? base64_decode(substr($v, 4))
				: $v);

		// No TaskProcessing in the unit environment.
		$this->container->method('get')->willThrowException(new \RuntimeException('not available'));

		$this->httpClient = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->httpClient);

		$this->service = new OcrSettingsService(
			$this->config,
			$this->encryption,
			$this->container,
			$clientService,
			$this->createMock(LoggerInterface::class)
		);
	}

	/** An IResponse the mocked client will answer with. */
	private function relayResponse(int $status, array $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));
		return $response;
	}

	// ── defaults ────────────────────────────────────────────────────

	public function testDefaultsToOff(): void {
		$this->assertSame('none', $this->service->getProvider());
		$this->assertFalse($this->service->isConfigured());
		$this->assertFalse($this->service->hasApiKey());
	}

	public function testAnUnknownStoredProviderReadsAsOff(): void {
		// A downgrade, or a hand-edited config, must not leave a provider
		// selected that no code in this version knows how to call.
		$this->stored['ocr_provider'] = 'some-future-backend';

		$this->assertSame('none', $this->service->getProvider());
		$this->assertFalse($this->service->isConfigured());
	}

	// ── configured state ────────────────────────────────────────────

	public function testCustomProviderNeedsBothEndpointAndModel(): void {
		$this->service->update(['provider' => 'custom', 'endpoint' => 'http://ollama.lan:11434/v1']);
		$this->assertFalse($this->service->isConfigured(), 'endpoint alone is not enough');

		$this->service->update(['model' => 'qwen2.5vl']);
		$this->assertTrue($this->service->isConfigured());
	}

	public function testRelayProviderNeedsALicenseKey(): void {
		$this->service->update(['provider' => 'relay']);
		$this->assertFalse($this->service->isConfigured());

		$this->service->update(['apiKey' => 'lic_123']);
		$this->assertTrue($this->service->isConfigured());
	}

	public function testNextcloudProviderIsUnconfiguredWithoutTaskProcessing(): void {
		$this->service->update(['provider' => 'nextcloud']);

		$this->assertFalse($this->service->isConfigured());
	}

	public function testNextcloudAiRequiresTheExactOcrTaskType(): void {
		// The backend runs core:image2text:ocr and nothing else, so nothing
		// else may satisfy the check: not speech-to-text, not translation,
		// and not image CAPTIONING (core:image2text) — a captioning-only
		// server would advertise scanning and then fail every request.
		$this->assertFalse($this->serviceWithTaskTypes([])->isNextcloudAiAvailable());
		$this->assertFalse($this->serviceWithTaskTypes(['core:audio2text' => []])->isNextcloudAiAvailable());
		$this->assertFalse($this->serviceWithTaskTypes(['core:image2text' => []])->isNextcloudAiAvailable());

		$this->assertTrue($this->serviceWithTaskTypes(['core:image2text:ocr' => []])->isNextcloudAiAvailable());
		$this->assertTrue($this->serviceWithTaskTypes(['core:audio2text' => [], 'core:image2text:ocr' => []])->isNextcloudAiAvailable());
	}

	public function testARejectedUpdateWritesNothing(): void {
		// A 400 answers "nothing was saved"; a partial write behind it would
		// break a working setup while the error claims the opposite.
		$this->service->update(['provider' => 'relay', 'apiKey' => 'lic_123']);
		$before = $this->stored;

		try {
			$this->service->update(['provider' => 'custom', 'endpoint' => 'not-a-url', 'model' => 'qwen2.5vl']);
			$this->fail('expected InvalidArgumentException');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame($before, $this->stored);
		$this->assertSame('relay', $this->service->getProvider());
		$this->assertTrue($this->service->isConfigured());
	}

	public function testRelayUsesItsOwnEndpointRegardlessOfWhatIsStored(): void {
		$this->service->update(['provider' => 'custom', 'endpoint' => 'http://ollama.lan:11434/v1']);
		$this->service->update(['provider' => 'relay']);

		$this->assertSame(OcrSettingsService::RELAY_ENDPOINT, $this->service->getEndpoint());
	}

	public function testSettingsReportTheStoredEndpointWhileOnRelay(): void {
		$this->service->update(['provider' => 'custom', 'endpoint' => 'http://ollama.lan:11434/v1', 'model' => 'qwen2.5vl']);
		$this->service->update(['provider' => 'relay']);

		// The admin form round-trips this field on save, so masking it here
		// would erase the stored endpoint the moment the admin switches back
		// to custom — while getEndpoint() (what callers use) stays the relay.
		$this->assertSame('http://ollama.lan:11434/v1', $this->service->getSettings()['endpoint']);
		$this->assertSame(OcrSettingsService::RELAY_ENDPOINT, $this->service->getEndpoint());
	}

	// ── the credential ──────────────────────────────────────────────

	public function testApiKeyIsStoredEncrypted(): void {
		$this->service->update(['apiKey' => 'sk-secret']);

		$this->assertStringStartsWith('enc:', $this->stored['ocr_api_key']);
		$this->assertStringNotContainsString('sk-secret', $this->stored['ocr_api_key']);
		$this->assertSame('sk-secret', $this->service->getApiKey());
	}

	public function testAnEmptyApiKeyClearsTheStoredOne(): void {
		$this->service->update(['apiKey' => 'sk-secret']);
		$this->service->update(['apiKey' => '']);

		$this->assertFalse($this->service->hasApiKey());
		$this->assertSame('', $this->service->getApiKey());
	}

	public function testSettingsNeverExposeTheKey(): void {
		$this->service->update(['provider' => 'relay', 'apiKey' => 'lic_123']);

		$settings = $this->service->getSettings();

		$this->assertArrayNotHasKey('apiKey', $settings);
		$this->assertTrue($settings['apiKeySet']);
		$this->assertNotContains('lic_123', $settings);
	}

	public function testSettingsKeysAreStable(): void {
		$this->assertSame(
			['provider', 'endpoint', 'model', 'apiKeySet', 'configured', 'nextcloudAiAvailable', 'relayBillingBase'],
			array_keys($this->service->getSettings())
		);
	}

	// ── validation ──────────────────────────────────────────────────

	public function testRejectsAnUnknownProvider(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(['provider' => 'chatgpt']);
	}

	public function testRejectsAnEndpointThatIsNotAUrl(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(['endpoint' => 'ollama.lan:11434']);
	}

	public function testAcceptsAPlainHttpLanEndpoint(): void {
		// The server makes the call, so a LAN box is both valid and the most
		// private option — rejecting http:// here would rule it out.
		$this->service->update(['endpoint' => 'http://192.168.1.10:11434/v1']);

		$this->assertSame('http://192.168.1.10:11434/v1', $this->service->getEndpoint());
	}

	public function testAnEmptyEndpointClearsIt(): void {
		$this->service->update(['endpoint' => 'https://api.example.com/v1']);
		$this->service->update(['endpoint' => '']);

		$this->assertSame('', $this->service->getEndpoint());
	}

	public function testUpdateIsPartial(): void {
		$this->service->update(['provider' => 'custom', 'endpoint' => 'https://api.example.com/v1', 'model' => 'gpt-4o']);
		$this->service->update(['model' => 'qwen2.5vl']);

		$this->assertSame('custom', $this->service->getProvider());
		$this->assertSame('https://api.example.com/v1', $this->service->getEndpoint());
		$this->assertSame('qwen2.5vl', $this->service->getModel());
	}

	public function testTrimsPastedValues(): void {
		$this->service->update(['endpoint' => '  https://api.example.com/v1  ', 'model' => ' gpt-4o ', 'apiKey' => ' sk-x ']);

		$this->assertSame('https://api.example.com/v1', $this->service->getEndpoint());
		$this->assertSame('gpt-4o', $this->service->getModel());
		$this->assertSame('sk-x', $this->service->getApiKey());
	}

	/** A service whose container resolves a TaskProcessing manager offering the given task types. */
	private function serviceWithTaskTypes(array $types): OcrSettingsService {
		$manager = $this->createMock(\OCP\TaskProcessing\IManager::class);
		$manager->method('getAvailableTaskTypes')->willReturn($types);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($manager);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->createMock(IClient::class));

		return new OcrSettingsService(
			$this->config,
			$this->encryption,
			$container,
			$clientService,
			$this->createMock(LoggerInterface::class)
		);
	}

	// ── billing portal (#537) ───────────────────────────────────────

	public function testPortalRefusesWhenProviderIsNotRelay(): void {
		$this->stored['ocr_provider'] = 'custom';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not_relay');
		$this->service->createPortalUrl();
	}

	public function testPortalRefusesWithoutAKey(): void {
		$this->stored['ocr_provider'] = 'relay';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no_key');
		$this->service->createPortalUrl();
	}

	public function testPortalReturnsTheSessionUrl(): void {
		$this->stored['ocr_provider'] = 'relay';
		$this->service->update(['apiKey' => 'owr_live_abc']);

		$this->httpClient->expects($this->once())
			->method('post')
			->with(
				'https://ocr.otherworld.dev/billing/portal',
				$this->callback(fn ($options) => $options['headers']['Authorization'] === 'Bearer owr_live_abc')
			)
			->willReturn($this->relayResponse(200, ['url' => 'https://billing.stripe.com/session/xyz']));

		$this->assertSame('https://billing.stripe.com/session/xyz', $this->service->createPortalUrl());
	}

	public function testPortalMapsAKeyWithNoSubscription(): void {
		$this->stored['ocr_provider'] = 'relay';
		$this->service->update(['apiKey' => 'owr_live_trial']);

		$this->httpClient->method('post')
			->willReturn($this->relayResponse(404, ['error' => 'nothing to manage', 'code' => 'no_subscription']));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no_subscription');
		$this->service->createPortalUrl();
	}

	public function testPortalMapsRelayFailure(): void {
		$this->stored['ocr_provider'] = 'relay';
		$this->service->update(['apiKey' => 'owr_live_abc']);

		$this->httpClient->method('post')->willThrowException(new \Exception('connection refused'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unavailable');
		$this->service->createPortalUrl();
	}
}
