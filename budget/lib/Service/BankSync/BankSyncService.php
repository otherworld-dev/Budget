<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\TransactionService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates bank sync operations: connecting, syncing transactions,
 * managing account mappings, and coordinating with providers.
 */
class BankSyncService {
	public function __construct(
		private BankConnectionMapper $connectionMapper,
		private BankAccountMappingMapper $mappingMapper,
		private ProviderFactory $providerFactory,
		private TransactionService $transactionService,
		private AuditService $auditService,
		private AdminSettingService $adminSettings,
		private AccountMapper $accountMapper,
		private \OCA\Budget\Db\DismissedImportMapper $dismissedImportMapper,
		private \OCA\Budget\Service\Import\ImportRuleApplicator $ruleApplicator,
		private \OCA\Budget\Service\TransactionTagService $transactionTagService,
		private \OCA\Budget\Service\BillService $billService,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?\OCA\Budget\Service\PensionService $pensionService = null,
	) {
	}

	/**
	 * Create a new bank connection.
	 */
	public function connect(string $userId, string $providerName, array $params, string $name): array {
		$this->requireEnabled();

		$provider = $this->providerFactory->getProvider($providerName);
		$result = $provider->initializeConnection($params);
		$now = date('Y-m-d H:i:s');

		// Create connection record
		$connection = new BankConnection();
		$connection->setUserId($userId);
		$connection->setProvider($providerName);
		$connection->setName($name);
		$connection->setCredentials($result['credentials']);
		$hasAuthUrl = !empty($result['authorizationUrl']);
		$connection->setStatus($hasAuthUrl ? 'pending_auth' : 'active');
		$connection->setCreatedAt($now);
		$connection->setUpdatedAt($now);
		$connection = $this->connectionMapper->insert($connection);

		// Create account mappings for discovered accounts
		foreach ($result['accounts'] as $account) {
			$mapping = new BankAccountMapping();
			$mapping->setConnectionId($connection->getId());
			$mapping->setExternalAccountId($account['id']);
			$mapping->setExternalAccountName($account['name']);
			$mapping->setEnabled(false);
			$mapping->setLastBalance($account['balance'] ?? null);
			$mapping->setLastCurrency($account['currency'] ?? null);
			$mapping->setCreatedAt($now);
			$mapping->setUpdatedAt($now);
			$this->mappingMapper->insert($mapping);
		}

		$this->auditService->log($userId, 'bank_connected', 'bank_connection', $connection->getId(), [
			'provider' => $providerName,
			'name' => $name,
			'accountCount' => count($result['accounts']),
		]);

		return [
			'connection' => $connection,
			'mappings' => $this->mappingMapper->findByConnection($connection->getId()),
			'authorizationUrl' => $result['authorizationUrl'] ?? null,
		];
	}

	/**
	 * Disconnect and delete a bank connection.
	 */
	public function disconnect(string $userId, int $connectionId): void {
		$connection = $this->connectionMapper->find($connectionId, $userId);

		// Revoke access at the provider (best-effort, won't throw)
		try {
			$provider = $this->providerFactory->getProvider($connection->getProvider());
			$provider->revokeConnection($connection->getCredentials());
		} catch (\Exception $e) {
			$this->logger->warning('Failed to revoke provider connection: ' . $e->getMessage(), ['app' => 'budget']);
		}

		$this->mappingMapper->deleteByConnection($connectionId);
		$this->connectionMapper->delete($connection);

		$this->auditService->log($userId, 'bank_disconnected', 'bank_connection', $connectionId, [
			'provider' => $connection->getProvider(),
			'name' => $connection->getName(),
		]);
	}

