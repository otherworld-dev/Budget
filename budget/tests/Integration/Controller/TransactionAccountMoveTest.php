<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Controller;

use OCA\Budget\Controller\ApiV1TransactionController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\IdempotencyKeyMapper;
use OCA\Budget\Db\TransactionReconciliationQueries;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** The public edit endpoint, with real services and an isolated Nextcloud database. */
class TransactionAccountMoveTest extends IntegrationTestCase {
	private function patch(int $id, array $body, ?string $actor = null): DataResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $body[$key] ?? $default);
		$controller = new ApiV1TransactionController(
			$request,
			$this->service(TransactionService::class),
			$this->service(AttachmentService::class),
			$this->service(TransactionSplitService::class),
			$this->service(ValidationService::class),
			$this->service(GranularShareService::class),
			$this->service(IdempotencyKeyMapper::class),
			$this->service(IFactory::class)->get('budget'),
			$actor ?? $this->userId,
			$this->service(LoggerInterface::class),
			$this->db(),
			$this->service(UserClock::class),
			$this->service(TransactionTagService::class),
		);
		return $controller->update($id);
	}

	private function balance(int $id): float {
		return (float)$this->service(AccountMapper::class)->findById($id)->getBalance();
	}

	public function testMoveKeepsTheIdentityMetadataAndChildrenAndRecomputesBothBalances(): void {
		$source = $this->makeAccount(['openingBalance' => 100.0])->getId();
		$target = $this->makeAccount(['openingBalance' => 200.0])->getId();
		$category = $this->makeCategory();
		$id = $this->makeTransaction($source, ['category_id' => $category, 'notes' => 'Synthetic note', 'reference' => 'Synthetic reference']);
		$tag = $this->makeTag($this->makeTagSet($category));
		$link = $this->tagTransaction($id, $tag);
		$receipt = $this->makeAttachment($id);
		$before = $this->fetchRow('budget_transactions', $id);
		$this->service(TransactionService::class)->recalculateAccountBalance($source, $this->userId);
		$this->assertSame(90.0, $this->balance($source));

		$response = $this->patch($id, ['account_id' => $target]);

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame($id, $response->getData()['id']);
		$this->assertSame($target, $response->getData()['account_id']);
		$after = $this->fetchRow('budget_transactions', $id);
		foreach ($before as $field => $value) {
			if (!in_array($field, ['account_id', 'updated_at'], true)) {
				$this->assertSame($value, $after[$field], $field);
			}
		}
		$this->assertSame($id, (int)$this->fetchRow('budget_attachments', $receipt)['transaction_id']);
		$this->assertSame($tag, (int)$this->fetchRow('budget_transaction_tags', $link)['tag_id']);
		$this->assertSame(100.0, $this->balance($source));
		$this->assertSame(190.0, $this->balance($target));
	}

	public function testASplitMovesWithItsPartsAndTags(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$category = $this->makeCategory();
		$id = $this->makeSplitTransaction($source, [[$category, '3.00'], [null, '7.00']]);
		$parts = $this->service(TransactionSplitService::class)->getSplits($id, $this->userId);
		$this->assertSame(200, $this->patch($id, ['account_id' => $target])->getStatus());
		$this->assertEquals($parts, $this->service(TransactionSplitService::class)->getSplits($id, $this->userId));
	}

	public function testTransferMoveIsRefusedAndBothSidesStayLinkedAndUnchanged(): void {
		$source = $this->makeAccount()->getId();
		$other = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$transfer = $this->service(TransactionService::class)->createTransfer(
			$this->userId, $this->userId, $source, $other, [$source, $other], '2026-03-15', 'Synthetic transfer', 10.0, 'debit'
		);
		$id = $transfer->getId();
		$partner = $transfer->getLinkedTransactionId();
		$before = [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_transactions', $partner)];

		$response = $this->patch($id, ['account_id' => $target, 'confirm_reconciled' => true]);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('transfer_leg', $response->getData()['error_code']);
		$this->assertSame($before, [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_transactions', $partner)]);
		$this->assertSame(-10.0, $this->balance($source));
		$this->assertSame(10.0, $this->balance($other));
		$this->assertSame(0.0, $this->balance($target));
	}

	public function testClosedAndDifferentCurrencyTargetsRefuseTheEntireEdit(): void {
		$source = $this->makeAccount()->getId();
		$id = $this->makeTransaction($source);
		$before = $this->fetchRow('budget_transactions', $id);
		$closed = $this->makeAccount(['closed' => true])->getId();
		$euros = $this->makeAccount(['currency' => 'EUR'])->getId();
		foreach ([$closed => 'account_closed', $euros => 'account_currency_mismatch'] as $target => $code) {
			$response = $this->patch($id, ['account_id' => $target, 'notes' => 'Must not be written']);
			$this->assertSame(400, $response->getStatus());
			$this->assertSame($code, $response->getData()['error_code']);
			$this->assertSame($before, $this->fetchRow('budget_transactions', $id));
		}
	}

	private function shareAccounts(string $owner, string $writer, array $permissions): void {
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $owner, 'shared_with_user_id' => $writer, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		foreach ($permissions as $id => $permission) {
			$this->insertRow('budget_share_items', [
				'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $id, 'permission' => $permission,
				'created_at' => $this->now(), 'updated_at' => $this->now(),
			]);
		}
	}

	public function testTargetMustBeVisibleWritableAndHaveTheSameOwner(): void {
		$owner = $this->newUserId();
		$source = $this->makeAccount([], $owner)->getId();
		$hidden = $this->makeAccount([], $owner)->getId();
		$readOnly = $this->makeAccount([], $owner)->getId();
		$writable = $this->makeAccount([], $owner)->getId();
		$mine = $this->makeAccount()->getId();
		$this->shareAccounts($owner, $this->userId, [$source => 'write', $readOnly => 'read', $writable => 'write']);
		$id = $this->makeTransaction($source);
		$before = $this->fetchRow('budget_transactions', $id);
		foreach ([$hidden => 404, $readOnly => 403, $mine => 400] as $target => $status) {
			$response = $this->patch($id, ['account_id' => $target]);
			$this->assertSame($status, $response->getStatus(), json_encode($response->getData()));
			if ($target === $mine) {
				$this->assertSame('account_owner_mismatch', $response->getData()['error_code']);
			}
			$this->assertSame($before, $this->fetchRow('budget_transactions', $id));
		}
		$this->assertSame(200, $this->patch($id, ['account_id' => $writable])->getStatus());
		$this->assertSame($writable, (int)$this->fetchRow('budget_transactions', $id)['account_id']);
	}

	public function testLegacyEditAndUnchangedAccountKeepTheirExistingBehavior(): void {
		$source = $this->makeAccount(['closed' => true])->getId();
		$id = $this->makeTransaction($source, ['reconciled' => true]);
		$this->assertSame(200, $this->patch($id, ['notes' => 'Legacy notes'])->getStatus());
		$this->assertSame(200, $this->patch($id, ['account_id' => $source])->getStatus());
		$row = $this->fetchRow('budget_transactions', $id);
		$this->assertSame('Legacy notes', $row['notes']);
		$this->assertSame($source, (int)$row['account_id']);
		$this->assertTrue((bool)$row['reconciled']);
	}

	public function testReconciledMoveNeedsConfirmationAndThenLeavesTheOldStatementWithAnAuditTrail(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$session = $this->insertRow('budget_recon_sessions', [
			'account_id' => $source, 'user_id' => $this->userId, 'statement_date' => '2026-03-31',
			'statement_balance' => '-10.00', 'starting_balance' => '0.00', 'status' => 'completed',
			'reconciled_count' => 1, 'created_at' => $this->now(),
		]);
		$id = $this->makeTransaction($source, ['reconciled' => true, 'recon_session_id' => $session]);
		$before = $this->fetchRow('budget_transactions', $id);
		$response = $this->patch($id, ['account_id' => $target]);
		$this->assertSame(409, $response->getStatus());
		$this->assertSame('reconciled', $response->getData()['error_code']);
		$this->assertSame($before, $this->fetchRow('budget_transactions', $id));

		$response = $this->patch($id, ['account_id' => $target, 'confirm_reconciled' => true]);
		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$row = $this->fetchRow('budget_transactions', $id);
		$this->assertFalse((bool)$row['reconciled']);
		$this->assertNull($row['recon_session_id']);
		$this->assertSame(1, $this->countRows('budget_audit_log', ['entity_id' => $id, 'action' => 'reconciled_tx_modified']));
		$this->assertSame(1, $this->countRows('budget_audit_log', ['entity_id' => $id, 'action' => 'reconciled_tx_unreconciled']));
	}

	public function testAStaleCategoryOrTagRefusesTheMoveWithoutDroppingMetadata(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$foreignCategory = $this->makeCategory([], $this->newUserId());
		$id = $this->makeTransaction($source, ['category_id' => $foreignCategory]);
		$response = $this->patch($id, ['account_id' => $target]);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_category', $response->getData()['error_code']);
		$this->assertSame($source, (int)$this->fetchRow('budget_transactions', $id)['account_id']);

		$id = $this->makeTransaction($source);
		$tag = $this->makeTag(null, $this->newUserId());
		$link = $this->tagTransaction($id, $tag);
		$response = $this->patch($id, ['account_id' => $target]);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_tags', $response->getData()['error_code']);
		$this->assertSame($source, (int)$this->fetchRow('budget_transactions', $id)['account_id']);
		$this->assertSame($tag, (int)$this->fetchRow('budget_transaction_tags', $link)['tag_id']);
	}

	public function testATickedUnreconciledRowMustLeaveItsSourceSessionBeforeMoving(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$session = $this->insertRow('budget_recon_sessions', [
			'account_id' => $source, 'user_id' => $this->userId, 'statement_date' => '2026-03-31',
			'statement_balance' => '-10.00', 'starting_balance' => '0.00', 'status' => 'in_progress',
			'reconciled_count' => 0, 'created_at' => $this->now(),
		]);
		$id = $this->makeTransaction($source, ['reconciled' => false]);
		$queries = $this->service(TransactionReconciliationQueries::class);
		$this->assertSame(1, $queries->tickIntoSession($source, [$id], $session));
		$before = $this->fetchRow('budget_transactions', $id);
		foreach ([false, true] as $confirmed) {
			$response = $this->patch($id, ['account_id' => $target, 'confirm_reconciled' => $confirmed]);
			$this->assertSame(409, $response->getStatus());
			$this->assertSame('reconciliation_in_progress', $response->getData()['error_code']);
			$this->assertSame($before, $this->fetchRow('budget_transactions', $id));
			$this->assertSame(-10.0, $queries->getSessionTickedSum($session));
		}
		$this->assertSame(1, $queries->untickFromSession($source, [$id], $session));
		$this->assertSame(200, $this->patch($id, ['account_id' => $target])->getStatus());
		$queries->markSessionReconciled($session);
		$row = $this->fetchRow('budget_transactions', $id);
		$this->assertSame($target, (int)$row['account_id']);
		$this->assertFalse((bool)$row['reconciled']);
		$this->assertNull($row['recon_session_id']);
	}

	/**
	 * Fail a real SQL write after preceding writes have succeeded. This runs
	 * on every database in CI, with the trigger always removed in finally.
	 *
	 * @return \Closure(): void
	 */
	private function rejectWrite(string $table, string $event, string $condition): \Closure {
		$db = $this->db();
		$name = 'bgt463_' . bin2hex(random_bytes(6));
		$prefix = $this->service(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$table = $db->quoteIdentifier($prefix . $table);
		$provider = $db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_SQLITE) {
			$db->executeStatement("CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'synthetic move failure'); END");
		} elseif ($provider === IDBConnection::PLATFORM_MYSQL) {
			$db->executeStatement("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic move failure'; END IF; END");
		} elseif ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$db->executeStatement("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic move failure'; END; \$\$");
			$db->executeStatement("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW WHEN ($condition) EXECUTE FUNCTION $name()");
		} else {
			throw new \RuntimeException('Unsupported integration database: ' . $provider);
		}
		return static function () use ($db, $name, $table, $provider): void {
			if ($provider === IDBConnection::PLATFORM_POSTGRES) {
				$db->executeStatement("DROP TRIGGER $name ON $table");
				$db->executeStatement("DROP FUNCTION $name()");
			} else {
				$db->executeStatement("DROP TRIGGER $name");
			}
		};
	}

	public function testFailureRecomputingTheOldBalanceRollsBackTheTransactionAndBothBalances(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$id = $this->makeTransaction($source);
		$this->service(TransactionService::class)->recalculateAccountBalance($source, $this->userId);
		$before = [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_accounts', $source), $this->fetchRow('budget_accounts', $target)];
		$remove = $this->rejectWrite('budget_accounts', 'UPDATE', "NEW.id = $source");
		try {
			$response = $this->patch($id, ['account_id' => $target, 'notes' => 'Must roll back']);
			$this->assertSame(400, $response->getStatus());
			$this->assertStringContainsString('synthetic move failure', $response->getData()['detail'] ?? '');
		} finally {
			$remove();
		}
		$this->assertFalse($this->db()->inTransaction());
		$this->assertSame($before, [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_accounts', $source), $this->fetchRow('budget_accounts', $target)]);
	}

	public function testFailureReplacingTagsRollsBackTheMoveBalancesOldTagsAndAuditLog(): void {
		$source = $this->makeAccount()->getId();
		$target = $this->makeAccount()->getId();
		$id = $this->makeTransaction($source, ['reconciled' => true, 'recon_session_id' => 7654321]);
		$oldTag = $this->makeTag(null);
		$link = $this->tagTransaction($id, $oldTag);
		$newTag = $this->makeTag(null);
		$failedTag = $this->makeTag(null);
		$this->service(TransactionService::class)->recalculateAccountBalance($source, $this->userId);
		$before = [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_accounts', $source), $this->fetchRow('budget_accounts', $target), $this->fetchRow('budget_transaction_tags', $link)];
		$remove = $this->rejectWrite('budget_transaction_tags', 'INSERT', "NEW.transaction_id = $id AND NEW.tag_id = $failedTag");
		try {
			$response = $this->patch($id, ['account_id' => $target, 'tag_ids' => [$newTag, $failedTag], 'confirm_reconciled' => true]);
			$this->assertSame(400, $response->getStatus());
			$this->assertStringContainsString('synthetic move failure', $response->getData()['detail'] ?? '');
		} finally {
			$remove();
		}
		$this->assertFalse($this->db()->inTransaction());
		$this->assertSame($before, [$this->fetchRow('budget_transactions', $id), $this->fetchRow('budget_accounts', $source), $this->fetchRow('budget_accounts', $target), $this->fetchRow('budget_transaction_tags', $link)]);
		$this->assertSame(1, $this->countRows('budget_transaction_tags', ['transaction_id' => $id]));
		$this->assertSame(0, $this->countRows('budget_audit_log', ['entity_type' => 'transaction', 'entity_id' => $id]));
	}
}
