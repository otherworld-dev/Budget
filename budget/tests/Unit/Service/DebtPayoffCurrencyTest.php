<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\DebtPayoffService;
use PHPUnit\Framework\TestCase;

/**
 * Debt Payoff works in the base currency, as the Accounts page's totals
 * do. A dollar card owing 276.96 was shown as Total Debt £276.96, and a
 * pound loan and a dollar card were added together as one currency.
 */
class DebtPayoffCurrencyTest extends TestCase {
	private DebtPayoffService $service;

	protected function setUp(): void {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([
			$this->debt(1, 'Dollar card', 'USD', -276.96, 20.0),
			$this->debt(2, 'Pound loan', 'GBP', -1000.0, 50.0),
		]);
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateBatch')->willReturn([]);
		// 1 USD = 0.75 GBP
		$conversion = $this->createMock(CurrencyConversionService::class);
		$conversion->method('getBaseCurrency')->willReturn('GBP');
		$conversion->method('convertToBase')->willReturnCallback(
			static fn ($amount, string $currency) => $currency === 'USD' ? (string)((float)$amount * 0.75) : (string)$amount
		);

		$this->service = new DebtPayoffService($accounts, $transactions, null, null, $conversion);
	}

	private function debt(int $id, string $name, string $currency, float $balance, float $minimum): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName($name);
		$account->setType('credit_card');
		$account->setCurrency($currency);
		$account->setBalance($balance);
		$account->setInterestRate(0.0);
		$account->setMinimumPayment($minimum);
		return $account;
	}

	public function testTheSummaryIsInTheBaseCurrency(): void {
		$summary = $this->service->getSummary('alice');

		// 276.96 x 0.75 + 1000
		$this->assertEqualsWithDelta(1207.72, $summary['totalBalance'], 0.001);
		$this->assertEqualsWithDelta(65.0, $summary['totalMinimumPayment'], 0.001);
		$this->assertEqualsWithDelta(207.72, $summary['lowestBalance'], 0.001);
		$this->assertSame('GBP', $summary['currency']);
	}

	public function testThePlanIsInTheBaseCurrencyAndKeepsEachDebtsOwnBalance(): void {
		$plan = $this->service->calculatePayoffPlan('alice', 'snowball');
		$debts = array_column($plan['debts'], null, 'id');

		$this->assertSame('GBP', $plan['currency']);
		$this->assertEqualsWithDelta(207.72, $debts[1]['originalBalance'], 0.001);
		$this->assertEqualsWithDelta(15.0, $debts[1]['minimumPayment'], 0.001);
		$this->assertSame('USD', $debts[1]['currency']);
		$this->assertEqualsWithDelta(276.96, $debts[1]['nativeBalance'], 0.001);
		$this->assertSame('GBP', $debts[2]['currency']);
		$this->assertEqualsWithDelta(1000.0, $debts[2]['nativeBalance'], 0.001);
		// No interest, so the plan pays exactly what is owed, in pounds
		$this->assertEqualsWithDelta(1207.72, $plan['totalPaid'], 0.01);
	}
}
