<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Api;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\Db\Account;
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
			'id', 'name', 'type', 'currency', 'balance', 'balance_in_base_currency',
			'base_currency', 'institution', 'shared', 'closed', 'updated_at',
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

		$this->assertNull($result['balance_in_base_currency']);
		$this->assertNull($result['base_currency']);
	}

	public function testAccountExposesConvertedBalanceWhenPresent(): void {
		$result = ApiSerializer::account([
			'id' => 1,
			'balance' => 100.0,
			'convertedBalance' => 85.25,
			'baseCurrency' => 'GBP',
		]);

		$this->assertSame('85.25', $result['balance_in_base_currency']);
		$this->assertSame('GBP', $result['base_currency']);
	}

	public function testAccountSharedFlagComesFromInternalMarker(): void {
		$this->assertTrue(ApiSerializer::account(['id' => 1, '_shared' => true])['shared']);
		$this->assertFalse(ApiSerializer::account(['id' => 1])['shared']);
	}

	// ── categories ──────────────────────────────────────────────────

	public function testCategoryKeysAreFixed(): void {
		$result = ApiSerializer::category(['id' => 1, 'name' => 'Food', 'type' => 'expense']);

		$this->assertSame(
			['id', 'name', 'type', 'parent_id', 'icon', 'color', 'shared'],
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
		$this->assertSame(3, $result['parent_id']);
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
		$this->assertNull(ApiSerializer::category(['id' => 1, 'parentId' => null])['parent_id']);
	}

	// ── transactions ────────────────────────────────────────────────

	public function testTransactionKeysAreFixed(): void {
		$result = ApiSerializer::transaction(['id' => 1, 'accountId' => 2]);

		$this->assertSame([
			'id', 'account_id', 'category_id', 'date', 'description', 'vendor',
			'amount', 'type', 'reference', 'notes', 'status', 'reconciled',
			'is_split', 'created_at', 'updated_at',
			'linked_transaction_id', 'linked_account_name', 'splits',
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

		$this->assertSame('Current Account', $withJoins['account_name']);
		$this->assertSame('GBP', $withJoins['account_currency']);
		$this->assertSame('Groceries', $withJoins['category_name']);

		// An uncategorised row has no category name to report — the key is
		// absent rather than an invented empty string.
		$uncategorised = ApiSerializer::transaction(['id' => 1, 'accountId' => 2]);
		$this->assertArrayNotHasKey('category_name', $uncategorised);
	}

	public function testTransactionCarriesTheSplitShareOnlyWhenThereIsOne(): void {
		// Filtering by category also matches a split transaction through its
		// parts, and `amount` is then the whole transaction, not the part that
		// matched — split_amount is the part, so a client totalling a category
		// does not credit it the whole receipt (#359).
		$share = ApiSerializer::transaction([
			'id' => 1,
			'accountId' => 2,
			'amount' => 82.40,
			'isSplit' => true,
			'matchedSplitAmount' => 12.4,
		]);

		$this->assertSame('12.40', $share['split_amount']);
		$this->assertSame('82.40', $share['amount'], 'amount stays the whole transaction');

		$plain = ApiSerializer::transaction(['id' => 1, 'accountId' => 2, 'amount' => 82.40]);
		$this->assertArrayNotHasKey('split_amount', $plain);
	}

	public function testTransactionReportsAZeroSplitShare(): void {
		// 0.00 is a real share, not an absent one.
		$result = ApiSerializer::transaction(['id' => 1, 'matchedSplitAmount' => 0.0]);

		$this->assertSame('0.00', $result['split_amount']);
	}

	public function testTransactionKeepsASplitShareNegative(): void {
		// A receipt's discount line is a negative part of an expense.
		$result = ApiSerializer::transaction(['id' => 1, 'matchedSplitAmount' => -3.5]);

		$this->assertSame('-3.50', $result['split_amount']);
	}

	public function testTransactionDefaultsStatusToCleared(): void {
		$this->assertSame('cleared', ApiSerializer::transaction(['id' => 1])['status']);
		$this->assertSame('scheduled', ApiSerializer::transaction(['id' => 1, 'status' => 'scheduled'])['status']);
	}

	public function testTransactionCarriesItsTransferLinkButNoOtherInternals(): void {
		$result = ApiSerializer::transaction([
			'id' => 1,
			'accountId' => 2,
			'importId' => 'csv:abc123',
			'billId' => 44,
			'pensionContribId' => 9,
			'linkedTransactionId' => 500,
			'linkedAccountId' => 7,
			'linkedAccountName' => 'Savings',
			'reconSessionId' => 12,
			'_canWrite' => true,
		]);

		// The other half of a transfer is part of v1 now (#407)...
		$this->assertSame(500, $result['linked_transaction_id']);
		$this->assertSame('Savings', $result['linked_account_name']);
		// ...but nothing else that only means something inside the app.
		foreach (['importId', 'billId', 'pensionContribId', 'linkedTransactionId', 'linkedAccountId',
			'linkedAccountName', 'reconSessionId', '_canWrite', 'splitCategories'] as $internal) {
			$this->assertArrayNotHasKey($internal, $result);
		}
	}

	public function testTransactionWithoutATransferHasNullLinks(): void {
		$result = ApiSerializer::transaction(['id' => 1]);

		$this->assertNull($result['linked_transaction_id']);
		$this->assertNull($result['linked_account_name']);
	}

	public function testTransactionSplitsAreAnEmptyListWhenNotSplit(): void {
		$this->assertSame([], ApiSerializer::transaction(['id' => 1])['splits']);
	}

	public function testTransactionMapsItsSplitParts(): void {
		// A list row carries its parts as splitCategories (#408)
		$result = ApiSerializer::transaction([
			'id' => 5,
			'isSplit' => true,
			'splitCategories' => [
				['id' => 31, 'transactionId' => 5, 'categoryId' => 12, 'categoryName' => 'Groceries', 'amount' => 3.4, 'description' => 'Flat White'],
				['id' => 32, 'transactionId' => 5, 'categoryId' => null, 'categoryName' => null, 'amount' => 1.42, 'description' => null],
			],
		]);

		$this->assertSame([
			['id' => 31, 'transaction_id' => 5, 'amount' => '3.40', 'category_id' => 12, 'category_name' => 'Groceries', 'description' => 'Flat White'],
			['id' => 32, 'transaction_id' => 5, 'amount' => '1.42', 'category_id' => null, 'category_name' => null, 'description' => null],
		], $result['splits']);
	}

	// ── recent ──────────────────────────────────────────────────────

	public function testRecentTransactionKeysAreFixed(): void {
		$result = ApiSerializer::recentTransaction(['id' => 1]);

		$this->assertSame([
			'id', 'merchant', 'date', 'amount', 'currency', 'account_name',
			'account_id', 'type', 'category_name', 'is_split', 'splits',
			'linked_transaction_id', 'linked_account_name',
		], array_keys($result));
	}

	public function testRecentTransactionSaysWhichWayTheMoneyWent(): void {
		$result = ApiSerializer::recentTransaction([
			'id' => 1,
			'accountId' => 3,
			'amount' => 1500,
			'type' => 'credit',
			'categoryName' => 'Salary',
		]);

		$this->assertSame(3, $result['account_id']);
		$this->assertSame('credit', $result['type']);
		// Amounts stay positive; type carries the direction
		$this->assertSame('1500.00', $result['amount']);
		$this->assertSame('Salary', $result['category_name']);
	}

	public function testRecentTransactionCarriesSplitsAndTransferLink(): void {
		$result = ApiSerializer::recentTransaction([
			'id' => 1,
			'isSplit' => true,
			'splitCategories' => [['id' => 9, 'transactionId' => 1, 'categoryId' => 4, 'categoryName' => 'Fuel', 'amount' => 20.0, 'description' => null]],
			'linkedTransactionId' => 77,
			'linkedAccountName' => null,
		]);

		$this->assertTrue($result['is_split']);
		$this->assertSame('20.00', $result['splits'][0]['amount']);
		$this->assertSame(77, $result['linked_transaction_id']);
		$this->assertNull($result['linked_account_name']);
		$this->assertNull(ApiSerializer::recentTransaction(['id' => 2])['category_name']);
	}

	// ── splits ──────────────────────────────────────────────────────

	public function testSplitKeysAreFixed(): void {
		$this->assertSame(
			['id', 'transaction_id', 'amount', 'category_id', 'category_name', 'description'],
			array_keys(ApiSerializer::split([]))
		);
	}

	// ── budget status (#767) ───────────────────────────────────────

	public function testBudgetStatusKeysAreFixed(): void {
		$result = ApiSerializer::budgetStatus(['month' => '2026-09']);

		$this->assertSame(['month', 'start_date', 'end_date', 'currency', 'totals', 'categories'], array_keys($result));
		$this->assertSame(['budgeted', 'spent', 'remaining'], array_keys($result['totals']));
	}

	public function testBudgetLineKeysAreFixed(): void {
		$this->assertSame([
			'category_id', 'name', 'parent_id', 'type', 'period',
			'budgeted', 'carried', 'spent', 'remaining', 'shared',
		], array_keys(ApiSerializer::budgetLine([])));
	}

	public function testBudgetStatusAmountsAreMoneyStrings(): void {
		$result = ApiSerializer::budgetStatus([
			'month' => '2026-09',
			'totals' => ['budgeted' => '525.333333', 'spent' => '1220.000000', 'remaining' => '-694.666667'],
			'categories' => [['categoryId' => 12, 'budgeted' => '400.000000', 'spent' => '431.2', 'remaining' => '-31.2', 'carried' => '0']],
		]);

		$this->assertSame(['budgeted' => '525.33', 'spent' => '1220.00', 'remaining' => '-694.67'], $result['totals']);
		$this->assertSame('-31.20', $result['categories'][0]['remaining']);
		$this->assertSame(12, $result['categories'][0]['category_id']);
	}

	// ── bills (#767) ───────────────────────────────────────────────

	public function testBillKeysAreFixed(): void {
		$this->assertSame([
			'id', 'name', 'amount', 'amount_type', 'currency', 'frequency', 'next_due_date',
			'overdue', 'account_id', 'account_name', 'category_id', 'is_transfer', 'auto_pay', 'shared',
		], array_keys(ApiSerializer::bill(['id' => 1])));
	}

	public function testBillMapsTheServiceRow(): void {
		$result = ApiSerializer::bill([
			'id' => '3', 'name' => 'Netflix', 'amount' => 12.99, 'amountType' => 'fixed', 'currency' => 'GBP',
			'frequency' => 'monthly', 'nextDueDate' => '2026-09-28', 'overdue' => false, 'accountId' => 1,
			'accountName' => 'Current account', 'categoryId' => 9, 'isTransfer' => false, 'autoPayEnabled' => true,
			'_shared' => true, 'userId' => 'owner1', 'paidUndoState' => 'x',
		]);

		$this->assertSame(3, $result['id']);
		$this->assertSame('12.99', $result['amount']);
		$this->assertTrue($result['auto_pay']);
		$this->assertTrue($result['shared']);
		$this->assertArrayNotHasKey('userId', $result);
	}

	// ── receipts ────────────────────────────────────────────────────

	public function testAttachmentKeysAreFixed(): void {
		$result = ApiSerializer::attachment(['id' => 1, 'transactionId' => 2, 'fileId' => 3]);

		$this->assertSame(
			['id', 'transaction_id', 'file_id', 'file_name', 'mime_type', 'created_at', 'missing'],
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

		$this->assertSame(85, $result['file_id']);
		$this->assertSame('receipt.png', $result['file_name']);
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
			'merchant', 'date', 'total', 'subtotal', 'tax', 'discount', 'currency',
			'suggested_category_id', 'suggested_category_name', 'line_items', 'warnings',
		], array_keys($result));
	}

	public function testReceiptDraftEmptyInputIsAllNulls(): void {
		$result = ApiSerializer::receiptDraft([]);

		$this->assertNull($result['merchant']);
		$this->assertNull($result['total']);
		$this->assertSame([], $result['line_items']);
		$this->assertSame([], $result['warnings']);
	}

	public function testReceiptDraftPassesEveryPopulatedFieldThrough(): void {
		// Every read in receiptDraft() has a '?? null' fallback, so a typo'd
		// key degrades to null instead of failing — this test feeds a fully
		// populated service-shaped draft and asserts nothing is lost, which
		// is the only thing that catches that class of regression.
		$result = ApiSerializer::receiptDraft([
			'merchant' => 'Tesco Express',
			'date' => '2026-08-01',
			'currency' => 'GBP',
			'total' => '9.75',
			'subtotal' => '8.13',
			'tax' => '1.62',
			'discount' => '0.75',
			'lineItems' => [['description' => 'Milk 2L', 'amount' => '1.65']],
			'suggestedCategoryId' => 42,
			'suggestedCategoryName' => 'Groceries',
			'warnings' => ['line-items-sum-mismatch'],
		]);

		$this->assertSame([
			'merchant' => 'Tesco Express',
			'date' => '2026-08-01',
			'total' => '9.75',
			'subtotal' => '8.13',
			'tax' => '1.62',
			'discount' => '0.75',
			'currency' => 'GBP',
			'suggested_category_id' => 42,
			'suggested_category_name' => 'Groceries',
			'line_items' => [['description' => 'Milk 2L', 'amount' => '1.65']],
			'warnings' => ['line-items-sum-mismatch'],
		], $result);
	}

	public function testReceiptDraftLineItemsAreReindexedAndStringed(): void {
		$result = ApiSerializer::receiptDraft([
			'lineItems' => [2 => ['description' => 'Milk', 'amount' => '1.65']],
		]);

		// json_encode must produce an array of {description, amount} objects
		// with money as strings, like everything else in v1.
		$this->assertSame([['description' => 'Milk', 'amount' => '1.65']], $result['line_items']);
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

	// ── closed accounts (#372) ─────────────────────────────────────

	/**
	 * Additive v1 field: a capture client can leave closed accounts out of
	 * its own picker. Always present and always a bool, never null.
	 */
	public function testAccountClosedIsABoolThatDefaultsToFalse(): void {
		$this->assertFalse(ApiSerializer::account(['id' => 1])['closed']);
		$this->assertTrue(ApiSerializer::account(['id' => 1, 'closed' => 1])['closed']);
		$this->assertFalse(ApiSerializer::account(['id' => 1, 'closed' => null])['closed']);
	}

	public function testAccountClosedComesFromTheEntity(): void {
		$account = new Account();
		$account->setId(3);
		$account->setName('Old current');
		$account->setType('checking');
		$account->setCurrency('GBP');
		$account->setClosed(true);

		$this->assertTrue(ApiSerializer::account($account)['closed']);
	}
}
