<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\AccountType;

/**
 * Calculates unrealised profit/loss for investment and cryptocurrency accounts
 * by comparing the fiat cost of all purchases to the current fiat value.
 */
class InvestmentService {
	public function __construct(
		private AccountMapper $accountMapper,
		private TransactionMapper $transactionMapper,
		private CurrencyConversionService $conversionService,
	) {
	}

	/**
	 * Calculate unrealised P&L for an investment or crypto account.
	 *
	 * Total cost: sum of each buy transaction's amount converted to base currency
	 *             at the transaction's date (historical rate).
	 * Total proceeds: sum of each sell transaction's amount converted similarly.
	 * Current value: current balance × current exchange rate → base currency.
	 * Unrealised P&L: current value - (total cost - total proceeds).
	 *
	 * @return array{totalCost: float, totalProceeds: float, netInvested: float, currentValue: float, unrealisedPnL: float, pnlPercentage: float|null, baseCurrency: string}
	 */
	public function calculateUnrealisedPnL(int $accountId, string $userId): array {
		$account = $this->accountMapper->find($accountId, $userId);
		$accountCurrency = $account->getCurrency() ?: 'USD';
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);

		$type = AccountType::tryFrom($account->getType());
		if (!$type || (!$this->isInvestmentType($type))) {
			return $this->emptyResult($baseCurrency);
		}

		$transactions = $this->transactionMapper->findAllClearedByAccount($accountId, $userId);

		if (empty($transactions)) {
			return $this->emptyResult($baseCurrency);
		}

		// Calculate total cost of buys and total proceeds of sells in base currency
		$totalCost = '0';
		$totalProceeds = '0';

		$conversionFailures = 0;

		foreach ($transactions as $tx) {
			$amount = (string)$tx->getAmount();
			$date = $tx->getDate();

			// Convert the transaction amount to base currency at the historical rate.
			$fiatValue = $this->conversionService->convert(
				$amount,
				$accountCurrency,
				$baseCurrency,
				$date
			);

			// Detect silent conversion failure (amount returned unchanged for different currencies)
			if ($accountCurrency !== $baseCurrency && $fiatValue === $amount) {
				$conversionFailures++;
			}

			if ($tx->getType() === 'credit') {
				// Credit = money into the account = buy (cost)
				$totalCost = bcadd($totalCost, $fiatValue, 10);
			} else {
				// Debit = money out of the account = sell (proceeds)
				$totalProceeds = bcadd($totalProceeds, $fiatValue, 10);
			}
		}

		// Net invested = total cost - total proceeds (what's still "in" the investment)
		$netInvested = bcsub($totalCost, $totalProceeds, 10);

		// Current value: convert current balance to base currency at today's rate
		// Use balance as-is (don't abs()) — negative balances indicate anomalies
		$currentBalance = (string)($account->getBalance() ?? 0);
		$currentValue = $this->conversionService->convert(
			$currentBalance,
			$accountCurrency,
			$baseCurrency
		);

		// Unrealised P&L = current value - net invested
		$unrealisedPnL = bcsub($currentValue, $netInvested, 2);

		// P&L percentage
		$pnlPercentage = null;
		if (bccomp($netInvested, '0', 2) > 0) {
			$pnlPercentage = MoneyCalculator::toFloat(
				bcmul(bcdiv($unrealisedPnL, $netInvested, 10), '100', 2)
			);
		}

		return [
			'totalCost' => MoneyCalculator::toFloat(bcadd($totalCost, '0', 2)),
			'totalProceeds' => MoneyCalculator::toFloat(bcadd($totalProceeds, '0', 2)),
			'netInvested' => MoneyCalculator::toFloat(bcadd($netInvested, '0', 2)),
			'currentValue' => MoneyCalculator::toFloat(bcadd($currentValue, '0', 2)),
			'unrealisedPnL' => MoneyCalculator::toFloat($unrealisedPnL),
			'pnlPercentage' => $pnlPercentage,
			'baseCurrency' => $baseCurrency,
			'conversionWarning' => $conversionFailures > 0,
		];
	}

	private function isInvestmentType(AccountType $type): bool {
		return in_array($type, [
			AccountType::INVESTMENT,
			AccountType::CRYPTOCURRENCY,
		], true);
	}

	private function emptyResult(string $baseCurrency): array {
		return [
			'totalCost' => 0.0,
			'totalProceeds' => 0.0,
			'netInvested' => 0.0,
			'currentValue' => 0.0,
			'unrealisedPnL' => 0.0,
			'pnlPercentage' => null,
			'baseCurrency' => $baseCurrency,
		];
	}
}
