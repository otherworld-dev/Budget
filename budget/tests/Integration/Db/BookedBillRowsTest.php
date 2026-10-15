<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The rows a bill booked for its payments, which the bank's own row of the
 * same payment takes the place of: only the bill's own, with its notes, of
 * the one type, in the dates, never a bank row or a pending placeholder.
 */
class BookedBillRowsTest extends IntegrationTestCase {
	public function testFindsOnlyTheRowsTheBillBookedItself(): void {
		$account = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$notes = 'Auto-generated transfer: Savings top-up';
		$booked = $this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'bill_id' => 999101, 'notes' => $notes]);
		$emptyImportId = $this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-04', 'bill_id' => 999101, 'notes' => $notes, 'import_id' => '']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'bill_id' => 999101, 'notes' => $notes, 'import_id' => 'bank-1']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'bill_id' => 999101, 'notes' => $notes, 'status' => 'scheduled']);
		$this->makeTransaction($account, ['type' => 'debit', 'date' => '2026-09-03', 'bill_id' => 999101, 'notes' => $notes]);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'bill_id' => 999102, 'notes' => $notes]);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-03', 'bill_id' => 999101, 'notes' => 'Paid early']);
		$this->makeTransaction($account, ['type' => 'credit', 'date' => '2026-09-20', 'bill_id' => 999101, 'notes' => $notes]);

		$found = $this->service(TransactionMapper::class)->findBookedBillRows(999101, 'credit', 'Auto-generated transfer:', '2026-09-01', '2026-09-10');

		$this->assertSame([$booked, $emptyImportId], array_map(fn ($t) => $t->getId(), $found));
	}
}