	/**
	 * Sync transactions from a bank connection.
	 *
	 * @return array{imported: int, skipped: int, errors: int, accounts: array}
	 */
	public function sync(string $userId, int $connectionId, bool $force = false): array {
		$this->requireEnabled();

		$connection = $this->connectionMapper->find($connectionId, $userId);
		// 'error' is retryable: it marks a failed fetch (bridge outage, lapsed
		// subscription, transient network), and blocking it here would leave the
		// connection permanently stuck — a successful sync is the only path back
		// to 'active'. Only 'expired' stays blocked (needs re-authorization).
		if (!in_array($connection->getStatus(), ['active', 'pending_auth', 'error'], true)) {
			throw new \Exception(
				$connection->getStatus() === 'expired'
					? 'Bank authorization has expired. Please reconnect.'
					: 'Connection is not active'
			);
		}

		$provider = $this->providerFactory->getProvider($connection->getProvider());

		// Check if re-authorization is needed
		if ($provider->requiresReauthorization($connection->getCredentials())) {
			$connection->setStatus('expired');
			$connection->setLastError('Bank authorization has expired. Please re-authorize.');
			$connection->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->connectionMapper->update($connection);
			throw new \Exception('Bank authorization has expired. Please reconnect.');
		}

		// Fetch accounts and transactions from provider
		$includePending = (bool)$connection->getIncludePending();
		try {
			$data = $provider->fetchAccounts($connection->getCredentials(), [
				'includePending' => $includePending,
			]);
		} catch (\Exception $e) {
			$connection->setStatus('error');
			$connection->setLastError($e->getMessage());
			$connection->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->connectionMapper->update($connection);

			$this->auditService->log($userId, 'bank_sync_failed', 'bank_connection', $connectionId, [
				'error' => $e->getMessage(),
			]);
			throw $e;
		}

		// Persist refreshed credentials if the provider returned them (e.g. GoCardless token refresh)
		if (isset($data['updatedCredentials'])) {
			$connection->setCredentials($data['updatedCredentials']);
			$connection->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->connectionMapper->update($connection);
		}

		// Auto-discover accounts if none have been mapped yet
		$allMappings = $this->mappingMapper->findByConnection($connectionId);
		if (empty($allMappings)) {
			$this->refreshAccounts($userId, $connectionId);
		}

		$enabledMappings = $this->mappingMapper->findEnabledByConnection($connectionId);
		if (empty($enabledMappings)) {
			// Return early with a helpful message — mappings exist but none are enabled/mapped
			$allMappings = $this->mappingMapper->findByConnection($connectionId);
			$discoveredCount = count($allMappings);

			// Update connection sync timestamp
			$connection->setLastSyncAt(date('Y-m-d H:i:s'));
			$connection->setLastError($discoveredCount > 0
				? $this->l->t('No accounts are enabled for sync. Open Account Mappings to enable and map your accounts.')
				: null);
			$connection->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->connectionMapper->update($connection);

			return [
				'imported' => 0,
				'skipped' => 0,
				'errors' => 0,
				'accounts' => [],
				'discovered' => $discoveredCount,
				'message' => $discoveredCount > 0
					? $this->l->t('Found %1$s account(s). Please open Account Mappings to enable and map them, then sync again.', [$discoveredCount])
					: null,
			];
		}

		$mappingsByExternalId = [];
		$createdForBillMatch = [];
		foreach ($enabledMappings as $m) {
			$mappingsByExternalId[$m->getExternalAccountId()] = $m;
		}

		$totalImported = 0;
		$totalSkipped = 0;
		$totalErrors = 0;
		$accountResults = [];

		foreach ($data['accounts'] as $externalAccount) {
			$txCount = count($externalAccount['transactions'] ?? []);
			$this->logger->info("Bank sync: external account '{$externalAccount['name']}' (id: {$externalAccount['id']}) has {$txCount} transactions", ['app' => 'budget']);

			$mapping = $mappingsByExternalId[$externalAccount['id']] ?? null;
			if (!$mapping) {
				$this->logger->info("Bank sync: no mapping found for external account '{$externalAccount['id']}', skipping", ['app' => 'budget']);
				continue; // Account not mapped or not enabled
			}

			$budgetAccountId = $mapping->getBudgetAccountId();
			if (!$budgetAccountId) {
				continue;
			}

			// Verify the budget account exists and belongs to this user
			try {
				$this->accountMapper->find($budgetAccountId, $userId);
			} catch (\Exception $e) {
				$totalErrors++;
				continue;
			}

			$imported = 0;
			$transferLinkIds = [];
			$skipped = 0;
			$balanceDirty = false;

			// Load existing pending bank-sync holds on this account so we can
			// reconcile them against their posted versions (issue #257). This
			// runs whatever the connection's Include pending setting is now:
			// holds imported while it was on still post or get cancelled after
			// it is switched off, and skipping them left each one pending for
			// good, beside its posted copy or long after the bank dropped it.
			$importPrefix = $connection->getProvider() . ':';
			$existingPending = $this->transactionService->findPendingImported($budgetAccountId, $importPrefix);
			$seenPendingIds = [];
			$postedHoldIds = [];
			// Rows imported in earlier syncs that the bank lists as posted
			$postedInFeed = [];

			// A hold the bank still lists anywhere in this feed is a separate,
			// still-pending payment, so it can never be the hold a posted row
			// with a new id came from. Marking only the rows processed so far
			// let a posted row listed before another hold take that hold: it
			// cleared the wrong merchant's row, re-imported the other hold as
			// a new row, and the real hold was later deleted as stale.
			$feedImportIds = [];
			foreach ($externalAccount['transactions'] as $tx) {
				$feedImportIds[$importPrefix . $tx['id']] = true;
			}
			foreach ($existingPending as $pendingTx) {
				if (isset($feedImportIds[(string)$pendingTx->getImportId()])) {
					$seenPendingIds[$pendingTx->getId()] = true;
				}
			}

			foreach ($externalAccount['transactions'] as $tx) {
				$importId = $connection->getProvider() . ':' . $tx['id'];
				$isPending = !empty($tx['pending']);

				// Already imported under this exact import ID?
				$existing = $this->transactionService->findByImportId($budgetAccountId, $importId);
				if ($existing !== null) {
					$existingStatus = $existing->getStatus() ?? 'cleared';
					// A previously-pending hold has now posted (same ID): clear it.
					if (!$isPending && $existingStatus === 'pending') {
						$posted = $this->postHold($existing, null, $tx, $userId, $connection);
						$postedHoldIds[$existing->getId()] = true;
						$balanceDirty = true;
						if ($posted->getBillId() === null) {
							$createdForBillMatch[] = $posted;
						}
					} elseif (!$isPending && $existingStatus === 'cleared') {
						$postedInFeed[] = $existing;
					}
					$seenPendingIds[$existing->getId()] = true;
					$skipped++;
					continue;
				}

				// Check dismissed imports (skip check when force re-sync)
				if (!$force && $this->dismissedImportMapper->isDismissed($budgetAccountId, $importId)) {
					$skipped++;
					continue;
				}

				// A posted transaction with a NEW id may be the posted version of a
				// pending hold whose id changed. Reconcile it instead of duplicating.
				if (!$isPending) {
					$match = $this->matchPendingHold($existingPending, $seenPendingIds, $tx);
					if ($match !== null) {
						$posted = $this->postHold($match, $importId, $tx, $userId, $connection);
						$postedHoldIds[$match->getId()] = true;
						$balanceDirty = true;
						if ($posted->getBillId() === null) {
							$createdForBillMatch[] = $posted;
						}
						$seenPendingIds[$match->getId()] = true;
						$imported++;
						continue;
					}
				}

				try {
					$txData = $this->importData($userId, $connection, $tx);

					$createdTx = $this->transactionService->create(
						userId: $userId,
						accountId: $budgetAccountId,
						date: $txData['date'],
						description: $txData['description'],
						amount: $txData['amount'],
						type: $txData['type'],
						categoryId: $txData['categoryId'] ?? null,
						vendor: $txData['vendor'] ?? null,
						notes: $txData['notes'] ?? null,
						importId: $importId,
						status: $isPending ? 'pending' : 'cleared',
						excludedFromForecast: !empty($txData['excludedFromForecast']),
						deferBalanceUpdate: true
					);
					// Set immediately after create: a later failure (e.g. tag
					// application) is swallowed by the catch below, and the
					// persisted row must still get its balance recompute.
					$balanceDirty = true;
					if ($isPending || !$this->isCopyOfBillHold($existingPending, $postedHoldIds, $tx)) {
						$createdForBillMatch[] = $createdTx;
					}

					// Apply deferred tag actions from import rules
					if (!empty($txData['_deferred_tags'])) {
						$finalTagIds = [];
						foreach ($txData['_deferred_tags'] as $tagAction) {
							$newTagIds = $tagAction['tagIds'] ?? [];
							if (($tagAction['behavior'] ?? 'merge') === 'merge') {
								$finalTagIds = array_values(array_unique(array_merge($finalTagIds, $newTagIds)));
							} else {
								$finalTagIds = $newTagIds;
							}
						}
						if (!empty($finalTagIds)) {
							$this->transactionTagService->setTransactionTags($createdTx->getId(), $userId, $finalTagIds);
						}
					}

					if (!empty($txData['_deferred_link_transfer'])) {
						$transferLinkIds[] = $createdTx->getId();
					}

					$imported++;
				} catch (\Exception $e) {
					$this->logger->warning('Bank sync: failed to create transaction: ' . $e->getMessage(), [
						'app' => 'budget',
						'importId' => $importId,
					]);
					$totalErrors++;
				}
			}

			// Clean up pending holds that dropped off the feed without posting
			// (e.g. a canceled authorization). Only remove ones not seen this
			// sync and older than a few days, to avoid deleting a hold the
			// provider momentarily omitted. Non-dismissing so a re-appearing
			// hold can still be re-imported. With Include pending off the feed
			// lists no holds at all, so every leftover one ages out here.
			$staleCutoff = date('Y-m-d', strtotime('-5 days'));
			$takenCopyIds = [];
			foreach ($existingPending as $pendingTx) {
				if (isset($seenPendingIds[$pendingTx->getId()])) {
					continue;
				}
				if ($pendingTx->getDate() > $staleCutoff) {
					continue;
				}
				// A hold that paid a bill may have posted as a separate row
				// while the bank still listed it. It takes that row over, so
				// the bill stays paid by the payment the bank actually took.
				if ($pendingTx->getBillId() !== null
					&& $this->takeOverPostedCopy($pendingTx, $postedInFeed, $takenCopyIds, $userId)) {
					$balanceDirty = true;
					continue;
				}
				// Otherwise the payment it made on a bill never happened: undo
				// it, or the bill stays paid and moved on for money the bank
				// never took. Best-effort, the hold goes either way.
				if ($pendingTx->getBillId() !== null) {
					try {
						if (!$this->billService->revertCancelledPayment($pendingTx->getBillId(), $pendingTx->getId())) {
							$this->logger->info("Bank sync: cancelled hold {$pendingTx->getId()} is not the latest payment of bill {$pendingTx->getBillId()}, so the bill was left as it is", ['app' => 'budget']);
						}
					} catch (\Exception $e) {
						$this->logger->warning("Bank sync: could not undo the bill payment of cancelled hold {$pendingTx->getId()}: {$e->getMessage()}", ['app' => 'budget']);
					}
				}
				try {
					$this->transactionService->delete($pendingTx->getId(), $userId, false, false);
					$balanceDirty = true;
				} catch (\Exception $e) {
					// Best-effort cleanup; ignore failures
				}
			}

			// Balance updates were deferred per-row (and a posted hold may have
			// changed amount); recompute once for this account
			if ($balanceDirty) {
				$this->transactionService->recalculateAccountBalance($budgetAccountId, $userId);
			}

			// Process deferred transfer linking for this account's batch
			foreach ($transferLinkIds as $txId) {
				try {
					$matches = $this->transactionService->findPotentialMatches($txId, $userId, 3);
					if (!empty($matches)) {
						$this->transactionService->linkTransactions($txId, $matches[0]->getId(), $userId);
					}
				} catch (\Exception $e) {
					// Silently skip
				}
			}

			$this->logger->info("Bank sync: account '{$externalAccount['name']}' result: {$imported} imported, {$skipped} duplicates skipped, mapped to budget account {$budgetAccountId}", ['app' => 'budget']);

			// Update mapping balance
			$mapping->setLastBalance($externalAccount['balance'] ?? null);
			$mapping->setLastCurrency($externalAccount['currency'] ?? null);
			$mapping->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->mappingMapper->update($mapping);

			$totalImported += $imported;
			$totalSkipped += $skipped;
			$accountResults[] = [
				'externalAccountId' => $externalAccount['id'],
				'name' => $externalAccount['name'],
				'imported' => $imported,
				'skipped' => $skipped,
			];
		}

		// Auto-mark bills paid from matching synced transactions (#274).
		// Best-effort: a matching failure must never fail the sync.
		if (!empty($createdForBillMatch)) {
			try {
				$this->billService->autoMatchPaidFromImport($userId, $createdForBillMatch);
			} catch (\Exception $e) {
				$this->logger->warning('Bill auto-match after sync failed: ' . $e->getMessage(), ['app' => 'budget']);
			}
			// A pension payment the app already booked: the bank's row
			// replaces the app's leg rather than doubling it
			try {
				$this->pensionService?->adoptImportedDuplicates($userId, $createdForBillMatch);
			} catch (\Exception $e) {
				$this->logger->warning('Pension leg match after sync failed: ' . $e->getMessage(), ['app' => 'budget']);
			}
		}

		// Update connection sync status
		$connection->setLastSyncAt(date('Y-m-d H:i:s'));
		$connection->setLastError($totalErrors > 0 ? $this->l->t('Sync completed with %1$s error(s)', [$totalErrors]) : null);
		$connection->setStatus('active');
		$connection->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->connectionMapper->update($connection);

		$this->auditService->log($userId, 'bank_sync_completed', 'bank_connection', $connectionId, [
			'imported' => $totalImported,
			'skipped' => $totalSkipped,
			'errors' => $totalErrors,
		]);

		return [
			'imported' => $totalImported,
			'skipped' => $totalSkipped,
			'errors' => $totalErrors,
			'accounts' => $accountResults,
		];
	}

