<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Enable Banking API.
 *
 * Every request is authenticated with a short-lived RS256 JWT signed with the
 * application's private key. The JWT is built with ext-openssl directly to avoid
 * pulling in a JWT library for a single fixed token shape.
 *
 * Error messages deliberately never include response bodies or headers: they can
 * contain account data and must not end up in logs or user-facing errors.
 *
 * Key material travels as EnableBankingKeys, and every argument that can carry
 * a secret (paths with session IDs, bodies with a code or a state) is marked
 * #[\SensitiveParameter], so exception traces logged with their arguments
 * show neither.
 */
class EnableBankingClient {
	public const BASE_URL = 'https://api.enablebanking.com';

	private const JWT_LIFETIME = 3600;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function buildJwt(#[\SensitiveParameter] EnableBankingKeys $keys, int $now): string {
		$header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $keys->applicationId()];
		$claims = [
			'iss' => 'enablebanking.com',
			'aud' => 'api.enablebanking.com',
			'iat' => $now,
			'exp' => $now + self::JWT_LIFETIME,
		];

		$signingInput = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
			. '.' . self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

		$key = openssl_pkey_get_private($keys->privateKey());
		if ($key === false || !openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
			throw new \RuntimeException('Unable to sign Enable Banking JWT');
		}

		return $signingInput . '.' . self::base64UrlEncode($signature);
	}

	/**
	 * Whether the PEM parses as a private key (a public key or garbage does not).
	 */
	public static function isValidPrivateKey(#[\SensitiveParameter] string $privateKeyPem): bool {
		return @openssl_pkey_get_private($privateKeyPem) !== false;
	}

	/**
	 * @return array Decoded JSON response body
	 * @throws EnableBankingRateLimitException on HTTP 429
	 * @throws EnableBankingAuthException on HTTP 401/403
	 * @throws \RuntimeException on any other HTTP error or undecodable body
	 */
	public function request(
		string $method,
		#[\SensitiveParameter]
		string $path,
		#[\SensitiveParameter]
		EnableBankingKeys $keys,
		#[\SensitiveParameter]
		?array $json = null,
		#[\SensitiveParameter]
		array $query = [],
	): array {
		$jwt = $this->buildJwt($keys, time());

		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $jwt,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
			'http_errors' => false,
		];
		if ($json !== null) {
			$options['json'] = $json;
		}
		if ($query !== []) {
			$options['query'] = $query;
		}

		$client = $this->clientService->newClient();
		$url = self::BASE_URL . $path;

		$method = strtoupper($method);
		if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
			throw new \InvalidArgumentException('Unsupported HTTP method');
		}

		try {
			$response = match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'DELETE' => $client->delete($url, $options),
			};
		} catch (\Throwable) {
			// Transport errors (timeouts, DNS, TLS) carry the full request URI,
			// including session IDs and account UIDs, in their message. Neither
			// the message nor the exception itself is passed on.
			throw new \RuntimeException('Enable Banking request failed (network error)');
		}

		$status = $response->getStatusCode();
		if ($status === 429) {
			throw new EnableBankingRateLimitException('Enable Banking rate limit reached (HTTP 429)');
		}
		if ($status === 401 || $status === 403) {
			throw new EnableBankingAuthException(
				'Enable Banking rejected the credentials (HTTP ' . $status . ')',
				$this->authFailureReason((string)$response->getBody())
			);
		}
		if ($status >= 400) {
			throw new \RuntimeException('Enable Banking request failed (HTTP ' . $status . ')');
		}

		$body = (string)$response->getBody();
		if ($body === '') {
			return [];
		}

		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('Enable Banking returned an invalid response');
		}

		return is_array($decoded) ? $decoded : [];
	}

	private static function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * The error body is read only to recognise a known cause by exact match;
	 * none of its text is kept or passed on.
	 */
	private function authFailureReason(string $body): ?string {
		$data = json_decode($body, true);
		if (is_array($data) && ($data['message'] ?? null) === 'Application is not active') {
			return EnableBankingAuthException::REASON_APPLICATION_INACTIVE;
		}
		return null;
	}
}
