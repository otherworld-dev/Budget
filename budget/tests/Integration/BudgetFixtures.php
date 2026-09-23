<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCP\Server;

/**
 * Test-data factories for the integration suite.
 *
 * Accounts go through AccountMapper so their sensitive columns are encrypted
 * exactly as the app stores them. Everything else is a raw row, so a test can
 * set the grey states the app itself no longer writes (NULL is_split, NULL
 * status, orphans) and the factories never depend on the service code under
 * test. Each factory takes overrides as column => value.
 *
 * Needs insertRow() and $userId from IntegrationTestCase.
 */
trait BudgetFixtures {
	protected function now(): string {
		return date('Y-m-d H:i:s');
	}

	/**
	 * @param array<string, mixed> $overrides entity property => value (camelCase setters)
	 */
	protected function makeAccount(array $overrides = [], ?string $userId = null): Account {
		$account = new Account();
		$account->setUserId($userId ?? $this->userId);
		$account->setName('Current account');
		$account->setType('checking');
		$account->setBalance(0.0);
		$account->setOpeningBalance(0.0);
		$account->setCurrency('GBP');
		$account->setCreatedAt($this->now());
		$account->setUpdatedAt($this->now());
		foreach ($overrides as $property => $value) {
			$account->{'set' . ucfirst($property)}($value);
		}
		/** @var Account */
		return Server::get(AccountMapper::class)->insert($account);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	protected function makeCategory(array $overrides = [], ?string $userId = null): int {
		return $this->insertRow('budget_categories', $overrides + [
			'user_id' => $userId ?? $this->userId,
			'name' => 'Groceries',
			'type' => 'expense',
			'sort_order' => 0,
			'created_at' => $this->now(),
		]);
	}

	/**
	 * A cleared debit dated in the past, uncategorised unless overridden.
	 *
	 * @param array<string, mixed> $overrides
	 */
	protected function makeTransaction(int $accountId, array $overrides = []): int {
		return $this->insertRow('budget_transactions', $overrides + [
			'account_id' => $accountId,
			'date' => '2026-03-15',
			'description' => 'Test transaction',
			'amount' => '10.00',
			'type' => 'debit',
			'status' => 'cleared',
			'is_split' => false,
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		]);
	}

	/**
	 * A split parent (category NULL, is_split true) with one part per
	 * [categoryId, amount] pair. Returns the parent id.
	 *
	 * @param array<array{0: int|null, 1: string}> $parts
	 * @param array<string, mixed> $overrides for the parent row
	 */
	protected function makeSplitTransaction(int $accountId, array $parts, array $overrides = []): int {
		$total = 0.0;
		foreach ($parts as [, $amount]) {
			$total += (float)$amount;
		}
		$parentId = $this->makeTransaction($accountId, $overrides + [
			'amount' => number_format($total, 2, '.', ''),
			'category_id' => null,
			'is_split' => true,
		]);
		foreach ($parts as [$categoryId, $amount]) {
			$this->makeSplit($parentId, $categoryId, $amount);
		}
		return $parentId;
	}

	protected function makeSplit(int $transactionId, ?int $categoryId, string $amount): int {
		return $this->insertRow('budget_tx_splits', [
			'transaction_id' => $transactionId,
			'category_id' => $categoryId,
			'amount' => $amount,
			'description' => 'part',
			'created_at' => $this->now(),
		]);
	}

	protected function makeTagSet(int $categoryId): int {
		return $this->insertRow('budget_tag_sets', [
			'category_id' => $categoryId,
			'name' => 'Shop',
			'sort_order' => 0,
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		]);
	}

	protected function makeTag(?int $tagSetId, ?string $userId = null): int {
		return $this->insertRow('budget_tags', [
			'tag_set_id' => $tagSetId,
			'user_id' => $userId ?? $this->userId,
			'name' => 'Tesco',
			'color' => '#00aa00',
			'sort_order' => 0,
			'hidden' => false,
			'created_at' => $this->now(),
		]);
	}

	protected function tagTransaction(int $transactionId, int $tagId): int {
		return $this->insertRow('budget_transaction_tags', [
			'transaction_id' => $transactionId,
			'tag_id' => $tagId,
			'created_at' => $this->now(),
		]);
	}

	protected function makeAttachment(int $transactionId, ?string $userId = null): int {
		return $this->insertRow('budget_attachments', [
			'transaction_id' => $transactionId,
			'user_id' => $userId ?? $this->userId,
			'file_id' => 424242,
			'file_name' => 'receipt.jpg',
			'mime_type' => 'image/jpeg',
			'created_at' => $this->now(),
		]);
	}

	protected function makeContact(?string $userId = null): int {
		return $this->insertRow('budget_contacts', [
			'user_id' => $userId ?? $this->userId,
			'name' => 'Sam',
			'email' => 'sam@example.com',
			'created_at' => $this->now(),
		]);
	}

	protected function makeExpenseShare(int $transactionId, int $contactId, ?string $userId = null): int {
		return $this->insertRow('budget_expense_shares', [
			'user_id' => $userId ?? $this->userId,
			'transaction_id' => $transactionId,
			'contact_id' => $contactId,
			'amount' => '5.00',
			'is_settled' => false,
			'created_at' => $this->now(),
		]);
	}

	/**
	 * Everything that can hang off one transaction: a tag, an attachment, an
	 * expense share and two split parts.
	 *
	 * @return list<array{0: string, 1: int}> [child table, row id] pairs
	 */
	protected function hangChildrenOn(int $transactionId, int $categoryId): array {
		$tag = $this->makeTag($this->makeTagSet($categoryId));
		return [
			['budget_transaction_tags', $this->tagTransaction($transactionId, $tag)],
			['budget_attachments', $this->makeAttachment($transactionId)],
			['budget_expense_shares', $this->makeExpenseShare($transactionId, $this->makeContact())],
			['budget_tx_splits', $this->makeSplit($transactionId, $categoryId, '4.00')],
			['budget_tx_splits', $this->makeSplit($transactionId, $categoryId, '6.00')],
		];
	}
}