	/**
	 * Get a single connection by ID (verified ownership).
	 */
	public function getConnection(string $userId, int $connectionId): BankConnection {
		return $this->connectionMapper->find($connectionId, $userId);
	}

	/**
	 * Update a connection entity directly.
	 */
	public function updateConnectionEntity(BankConnection $connection): void {
		$this->connectionMapper->update($connection);
	}

	/**
	 * Get all connections for a user with their mappings.
	 */
	public function getConnections(string $userId): array {
		$connections = $this->connectionMapper->findAll($userId);
		$result = [];

		foreach ($connections as $conn) {
			$result[] = [
				'connection' => $conn,
				'mappings' => $this->mappingMapper->findByConnection($conn->getId()),
			];
		}

		return $result;
	}

	/**
	 * Update an account mapping (map external → Budget account, enable/disable).
	 */
	public function updateMapping(string $userId, int $connectionId, int $mappingId, ?int $budgetAccountId, bool $clearBudgetAccount, ?bool $enabled): BankAccountMapping {
		// Verify connection ownership
		$this->connectionMapper->find($connectionId, $userId);

		$mapping = $this->mappingMapper->find($mappingId);
		if ($mapping->getConnectionId() !== $connectionId) {
			throw new \Exception('Mapping does not belong to this connection');
		}

		$oldBudgetAccountId = $mapping->getBudgetAccountId();

		if ($clearBudgetAccount) {
			$mapping->setBudgetAccountId(null);
		} elseif ($budgetAccountId !== null) {
			// Verify the budget account belongs to this user
			$this->accountMapper->find($budgetAccountId, $userId);
			$mapping->setBudgetAccountId($budgetAccountId);
		}

		// Clear dismissed imports when mapping changes so re-sync can re-import
		if ($oldBudgetAccountId !== null && $mapping->getBudgetAccountId() !== $oldBudgetAccountId) {
			$this->dismissedImportMapper->deleteByAccount($oldBudgetAccountId);
		}

		if ($enabled !== null) {
			$mapping->setEnabled($enabled);
		}

		$mapping->setUpdatedAt(date('Y-m-d H:i:s'));
		return $this->mappingMapper->update($mapping);
	}

