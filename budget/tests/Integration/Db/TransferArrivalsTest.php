<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The credits that can be a linked transfer's arrival: the exact amount (a
 * decimal compared to the cent), in the window, and free to take.
 */
class TransferArrivalsTest extends IntegrationTestCase {
	public function testFindsOnlyFreeCreditsOfTheAmountInTheWindow(): void {
		$account = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$other = $this->makeAccount()->getId();
		$arrival = $this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-02']);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.11', 'date' => '2026-02-02']);
		$this->makeTransaction($account, ['type' => 'debit', 'amount' => '250.10', 'date' => '2026-02-02']);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-09']);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-02', 'bill_id' => 999003]);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-02', 'status' => 'scheduled']);
		$partner = $this->makeTransaction($other, ['type' => 'debit', 'amount' => '250.10', 'date' => '2026-02-02']);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-02', 'linked_transaction_id' => $partner]);
		$this->makeTransaction($other, ['type' => 'credit', 'amount' => '250.10', 'date' => '2026-02-02']);

		$found = $this->service(TransactionMapper::class)->findTransferArrivals($account, 250.10, '2026-01-29', '2026-02-04');

		$this->assertSame([$arrival], array_map(fn ($t) => $t->getId(), $found));
	}

	/**
	 * Between currencies the bank converts at its own rate, so its credit is
	 * looked for within a margin of the app's figure, to the column's eight
	 * places (a crypto amount, too).
	 */
	public function testAMarginFindsTheBanksOwnConversion(): void {
		$account = $this->makeAccount(['name' => 'Euro savings', 'type' => 'savings', 'currency' => 'EUR'])->getId();
		$near = $this->makeTransaction($account, ['type' => 'credit', 'amount' => '117.40', 'date' => '2026-02-02']);
		$this->makeTransaction($account, ['type' => 'credit', 'amount' => '105.80', 'date' => '2026-02-02']);
		$coin = $this->makeAccount(['name' => 'Wallet', 'type' => 'cryptocurrency', 'currency' => 'BTC'])->getId();
		$sats = $this->makeTransaction($coin, ['type' => 'credit', 'amount' => '0.00210000', 'date' => '2026-02-02']);
		$this->makeTransaction($coin, ['type' => 'credit', 'amount' => '0.00200000', 'date' => '2026-02-02']);
		$mapper = $this->service(TransactionMapper::class);
		$ids = fn (array $rows) => array_map(fn ($t) => $t->getId(), $rows);

		$this->assertSame([$near], $ids($mapper->findTransferArrivals($account, 117.65, '2026-01-29', '2026-02-04', 11.765)));
		$this->assertSame([], $ids($mapper->findTransferArrivals($account, 117.65, '2026-01-29', '2026-02-04')));
		$this->assertSame([$sats], $ids($mapper->findTransferArrivals($coin, 0.0023, '2026-01-29', '2026-02-04', 0.00023)));
	}
}
