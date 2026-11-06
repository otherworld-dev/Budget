<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\BankSync\BankConnectionRevoker;
use OCA\Budget\Service\BankSync\BankSyncProviderInterface;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\TransactionService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a bank provider sends is checked before it is stored or handed to
 * the browser: an account's currency and balance, which the mappings list
 * shows, and the bank authorization link, which the browser opens.
 */
class BankSyncProviderDataTest extends TestCase {
	private const USER_ID = 'user1';
	private const HOSTILE = '<img src=x onerror=alert(1)>';

	private BankSyncService $service;
	private BankConnectionMapper $connectionMapper;
	private BankAccountMappingMapper $mappingMapper;
	private ProviderFactory $providerFactory;
	private AdminSettingService $adminSettings;
	private AccountMapper $accountMapper;
	private LoggerInterface $logger;
	private BankSyncProviderInterface $provider;

	protected function setUp(): void {
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->mappingMapper = $this->createMock(BankAccountMappingMapper::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = $this->createMock(BankSyncProviderInterface::class);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));
		$ruleApplicator = $this->createMock(\OCA\Budget\Service\Import\ImportRuleApplicator::class);
		$ruleApplicator->method('applyRules')->willReturnArgument(1);

		$this->service = new BankSyncService(
			$this->connectionMapper,
			$this->mappingMapper,
			$this->providerFactory,
			$this->createMock(TransactionService::class),
			$this->createMock(AuditService::class),
			$this->adminSettings,
			$this->accountMapper,
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$ruleApplicator,
			$this->createMock(\OCA\Budget\Service\TransactionTagService::class),
			$this->createMock(\OCA\Budget\Service\BillService::class),
			$l,
			$this->logger,
		);
	}

	// ===== currency and balance =====

	public static function currencies(): array {
		return [
			'code' => ['GBP', 'GBP'],
			'lower case' => ['eur', 'EUR'],
			'padded' => [' USD ', 'USD'],
			'markup' => [self::HOSTILE, null],
			'too long' => ['GBPX', null],
			'symbol' => ["\u{00A3}", null],
			'empty' => ['', null],
			'missing' => [null, null],
			'number' => [826, null],
			'array' => [['GBP'], null],
		];
	}

	#[DataProvider('currencies')]
	public function testCurrencyIsKeptOnlyWhenItIsACode(mixed $given, ?string $stored): void {
		$this->assertSame($stored, BankSyncService::cleanCurrency($given));
	}

	public static function balances(): array {
		return [
			'decimal' => ['1234.56', '1234.56'],
			'negative' => ['-12.30', '-12.30'],
			'padded' => [' 5 ', '5'],
			'int' => [100, '100'],
			'float' => [12.5, '12.5'],
			'markup' => [self::HOSTILE, null],
			'number then markup' => ['12<script>', null],
			'empty' => ['', null],
			'missing' => [null, null],
			'infinite' => [INF, null],
			'not a number' => [NAN, null],
			'bool' => [true, null],
		];
	}

	#[DataProvider('balances')]
	public function testBalanceIsKeptOnlyWhenItIsANumber(mixed $given, ?string $stored): void {
		$this->assertSame($stored, BankSyncService::cleanBalance($given));
	}

	public function testConnectStoresNoMarkupFromTheProvider(): void {
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => 'creds',
			'accounts' => [
				['id' => 'ext-1', 'name' => 'Checking', 'balance' => self::HOSTILE, 'currency' => self::HOSTILE],
				['id' => 'ext-2', 'name' => 'Savings', 'balance' => '50.00', 'currency' => 'GBP'],
			],
		]);
		$this->connectionMapper->method('insert')->willReturnCallback(function (BankConnection $c) {
			$c->setId(1);
			return $c;
		});
		$stored = [];
		$this->mappingMapper->method('insert')->willReturnCallback(function (BankAccountMapping $m) use (&$stored) {
			$stored[$m->getExternalAccountId()] = [$m->getLastCurrency(), $m->getLastBalance()];
			return $m;
		});
		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$this->service->connect(self::USER_ID, 'simplefin', ['setupToken' => 't'], 'Bank');

		$this->assertSame([null, null], $stored['ext-1']);
		$this->assertSame(['GBP', '50.00'], $stored['ext-2']);
	}

	public function testRefreshStoresNoMarkupFromTheProvider(): void {
		$this->connectionMapper->method('find')->willReturn($this->connection('simplefin', 'active'));
		$this->provider->method('fetchAccountList')->willReturn([
			'accounts' => [
				['id' => 'ext-new', 'name' => 'New', 'balance' => self::HOSTILE, 'currency' => self::HOSTILE],
				['id' => 'ext-old', 'name' => 'Old', 'balance' => self::HOSTILE, 'currency' => self::HOSTILE],
			],
		]);
		$existing = $this->mapping('ext-old');
		$existing->setLastBalance('10.00');
		$existing->setLastCurrency('GBP');
		$this->mappingMapper->method('findByExternalId')
			->willReturnCallback(fn (int $c, string $ext) => $ext === 'ext-old' ? $existing : null);

		$inserted = null;
		$this->mappingMapper->method('insert')->willReturnCallback(function (BankAccountMapping $m) use (&$inserted) {
			$inserted = $m;
			return $m;
		});
		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$this->service->refreshAccounts(self::USER_ID, 1);

		$this->assertNull($inserted->getLastCurrency());
		$this->assertNull($inserted->getLastBalance());
		// A hostile value replaces the old one rather than leaving it stale
		$this->assertNull($existing->getLastCurrency());
		$this->assertNull($existing->getLastBalance());
	}

	public function testSyncStoresNoMarkupFromTheProvider(): void {
		$this->connectionMapper->method('find')->willReturn($this->connection('simplefin', 'active'));
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				['id' => 'ext-1', 'name' => 'Checking', 'balance' => self::HOSTILE, 'currency' => self::HOSTILE, 'transactions' => []],
			],
		]);
		$mapping = $this->mapping('ext-1', 100);
		$this->mappingMapper->method('findByConnection')->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->willReturn([$mapping]);
		$account = new Account();
		$account->setId(100);
		$this->accountMapper->method('find')->willReturn($account);

		$updated = null;
		$this->mappingMapper->method('update')->willReturnCallback(function (BankAccountMapping $m) use (&$updated) {
			$updated = $m;
			return $m;
		});

		$this->service->sync(self::USER_ID, 1);

		$this->assertSame($mapping, $updated);
		$this->assertNull($updated->getLastCurrency());
		$this->assertNull($updated->getLastBalance());
	}

	// ===== authorization link =====

	public static function unsafeLinks(): array {
		return [
			'javascript' => ['javascript:alert(document.cookie)'],
			'data' => ['data:text/html,<script>alert(1)</script>'],
			'http' => ['http://ob.gocardless.com/psd2/start/abc'],
			'no scheme' => ['//ob.gocardless.com/psd2/start/abc'],
			'relative' => ['/psd2/start/abc'],
			'no host' => ['https:///psd2'],
			'not a string' => [['https://ob.gocardless.com']],
		];
	}

	#[DataProvider('unsafeLinks')]
	public function testConnectRefusesAnAuthorizationLinkThatIsNotHttps(mixed $link): void {
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => 'creds',
			'accounts' => [],
			'authorizationUrl' => $link,
		]);
		// Refused before anything is stored
		$this->connectionMapper->expects($this->never())->method('insert');

		$this->expectException(\Exception::class);
		$this->service->connect(self::USER_ID, 'gocardless', ['secretId' => 'a', 'secretKey' => 'b', 'institutionId' => 'X'], 'Bank');
	}

	#[DataProvider('unsafeLinks')]
	public function testReauthorizeRefusesAnAuthorizationLinkThatIsNotHttps(mixed $link): void {
		$connection = $this->connection('gocardless', 'expired');
		$connection->setCredentials(json_encode(['secretId' => 'sid', 'secretKey' => 'skey', 'requisitionId' => 'old']));
		$this->connectionMapper->method('find')->willReturn($connection);
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => json_encode(['secretId' => 'sid', 'secretKey' => 'skey', 'requisitionId' => 'new']),
			'accounts' => [],
			'authorizationUrl' => $link,
		]);
		$this->connectionMapper->expects($this->never())->method('update');

		$this->expectException(\Exception::class);
		$this->service->reauthorize(self::USER_ID, 1, 'BANK', '');
	}

	public function testConnectPassesAnHttpsAuthorizationLinkOn(): void {
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => 'creds',
			'accounts' => [],
			'authorizationUrl' => 'https://ob.gocardless.com/psd2/start/abc/BANK',
		]);
		$this->connectionMapper->method('insert')->willReturnCallback(function (BankConnection $c) {
			$c->setId(1);
			return $c;
		});
		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$result = $this->service->connect(self::USER_ID, 'gocardless', ['secretId' => 'a', 'secretKey' => 'b', 'institutionId' => 'X'], 'Bank');

		$this->assertSame('https://ob.gocardless.com/psd2/start/abc/BANK', $result['authorizationUrl']);
		$this->assertSame('pending_auth', $result['connection']->getStatus());
	}

	// ===== revoking =====

	public function testDisconnectRevokesThroughTheRevoker(): void {
		$connection = $this->connection('gocardless', 'active');
		$this->connectionMapper->method('find')->willReturn($connection);
		$this->provider->expects($this->once())->method('revokeConnection')->with('creds-1');

		$this->service->disconnect(self::USER_ID, 1);
	}

	public function testRevokerLogsAFailureWithoutTheCredentials(): void {
		$this->provider->method('revokeConnection')->willReturnCallback(
			static fn (string $credentials) => throw new \RuntimeException('provider down')
		);
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$logged) {
			$logged[] = [$message, $context];
		});
		$revoker = new BankConnectionRevoker($this->connectionMapper, $this->providerFactory, $this->logger);

		$revoker->revoke('gocardless', '{"secretKey":"TOP-SECRET-KEY"}', 7);

		$this->assertCount(1, $logged);
		$this->assertArrayNotHasKey('exception', $logged[0][1]);
		$this->assertStringNotContainsString('TOP-SECRET-KEY', print_r($logged, true));
		$this->assertSame('provider down', $logged[0][1]['message']);
		$this->assertSame(7, $logged[0][1]['connection']);
	}

	public function testRevokerSkipsConnectionsWhoseSecretCouldNotBeRead(): void {
		$readable = $this->connection('gocardless', 'active');
		$unreadable = $this->connection('gocardless', 'active');
		$unreadable->setId(2);
		$unreadable->setCredentials(null);
		$this->connectionMapper->method('findAll')->with(self::USER_ID)->willReturn([$readable, $unreadable]);
		$revoker = new BankConnectionRevoker($this->connectionMapper, $this->providerFactory, $this->logger);

		$this->assertSame(
			[['id' => 1, 'provider' => 'gocardless', 'credentials' => 'creds-1']],
			$revoker->collect(self::USER_ID)
		);
	}

	public function testRevokerReturnsNothingWhenConnectionsCanNotBeRead(): void {
		$this->connectionMapper->method('findAll')->willThrowException(new \Exception('no such table'));
		$revoker = new BankConnectionRevoker($this->connectionMapper, $this->providerFactory, $this->logger);

		$this->assertSame([], $revoker->collect(self::USER_ID));
	}

	// ===== helpers =====

	private function connection(string $provider, string $status): BankConnection {
		$c = new BankConnection();
		$c->setId(1);
		$c->setUserId(self::USER_ID);
		$c->setProvider($provider);
		$c->setName('Bank');
		$c->setCredentials('creds-1');
		$c->setStatus($status);
		return $c;
	}

	private function mapping(string $externalId, ?int $budgetAccountId = null): BankAccountMapping {
		$m = new BankAccountMapping();
		$m->setId(10);
		$m->setConnectionId(1);
		$m->setExternalAccountId($externalId);
		$m->setExternalAccountName('Account');
		$m->setBudgetAccountId($budgetAccountId);
		$m->setEnabled($budgetAccountId !== null);
		return $m;
	}
}