	/**
	 * Refresh the account list from the provider (does NOT import transactions).
	 */
	public function refreshAccounts(string $userId, int $connectionId): array {
		$this->requireEnabled();

		$connection = $this->connectionMapper->find($connectionId, $userId);
		$provider = $this->providerFactory->getProvider($connection->getProvider());
		$data = $provider->fetchAccountList($connection->getCredentials());
		$now = date('Y-m-d H:i:s');

		// If accounts were fetched successfully and connection was pending auth,
		// promote to active — the user has completed bank authorization
		if (!empty($data['accounts']) && $connection->getStatus() === 'pending_auth') {
			$connection->setStatus('active');
			$connection->setUpdatedAt($now);
			$this->connectionMapper->update($connection);
		}

		// Persist refreshed credentials if the provider returned them
		if (isset($data['updatedCredentials'])) {
			$connection->setCredentials($data['updatedCredentials']);
			$connection->setUpdatedAt($now);
			$this->connectionMapper->update($connection);
		}

		// Add any new accounts that don't exist yet
		$newMappings = [];
		foreach ($data['accounts'] as $account) {
			$existing = $this->mappingMapper->findByExternalId($connectionId, $account['id']);
			if (!$existing) {
				$mapping = new BankAccountMapping();
				$mapping->setConnectionId($connectionId);
				$mapping->setExternalAccountId($account['id']);
				$mapping->setExternalAccountName($account['name']);
				$mapping->setEnabled(false);
				$mapping->setLastBalance($account['balance'] ?? null);
				$mapping->setLastCurrency($account['currency'] ?? null);
				$mapping->setCreatedAt($now);
				$mapping->setUpdatedAt($now);
				$newMappings[] = $this->mappingMapper->insert($mapping);
			} else {
				// Update balance
				$existing->setLastBalance($account['balance'] ?? null);
				$existing->setLastCurrency($account['currency'] ?? null);
				$existing->setUpdatedAt($now);
				$this->mappingMapper->update($existing);
			}
		}

		return $this->mappingMapper->findByConnection($connectionId);
	}

