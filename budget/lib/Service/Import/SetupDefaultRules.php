<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Db\ImportRule;

/**
 * The empty import rules "Create default categories" made before 3.0.
 *
 * Setup made six rules (Grocery Stores, Gas Stations, ...) with no category
 * and no other action. A rule with nothing to do still matched first and
 * stopped the rules after it, so a description containing "shell", "amazon"
 * or "cash" was never categorised by the user's own rules. Such a rule, while
 * it is still exactly what setup made, is switched off and moved to the
 * lowest priority: by migration 122 on upgrade, and by a backup restore,
 * which brings back a pre-3.0 archive's rules as they were (V2-1). Rules the
 * user changed in any way are left alone.
 */
final class SetupDefaultRules {
	/** The rules setup made, name => pattern, exactly as it wrote them */
	public const RULES = [
		'Grocery Stores' => 'grocery|supermarket|safeway|kroger|trader joe|whole foods',
		'Gas Stations' => 'gas|fuel|shell|chevron|exxon|bp|mobil',
		'Restaurants' => 'restaurant|cafe|coffee|starbucks|mcdonald|burger',
		'Online Shopping' => 'amazon|ebay|paypal|stripe',
		'Utilities' => 'electric|water|gas|utility|power|energy',
		'ATM Withdrawals' => 'ATM|withdrawal|cash',
	];

	/** The priority setup gave each of them (unchanged since 2.0) */
	public const PRIORITIES = [
		'Grocery Stores' => 10,
		'Gas Stations' => 10,
		'Restaurants' => 8,
		'Online Shopping' => 5,
		'Utilities' => 9,
		'ATM Withdrawals' => 7,
	];

	/** Where a switched-off default goes: the lowest priority there is */
	public const RETIRED_PRIORITY = 0;

	/**
	 * Whether a stored rule is one setup made, unchanged, with no action.
	 *
	 * Opening such a rule in the rule editor converted it to the newer
	 * format (criteria + an empty action list); that still counts as
	 * unchanged. Any category, vendor, action, other criteria, priority,
	 * flag or group means the user made it theirs, and it is left alone.
	 *
	 * @param array<string, mixed> $row A budget_import_rules row
	 */
	public static function isUntouchedEmptyDefault(array $row): bool {
		$name = (string)($row['name'] ?? '');
		$pattern = self::RULES[$name] ?? null;
		if ($pattern === null || (string)($row['pattern'] ?? '') !== $pattern) {
			return false;
		}
		if (!array_key_exists('priority', $row) || (int)$row['priority'] !== self::PRIORITIES[$name]) {
			return false;
		}
		if (!self::isTrueOrUnset($row['stop_processing'] ?? null)
			|| !self::isTrueOrUnset($row['apply_on_import'] ?? null)
			|| (string)($row['group_name'] ?? '') !== '') {
			return false;
		}
		if (($row['field'] ?? 'description') !== 'description' || ($row['match_type'] ?? '') !== 'regex') {
			return false;
		}
		if (($row['category_id'] ?? null) !== null || (string)($row['vendor_name'] ?? '') !== '') {
			return false;
		}

		return self::hasNoActions($row['actions'] ?? null)
			&& self::isDefaultCriteria($row['criteria'] ?? null, $pattern);
	}

	/**
	 * isUntouchedEmptyDefault() for a rule that is not stored yet, as a
	 * restore builds it.
	 */
	public static function isUntouchedEmptyDefaultRule(ImportRule $rule): bool {
		return self::isUntouchedEmptyDefault([
			'name' => $rule->getName(),
			'pattern' => $rule->getPattern(),
			'priority' => $rule->getPriority(),
			'stop_processing' => $rule->getStopProcessing(),
			'apply_on_import' => $rule->getApplyOnImport(),
			'group_name' => $rule->getGroupName(),
			'field' => $rule->getField(),
			'match_type' => $rule->getMatchType(),
			'category_id' => $rule->getCategoryId(),
			'vendor_name' => $rule->getVendorName(),
			'actions' => $rule->getActions(),
			'criteria' => $rule->getCriteria(),
		]);
	}

	/**
	 * Switch such a rule off and move it to the lowest priority, so that if
	 * it is switched back on it can't block the user's own rules either.
	 */
	public static function retire(ImportRule $rule): void {
		$rule->setActive(false);
		$rule->setPriority(self::RETIRED_PRIORITY);
	}

	/**
	 * A boolean column as setup left it: on, or never set (both default on).
	 * Databases hand booleans back as true, 1, '1' or 't'.
	 */
	private static function isTrueOrUnset(mixed $value): bool {
		return $value === null || in_array($value, [true, 1, '1', 't', 'true'], true);
	}

	private static function hasNoActions(mixed $json): bool {
		if ($json === null || $json === '') {
			return true;
		}
		$actions = json_decode((string)$json, true);
		if (!is_array($actions)) {
			// Unreadable: the rule falls back to its (empty) legacy columns
			return true;
		}
		if (($actions['categoryId'] ?? null) !== null
			|| (string)($actions['vendor'] ?? '') !== ''
			|| (string)($actions['notes'] ?? '') !== '') {
			return false;
		}
		return empty($actions['actions']);
	}

	private static function isDefaultCriteria(mixed $json, string $pattern): bool {
		if ($json === null || $json === '') {
			return true;
		}
		$criteria = json_decode((string)$json, true);
		$conditions = $criteria['root']['conditions'] ?? null;
		if (!is_array($conditions) || count($conditions) !== 1) {
			return false;
		}
		$condition = $conditions[0];
		return is_array($condition)
			&& ($condition['type'] ?? null) === 'condition'
			&& ($condition['field'] ?? null) === 'description'
			&& ($condition['matchType'] ?? null) === 'regex'
			&& ($condition['pattern'] ?? null) === $pattern
			&& empty($condition['negate']);
	}
}
