<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Service\TransactionTagService;
use Psr\Log\LoggerInterface;

/**
 * Applies actions from import rules to transactions.
 *
 * Supports:
 * - Multiple action types (category, vendor, notes, tags, account, type, reference)
 * - Conflict resolution via priority and behavior settings
 * - Action behaviors (always, if_empty, append, merge)
 * - Stop processing control
 * - Entity validation
 */
class RuleActionApplicator {

	private TransactionTagService $transactionTagService;
	private CategoryMapper $categoryMapper;
	private AccountMapper $accountMapper;
	private LoggerInterface $logger;

	/** Maximum actions per rule to prevent abuse */
	private const MAX_ACTIONS = 20;

	public function __construct(
		TransactionTagService $transactionTagService,
		CategoryMapper $categoryMapper,
		AccountMapper $accountMapper,
		LoggerInterface $logger
	) {
		$this->transactionTagService = $transactionTagService;
		$this->categoryMapper = $categoryMapper;
		$this->accountMapper = $accountMapper;
		$this->logger = $logger;
	}

	/**
	 * Apply all matching rules to a transaction.
	 * Handles multiple rule matches with conflict resolution.
	 *
	 * @param Transaction $transaction Transaction to modify
	 * @param ImportRule[] $matchingRules Rules sorted by priority DESC
	 * @param string $userId User ID for validation
	 * @return array Changes made ['field' => 'old_value' => 'new_value']
	 */
	public function applyRules(Transaction $transaction, array $matchingRules, string $userId): array {
		$changes = [];
		$appliedActions = []; // Track what's been applied for conflict resolution

		foreach ($matchingRules as $rule) {
			$actions = $rule->getParsedActions();

			// Get actions array based on format
			if (isset($actions['version']) && $actions['version'] === 2) {
				// v2 format: {"version": 2, "actions": [...], "stopProcessing": true}
				$actionList = $actions['actions'] ?? [];
			} elseif (isset($actions['actions'])) {
				// v2 format without version field
				$actionList = $actions['actions'];
			} else {
				// Legacy v1 format: {"categoryId": 5, "vendor": "X", "notes": "Y"}
				$actionList = $this->convertLegacyActions($actions);
			}

			// Apply this rule's actions
			$this->applyRuleActions($transaction, $actionList, $userId, $appliedActions, $changes);

			// Check stop_processing flag (v2) or default to true for v1
			$stopProcessing = $rule->getStopProcessing() ?? true;
			if ($stopProcessing) {
				break; // Don't process more rules
			}
		}

		return $changes;
	}

	/**
	 * Convert legacy v1 actions to v2 action format.
	 *
	 * @param array $legacyActions Legacy format: {categoryId, vendor, notes}
	 * @return array v2 format: [{type, value, behavior, priority}, ...]
	 */
	private function convertLegacyActions(array $legacyActions): array {
		$actions = [];

		if (isset($legacyActions['categoryId']) && $legacyActions['categoryId'] !== null) {
			$actions[] = [
				'type' => 'set_category',
				'value' => $legacyActions['categoryId'],
				'behavior' => 'always',
				'priority' => 100
			];
		}

		if (isset($legacyActions['vendor']) && $legacyActions['vendor'] !== null && $legacyActions['vendor'] !== '') {
			$actions[] = [
				'type' => 'set_vendor',
				'value' => $legacyActions['vendor'],
				'behavior' => 'always',
				'priority' => 90
			];
		}

		if (isset($legacyActions['notes']) && $legacyActions['notes'] !== null && $legacyActions['notes'] !== '') {
			$actions[] = [
				'type' => 'set_notes',
				'value' => $legacyActions['notes'],
				'behavior' => 'always',
				'priority' => 80
			];
		}

		return $actions;
	}

	/**
	 * Apply actions from a single rule.
	 *
	 * @param Transaction $transaction Transaction to modify
	 * @param array $actionList List of actions
	 * @param string $userId User ID
	 * @param array &$appliedActions Tracking array for conflict resolution
	 * @param array &$changes Changes tracking array
	 */
	private function applyRuleActions(
		Transaction $transaction,
		array $actionList,
		string $userId,
		array &$appliedActions,
		array &$changes
	): void {
		// Sort actions by priority (higher first)
		usort($actionList, function ($a, $b) {
			$aPriority = $a['priority'] ?? 50;
			$bPriority = $b['priority'] ?? 50;
			return $bPriority - $aPriority;
		});

		foreach ($actionList as $action) {
			try {
				$this->applyAction($transaction, $action, $userId, $appliedActions, $changes);
			} catch (\Exception $e) {
				$this->logger->error('Failed to apply action', [
					'action' => $action,
					'error' => $e->getMessage()
				]);
			}
		}
	}

