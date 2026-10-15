<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Re-splitting deleted the stored parts first and checked the new ones as it
 * inserted them, so a part the database refused (a zero amount, which the
 * entity then left out of its INSERT) answered 400 with the transaction left
 * holding some of its parts, or none. A refused split must change nothing.
 */
class SplitRefusalTest extends IntegrationTestCase {
	private TransactionSplitService $splits;
	private int $accountId;
	private int $food;
	private int $bills;

	protected function setUp(): void {
		parent::setUp();
		$this->splits = $this->service(TransactionSplitService::class);
		$this->accountId = $this->makeAccount()->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->bills = $this->makeCategory(['name' => 'Bills']);
	}

	#[DataProvider('refusedParts')]
	public function testARefusedResplitLeavesTheStoredPartsAsTheyWere(array $amounts): void {
		$id = $this->makeSplitTransaction($this->accountId, [[$this->food, '30.00'], [$this->bills, '20.00']]);

		try {
			$this->splits->splitTransaction($id, $this->userId, [
				['categoryId' => $this->food, 'amount' => $amounts[0]],
				['categoryId' => $this->bills, 'amount' => $amounts[1]],
			]);
			$this->fail('The split was accepted');
		} catch (\InvalidArgumentException $e) {
			// refused, as it should be
		}

		$this->assertSame([[$this->food, 30.0], [$this->bills, 20.0]], $this->storedParts($id));
		$row = $this->fetchRow('budget_transactions', $id);
		$this->assertTrue((bool)$row['is_split']);
		$this->assertNull($row['category_id']);
	}

	public static function refusedParts(): array {
		return [
			'a zero part second' => [['50.00', '0.00']],
			'a zero part first' => [[0, 50]],
			'a part that is not a number' => [['abc', '50.00']],
		];
	}

	public function testAnAcceptedResplitReplacesTheParts(): void {
		$id = $this->makeSplitTransaction($this->accountId, [[$this->food, '30.00'], [$this->bills, '20.00']]);

		$this->splits->splitTransaction($id, $this->userId, [
			['categoryId' => $this->food, 'amount' => '45.00'],
			['categoryId' => null, 'amount' => '5.00'],
		]);

		$this->assertSame([[$this->food, 45.0], [null, 5.0]], $this->storedParts($id));
	}

	/** The entity's old '0' default dropped an explicit zero from the INSERT */
	public function testAPartWhoseAmountIsSetToZeroReachesTheDatabase(): void {
		$id = $this->makeTransaction($this->accountId);
		$split = new TransactionSplit();
		$split->setTransactionId($id);
		$split->setAmount('0');
		$split->setCreatedAt($this->now());

		$inserted = $this->service(TransactionSplitMapper::class)->insert($split);

		$this->assertSame(0.0, (float)$this->fetchRow('budget_tx_splits', $inserted->getId())['amount']);
	}

	/** @return array<array{0: int|null, 1: float}> */
	private function storedParts(int $transactionId): array {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('category_id', 'amount')->from('budget_tx_splits')
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$parts = [];
		while ($row = $result->fetch()) {
			$parts[] = [$row['category_id'] === null ? null : (int)$row['category_id'], (float)$row['amount']];
		}
		$result->closeCursor();
		return $parts;
	}
}
