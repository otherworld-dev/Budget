<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync\EnableBanking;

use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingAuthException;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingClient;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeys;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingRateLimitException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnableBankingClientTest extends TestCase {
	private EnableBankingClient $api;
	private IClient $client;
	private string $privateKeyPem;
	private string $publicKeyPem;

	protected function setUp(): void {
		$clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->api = new EnableBankingClient($clientService, $this->createMock(LoggerInterface::class));

		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		openssl_pkey_export($key, $pem);
		$this->privateKeyPem = $pem;
		$this->publicKeyPem = openssl_pkey_get_details($key)['key'];
	}

	private function keys(): EnableBankingKeys {
		return new EnableBankingKeys('app-1', $this->privateKeyPem);
	}

	/**
	 * print_r() of the frames of app code, arguments included. PHPUnit's
	 * own frames carry the test case, which holds the PEM itself.
	 */
	public static function appFrames(\Throwable $e): string {
		$frames = array_filter(
			$e->getTrace(),
			fn (array $f) => str_starts_with($f['class'] ?? '', 'OCA\\Budget\\')
				&& !str_starts_with($f['class'] ?? '', 'OCA\\Budget\\Tests\\')
		);
		return print_r($frames, true);
	}

	private function b64urlDecode(string $data): string {
		return base64_decode(strtr($data, '-_', '+/'));
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	public function testBuildJwtHasExpectedHeaderAndClaims(): void {
		$jwt = $this->api->buildJwt($this->keys(), 1_800_000_000);
		[$h, $p] = explode('.', $jwt);

		$this->assertSame(
			['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'app-1'],
			json_decode($this->b64urlDecode($h), true)
		);
		$this->assertSame(
			[
				'iss' => 'enablebanking.com',
				'aud' => 'api.enablebanking.com',
				'iat' => 1_800_000_000,
				'exp' => 1_800_003_600,
			],
			json_decode($this->b64urlDecode($p), true)
		);
	}

	public function testBuildJwtSignatureVerifiesWithPublicKey(): void {
		$jwt = $this->api->buildJwt($this->keys(), 1_800_000_000);
		[$h, $p, $s] = explode('.', $jwt);

		$this->assertSame(
			1,
			openssl_verify("$h.$p", $this->b64urlDecode($s), $this->publicKeyPem, OPENSSL_ALGO_SHA256)
		);
	}

	public function testIsValidPrivateKey(): void {
		$this->assertTrue(EnableBankingClient::isValidPrivateKey($this->privateKeyPem));
		$this->assertFalse(EnableBankingClient::isValidPrivateKey('not a key'));
		$this->assertFalse(EnableBankingClient::isValidPrivateKey($this->publicKeyPem));
	}

	public function testRequestSendsBearerJwtAndJson(): void {
		$payload = ['access' => ['valid_until' => '2027-01-01T00:00:00+00:00']];

		$this->client->expects($this->once())
			->method('post')
			->with(
				'https://api.enablebanking.com/auth',
				$this->callback(function (array $options) use ($payload): bool {
					return str_starts_with($options['headers']['Authorization'] ?? '', 'Bearer ')
						&& $options['json'] === $payload
						&& $options['http_errors'] === false;
				})
			)
			->willReturn($this->response(200, '{"url":"x"}'));

		$result = $this->api->request('POST', '/auth', $this->keys(), $payload);

		$this->assertSame(['url' => 'x'], $result);
	}

	public function testRequestPassesQueryForGet(): void {
		$this->client->expects($this->once())
			->method('get')
			->with(
				'https://api.enablebanking.com/accounts/abc/transactions',
				$this->callback(fn (array $options): bool => $options['query'] === ['date_from' => '2026-01-01'])
			)
			->willReturn($this->response(200, '{"transactions":[]}'));

		$result = $this->api->request(
			'GET',
			'/accounts/abc/transactions',
			$this->keys(),
			null,
			['date_from' => '2026-01-01']
		);

		$this->assertSame(['transactions' => []], $result);
	}

	public function testRequestMaps429ToRateLimitException(): void {
		$this->client->method('get')->willReturn($this->response(429, '{}'));

		$this->expectException(EnableBankingRateLimitException::class);
		$this->api->request('GET', '/aspsps', $this->keys());
	}

	/**
	 * @dataProvider authStatusProvider
	 */
	public function testRequestMaps401And403ToAuthException(int $status): void {
		$this->client->method('get')->willReturn($this->response($status, '{}'));

		$this->expectException(EnableBankingAuthException::class);
		$this->api->request('GET', '/aspsps', $this->keys());
	}

	public static function authStatusProvider(): array {
		return [[401], [403]];
	}

	public function testRequestErrorMessageDoesNotLeakBody(): void {
		$this->client->method('get')->willReturn($this->response(500, '{"secret":"S3CRET"}'));

		try {
			$this->api->request('GET', '/aspsps', $this->keys());
			$this->fail('Expected exception');
		} catch (\RuntimeException $e) {
			$this->assertStringNotContainsString('S3CRET', $e->getMessage());
			$this->assertSame('Enable Banking request failed (HTTP 500)', $e->getMessage());
		}
	}

	/**
	 * With zend.exception_ignore_args off (PHP's default without a production
	 * php.ini) every frame keeps its arguments, and Nextcloud logs them with
	 * the exception. A wrong application ID answers 401, and that trace held
	 * the PEM and the session path.
	 */
	public function testExceptionTraceCarriesNeitherKeyNorSessionId(): void {
		$previous = ini_set('zend.exception_ignore_args', '0');
		try {
			$this->client->method('get')->willReturn($this->response(401, '{}'));

			try {
				$this->api->request('GET', '/sessions/SESSION-SECRET', $this->keys(), null, ['state' => 'STATE-SECRET']);
				$this->fail('Expected an EnableBankingAuthException');
			} catch (EnableBankingAuthException $e) {
				$this->assertNotSame([], $e->getTrace()[0]['args'] ?? [], 'trace arguments are recorded');
				foreach ([(string)$e, $e->getTraceAsString(), self::appFrames($e)] as $dump) {
					$this->assertStringNotContainsString('BEGIN PRIVATE KEY', $dump);
					$this->assertStringNotContainsString('SESSION-SECRET', $dump);
					$this->assertStringNotContainsString('STATE-SECRET', $dump);
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', (string)$previous);
		}
	}

	/**
	 * PHP 8.1 ignores #[\SensitiveParameter]; the keys object alone has to
	 * keep the PEM out of debug output and Nextcloud's log serializer, which
	 * reads public properties only.
	 */
	public function testKeysObjectHidesThePrivateKey(): void {
		$keys = $this->keys();

		$this->assertStringNotContainsString('BEGIN PRIVATE KEY', print_r($keys, true));
		$this->assertStringNotContainsString('BEGIN PRIVATE KEY', var_export($keys->__debugInfo(), true));
		$this->assertSame([], get_object_vars($keys));
		$this->assertSame('app-1', $keys->applicationId());
		$this->assertSame($this->privateKeyPem, $keys->privateKey());
	}

	public function testRequestTransportErrorDoesNotLeakUrl(): void {
		// Guzzle transport errors put the full request URI (with session IDs,
		// account UIDs and query strings) into their message.
		$this->client->method('get')->willThrowException(
			new \RuntimeException('cURL error 28 for https://api.enablebanking.com/sessions/SESSION-SECRET')
		);

		try {
			$this->api->request('GET', '/sessions/SESSION-SECRET', $this->keys());
			$this->fail('Expected a RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertSame('Enable Banking request failed (network error)', $e->getMessage());
			$this->assertStringNotContainsString('SESSION-SECRET', $e->getMessage());
			$this->assertNull($e->getPrevious());
		}
	}
}
