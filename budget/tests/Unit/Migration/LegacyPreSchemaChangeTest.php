<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use OCA\Budget\Migration\Version001000011Date20260117;
use OCA\Budget\Migration\Version001000012Date20260117;
use OCA\Budget\Migration\Version001000015Date20260117;
use OCA\Budget\Migration\Version001000016Date20260118;
use OCA\Budget\Migration\Version001000018Date20260118;
use OCA\Budget\Migration\Version001000028Date20260207;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Six migrations that shipped before v2.1.1 drop broken tables and columns in
 * preSchemaChange(). They reached the database through
 * \OC::$server->getDatabaseConnection() and ->getConfig(), which Nextcloud 35
 * removed, so upgrading an install still on Budget 2.1.0 or older died with
 * "Call to undefined method OC\Server::getDatabaseConnection()".
 *
 * The connection and config are injected now. Each step must still issue
 * exactly the statements it always did, in the same order, under the same
 * guards, with the same errors swallowed: only installs that haven't run them
 * yet will ever execute the new code.
 */
class LegacyPreSchemaChangeTest extends TestCase {
	private const LIB_DIR = __DIR__ . '/../../../lib';

	private mixed $server = null;

	/** @var string[] statements executed, in order (failed ones included) */
	private array $statements = [];

	/** @var string[] lines written to the migration output */
	private array $info = [];

	protected function setUp(): void {
		// Nextcloud 35's container: get()/has() only, none of the old getters
		$this->server = \OC::$server;
		\OC::$server = $this->createMock(ContainerInterface::class);
	}

	protected function tearDown(): void {
		\OC::$server = $this->server;
	}

