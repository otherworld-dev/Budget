<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Api;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\Db\Attachment;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * These are contract tests, not behaviour tests. The v1 API promises a fixed
 * set of keys; asserting the exact key set means dropping or renaming one
 * fails here rather than in somebody's phone app weeks later.
 *
 * Adding a key to an entity must NOT fail these — that is the whole point of
 * the serializer sitting between the database and the wire.
 */
class ApiSerializerTest extends TestCase {
	// ── accounts ────────────────────────────────────────────────────

	public function testAccountKeysAreFixed(): void {
		$result = ApiSerializer::account(['id' => 1, 'name' => 'Current', 'type' => 'checking']);

		$this->assertSame([
			'id', 'name', 'type', 'currency', 'balance', 'balanceInBaseCurrency',
			'baseCurrency', 'institution', 'shared', 'updatedAt',
		], array_keys($result));
	}

	public function testAccountCoercesTypes(): void {
		$result = ApiSerializer::account([
			'id' => '42',
			'name' => 'Savings',
			'type' => 'savings',
			'currency' => 'GBP',
			'balance' => '1234.5',
		]);

		$this->assertSame(42, $result['id']);
		$this->assertSame('1234.50', $result['balance']);
	}

	public function testAccountBalanceIsAlwaysATwoPlaceDecimalString(): void {
		// Money never crosses the wire as a JSON number — see ApiSerializer::money().
		$this->assertSame('0.00', ApiSerializer::account(['id' => 1])['balance']);
		$this->assertSame('-12.50', ApiSerializer::account(['id' => 1, 'balance' => -12.5])['balance']);
		$this->assertSame('1000000.00', ApiSerializer::account(['id' => 1, 'balance' => 1000000])['balance']);
	}

	public function testAccountNeverLeaksBankingDetails(): void {
		$result = ApiSerializer::account([
			'id' => 1,
			'name' => 'Current',
			'iban' => 'GB33BUKB20201555555555',
			'accountNumber' => '12345678',
			'sortCode' => '20-20-15',
			'userId' => 'someone-else',
		]);

		foreach (['iban', 'accountNumber', 'sortCode', 'userId'] as $secret) {
			$this->assertArrayNotHasKey($secret, $result);
		}
	}

	public function testAccountConvertedBalanceIsNullWhenAbsent(): void {
		$result = ApiSerializer::account(['id' => 1, 'balance' => 10.0]);

		$this->assertNull($result['balanceInBaseCurrency']);
		$this->assertNull($result['baseCurrency']);
	}

	public function testAccountExposesConvertedBalanceWhenPresent(): void {
		$result = ApiSerializer::account([
			'id' => 1,
			'balance' => 100.0,
			'convertedBalance' => 85.25,
			'baseCurrency' => 'GBP',
		]);

		$this->assertSame('85.25', $result['balanceInBaseCurrency']);
		$this->assertSame('GBP', $result['baseCurrency']);
	}

	public function testAccountSharedFlagComesFromInternalMarker(): void {
		$this->assertTrue(ApiSerializer::account(['id' => 1, '_shared' => true])['shared']);
		$this->assertFalse(ApiSerializer::account(['id' => 1])['shared']);
	}

	// ── categories ──────────────────────────────────────────────────

	public function testCategoryKeysAreFixed(): void {
		$result = ApiSerializer::category(['id' => 1, 'name' => 'Food', 'type' => 'expense']);

		$this->assertSame(
			['id', 'name', 'type', 'parentId', 'icon', 'color', 'shared'],
			array_keys($result)
		);
	}

	public function testCategoryAcceptsAnEntity(): void {
		$category = new Category();
		$category->setId(7);
		$category->setName('Groceries');
		$category->setType('expense');
		$category->setParentId(3);
		$category->setColor('#ef4444');

		$result = ApiSerializer::category($category);

		$this->assertSame(7, $result['id']);
		$this->assertSame('Groceries', $result['name']);
		$this->assertSame(3, $result['parentId']);
		$this->assertSame('#ef4444', $result['color']);
	}

	public function testCategoryDropsBudgetInternals(): void {
		$category = new Category();
		$category->setId(7);
		$category->setName('Groceries');
		$category->setType('expense');
		$category->setBudgetAmount(250.0);

		$result = ApiSerializer::category($category);

		// Budgets are not part of v1; leaking the column would make it one.
		$this->assertArrayNotHasKey('budgetAmount', $result);
		$this->assertArrayNotHasKey('excludedFromReports', $result);
	}

	public function testCategoryTopLevelHasNullParent(): void {
		$this->assertNull(ApiSerializer::category(['id' => 1, 'parentId' => null])['parentId']);
	}

	// ── transactions ────────────────────────────────────────────────

	public function testTransactionKeysAreFixed(): void {
		$result = ApiSerializer::transaction(['id' => 1, 'accountId' => 2]);

		$this->assertSame([
			'id', 'accountId', 'categoryId', 'date', 'description', 'vendor',
			'amount', 'type', 'reference', 'notes', 'status', 'reconciled',
			'isSplit', 'createdAt', 'updatedAt',
		], array_keys($result));
	}

