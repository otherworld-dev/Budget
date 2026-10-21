<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * TransactionMapper::findImportComparables() returns each row's reference
 * too: a manually mapped import compares it with the file's cells, so a bank
 * line under another reference is never taken for a stored one (#399).
 */
class ImportComparablesReferenceTest extends IntegrationTestCase {
	public function testReturnsEachRowsReference(): void {
		$account = $this->makeAccount()->getId();
		$withReference = $this->makeTransaction($account, ['date' => '2026-10-14', 'description' => 'Kaffee', 'reference' => 'R-1001', 'import_id' => 'hash_a']);
		$withoutReference = $this->makeTransaction($account, ['date' => '2026-10-14', 'description' => 'Kaffee']);

		$rows = array_column($this->service(TransactionMapper::class)->findImportComparables($account, '2026-10-01', '2026-10-31'), null, 'id');

		$this->assertSame('R-1001', $rows[$withReference]['reference']);
		$this->assertNull($rows[$withoutReference]['reference']);
	}
}
