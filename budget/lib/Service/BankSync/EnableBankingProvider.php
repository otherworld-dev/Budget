<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;

class EnableBankingProvider implements IBankSyncProvider {
	private const BASE_URL = 'https://api.enablebanking.com';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'enablebanking';
	}

	public function getName(): string {
		return 'EnableBanking (Europe)';
	}

	public function initializeConnection(array $params): array {
		$appId = $params['appId'] ?? null;
		$privateKey = $params['privateKey'] ?? null;
		$institutionId = $params['institutionId'] ?? null;
		$redirectUrl = $params['redirectUrl'] ?? "http://localhost:8080/index.php/apps/budget/settings/enablebanking/callback";
		$redirectUrl = str_replace("http://localhost", "https://localhost", $redirectUrl);

		if (!$appId || !$privateKey) {
			throw new \InvalidArgumentException('Application ID and Private Key are required');
		}

		$credentials = json_encode([
			'appId' => $appId,
			'privateKey' => $privateKey,
		]);

		$institutionId = $institutionId ?? "Mock ASPSP";
		$authData = $this->createAuthLink($appId, $privateKey, $institutionId, $redirectUrl ?? '');
		$credentials = json_encode(array_merge(
			json_decode($credentials, true),
			['authData' => $authData]
		));

		return [
			'credentials' => $credentials,
			'accounts' => [],
			'authorizationUrl' => $authData['url'],
			'state' => $authData['state'],
		];
	}

	public function createSession(string $code, string $credentials): string {
		$creds = json_decode($credentials, true);
		$appId = $creds['appId'] ?? '';
		$privateKey = $creds['privateKey'] ?? '';

		$b64url = function($data) { return str_replace(['+','/','='], ['-','_',''], base64_encode($data)); };
		$header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $appId]));
		$payload = $b64url(json_encode(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => time(), 'exp' => time() + 3600]));
		openssl_sign("$header.$payload", $signature, $privateKey, OPENSSL_ALGO_SHA256);
		$jwt = "$header.$payload." . $b64url($signature);

		$client = $this->clientService->newClient();
		$res = $client->post(self::BASE_URL . '/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type' => 'application/json'
			],
			'body' => json_encode(['code' => $code])
		]);
		
		if ($res->getStatusCode() !== 200) {
			throw new \Exception("Failed to create session: " . $res->getBody());
		}

		$data = json_decode($res->getBody(), true);
		if (!isset($data['session_id'])) {
			throw new \Exception("Invalid session response");
		}

		$creds['sessionId'] = $data['session_id'];
		return json_encode($creds);
	}

	public function fetchAccounts(string $credentials, array $options = []): array {
		$creds = json_decode($credentials, true);
		$appId = $creds['appId'] ?? '';
		$privateKey = $creds['privateKey'] ?? '';
		$sessionId = $creds['sessionId'] ?? '';
		
		if (!$sessionId) return ['accounts' => []];

		$b64url = function($data) { return str_replace(['+','/','='], ['-','_',''], base64_encode($data)); };
		$header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $appId]));
		$payload = $b64url(json_encode(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => time(), 'exp' => time() + 3600]));
		openssl_sign("$header.$payload", $signature, $privateKey, OPENSSL_ALGO_SHA256);
		$jwt = "$header.$payload." . $b64url($signature);
		
		$client = $this->clientService->newClient();
		$res = $client->get(self::BASE_URL . '/sessions/' . $sessionId, [
			'headers' => ['Authorization' => 'Bearer ' . $jwt]
		]);
		
		$data = json_decode($res->getBody(), true);
		if (!isset($data['accounts'])) return ['accounts' => []];
		
		$accounts = [];
		$accountList = $data['accounts_data'] ?? $data['accounts'];
		foreach ($accountList as $acc) {
			$uid = is_array($acc) ? ($acc['uid'] ?? '') : $acc;
			if (!$uid) continue;
			
			// Get balance
			$resBal = $client->get(self::BASE_URL . '/accounts/' . $uid . '/balances', [
				'headers' => ['Authorization' => 'Bearer ' . $jwt]
			]);
			$balData = json_decode($resBal->getBody(), true);
			
			$balance = 0;
			$currency = 'EUR';
			if (isset($balData['balances']) && is_array($balData['balances']) && count($balData['balances']) > 0) {
				$b0 = $balData['balances'][0];
				if (is_array($b0) && isset($b0['balance_amount']) && is_array($b0['balance_amount'])) {
					$balance = (float) ($b0['balance_amount']['amount'] ?? 0);
					$currency = $b0['balance_amount']['currency'] ?? 'EUR';
				}
			}
			
			// Get transactions
			$resTx = $client->get(self::BASE_URL . '/accounts/' . $uid . '/transactions', [
				'headers' => ['Authorization' => 'Bearer ' . $jwt]
			]);
			$txData = json_decode($resTx->getBody(), true);
			
			$transactions = [];
			if (isset($txData['transactions']) && is_array($txData['transactions'])) {
				foreach ($txData['transactions'] as $tx) {
					$amount = (float) ($tx['transaction_amount']['amount'] ?? 0);
					if (($tx['credit_debit_indicator'] ?? '') === 'DBIT' && $amount > 0) {
						$amount = -$amount;
					}
					
					$desc = $tx['remittance_information_unstructured'] ?? $tx['additional_information'] ?? '';
					if (!$desc && !empty($tx['remittance_information_unstructured_array'])) $desc = implode(', ', $tx['remittance_information_unstructured_array']);
					if (!$desc) $desc = $tx['creditor_name'] ?? $tx['debtor_name'] ?? 'Bank Transaction';
					
					$transactions[] = [
						'id' => $tx['transaction_id'] ?? $tx['entry_reference'] ?? md5(json_encode($tx)),
						'date' => $tx['booking_date'] ?? $tx['value_date'] ?? date('Y-m-d'),
						'amount' => $amount,
						'description' => $desc,
						'vendor' => $tx['creditor_name'] ?? $tx['debtor_name'] ?? null,
						'pending' => ($tx['status'] ?? '') === 'PDNG'
					];
				}
			}
			
			$accounts[] = [
				'id' => $uid,
				'name' => (is_array($acc) && isset($acc['account_id'])) ? $acc['account_id'] : $uid,
				'balance' => $balance,
				'currency' => $currency,
				'transactions' => $transactions
			];
		}
		
		return ['accounts' => $accounts];
	}

	public function fetchAccountList(string $credentials, array $options = []): array {
		return $this->fetchAccounts($credentials, $options);
	}

	public function requiresReauthorization(string $credentials): bool {
		return false;
	}

	public function revokeConnection(string $credentials): void {
	}

	private function createAuthLink(string $appId, string $privateKey, string $aspsp, string $redirectUrl): array {
		$b64url = function($data) { return str_replace(['+','/','='], ['-','_',''], base64_encode($data)); };
		$header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $appId]));
		$payload = $b64url(json_encode(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => time(), 'exp' => time() + 3600]));
		$success = openssl_sign("$header.$payload", $signature, $privateKey, OPENSSL_ALGO_SHA256);
		if (!$success) throw new \Exception('Invalid Private Key.');
		$jwt = "$header.$payload." . $b64url($signature);
		$state = bin2hex(random_bytes(16));
		
		$client = $this->clientService->newClient();
		$res = $client->post(self::BASE_URL . '/auth', [
			'headers' => [
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type' => 'application/json'
			],
			'body' => json_encode([
				'access' => ['valid_until' => date('c', time() + 90 * 86400)],
				'aspsp' => ['name' => $aspsp, 'country' => 'FI'],
				'redirect_url' => $redirectUrl,
				'state' => $state,
				'psu_type' => 'personal'
			])
		]);
		
		if ($res->getStatusCode() !== 200) {
			throw new \Exception("API AUTH FAILED: HTTP " . $res->getStatusCode() . " - " . $res->getBody());
		}
		
		$data = json_decode($res->getBody(), true);
		if (!isset($data['url'])) {
			throw new \Exception('Failed to generate EnableBanking auth link');
		}
		
		return [
			'url' => $data['url'],
			'state' => $state,
			'psu_type' => 'personal'
		];
	}
}