	/**
	 * Re-authorize an expired GoCardless connection with a new requisition.
	 *
	 * @return array{authorizationUrl: string}
	 */
	public function reauthorize(string $userId, int $connectionId, string $institutionId, string $redirectUrl): array {
		$this->requireEnabled();

		$connection = $this->connectionMapper->find($connectionId, $userId);

		if ($connection->getProvider() !== 'gocardless') {
			throw new \Exception('Re-authorization is only supported for GoCardless connections');
		}

		$provider = $this->providerFactory->getProvider('gocardless');
		$creds = json_decode($connection->getCredentials(), true);

		if (!$creds || !isset($creds['secretId'], $creds['secretKey'])) {
			throw new \Exception('Stored credentials are incomplete');
		}

		// Re-initialize with institution to create a new requisition
		$result = $provider->initializeConnection([
			'secretId' => $creds['secretId'],
			'secretKey' => $creds['secretKey'],
			'institutionId' => $institutionId,
			'redirectUrl' => $redirectUrl,
		]);

		// Update connection with new credentials (new requisitionId, fresh token)
		// Status stays pending until user completes bank authorization
		$connection->setCredentials($result['credentials']);
		$connection->setStatus('pending_auth');
		$connection->setLastError(null);
		$connection->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->connectionMapper->update($connection);

		$this->auditService->log($userId, 'bank_reauthorized', 'bank_connection', $connectionId, [
			'provider' => 'gocardless',
		]);

		return [
			'authorizationUrl' => $result['authorizationUrl'] ?? null,
		];
	}

