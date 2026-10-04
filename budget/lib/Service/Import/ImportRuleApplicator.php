<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\GranularShareService;
use Psr\Log\LoggerInterface;

/**
 * Applies import rules to automatically categorize and tag transactions during import.
 * Uses v2 schema: CriteriaEvaluator for matching and JSON actions for application.
 */
class ImportRuleApplicator {
	private ImportRuleMapper $importRuleMapper;
	private CriteriaEvaluator $criteriaEvaluator;
	private GranularShareService $granularShareService;
	private ?LoggerInterface $logger;

	public function __construct(
		ImportRuleMapper $importRuleMapper,
		CriteriaEvaluator $criteriaEvaluator,
		GranularShareService $granularShareService,
		?LoggerInterface $logger = null,
	) {
		$this->importRuleMapper = $importRuleMapper;
		$this->criteriaEvaluator = $criteriaEvaluator;
		$this->granularShareService = $granularShareService;
		$this->logger = $logger;
	}

	/**
	 * Active rules that apply during a user's import: their own plus rules
	 * shared with them. Sorted by priority DESC.
	 *
	 * @return ImportRule[]
	 */
	private function activeRulesFor(string $userId): array {
		$own = $this->importRuleMapper->findActive($userId);
		$sharedIds = $this->granularShareService->getSharedImportRuleIds($userId);
		$shared = $this->importRuleMapper->findActiveByIds($sharedIds);

		$all = array_merge($own, $shared);
		usort($all, fn (ImportRule $a, ImportRule $b) => $b->getPriority() - $a->getPriority());
		return $all;
	}

	/**
	 * The rules an import of $userId's runs, to load once and hand to
	 * applyRules() for every row: loading them per row cost a query plus
	 * hydrating and sorting every rule, for each row of the file (T6-4).
	 *
	 * @return ImportRule[]
	 */
	public function rulesFor(string $userId): array {
		return $this->activeRulesFor($userId);
	}

	/**
	 * Apply matching rules to a single transaction.
	 *
	 * @param string $userId The user ID
	 * @param array $transaction Transaction data
	 * @param ImportRule[]|null $rules From rulesFor(); loaded here when not given
	 * @return array Transaction data with rules applied
	 */
	public function applyRules(string $userId, array $transaction, ?array $rules = null): array {
		$rules ??= $this->activeRulesFor($userId);

		foreach ($rules as $rule) {
			// Skip rules not meant for import
			if ($rule->getApplyOnImport() === false) {
				continue;
			}

			// Match using CriteriaEvaluator
			$criteria = $rule->getCriteria();
			$schemaVersion = $rule->getSchemaVersion() ?? 2;

			if (!$this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
				continue;
			}

			// Apply v2 actions
			$transaction = $this->applyActions($rule, $transaction, $userId);

			// Track which rule was applied
			$transaction['appliedRule'] = [
				'id' => $rule->getId(),
				'name' => $rule->getName(),
			];

			// Respect stopProcessing flag
			if ($rule->getStopProcessing() ?? true) {
				break;
			}
		}

		return $transaction;
	}

	/**
	 * Apply rules to multiple transactions.
	 *
	 * @param string $userId The user ID
	 * @param array $transactions List of transactions
	 * @return array List of transactions with rules applied
	 */
	public function applyRulesToMany(string $userId, array $transactions): array {
		return array_map(
			fn ($transaction) => $this->applyRules($userId, $transaction),
			$transactions
		);
	}

	/**
	 * Preview rule applications without modifying transactions.
	 *
	 * @param string $userId The user ID
	 * @param array $transactions List of transactions
	 * @return array List of rule matches with transaction indices
	 */
	public function previewRuleApplications(string $userId, array $transactions): array {
		$rules = $this->activeRulesFor($userId);
		$previews = [];

		foreach ($transactions as $index => $transaction) {
			foreach ($rules as $rule) {
				if ($rule->getApplyOnImport() === false) {
					continue;
				}

				$criteria = $rule->getCriteria();
				$schemaVersion = $rule->getSchemaVersion() ?? 2;

				if ($this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
					$previews[] = [
						'transactionIndex' => $index,
						'transaction' => $transaction,
						'rule' => [
							'id' => $rule->getId(),
							'name' => $rule->getName(),
						],
					];
					break; // First matching rule per transaction
				}
			}
		}

		return $previews;
	}

