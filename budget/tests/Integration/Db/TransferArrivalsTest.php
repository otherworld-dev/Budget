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
}
