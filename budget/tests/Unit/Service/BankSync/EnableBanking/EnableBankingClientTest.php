<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync\EnableBanking;

use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingAuthException;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingClient;
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
		$jwt = $this->api->buildJwt('app-1', $this->privateKeyPem, 1_800_000_000);
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
		$jwt = $this->api->buildJwt('app-1', $this->privateKeyPem, 1_800_000_000);
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

		$result = $this->api->request('POST', '/auth', 'app-1', $this->privateKeyPem, $payload);

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
			'app-1',
			$this->privateKeyPem,
			null,
			['date_from' => '2026-01-01']
		);

		$this->assertSame(['transactions' => []], $result);
	}

	public function testRequestMaps429ToRateLimitException(): void {
		$this->client->method('get')->willReturn($this->response(429, '{}'));

		$this->expectException(EnableBankingRateLimitException::class);
		$this->api->request('GET', '/aspsps', 'app-1', $this->privateKeyPem);
	}

	/**
	 * @dataProvider authStatusProvider
	 */
	public function testRequestMaps401And403ToAuthException(int $status): void {
		$this->client->method('get')->willReturn($this->response($status, '{}'));

		$this->expectException(EnableBankingAuthException::class);
		$this->api->request('GET', '/aspsps', 'app-1', $this->privateKeyPem);
	}

	public static function authStatusProvider(): array {
		return [[401], [403]];
	}

	public function testRequestErrorMessageDoesNotLeakBody(): void {
		$this->client->method('get')->willReturn($this->response(500, '{"secret":"S3CRET"}'));

		try {
			$this->api->request('GET', '/aspsps', 'app-1', $this->privateKeyPem);
			$this->fail('Expected exception');
		} catch (\RuntimeException $e) {
			$this->assertStringNotContainsString('S3CRET', $e->getMessage());
			$this->assertSame('Enable Banking request failed (HTTP 500)', $e->getMessage());
		}
	}
}