	/**
	 * A bank row as the sync would store it: the normalized provider fields,
	 * with the user's import rules applied when the connection uses them.
	 *
	 * @param array $tx Normalized incoming transaction
	 */
	private function importData(string $userId, BankConnection $connection, array $tx): array {
		// Negative amount = debit (outflow), positive = credit (inflow)
		$amount = (float)$tx['amount'];
		$txData = [
			'date' => $tx['date'],
			'description' => $tx['description'] ?? '',
			'amount' => abs($amount),
			'type' => $amount < 0 ? 'debit' : 'credit',
			'vendor' => $tx['vendor'] ?? null,
			'categoryId' => null,
			'notes' => null,
			'source' => 'Bank Sync',
		];

		if ($connection->getApplyRules()) {
			$txData = $this->ruleApplicator->applyRules($userId, $txData);
		}

		return $txData;
	}

	/**
	 * Clear a hold as its posted version, taking the bank's final amount and
	 * text (through the import rules, as a fresh import would get them) and
	 * keeping everything the user added to the hold. The caller recomputes
	 * the balance, since the amount may have changed.
	 *
	 * A returned row with no bill yet goes to bill matching like a new import:
	 * the posted text or amount can match a bill the hold's didn't, and the
	 * bill otherwise stayed unpaid while its pre-booked row booked it again.
	 * A row that already paid a bill is that bill's payment and must not be
	 * offered again, or it would settle a second occurrence.
	 *
	 * @param array $tx Normalized posted transaction
	 */
	private function postHold(\OCA\Budget\Db\Transaction $hold, ?string $newImportId, array $tx, string $userId, BankConnection $connection): \OCA\Budget\Db\Transaction {
		$data = $this->importData($userId, $connection, $tx);

		return $this->transactionService->reconcilePendingToPosted($hold, $newImportId, $tx['date'], [
			'amount' => (float)$data['amount'],
			'type' => $data['type'],
			'description' => $data['description'],
			'vendor' => $data['vendor'] ?? null,
		]);
	}

	/**
	 * Find an existing pending hold that matches a newly-posted transaction whose
	 * provider id changed when it posted. Matches on type + amount and a date
	 * within a few days. Returns the matched (still-pending, not-yet-seen)
	 * transaction, or null.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $existingPending
	 * @param array<int,bool> $seenPendingIds
	 * @param array $tx Normalized incoming transaction
	 */
	private function matchPendingHold(array $existingPending, array $seenPendingIds, array $tx): ?\OCA\Budget\Db\Transaction {
		$best = null;
		$bestRank = null;
		foreach ($existingPending as $pendingTx) {
			if (isset($seenPendingIds[$pendingTx->getId()])) {
				continue;
			}
			if (($pendingTx->getStatus() ?? '') !== 'pending') {
				continue; // already reconciled this sync
			}
			$rank = $this->postedVersionRank($pendingTx, $tx);
			if ($rank !== null && ($bestRank === null || ($rank <=> $bestRank) < 0)) {
				$best = $pendingTx;
				$bestRank = $rank;
			}
		}

		return $best;
	}