	/**
	 * @param string[] $failOn statements containing one of these throw, as a
	 *                         database error would
	 */
	private function db(array $failOn = []): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(function (string $sql) use ($failOn) {
			$this->statements[] = $sql;
			foreach ($failOn as $needle) {
				if (str_contains($sql, $needle)) {
					throw new \RuntimeException('simulated failure: ' . $sql);
				}
			}
			return 0;
		});
		return $db;
	}

	private function config(string $prefix): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default = '') => $key === 'dbtableprefix' ? $prefix : $default
		);
		$config->method('getSystemValue')->willReturnCallback(
			fn ($key, $default = '') => $key === 'dbtableprefix' ? $prefix : $default
		);
		return $config;
	}

	/**
	 * @param array<string, string[]> $tables table => its columns
	 */
	private function schemaClosure(array $tables): \Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name) => isset($tables[$name]));
		$schema->method('getTable')->willReturnCallback(function (string $name) use ($tables) {
			$columns = $tables[$name];
			return new class($columns) {
				public function __construct(
					private array $columns,
				) {
				}

				public function hasColumn(string $name): bool {
					return in_array($name, $this->columns, true);
				}
			};
		});
		return static fn () => $schema;
	}

	private function migrationOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) {
			$this->info[] = $message;
		});
		return $output;
	}

	private function runStep(string $class, array $tables, string $prefix = 'oc_', array $failOn = []): void {
		$migration = new $class($this->db($failOn), $this->config($prefix));
		$migration->preSchemaChange($this->migrationOutput(), $this->schemaClosure($tables), []);
	}

	/** Every table and column the six steps look for */
	private const FULL_SCHEMA = [
		'budget_transactions' => ['id', 'is_split'],
		'budget_import_rules' => ['id', 'apply_on_import', 'stop_processing'],
		'budget_bills' => ['id', 'auto_pay_enabled', 'auto_pay_failed', 'is_transfer'],
		'budget_recurring_income' => ['id'],
		'budget_expense_shares' => ['id'],
		'budget_contacts' => ['id'],
		'budget_settlements' => ['id'],
	];

	public static function statementCases(): array {
		$full = self::FULL_SCHEMA;
		$clean = [
			'budget_transactions' => ['id'],
			'budget_import_rules' => ['id'],
			'budget_bills' => ['id'],
		];

		return [
			'011 drops the recurring income table, whatever the schema says' => [
				Version001000011Date20260117::class, [],
				['DROP TABLE IF EXISTS nc_budget_recurring_income'],
			],
			'012 drops a broken is_split' => [
				Version001000012Date20260117::class, $full,
				['ALTER TABLE nc_budget_transactions DROP COLUMN is_split'],
			],
			'012 leaves a transactions table without is_split alone' => [
				Version001000012Date20260117::class, $clean, [],
			],
			'012 does nothing before the transactions table exists' => [
				Version001000012Date20260117::class, [], [],
			],
			'015 drops the three shared-expense tables in order' => [
				Version001000015Date20260117::class, [],
				[
					'DROP TABLE IF EXISTS nc_budget_expense_shares',
					'DROP TABLE IF EXISTS nc_budget_contacts',
					'DROP TABLE IF EXISTS nc_budget_settlements',
				],
			],
			'016 drops a broken apply_on_import' => [
				Version001000016Date20260118::class, $full,
				['ALTER TABLE nc_budget_import_rules DROP COLUMN apply_on_import'],
			],
			'016 leaves rules without apply_on_import alone' => [
				Version001000016Date20260118::class, $clean, [],
			],
			'018 drops four tables and both broken columns' => [
				Version001000018Date20260118::class, $full,
				[
					'DROP TABLE IF EXISTS nc_budget_expense_shares',
					'DROP TABLE IF EXISTS nc_budget_contacts',
					'DROP TABLE IF EXISTS nc_budget_settlements',
					'DROP TABLE IF EXISTS nc_budget_recurring_income',
					'ALTER TABLE nc_budget_transactions DROP COLUMN is_split',
					'ALTER TABLE nc_budget_import_rules DROP COLUMN apply_on_import',
				],
			],
			'018 drops only the tables when the columns are absent' => [
				Version001000018Date20260118::class, $clean,
				[
					'DROP TABLE IF EXISTS nc_budget_expense_shares',
					'DROP TABLE IF EXISTS nc_budget_contacts',
					'DROP TABLE IF EXISTS nc_budget_settlements',
					'DROP TABLE IF EXISTS nc_budget_recurring_income',
				],
			],
			'028 drops the four NOT NULL booleans' => [
				Version001000028Date20260207::class, $full,
				[
					'ALTER TABLE nc_budget_import_rules DROP COLUMN stop_processing',
					'ALTER TABLE nc_budget_bills DROP COLUMN auto_pay_enabled',
					'ALTER TABLE nc_budget_bills DROP COLUMN auto_pay_failed',
					'ALTER TABLE nc_budget_bills DROP COLUMN is_transfer',
				],
			],
			'028 drops only the columns that exist' => [
				Version001000028Date20260207::class,
				['budget_bills' => ['id', 'is_transfer']],
				['ALTER TABLE nc_budget_bills DROP COLUMN is_transfer'],
			],
		];
	}

	/**
	 * @dataProvider statementCases
	 * @param array<string, string[]> $tables
	 * @param string[] $expected
	 */
	public function testIssuesTheSameStatementsWithoutTheRemovedServerGetters(string $class, array $tables, array $expected): void {
		$this->runStep($class, $tables, 'nc_');

		$this->assertSame($expected, $this->statements);
	}

	public function testTheDefaultPrefixIsUsedWhenNoneIsConfigured(): void {
		$migration = new Version001000011Date20260117($this->db(), $this->config('oc_'));
		$migration->preSchemaChange($this->migrationOutput(), $this->schemaClosure([]), []);

		$this->assertSame(['DROP TABLE IF EXISTS oc_budget_recurring_income'], $this->statements);
	}

	public function testA015DropThatFailsDoesNotStopTheOthers(): void {
		$this->runStep(Version001000015Date20260117::class, [], 'oc_', ['budget_contacts']);

		$this->assertSame([
			'DROP TABLE IF EXISTS oc_budget_expense_shares',
			'DROP TABLE IF EXISTS oc_budget_contacts',
			'DROP TABLE IF EXISTS oc_budget_settlements',
		], $this->statements);
	}

	public function testA018FailureIsSwallowedPerTableAndPerColumn(): void {
		$this->runStep(Version001000018Date20260118::class, self::FULL_SCHEMA, 'oc_', ['budget_settlements', 'is_split']);

		$this->assertSame([
			'DROP TABLE IF EXISTS oc_budget_expense_shares',
			'DROP TABLE IF EXISTS oc_budget_contacts',
			'DROP TABLE IF EXISTS oc_budget_settlements',
			'DROP TABLE IF EXISTS oc_budget_recurring_income',
			'ALTER TABLE oc_budget_transactions DROP COLUMN is_split',
			'ALTER TABLE oc_budget_import_rules DROP COLUMN apply_on_import',
		], $this->statements);
	}

	public function testA028FailureSkipsTheRestOfThatTableOnlyAndReportsWhatWasDropped(): void {
		$this->runStep(Version001000028Date20260207::class, self::FULL_SCHEMA, 'oc_', ['auto_pay_enabled']);

		// The try wraps a whole table: the bills columns after the failed one
		// are skipped, the rules column before it was dropped and reported
		$this->assertSame([
			'ALTER TABLE oc_budget_import_rules DROP COLUMN stop_processing',
			'ALTER TABLE oc_budget_bills DROP COLUMN auto_pay_enabled',
		], $this->statements);
		$this->assertSame(['Dropped budget_import_rules.stop_processing'], $this->info);
	}

	public function testA012SchemaErrorIsSwallowed(): void {
		$migration = new Version001000012Date20260117($this->db(), $this->config('oc_'));
		$migration->preSchemaChange($this->migrationOutput(), static function () {
			throw new \RuntimeException('schema unreadable');
		}, []);

		$this->assertSame([], $this->statements);
	}

	/**
	 * \OC::$server is private API and Nextcloud keeps removing its getters:
	 * nothing in the app may call them.
	 */
	public function testNothingInTheAppCallsTheServerContainerDirectly(): void {
		$offenders = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB_DIR));
		foreach ($files as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			foreach (file($file->getPathname()) as $number => $line) {
				if (preg_match('/\\\\OC::\$server\s*->/', $line)) {
					$offenders[] = substr($file->getPathname(), strlen(self::LIB_DIR) + 1) . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $offenders, 'Use \OCP\Server::get() or constructor injection');
	}
}
