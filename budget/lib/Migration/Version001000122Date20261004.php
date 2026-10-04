<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Switch off the empty import rules "Create default categories" used to add.
 *
 * Setup made six rules (Grocery Stores, Gas Stations, ...) with no category
 * and no other action. A rule with nothing to do still matches first and
 * stops the rules after it, so a description containing "shell", "amazon" or
 * "cash" was never categorised by the user's own rules, on import or in Run
 * rules. Setup now gives each rule its category; this deactivates the ones it
 * already made, but only while they are still exactly what setup made and
 * still have no action. Nothing is deleted: the user can switch a rule back on
 * or remove it.
 */
class Version001000122Date20261004 extends SimpleMigrationStep {
	/** The rules setup made, name => pattern, exactly as it wrote them */
	public const DEFAULT_RULES = [
		'Grocery Stores' => 'grocery|supermarket|safeway|kroger|trader joe|whole foods',
		'Gas Stations' => 'gas|fuel|shell|chevron|exxon|bp|mobil',
		'Restaurants' => 'restaurant|cafe|coffee|starbucks|mcdonald|burger',
		'Online Shopping' => 'amazon|ebay|paypal|stripe',
		'Utilities' => 'electric|water|gas|utility|power|energy',
		'ATM Withdrawals' => 'ATM|withdrawal|cash',
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_import_rules')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'pattern', 'field', 'match_type', 'category_id', 'vendor_name', 'actions', 'criteria')
			->from('budget_import_rules')
			->where($qb->expr()->in('name', $qb->createNamedParameter(array_keys(self::DEFAULT_RULES), IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			if (self::isUntouchedEmptyDefault($row)) {
				$ids[] = (int)$row['id'];
			}
		}
		$result->closeCursor();

		$now = date('Y-m-d H:i:s');
		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_import_rules')
				->set('active', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->in('id', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->executeStatement();
		}

		if ($ids !== []) {
			$output->info('Switched off ' . count($ids) . ' default import rule(s) that had no action');
		}
	}

	/**
	 * Whether a stored rule is one setup made, unchanged, with no action.
	 *
	 * Opening such a rule in the rule editor converted it to the newer
	 * format (criteria + an empty action list); that still counts as
	 * unchanged. Any category, vendor, action or other criteria means the
	 * user made it theirs, and it is left alone.
	 *
	 * @param array<string, mixed> $row A budget_import_rules row
	 */
	public static function isUntouchedEmptyDefault(array $row): bool {
		$name = (string)($row['name'] ?? '');
		$pattern = self::DEFAULT_RULES[$name] ?? null;
		if ($pattern === null || (string)($row['pattern'] ?? '') !== $pattern) {
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