	public function testTransactionAcceptsAnEntity(): void {
		$transaction = new Transaction();
		$transaction->setId(99);
		$transaction->setAccountId(2);
		$transaction->setDate('2026-08-01');
		$transaction->setDescription('Weekly shop');
		$transaction->setAmount(42.5);
		$transaction->setType('debit');

		$result = ApiSerializer::transaction($transaction);

		$this->assertSame(99, $result['id']);
		$this->assertSame('2026-08-01', $result['date']);
		$this->assertSame('42.50', $result['amount']);
		$this->assertSame('debit', $result['type']);
	}

	public function testTransactionAmountIsATwoPlaceDecimalString(): void {
		$this->assertSame('0.00', ApiSerializer::transaction(['id' => 1])['amount']);
		$this->assertSame('7.05', ApiSerializer::transaction(['id' => 1, 'amount' => 7.05])['amount']);
		// A DECIMAL column reaches the serializer as a string on some drivers.
		$this->assertSame('15.00', ApiSerializer::transaction(['id' => 1, 'amount' => '15'])['amount']);
	}

	public function testTransactionIncludesJoinedFieldsOnlyWhenPresent(): void {
		$withJoins = ApiSerializer::transaction([
			'id' => 1,
			'accountId' => 2,
			'accountName' => 'Current Account',
			'accountCurrency' => 'GBP',
			'categoryName' => 'Groceries',
		]);

		$this->assertSame('Current Account', $withJoins['accountName']);
		$this->assertSame('GBP', $withJoins['accountCurrency']);
		$this->assertSame('Groceries', $withJoins['categoryName']);

		// An uncategorised row has no category name to report — the key is
		// absent rather than an invented empty string.
		$uncategorised = ApiSerializer::transaction(['id' => 1, 'accountId' => 2]);
		$this->assertArrayNotHasKey('categoryName', $uncategorised);
	}

	public function testTransactionDefaultsStatusToCleared(): void {
		$this->assertSame('cleared', ApiSerializer::transaction(['id' => 1])['status']);
		$this->assertSame('scheduled', ApiSerializer::transaction(['id' => 1, 'status' => 'scheduled'])['status']);
	}

	public function testTransactionDropsInternalLinkageFields(): void {
		$result = ApiSerializer::transaction([
			'id' => 1,
			'accountId' => 2,
			'importId' => 'csv:abc123',
			'billId' => 44,
			'pensionContribId' => 9,
			'linkedTransactionId' => 500,
			'reconSessionId' => 12,
		]);

		foreach (['importId', 'billId', 'pensionContribId', 'linkedTransactionId', 'reconSessionId'] as $internal) {
			$this->assertArrayNotHasKey($internal, $result);
		}
	}

	// ── receipts ────────────────────────────────────────────────────

	public function testAttachmentKeysAreFixed(): void {
		$result = ApiSerializer::attachment(['id' => 1, 'transactionId' => 2, 'fileId' => 3]);

		$this->assertSame(
			['id', 'transactionId', 'fileId', 'fileName', 'mimeType', 'createdAt', 'missing'],
			array_keys($result)
		);
	}

	public function testAttachmentAcceptsAnEntity(): void {
		$attachment = new Attachment();
		$attachment->setId(1);
		$attachment->setTransactionId(2);
		$attachment->setFileId(85);
		$attachment->setFileName('receipt.png');
		$attachment->setMimeType('image/png');

		$result = ApiSerializer::attachment($attachment);

		$this->assertSame(85, $result['fileId']);
		$this->assertSame('receipt.png', $result['fileName']);
		// A freshly created row carries no missing flag; absent means present.
		$this->assertFalse($result['missing']);
	}

	public function testAttachmentPropagatesMissingFlag(): void {
		$result = ApiSerializer::attachment(['id' => 1, 'missing' => true]);

		$this->assertTrue($result['missing']);
	}

	// ── receipt drafts ──────────────────────────────────────────────

	public function testReceiptDraftKeysAreFixed(): void {
		$result = ApiSerializer::receiptDraft([]);

		$this->assertSame([
			'merchant', 'date', 'currency', 'total', 'lineItems',
			'suggestedCategoryId', 'suggestedCategoryName', 'warnings',
		], array_keys($result));
	}

	public function testReceiptDraftEmptyInputIsAllNulls(): void {
		$result = ApiSerializer::receiptDraft([]);

		$this->assertNull($result['merchant']);
		$this->assertNull($result['total']);
		$this->assertSame([], $result['lineItems']);
		$this->assertSame([], $result['warnings']);
	}

	public function testReceiptDraftLineItemsAreReindexedAndStringed(): void {
		$result = ApiSerializer::receiptDraft([
			'lineItems' => [2 => ['description' => 'Milk', 'amount' => '1.65']],
		]);

		// json_encode must produce an array of {description, amount} objects
		// with money as strings, like everything else in v1.
		$this->assertSame([['description' => 'Milk', 'amount' => '1.65']], $result['lineItems']);
	}

	// ── map ─────────────────────────────────────────────────────────

	public function testMapReindexesSparseArrays(): void {
		$filtered = [1 => ['id' => 2], 3 => ['id' => 4]];

		$result = ApiSerializer::map($filtered, [ApiSerializer::class, 'category']);

		// json_encode must produce an array, not an object keyed 1 and 3.
		$this->assertSame([0, 1], array_keys($result));
	}

	public function testMapOnEmptyArray(): void {
		$this->assertSame([], ApiSerializer::map([], [ApiSerializer::class, 'account']));
	}
}