	/** Days either side of a hold within which any posted row may be its copy */
	private const POSTED_COPY_DAYS = 5;
	/** ...and how far the amount may move without the merchant agreeing (FX) */
	private const POSTED_COPY_DRIFT = 0.03;
	/** Days and amount drift allowed when the merchant agrees (tips, pre-auths) */
	private const POSTED_COPY_DAYS_SAME_MERCHANT = 10;
	private const POSTED_COPY_DRIFT_SAME_MERCHANT = 0.25;

	/**
	 * How well a posted bank row fits as the posted version of a hold, or null
	 * when it doesn't fit at all. A lower rank is a better fit: the same
	 * merchant first, then the same amount, then the closest date.
	 *
	 * Holds don't always post as they were authorised. An FX settlement moves
	 * the amount by a few pence, a tip or a fuel or hotel pre-auth by more,
	 * and some post a week later. Asking for the exact amount within five
	 * days imported each of those as a second row beside the hold, and a
	 * bill the hold had paid was left tied to a row later deleted as stale.
	 * A row within a few days may now differ by a few percent whatever its
	 * text, and a row naming the same merchant may differ by up to a quarter
	 * and post up to ten days later.
	 *
	 * @param array $tx Normalized posted transaction (signed amount)
	 * @return array|null
	 */
	private function postedVersionRank(\OCA\Budget\Db\Transaction $hold, array $tx): ?array {
		$amount = (float)$tx['amount'];
		if ($hold->getType() !== ($amount < 0 ? 'debit' : 'credit')) {
			return null;
		}
		$holdAmount = (float)$hold->getAmount();
		$amountDiff = abs($holdAmount - abs($amount));
		$sameAmount = $amountDiff <= 0.001;
		$drift = $holdAmount > 0 ? $amountDiff / $holdAmount : ($sameAmount ? 0.0 : INF);
		$dayDiff = abs((strtotime($tx['date']) - strtotime($hold->getDate())) / 86400);

		$sameMerchant = self::descriptionsShareAWord(
			$hold->getDescription() . ' ' . ($hold->getVendor() ?? ''),
			($tx['description'] ?? '') . ' ' . ($tx['vendor'] ?? '')
		);

		$fits = ($dayDiff <= self::POSTED_COPY_DAYS && ($sameAmount || $drift <= self::POSTED_COPY_DRIFT))
			|| ($sameMerchant && $dayDiff <= self::POSTED_COPY_DAYS_SAME_MERCHANT
				&& $drift <= self::POSTED_COPY_DRIFT_SAME_MERCHANT);
		if (!$fits) {
			return null;
		}

		return [$sameMerchant ? 0 : 1, $sameAmount ? 0 : 1, $dayDiff, $amountDiff];
	}