	/**
	 * Apply a single action to a transaction.
	 *
	 * @param Transaction $transaction Transaction to modify
	 * @param array $action Action definition
	 * @param string $userId User ID
	 * @param array &$appliedActions Tracking array
	 * @param array &$changes Changes tracking array
	 */
	private function applyAction(
		Transaction $transaction,
		array $action,
		string $userId,
		array &$appliedActions,
		array &$changes
	): void {
		$type = $action['type'] ?? null;
		$value = $action['value'] ?? null;
		$behavior = $action['behavior'] ?? 'always';
		$priority = $action['priority'] ?? 50;

		if (!$type) {
			return;
		}

		switch ($type) {
			case 'set_category':
				if ($this->shouldApply($type, $behavior, $transaction->getCategoryId(), $appliedActions)) {
					// Validate category exists
					try {
						$this->categoryMapper->find((int)$value, $userId);
						$oldValue = $transaction->getCategoryId();
						$transaction->setCategoryId((int)$value);
						$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
						$changes['category'] = ['old' => $oldValue, 'new' => $value];
					} catch (\Exception $e) {
						$this->logger->warning('Invalid category reference in rule action', [
							'categoryId' => $value,
							'error' => $e->getMessage()
						]);
					}
				}
				break;

			case 'set_vendor':
				if ($this->shouldApply($type, $behavior, $transaction->getVendor(), $appliedActions)) {
					$oldValue = $transaction->getVendor();
					$transaction->setVendor($value);
					$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
					$changes['vendor'] = ['old' => $oldValue, 'new' => $value];
				}
				break;

			case 'set_notes':
				if ($behavior === 'append') {
					// Append to existing notes
					$existing = $transaction->getNotes() ?? '';
					$separator = $action['separator'] ?? ' ';
					$newNotes = $existing ? $existing . $separator . $value : $value;
					$transaction->setNotes($newNotes);
					$appliedActions[$type] = ['priority' => $priority, 'behavior' => 'append'];
					$changes['notes'] = ['old' => $existing, 'new' => $newNotes];
				} elseif ($this->shouldApply($type, $behavior, $transaction->getNotes(), $appliedActions)) {
					// Replace notes
					$oldValue = $transaction->getNotes();
					$transaction->setNotes($value);
					$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
					$changes['notes'] = ['old' => $oldValue, 'new' => $value];
				}
				break;

			case 'add_tags':
				// Tags use transaction ID, so we need it to be persisted first
				// This will be called after transaction is saved
				// Store tag action for deferred execution
				if (!isset($appliedActions['_deferred_tags'])) {
					$appliedActions['_deferred_tags'] = [];
				}
				$appliedActions['_deferred_tags'][] = [
					'tagIds' => $value,
					'behavior' => $behavior
				];
				break;

			case 'set_account':
				if ($this->shouldApply($type, $behavior, $transaction->getAccountId(), $appliedActions)) {
					// Validate account exists
					try {
						$this->accountMapper->find((int)$value, $userId);
						$oldValue = $transaction->getAccountId();
						$transaction->setAccountId((int)$value);
						$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
						$changes['account'] = ['old' => $oldValue, 'new' => $value];
					} catch (\Exception $e) {
						$this->logger->warning('Invalid account reference in rule action', [
							'accountId' => $value,
							'error' => $e->getMessage()
						]);
					}
				}
				break;

			case 'set_type':
				if ($this->shouldApply($type, $behavior, $transaction->getType(), $appliedActions)) {
					// Validate type is 'income' or 'expense'
					if (in_array($value, ['income', 'expense'], true)) {
						$oldValue = $transaction->getType();
						$transaction->setType($value);
						$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
						$changes['type'] = ['old' => $oldValue, 'new' => $value];
					} else {
						$this->logger->warning('Invalid transaction type in rule action', ['type' => $value]);
					}
				}
				break;

			case 'set_reference':
				if ($this->shouldApply($type, $behavior, $transaction->getReference(), $appliedActions)) {
					$oldValue = $transaction->getReference();
					$transaction->setReference($value);
					$appliedActions[$type] = ['priority' => $priority, 'value' => $value];
					$changes['reference'] = ['old' => $oldValue, 'new' => $value];
				}
				break;

			default:
				$this->logger->warning('Unknown action type', ['type' => $type]);
		}
	}

