<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * AccountMapper::update() writes ~25 columns by hand (working around Entity
 * dirty-tracking), so a column missing from its ->set() list persists on
 * insert and silently never on update (#353). This writes every mapped
 * property, changes every one, updates, and reads the row back.
 *
 * FIELDS must name every Account property. A new column fails
 * testEveryAccountPropertyIsCovered until it is added here - and then the
 * round trip below proves update() writes it too.
 */
class AccountMapperUpdateTest extends IntegrationTestCase {
	/** Set once, never rewritten by update() */
	private const IMMUTABLE = ['id', 'userId', 'createdAt'];

	/** property => [value on insert, value after update] */
	private const FIELDS = [
		'name' => ['Everyday', 'Bills account'],
		'type' => ['checking', 'credit_card'],
		'balance' => [100.25, -42.5],
		'openingBalance' => [10.0, 55.75],
		'currency' => ['GBP', 'EUR'],
		'institution' => ['First Bank', 'Second Bank'],
		'accountNumber' => ['11112222', '33334444'],
		'routingNumber' => ['021000021', '011000015'],
		'sortCode' => ['11-22-33', '44-55-66'],
		'iban' => ['GB33BUKB20201555555555', 'DE89370400440532013000'],
		'swiftBic' => ['BUKBGB22', 'COBADEFFXXX'],
		'walletAddress' => ['bc1qfirstaddress', 'bc1qsecondaddress'],
		'accountHolderName' => ['A. Person', 'B. Person'],
		'openingDate' => ['2025-01-01', '2025-06-15'],
		'interestRate' => [1.5, 19.9],
		'creditLimit' => [500.0, 2500.0],
		'overdraftLimit' => [100.0, 250.0],
		'minimumPayment' => [5.0, 25.0],
		'statementDay' => [5, 21],
		'interestEnabled' => [false, true],
		'compoundingFrequency' => ['monthly', 'daily'],
		'accruedInterest' => [0.0, 3.21],
		'updatedAt' => ['2026-01-01 10:00:00', '2026-02-02 11:11:11'],
		'lastReconciled' => ['2026-01-31', '2026-02-28'],
		'excludedFromReports' => [false, true],
		'liabilityInCredit' => [null, true],
		'closed' => [false, true],
	];

	public function testEveryAccountPropertyIsCovered(): void {
		$properties = [];
		foreach ((new \ReflectionClass(Account::class))->getProperties() as $property) {
			if (!str_starts_with($property->getName(), '_')) {
				$properties[] = $property->getName();
			}
		}

		$this->assertEqualsCanonicalizing(
			$properties,
			array_merge(self::IMMUTABLE, array_keys(self::FIELDS)),
			'A new Account property needs an entry in FIELDS (and in AccountMapper::update()\'s set-list)'
		);
	}

	/**
	 * Asserts one column at a time so a failure names the column that
	 * update() drops. statement_day is missing from the set-list at the time
	 * of writing (#347); a separate change fixes it. Drop the group then.
	 */
	#[Group('known-bug')]
	public function testUpdatePersistsEveryColumn(): void {
		$mapper = $this->service(AccountMapper::class);
		$account = $this->makeAccount($this->column(0));

		foreach ($this->column(1) as $property => $value) {
			$account->{'set' . ucfirst($property)}($value);
		}
		$mapper->update($account);
		$reloaded = $mapper->find($account->getId(), $this->userId);

		$lost = [];
		foreach ($this->column(1) as $property => $expected) {
			$actual = $reloaded->{'get' . ucfirst($property)}();
			if (!$this->sameValue($expected, $actual)) {
				$lost[$property] = ['expected' => $expected, 'stored' => $actual];
			}
		}
		$this->assertSame([], $lost, 'Columns AccountMapper::update() did not persist');
	}

	public function testInsertPersistsEveryColumn(): void {
		$mapper = $this->service(AccountMapper::class);
		$account = $this->makeAccount($this->column(1));

		$reloaded = $mapper->find($account->getId(), $this->userId);

		$lost = [];
		foreach ($this->column(1) as $property => $expected) {
			$actual = $reloaded->{'get' . ucfirst($property)}();
			if (!$this->sameValue($expected, $actual)) {
				$lost[$property] = ['expected' => $expected, 'stored' => $actual];
			}
		}
		$this->assertSame([], $lost, 'Columns the insert did not persist');
	}

	public function testSensitiveColumnsAreEncryptedAtRest(): void {
		$account = $this->makeAccount(['iban' => 'GB33BUKB20201555555555', 'accountNumber' => '11112222']);

		$row = $this->fetchRow('budget_accounts', $account->getId());

		$this->assertNotSame('GB33BUKB20201555555555', $row['iban']);
		$this->assertNotSame('11112222', $row['account_number']);
		$this->assertStringNotContainsString('20201555555555', (string)$row['iban']);
	}

	public function testUpdateCanClearANullableColumn(): void {
		$mapper = $this->service(AccountMapper::class);
		$account = $this->makeAccount(['institution' => 'First Bank', 'iban' => 'GB33BUKB20201555555555', 'liabilityInCredit' => true]);

		$account->setInstitution(null);
		$account->setIban(null);
		$account->setLiabilityInCredit(null);
		$mapper->update($account);
		$reloaded = $mapper->find($account->getId(), $this->userId);

		$this->assertNull($reloaded->getInstitution());
		$this->assertNull($reloaded->getIban());
		$this->assertNull($reloaded->getLiabilityInCredit());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function column(int $index): array {
		return array_map(static fn (array $pair) => $pair[$index], self::FIELDS);
	}

	private function sameValue(mixed $expected, mixed $actual): bool {
		if (is_float($expected)) {
			return is_numeric($actual) && abs($expected - (float)$actual) < 0.00001;
		}
		if (is_bool($expected)) {
			return $actual !== null && (bool)$actual === $expected;
		}
		if (is_int($expected)) {
			return $actual !== null && (int)$actual === $expected;
		}
		if ($expected === null) {
			return $actual === null;
		}
		// Date columns may come back with a time part on some databases
		return (string)$actual === $expected || str_starts_with((string)$actual, $expected . ' ');
	}
}