	/**
	 * Whether a newly posted row looks like the posted copy of a hold that
	 * already paid a bill and hasn't posted itself. The bank sometimes lists
	 * a payment as pending and posted at once, under two ids. That hold can't
	 * be merged while the bank still lists it, but the posted row must not
	 * reach bill matching either: the bill has already moved on to its next
	 * occurrence, and for a weekly or daily bill the same payment then paid
	 * that one too. Once the hold drops off, the stale cleanup hands the bill
	 * over to this row.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $existingPending
	 * @param array<int,bool> $postedHoldIds holds already posted this sync
	 * @param array $tx Normalized posted transaction
	 */
	private function isCopyOfBillHold(array $existingPending, array $postedHoldIds, array $tx): bool {
		foreach ($existingPending as $hold) {
			if ($hold->getBillId() === null || isset($postedHoldIds[$hold->getId()])) {
				continue;
			}
			if ($this->postedVersionRank($hold, $tx) !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Let a stale bill-paying hold take over its posted copy: the copy is
	 * deleted and the hold takes its import id and figures, so the bill link,
	 * the bill's undo snapshot and anything the user added to the hold all
	 * carry on, and the payment is counted once. The copy goes first, as two
	 * rows can't share an import id. Returns false when there is no copy or
	 * it couldn't be removed, leaving the hold to the usual cleanup.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $postedInFeed
	 * @param array<int,bool> $takenIds copies already taken this sync
	 */
	private function takeOverPostedCopy(\OCA\Budget\Db\Transaction $hold, array $postedInFeed, array &$takenIds, string $userId): bool {
		$copy = $this->findPostedCopy($hold, $postedInFeed, $takenIds);
		if ($copy === null) {
			return false;
		}
		try {
			$this->transactionService->delete($copy->getId(), $userId, false, false);
		} catch (\Exception $e) {
			$this->logger->warning("Bank sync: could not merge hold {$hold->getId()} into its posted copy {$copy->getId()}: {$e->getMessage()}", ['app' => 'budget']);
			return false;
		}
		$takenIds[$copy->getId()] = true;

		try {
			$this->transactionService->reconcilePendingToPosted($hold, $copy->getImportId(), $copy->getDate(), [
				'amount' => (float)$copy->getAmount(),
				'type' => $copy->getType(),
				'description' => $copy->getDescription(),
				'vendor' => $copy->getVendor(),
			]);
		} catch (\Exception $e) {
			// The copy's import id is free again, so the next sync imports
			// it afresh and the hold goes through the cleanup once more
			$this->logger->warning("Bank sync: could not post hold {$hold->getId()} as its copy: {$e->getMessage()}", ['app' => 'budget']);
		}
		return true;
	}

	/**
	 * The posted copy of a bill-paying hold that the bank has stopped listing,
	 * among the posted rows it does list: a row imported separately while the
	 * hold was still listed beside it. Only a row imported after the hold
	 * qualifies (an older one is an earlier payment), and only a plain one:
	 * the copy is deleted when the hold takes it over, so a row with its own
	 * bill, transfer, pension, split or reconciliation is left alone.
	 *
	 * @param \OCA\Budget\Db\Transaction[] $postedInFeed
	 * @param array<int,bool> $takenIds
	 */
	private function findPostedCopy(\OCA\Budget\Db\Transaction $hold, array $postedInFeed, array $takenIds): ?\OCA\Budget\Db\Transaction {
		$best = null;
		$bestRank = null;
		foreach ($postedInFeed as $row) {
			if (isset($takenIds[$row->getId()])
				|| $row->getBillId() !== null
				|| $row->getLinkedTransactionId() !== null
				|| $row->getPensionContribId() !== null
				|| $row->getReconciled()
				|| $row->getIsSplit()
				|| (string)$row->getCreatedAt() < (string)$hold->getCreatedAt()) {
				continue;
			}
			$rank = $this->postedVersionRank($hold, [
				'amount' => ($row->getType() === 'debit' ? -1 : 1) * (float)$row->getAmount(),
				'date' => $row->getDate(),
				'description' => $row->getDescription(),
				'vendor' => $row->getVendor(),
			]);
			if ($rank !== null && ($bestRank === null || ($rank <=> $bestRank) < 0)) {
				$best = $row;
				$bestRank = $rank;
			}
		}
		return $best;
	}

	/** Card-network and banking filler that says nothing about the merchant. */
	private const DESCRIPTION_NOISE = [
		'PENDING', 'CARD', 'DEBIT', 'CREDIT', 'PAYMENT', 'PURCHASE', 'AUTH',
		'AUTHORIZATION', 'AUTHORISATION', 'VISA', 'MASTERCARD', 'AMEX',
		'TRANSACTION', 'CONTACTLESS', 'ONLINE', 'DIRECT', 'TRANSFER',
		'RECURRING', 'FROM', 'WITH', 'PAYPAL', 'CHECKCARD', 'HTTP', 'HTTPS',
	];

	/**
	 * Whether two bank descriptions name the same merchant: a word of four or
	 * more letters from one appears in the other, ignoring filler and
	 * numbers. Spacing and punctuation are ignored on the other side, because
	 * a hold's text and its posted text are often run together differently
	 * ("ACME*ENERGY" against "ACMEENERGY DD").
	 */
	private static function descriptionsShareAWord(string $a, string $b): bool {
		$words = static function (string $text): array {
			$parts = preg_split('/[^A-Z0-9]+/', strtoupper($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			return array_filter($parts, static fn (string $w) => strlen($w) >= 4
				&& !ctype_digit($w)
				&& !in_array($w, self::DESCRIPTION_NOISE, true));
		};
		$compactA = preg_replace('/[^A-Z0-9]/', '', strtoupper($a));
		$compactB = preg_replace('/[^A-Z0-9]/', '', strtoupper($b));

		foreach ($words($a) as $word) {
			if (str_contains($compactB, $word)) {
				return true;
			}
		}
		foreach ($words($b) as $word) {
			if (str_contains($compactA, $word)) {
				return true;
			}
		}
		return false;
	}

	private function requireEnabled(): void {
		if (!$this->adminSettings->isBankSyncEnabled()) {
			throw new \Exception('Bank sync is disabled by the administrator');
		}
	}
}