	/**
	 * Determine if an action should be applied based on behavior and existing actions.
	 *
	 * @param string $type Action type
	 * @param string $behavior Behavior (always, if_empty)
	 * @param mixed $currentValue Current field value
	 * @param array $appliedActions Tracking array
	 * @return bool Whether to apply the action
	 */
	private function shouldApply(string $type, string $behavior, $currentValue, array $appliedActions): bool {
		// Check if another rule already set this field (higher priority)
		if (isset($appliedActions[$type])) {
			// Conflict: already applied by higher-priority rule
			return false;
		}

		// Check behavior
		if ($behavior === 'if_empty') {
			// Only apply if field is null or empty string
			return $currentValue === null || $currentValue === '';
		}

		// 'always' or 'replace' - always apply
		return true;
	}

	/**
	 * Apply deferred tag actions after transaction is persisted.
	 *
	 * @param Transaction $transaction Persisted transaction
	 * @param array $appliedActions Actions tracking array
	 * @param string $userId User ID
	 * @param array &$changes Changes tracking array
	 */
	public function applyDeferredTagActions(Transaction $transaction, array $appliedActions, string $userId, array &$changes): void {
		if (!isset($appliedActions['_deferred_tags']) || empty($appliedActions['_deferred_tags'])) {
			return;
		}

		try {
			// Get existing tags
			$existingTags = $this->transactionTagService->getTransactionTags($transaction->getId(), $userId);
			$existingTagIds = array_column($existingTags, 'tagId');

			$finalTagIds = $existingTagIds;

			// Apply each tag action
			foreach ($appliedActions['_deferred_tags'] as $tagAction) {
				$newTagIds = $tagAction['tagIds'] ?? [];
				$behavior = $tagAction['behavior'] ?? 'merge';

				if ($behavior === 'merge') {
					// Merge with existing tags (union)
					$finalTagIds = array_unique(array_merge($finalTagIds, $newTagIds));
				} else {
					// Replace all tags
					$finalTagIds = $newTagIds;
				}
			}

			// Set final tags
			$this->transactionTagService->setTransactionTags($transaction->getId(), $userId, $finalTagIds);
			$changes['tags'] = ['old' => $existingTagIds, 'new' => $finalTagIds];
		} catch (\Exception $e) {
			$this->logger->error('Failed to apply tag actions', [
				'transactionId' => $transaction->getId(),
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Validate actions array structure and referenced entities.
	 *
	 * @param array $actions Actions array
	 * @param string $userId User ID
	 * @return array ['valid' => bool, 'errors' => string[]]
	 */
	public function validateActions(array $actions, string $userId): array {
		$errors = [];

		// Get action list from different formats
		if (isset($actions['version']) && $actions['version'] === 2) {
			$actionList = $actions['actions'] ?? [];
		} elseif (isset($actions['actions'])) {
			$actionList = $actions['actions'];
		} else {
			// Legacy format - no validation needed
			return ['valid' => true, 'errors' => []];
		}

		// Check action count
		if (count($actionList) > self::MAX_ACTIONS) {
			$errors[] = 'Too many actions (max ' . self::MAX_ACTIONS . ')';
		}

		foreach ($actionList as $idx => $action) {
			$type = $action['type'] ?? null;
			$value = $action['value'] ?? null;

			if (!$type) {
				$errors[] = "Action $idx: missing type";
				continue;
			}

			// Validate action-specific requirements
			switch ($type) {
				case 'set_category':
					if ($value !== null) {
						try {
							$this->categoryMapper->find((int)$value, $userId);
						} catch (\Exception $e) {
							$errors[] = "Action $idx: invalid category ID $value";
						}
					}
					break;

				case 'set_account':
					if ($value !== null) {
						try {
							$this->accountMapper->find((int)$value, $userId);
						} catch (\Exception $e) {
							$errors[] = "Action $idx: invalid account ID $value";
						}
					}
					break;

				case 'set_type':
					if (!in_array($value, ['income', 'expense'], true)) {
						$errors[] = "Action $idx: invalid transaction type '$value'";
					}
					break;

				case 'add_tags':
					if (!is_array($value)) {
						$errors[] = "Action $idx: tags must be an array";
					}
					break;

				case 'set_vendor':
				case 'set_notes':
				case 'set_reference':
					// String values - no specific validation
					break;

				default:
					$errors[] = "Action $idx: unknown action type '$type'";
			}
		}

		return [
			'valid' => empty($errors),
			'errors' => $errors
		];
	}
}
