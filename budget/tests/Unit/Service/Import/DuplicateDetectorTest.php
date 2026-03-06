<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;

class DuplicateDetectorTest extends TestCase {
	private DuplicateDetector $detector;
	private TransactionService $transactionService;

	protected function setUp(): void {
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->detector = new DuplicateDetector($this->transactionService);
	}

	// ── isDuplicateByImportId ───────────────────────────────────────

	public function testIsDuplicateByImportIdTrue(): void {
		$this->transactionService->method('existsByImportId')
			->with(1, 'import-123')
			->willReturn(true);

		$this->assertTrue($this->detector->isDuplicateByImportId(1, 'import-123'));
	}

	public function testIsDuplicateByImportIdFalse(): void {
		$this->transactionService->method('existsByImportId')
			->with(1, 'import-456')
			->willReturn(false);

		$this->assertFalse($this->detector->isDuplicateByImportId(1, 'import-456'));
	}

	// ── isDuplicate ─────────────────────────────────────────────────

	public function testIsDuplicateWithImportId(): void {
		$this->transactionService->method('existsByImportId')
			->with(1, 'import-123')
			->willReturn(true);

		$this->assertTrue($this->detector->isDuplicate(1, ['date' => '2025-01-01'], 'import-123'));
	}

	public function testIsDuplicateWithoutImportId(): void {
		// Without import ID, always returns false (no fuzzy matching yet)
		$this->assertFalse($this->detector->isDuplicate(1, ['date' => '2025-01-01']));
	}

	public function testIsDuplicateWithNullImportId(): void {
		$this->assertFalse($this->detector->isDuplicate(1, ['date' => '2025-01-01'], null));
	}

	// ── filterDuplicates ────────────────────────────────────────────

	public function testFilterDuplicatesSeparatesUniqueAndDuplicates(): void {
		$this->transactionService->method('existsByImportId')
			->willReturnCallback(function (int $accountId, string $importId) {
				return $importId === 'dup-1' || $importId === 'dup-2';
			});

		$transactions = [
			['id' => 'dup-1', 'amount' => 10],
			['id' => 'new-1', 'amount' => 20],
			['id' => 'dup-2', 'amount' => 30],
			['id' => 'new-2', 'amount' => 40],
		];

		$result = $this->detector->filterDuplicates(
			1,
			$transactions,
			fn($t) => $t['id']
		);

		$this->assertCount(2, $result['unique']);
		$this->assertCount(2, $result['duplicates']);
		$this->assertSame(20, $result['unique'][0]['amount']);
		$this->assertSame(40, $result['unique'][1]['amount']);
	}

	public function testFilterDuplicatesAllUnique(): void {
		$this->transactionService->method('existsByImportId')->willReturn(false);

		$transactions = [
			['id' => 'new-1'],
			['id' => 'new-2'],
		];

		$result = $this->detector->filterDuplicates(1, $transactions, fn($t) => $t['id']);

		$this->assertCount(2, $result['unique']);
		$this->assertEmpty($result['duplicates']);
	}

	public function testFilterDuplicatesAllDuplicates(): void {
		$this->transactionService->method('existsByImportId')->willReturn(true);

		$transactions = [
			['id' => 'dup-1'],
			['id' => 'dup-2'],
		];

		$result = $this->detector->filterDuplicates(1, $transactions, fn($t) => $t['id']);

		$this->assertEmpty($result['unique']);
		$this->assertCount(2, $result['duplicates']);
	}

	public function testFilterDuplicatesEmptyInput(): void {
		$result = $this->detector->filterDuplicates(1, [], fn($t) => $t['id']);

		$this->assertEmpty($result['unique']);
		$this->assertEmpty($result['duplicates']);
	}

	// ── checkBatch ──────────────────────────────────────────────────

	public function testCheckBatchReturnsDuplicateMap(): void {
		$this->transactionService->method('existsByImportId')
			->willReturnCallback(function (int $accountId, string $importId) {
				return $importId === 'exists-1';
			});

		$result = $this->detector->checkBatch(1, ['exists-1', 'new-1', 'new-2']);

		$this->assertTrue($result['exists-1']);
		$this->assertFalse($result['new-1']);
		$this->assertFalse($result['new-2']);
	}

	public function testCheckBatchEmpty(): void {
		$result = $this->detector->checkBatch(1, []);

		$this->assertEmpty($result);
	}
}
