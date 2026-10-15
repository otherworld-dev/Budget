<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ImportService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A rule made before 2.28 (schema 1: field, pattern and match type columns,
 * the category in category_id, no criteria, no actions), exactly as an
 * upgrade leaves it, categorises a real CSV import again: since 2.28 the
 * import read only the criteria column and such a rule matched nothing.
 */
class LegacyRuleImportTest extends IntegrationTestCase {
	private function importCsv(int $accountId, string $csv): array {
		$tmp = tempnam(sys_get_temp_dir(), 'legacy-rule');
		file_put_contents($tmp, $csv);
		$import = $this->service(ImportService::class);
		$upload = $import->processUpload($this->userId, ['name' => 'statement.csv', 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
		@unlink($tmp);
		return $import->processImport(
			$this->userId, $upload['fileId'],
			['date' => 0, 'description' => 1, 'amount' => 2, 'skipFirstRow' => true],
			$accountId
		);
	}

	public function testARuleFromBeforeTheRulesEngineCategorisesTheImport(): void {
		$account = $this->makeAccount()->getId();
		$groceries = $this->makeCategory(['name' => 'Groceries']);
		$this->insertRow('budget_import_rules', [
			'user_id' => $this->userId,
			'name' => 'Tesco',
			'pattern' => 'tesco',
			'field' => 'description',
			'match_type' => 'contains',
			'category_id' => $groceries,
			'vendor_name' => 'Tesco',
			'priority' => 0,
			'active' => true,
			'apply_on_import' => true,
			'schema_version' => 1,
			'stop_processing' => true,
			'created_at' => $this->now(),
		]);
		$foreign = $this->makeCategory(['name' => 'Not yours'], $this->newUserId());
		$this->insertRow('budget_import_rules', [
			'user_id' => $this->userId,
			'name' => 'Aldi',
			'pattern' => 'aldi',
			'field' => 'description',
			'match_type' => 'contains',
			'category_id' => $foreign,
			'priority' => 0,
			'active' => true,
			'apply_on_import' => true,
			'schema_version' => 1,
			'stop_processing' => true,
			'created_at' => $this->now(),
		]);

		$result = $this->importCsv($account, "Date,Description,Amount\n2026-03-02,TESCO STORES 2041,-12.50\n2026-03-03,ALDI 77,-4.00\n2026-03-04,BOOTS,-3.00\n");

		$this->assertSame(3, $result['imported']);
		$rows = $this->db()->executeQuery(
			'SELECT description, category_id, vendor FROM *PREFIX*budget_transactions WHERE account_id = ? ORDER BY date',
			[$account]
		)->fetchAll();
		$this->assertSame($groceries, (int)$rows[0]['category_id']);
		$this->assertSame('Tesco', $rows[0]['vendor']);
		// Another user's category is never stamped (R6-4)
		$this->assertNull($rows[1]['category_id']);
		$this->assertNull($rows[2]['category_id']);
	}
}
