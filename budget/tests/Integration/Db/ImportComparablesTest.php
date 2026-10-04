<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * TransactionMapper::findImportComparables(), which a preset import reads to
 * recognise rows a manual mapping of the same file already stored (R5-4):
 * one account, the date range inclusive at both ends, scheduled
 * placeholders left out and a NULL status kept.
 */
class ImportComparablesTest extends IntegrationTestCase {
	public function testReturnsTheAccountsRowsInTheRangeWithoutPlaceholders(): void {
		$account = $this->makeAccount()->getId();
		$other = $this->makeAccount(['name' => 'Other'])->getId();

		$first = $this->makeTransaction($account, ['date' => '2024-01-01', 'description' => 'First day', 'amount' => '3.50', 'import_id' => 'hash_a']);
		$last = $this->makeTransaction($account, ['date' => '2024-01-31', 'description' => 'Last day', 'vendor' => 'Shop', 'notes' => 'note']);
		$noStatus = $this->makeTransaction($account, ['date' => '2024-01-20', 'status' => null]);
		$this->makeTransaction($account, ['date' => '2024-01-15', 'status' => 'scheduled']);
		$this->makeTransaction($account, ['date' => '2024-02-01']);
		$this->makeTransaction($account, ['date' => '2023-12-31']);
		$this->makeTransaction($other, ['date' => '2024-01-10']);

		$rows = $this->service(TransactionMapper::class)->findImportComparables($account, '2024-01-01', '2024-01-31');

		$this->assertSame([$first, $last, $noStatus], array_column($rows, 'id'));
		$byId = array_column($rows, null, 'id');
		$this->assertSame('2024-01-01', $byId[$first]['date']);
		$this->assertSame('debit', $byId[$first]['type']);
		$this->assertSame('hash_a', $byId[$first]['import_id']);
		$this->assertEqualsWithDelta(3.5, (float)$byId[$first]['amount'], 0.0001);
		$this->assertSame('Shop', $byId[$last]['vendor']);
		$this->assertSame('note', $byId[$last]['notes']);
		$this->assertNull($byId[$last]['import_id']);
	}
}
