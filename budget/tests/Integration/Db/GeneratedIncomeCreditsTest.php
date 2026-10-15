<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The credits the app booked for recurring income, which an imported bank
 * row of the same payment replaces: only those, in the one account and date
 * range, and never an imported row.
 */
class GeneratedIncomeCreditsTest extends IntegrationTestCase {
	public function testFindsOnlyTheAppsOwnIncomeCredits(): void {
		$account = $this->makeAccount()->getId();
		$other = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$booked = $this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'notes' => 'Auto-generated from income: Salary']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'notes' => 'Auto-generated from income: Salary', 'import_id' => 'bank-1']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'notes' => 'Refund']);
		$this->makeTransaction($account, ['type' => 'debit', 'date' => '2026-09-03', 'notes' => 'Auto-generated from income: Salary']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-10', 'notes' => 'Auto-generated from income: Salary']);
		$this->makeTransaction($other, ['type' => 'credit', 'date' => '2026-09-03', 'notes' => 'Auto-generated from income: Salary']);

		$found = $this->service(TransactionMapper::class)->findGeneratedIncomeCredits($account, '2026-09-01', '2026-09-05');

		$this->assertSame([$booked], array_map(fn ($t) => $t->getId(), $found));
	}
}
