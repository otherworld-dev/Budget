<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Command;

use OCA\Budget\Command\OcrConfig;
use OCA\Budget\Service\EncryptionService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The command exists so nobody reaches for `occ config:app:set budget ocr_*`,
 * which skips validation and would store an API key in plaintext. These tests
 * pin the two properties that justify it: everything goes through
 * OcrSettingsService, and a rejected value changes nothing.
 *
 * Symfony's CommandTester is not vendored, so the command is driven directly
 * with ArrayInput/BufferedOutput — run() is public API either way.
 */
class OcrConfigTest extends TestCase {
	private OcrConfig $command;
	private OcrSettingsService $settings;

	/** Stands in for the app config table. */
	private array $stored = [];

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(fn ($app, $key, $default = '') => $this->stored[$key] ?? $default);
		$config->method('setAppValue')
			->willReturnCallback(function ($app, $key, $value): void {
				$this->stored[$key] = $value;
			});

		$encryption = $this->createMock(EncryptionService::class);
		$encryption->method('encrypt')
			->willReturnCallback(fn (?string $v) => $v === null || $v === '' ? $v : 'enc:' . base64_encode($v));
		$encryption->method('decrypt')
			->willReturnCallback(fn (?string $v) => is_string($v) && str_starts_with($v, 'enc:')
				? base64_decode(substr($v, 4)) : $v);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no TaskProcessing'));

		$clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
		$this->settings = new OcrSettingsService($config, $encryption, $container, $clientService, $this->createMock(LoggerInterface::class));
		$this->command = new OcrConfig($this->settings);
	}

	/** @return array{0: int, 1: string} exit code and output */
	private function invoke(array $input): array {
		$output = new BufferedOutput();
		$code = $this->command->run(new ArrayInput($input + ['action' => 'show']), $output);

		return [$code, $output->fetch()];
	}

	public function testShowReportsAnUnconfiguredInstanceAsUnusable(): void {
		[$code, $out] = $this->invoke(['action' => 'show']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('none', $out);
		$this->assertStringContainsString('no', $out);
		$this->assertStringNotContainsString('yes', $out);
	}

	public function testSetConfiguresACustomEndpoint(): void {
		[$code, $out] = $this->invoke([
			'action' => 'set',
			'--provider' => 'custom',
			'--endpoint' => 'http://192.168.1.10:11434/v1',
			'--model' => 'qwen2.5vl:7b',
		]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Updated.', $out);
		$this->assertTrue($this->settings->isConfigured());
		$this->assertSame('custom', $this->settings->getProvider());
	}

	public function testAnUnknownProviderIsRejectedAndChangesNothing(): void {
		$before = $this->stored;

		[$code, $out] = $this->invoke(['action' => 'set', '--provider' => 'chatgpt']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Unknown OCR provider', $out);
		$this->assertSame($before, $this->stored);
	}

	public function testABadEndpointIsRejectedWithoutApplyingTheProvider(): void {
		// Atomicity: the valid --provider in the same command must not stick.
		[$code, $out] = $this->invoke([
			'action' => 'set',
			'--provider' => 'custom',
			'--endpoint' => 'ollama.lan:11434',
		]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('valid http', $out);
		$this->assertSame('none', $this->settings->getProvider());
	}

	public function testTheKeyIsStoredEncryptedAndNeverPrinted(): void {
		[$code, $out] = $this->invoke(['action' => 'set', '--api-key' => 'sk-secret-123']);

		$this->assertSame(0, $code);
		$this->assertStringNotContainsString('sk-secret-123', $out);
		$this->assertStringContainsString('encrypted, not shown', $out);
		$this->assertStringStartsWith('enc:', $this->stored['ocr_api_key']);
		$this->assertSame('sk-secret-123', $this->settings->getApiKey());
	}

	public function testAnEmptyKeyClearsIt(): void {
		$this->invoke(['action' => 'set', '--api-key' => 'sk-secret-123']);

		[$code, $out] = $this->invoke(['action' => 'set', '--api-key' => '']);

		$this->assertSame(0, $code);
		$this->assertFalse($this->settings->hasApiKey());
		$this->assertStringContainsString('not set', $out);
	}

	public function testSetWithNoOptionsIsAnError(): void {
		[$code, $out] = $this->invoke(['action' => 'set']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Nothing to set', $out);
	}

	public function testAnUnknownActionIsAnError(): void {
		[$code, $out] = $this->invoke(['action' => 'enable']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Unknown action', $out);
	}

	public function testNextcloudProviderWarnsWhenTheServerCannotServeIt(): void {
		$this->invoke(['action' => 'set', '--provider' => 'nextcloud']);

		[, $out] = $this->invoke(['action' => 'show']);

		$this->assertStringContainsString('core:image2text:ocr', $out);
	}
}
