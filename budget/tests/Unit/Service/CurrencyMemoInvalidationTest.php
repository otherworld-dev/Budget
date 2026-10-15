<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserTableCleaner;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * CurrencyConversionService memoizes each user's base currency and manual
 * rates (T6-7). Every way the app writes either must drop that memo, or a
 * conversion later in the same request or cron run would use the old value.
 */
class CurrencyMemoInvalidationTest extends TestCase {
	private int $settingReads = 0;

	/** A connection whose query builders accept anything and change nothing */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['insert', 'update', 'delete', 'select', 'from', 'where', 'andWhere', 'setValue', 'set'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeStatement')->willReturn(1);
			$qb->method('getLastInsertId')->willReturn(7);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		$db->method('executeStatement')->willReturn(1);
		return $db;
	}

	private function conversion(): CurrencyConversionService {
		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturnCallback(function () {
			$this->settingReads++;
			return 'GBP';
		});
		return new CurrencyConversionService(
			$this->createMock(ExchangeRateService::class),
			$settings,
			$this->createMock(ManualExchangeRateMapper::class),
		);
	}

	public static function writes(): array {
		$setting = static function (): Setting {
			$s = new Setting();
			$s->setId(3);
			$s->setUserId('user1');
			$s->setKey('default_currency');
			$s->setValue('EUR');
			return $s;
		};
		$rate = static function (): ManualExchangeRate {
			$r = new ManualExchangeRate();
			$r->setId(4);
			$r->setUserId('user1');
			$r->setCurrency('USD');
			$r->setRatePerEur('1.1');
			return $r;
		};
		return [
			'a setting inserted' => [fn (IDBConnection $db) => (new SettingMapper($db))->insert($setting())],
			'a setting updated' => [fn (IDBConnection $db) => (new SettingMapper($db))->update($setting())],
			'a setting deleted' => [fn (IDBConnection $db) => (new SettingMapper($db))->delete($setting())],
			'a setting deleted by key' => [fn (IDBConnection $db) => (new SettingMapper($db))->deleteByKey('user1', 'default_currency')],
			'every setting of a user deleted' => [fn (IDBConnection $db) => (new SettingMapper($db))->deleteAll('user1')],
			'a manual rate inserted' => [fn (IDBConnection $db) => (new ManualExchangeRateMapper($db))->insert($rate())],
			'a manual rate updated' => [fn (IDBConnection $db) => (new ManualExchangeRateMapper($db))->update($rate())],
			'a manual rate deleted' => [fn (IDBConnection $db) => (new ManualExchangeRateMapper($db))->delete($rate())],
			'a reset or restore clearing the tables' => [fn (IDBConnection $db) => (new UserTableCleaner($db))->clearRegisteredTables('user1')],
		];
	}

	/**
	 * @dataProvider writes
	 */
	public function testAWriteDropsTheMemo(\Closure $write): void {
		$conversion = $this->conversion();
		$conversion->getBaseCurrency('user1');
		$conversion->getBaseCurrency('user1');
		$this->assertSame(1, $this->settingReads, 'read once while nothing changes');

		$write($this->db());
		$conversion->getBaseCurrency('user1');

		$this->assertSame(2, $this->settingReads, 'read again after the write');
	}
}
