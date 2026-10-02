<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recurring income paid into an account in another currency. It had no
 * currency at all: Monthly Total added euros and dollars to pounds as they
 * were, and every row was shown with the default currency's symbol.
 */
class RecurringIncomeCurrencyTest extends TestCase {
	private RecurringIncomeMapper $mapper;
	private AccountMapper $accounts;
	private RecurringIncomeService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RecurringIncomeMapper::class);
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->accounts->method('findByIds')->willReturnCallback(fn (array $ids) => array_values(array_filter([
			$this->account(1, 'GBP'),
			$this->account(2, 'EUR'),
			$this->account(3, 'USD'),
		], fn (Account $a) => in_array($a->getId(), $ids, true))));
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturnMap([['alice', 'GBP'], ['bob', 'EUR']]);
		// 1 EUR = 0.5 GBP, 1 USD = 0.25 GBP
		$currency->method('convertToBaseFloat')->willReturnCallback(
			fn (float $amount, string $from) => match ($from) {
				'EUR' => $amount / 2,
				'USD' => $amount / 4,
				default => $amount,
			}
		);

		$this->service = new RecurringIncomeService(
			$this->mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringIncomeDetector::class),
			$this->createMock(TransactionService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			null,
			null,
			null,
			$this->accounts,
			$currency,
		);
	}

	private function account(int $id, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setCurrency($currency);
		return $account;
	}

	private function income(int $id, float $amount, ?int $accountId, string $userId = 'alice'): RecurringIncome {
		$income = new RecurringIncome();
		$income->setId($id);
		$income->setUserId($userId);
		$income->setName('Income ' . $id);
		$income->setAmount($amount);
		$income->setFrequency('monthly');
		$income->setIsActive(true);
		$income->setAccountId($accountId);
		$income->setNextExpectedDate('2099-01-25');
		return $income;
	}

	public function testTheSummaryConvertsEachIncomeToTheBaseCurrency(): void {
		$this->mapper->method('findActive')->willReturn([
			$this->income(1, 2000.0, 1),
			$this->income(2, 1000.0, 2),
			$this->income(3, 400.0, 3),
			$this->income(4, 100.0, null),
		]);
		$this->mapper->method('findAll')->willReturn([]);

		$summary = $this->service->getMonthlySummary('alice');

		// 2000 + 500 + 100 + 100
		$this->assertEqualsWithDelta(2700.0, $summary['monthlyTotal'], 0.001);
		$this->assertEqualsWithDelta(2700.0, $summary['totalMonthly'], 0.001);
		$this->assertEqualsWithDelta(32400.0, $summary['totalYearly'], 0.001);
		$this->assertEqualsWithDelta(2700.0, $summary['byFrequency']['monthly']['totalMonthly'], 0.001);
		$this->assertSame('GBP', $summary['baseCurrency']);
	}

	/**
	 * The dashboard's Income Tracking tile offers an account; its headline
	 * must then be that account's income only.
	 */
	public function testTheSummaryCanBeHeldToOneAccount(): void {
		$received = $this->income(5, 50.0, 2);
		$received->setIsActive(false);
		$received->setLastReceivedDate(date('Y-m-d'));
		$this->mapper->method('findActive')->willReturn([
			$this->income(1, 2000.0, 1),
			$this->income(2, 1000.0, 2),
		]);
		$this->mapper->method('findAll')->willReturn([$received, $this->income(1, 2000.0, 1)]);

		$summary = $this->service->getMonthlySummary('alice', 2);

		$this->assertEqualsWithDelta(500.0, $summary['monthlyTotal'], 0.001);
		$this->assertSame(1, $summary['activeCount']);
		$this->assertSame(1, $summary['receivedThisMonth']);
	}

	public function testEachIncomeCarriesItsAccountsCurrency(): void {
		$incomes = $this->service->enrichWithCurrency([
			$this->income(1, 10.0, 2),
			$this->income(2, 10.0, null),
		], 'alice');

		$this->assertSame('EUR', $incomes[0]->jsonSerialize()['currency']);
		$this->assertSame('GBP', $incomes[1]->jsonSerialize()['currency']);
	}

	public function testSharedIncomeIsPricedAsItsOwnerSeesIt(): void {
		$rows = $this->service->enrichSharedWithCurrency([
			['id' => 1, 'userId' => 'bob', 'accountId' => 3],
			['id' => 2, 'userId' => 'bob', 'accountId' => null],
		]);

		$this->assertSame(['USD', 'EUR'], array_column($rows, 'currency'));
	}
}