	/**
	 * Get statistics about rule matches for a set of transactions.
	 *
	 * @param string $userId The user ID
	 * @param array $transactions List of transactions
	 * @return array Statistics about rule matches
	 */
	public function getMatchStatistics(string $userId, array $transactions): array {
		$rules = $this->activeRulesFor($userId);
		$matched = 0;
		$unmatched = 0;
		$ruleUsage = [];

		foreach ($transactions as $transaction) {
			$found = false;
			foreach ($rules as $rule) {
				if ($rule->getApplyOnImport() === false) {
					continue;
				}

				$criteria = $rule->getCriteria();
				$schemaVersion = $rule->getSchemaVersion() ?? 2;

				if ($this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
					$matched++;
					$ruleId = $rule->getId();
					$ruleUsage[$ruleId] = ($ruleUsage[$ruleId] ?? 0) + 1;
					$found = true;
					break;
				}
			}

			if (!$found) {
				$unmatched++;
			}
		}

		return [
			'total' => count($transactions),
			'matched' => $matched,
			'unmatched' => $unmatched,
			'matchRate' => count($transactions) > 0 ? $matched / count($transactions) : 0,
			'ruleUsage' => $ruleUsage,
		];
	}

	/**
	 * Extract and apply v2 actions from a rule to a transaction array.
	 *
	 * @param string $userId The user performing the import (may differ from the
	 *                       rule owner when the rule was shared with them).
	 */
	private function applyActions(ImportRule $rule, array $transaction, string $userId): array {
		$actions = $rule->getParsedActions();
		$actionList = [];

		if (isset($actions['version']) && $actions['version'] === 2) {
			$actionList = $actions['actions'] ?? [];
		} elseif (isset($actions['actions'])) {
			$actionList = $actions['actions'];
		}

		// Sort by priority (higher first)
		usort($actionList, fn ($a, $b) => ($b['priority'] ?? 50) - ($a['priority'] ?? 50));

		foreach ($actionList as $action) {
			$type = $action['type'] ?? null;
			$value = $action['value'] ?? null;
			$behavior = $action['behavior'] ?? 'always';

			if ($type === null) {
				continue;
			}

			switch ($type) {
				case 'set_category':
					if ($this->shouldApply($behavior, $transaction['categoryId'] ?? null)) {
						// The row lands in the importer's ledger, so the category
						// must be one they can use (own, or shared with them),
						// whoever's rule it is: an unchecked id from an own rule
						// put another user's category, and its name, on the row
						// (R6-4). Otherwise the action is skipped.
						if ($this->granularShareService->canAccess($userId, ShareItem::TYPE_CATEGORY, (int)$value)) {
							$transaction['categoryId'] = (int)$value;
						}
					}
					break;

				case 'set_vendor':
					if ($this->shouldApply($behavior, $transaction['vendor'] ?? null)) {
						$transaction['vendor'] = $value;
					}
					break;

				case 'set_description':
					if ($this->shouldApply($behavior, $transaction['description'] ?? null)) {
						$transaction['description'] = (string)$value;
					}
					break;

				case 'set_notes':
					$existing = $transaction['notes'] ?? null;
					if ($behavior === 'append' && $existing) {
						$separator = $action['separator'] ?? ' ';
						$transaction['notes'] = $existing . $separator . $value;
					} elseif ($this->shouldApply($behavior, $existing)) {
						$transaction['notes'] = $value;
					}
					break;

				case 'set_type':
					// Map user-facing terms to internal DB values: income->credit, expense->debit
					$typeMap = ['income' => 'credit', 'expense' => 'debit'];
					if (isset($typeMap[$value])
						&& $this->shouldApply($behavior, $transaction['type'] ?? null)) {
						$transaction['type'] = $typeMap[$value];
						// The rule answered what the type column couldn't, so
						// the preview shouldn't still flag this row (#333)
						unset($transaction['_typeUnresolved']);
					}
					break;

				case 'set_reference':
					if ($this->shouldApply($behavior, $transaction['reference'] ?? null)) {
						$transaction['reference'] = $value;
					}
					break;

				case 'regex_replace':
					$sourceField = $action['field'] ?? 'description';
					$targetField = $action['target'] ?? $sourceField;
					$pattern = $action['pattern'] ?? null;
					$replacement = $action['replacement'] ?? '';
					if (!in_array($sourceField, ['description', 'vendor', 'reference', 'notes'], true)
						|| !in_array($targetField, ['description', 'vendor', 'reference', 'notes'], true)
						|| !is_string($pattern) || !is_string($replacement)) {
						break;
					}
					$current = $transaction[$sourceField] ?? null;
					if ($pattern === '' || !is_string($current)) {
						break;
					}
					$normalizedPattern = RegexPattern::forReplace($pattern);
					if ($normalizedPattern === null) {
						break;
					}
					$targetCurrent = $transaction[$targetField] ?? null;
					if ($this->shouldApply($behavior, $targetCurrent)) {
						$updated = @preg_replace($normalizedPattern, $replacement, $current, -1, $matchCount);
						// No match means preg_replace handed back the source unchanged;
						// writing that into a different target would copy it verbatim.
						if ($updated === null || $matchCount === 0) {
							break;
						}
						// Text that is not UTF-8 breaks every list it appears in
						if (!mb_check_encoding($updated, 'UTF-8')) {
							$this->logger?->warning('Regex replace skipped: the result is not valid UTF-8', [
								'app' => 'budget',
								'ruleId' => $rule->getId(),
							]);
							break;
						}
						$transaction[$targetField] = $updated;
					}
					break;

				case 'change_case':
					$field = $action['field'] ?? 'description';
					$mode = $action['mode'] ?? 'upper';
					$current = $transaction[$field] ?? null;
					if (!in_array($field, ['description', 'vendor', 'reference', 'notes'], true)
						|| ($current !== null && !is_string($current))
						|| !in_array($mode, ['upper', 'lower', 'title', 'sentence'], true)) {
						break;
					}
					$current ??= '';
					$updated = match ($mode) {
						'upper' => mb_strtoupper($current, 'UTF-8'),
						'lower' => mb_strtolower($current, 'UTF-8'),
						'title' => mb_convert_case($current, MB_CASE_TITLE, 'UTF-8'),
						'sentence' => mb_strtoupper(mb_substr($current, 0, 1, 'UTF-8'), 'UTF-8') . mb_strtolower(mb_substr($current, 1, null, 'UTF-8'), 'UTF-8'),
					};
					$transaction[$field] = $updated;
					break;

				case 'replace_text':
					$field = $action['field'] ?? 'description';
					$find = $action['find'] ?? '';
					$replace = $action['replace'] ?? '';
					$current = $transaction[$field] ?? null;
					if (!in_array($field, ['description', 'vendor', 'reference', 'notes'], true)
						|| !is_string($find) || $find === '' || !is_string($replace)
						|| ($current !== null && !is_string($current))) {
						break;
					}
					$current ??= '';
					$transaction[$field] = str_replace($find, $replace, $current);
					break;

				case 'add_tags':
					// Store tag actions for deferred application after transaction is persisted
					if (!isset($transaction['_deferred_tags'])) {
						$transaction['_deferred_tags'] = [];
					}
					$transaction['_deferred_tags'][] = [
						'tagIds' => $value,
						'behavior' => $behavior,
					];
					break;

				case 'link_transfer':
					// Flag for deferred transfer linking after all transactions are persisted
					$transaction['_deferred_link_transfer'] = true;
					break;

				case 'set_forecast_exclude':
					// Mark extraordinary/one-time items so they don't skew the
					// forecast (#270). Value defaults to true when omitted.
					$transaction['excludedFromForecast'] = ($value === null)
						? true
						: filter_var($value, FILTER_VALIDATE_BOOLEAN);
					break;

					// set_account: skip during import (account is set by the import target)
			}
		}

		return $transaction;
	}

	/**
	 * Check if an action should be applied based on its behavior.
	 */
	private function shouldApply(string $behavior, $currentValue): bool {
		if ($behavior === 'if_empty') {
			return $currentValue === null || $currentValue === '';
		}
		return true;
	}
}
