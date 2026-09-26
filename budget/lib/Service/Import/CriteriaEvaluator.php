<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use Psr\Log\LoggerInterface;

/**
 * Evaluates complex boolean expression trees for import rule matching.
 *
 * Supports:
 * - Nested boolean operators (AND/OR)
 * - Negation (NOT)
 * - Multiple match types (string, numeric, date)
 * - Short-circuit evaluation for performance
 * - Legacy v1 format fallback
 */
class CriteriaEvaluator {

	private LoggerInterface $logger;

	/** Maximum allowed nesting depth to prevent stack overflow */
	private const MAX_DEPTH = 5;

	/** Valid string match types */
	private const STRING_MATCH_TYPES = ['contains', 'starts_with', 'ends_with', 'equals', 'regex'];

	/** Valid numeric match types */
	private const NUMERIC_MATCH_TYPES = ['equals', 'greater_than', 'less_than', 'between'];

	/** Valid date match types */
	private const DATE_MATCH_TYPES = ['equals', 'before', 'after', 'between'];

	/** Valid account match types (exact account id only) */
	private const ACCOUNT_MATCH_TYPES = ['equals'];

	/** Valid fields for matching */
	private const VALID_FIELDS = ['description', 'vendor', 'reference', 'notes', 'amount', 'date', 'type', 'account', 'account_type', 'source'];

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	/**
	 * Evaluate criteria tree against transaction data.
	 *
	 * @param array|string|null $criteria Criteria tree, legacy pattern, or null
	 * @param array $transactionData Transaction fields
	 * @param int $schemaVersion 1=legacy, 2=complex
	 * @return bool Match result
	 */
	public function evaluate($criteria, array $transactionData, int $schemaVersion = 2): bool {
		// Handle null/empty criteria
		if ($criteria === null || $criteria === '') {
			return false;
		}

		// Handle legacy format (schema_version=1)
		if ($schemaVersion === 1) {
			return $this->evaluateLegacy($criteria, $transactionData);
		}

		// Parse JSON if string
		if (is_string($criteria)) {
			$criteria = json_decode($criteria, true);
			if ($criteria === null) {
				$this->logger->error('Failed to parse criteria JSON', ['criteria' => $criteria]);
				return false;
			}
		}

		// Validate structure
		if (!isset($criteria['root'])) {
			$this->logger->error('Invalid criteria structure: missing root', ['criteria' => $criteria]);
			return false;
		}

		try {
			return $this->evaluateNode($criteria['root'], $transactionData, 0);
		} catch (\Exception $e) {
			$this->logger->error('Error evaluating criteria', [
				'error' => $e->getMessage(),
				'criteria' => $criteria
			]);
			return false;
		}
	}

	/**
	 * Evaluate legacy v1 format criteria.
	 *
	 * @param array|string $criteria Legacy criteria (field, pattern, matchType)
	 * @param array $transactionData Transaction fields
	 * @return bool Match result
	 */
	private function evaluateLegacy($criteria, array $transactionData): bool {
		// Legacy format is passed as array with field, pattern, matchType
		if (is_array($criteria)) {
			$field = $criteria['field'] ?? null;
			$pattern = $criteria['pattern'] ?? null;
			$matchType = $criteria['matchType'] ?? 'contains';
		} else {
			// If string, it's just the pattern (use default field)
			$field = 'description';
			$pattern = $criteria;
			$matchType = 'contains';
		}

		if (!$field || !$pattern) {
			return false;
		}

		// Get field value
		if (!isset($transactionData[$field])) {
			return false;
		}

		$value = $transactionData[$field];

		return $this->matchValue($value, $matchType, $pattern, $field);
	}

	/**
	 * Recursively evaluate a node in the criteria tree.
	 *
	 * @param array $node Node to evaluate (group or condition)
	 * @param array $data Transaction data
	 * @param int $depth Current nesting depth
	 * @return bool Evaluation result
	 */
	private function evaluateNode(array $node, array $data, int $depth): bool {
		// Check depth limit
		if ($depth > self::MAX_DEPTH) {
			throw new \InvalidArgumentException('Criteria tree exceeds maximum depth of ' . self::MAX_DEPTH);
		}

		// Determine node type
		if (isset($node['operator'])) {
			// Group node - recurse
			return $this->evaluateGroup($node, $data, $depth);
		} elseif (isset($node['type']) && $node['type'] === 'condition') {
			// Leaf condition
			$result = $this->evaluateCondition($node, $data);
			// Apply negation if specified
			return isset($node['negate']) && $node['negate'] ? !$result : $result;
		}

		throw new \InvalidArgumentException('Unknown node type: ' . json_encode($node));
	}

	/**
	 * Evaluate a group node (AND/OR operator).
	 *
	 * @param array $node Group node
	 * @param array $data Transaction data
	 * @param int $depth Current nesting depth
	 * @return bool Evaluation result
	 */
	private function evaluateGroup(array $node, array $data, int $depth): bool {
		$operator = $node['operator'] ?? null;
		$conditions = $node['conditions'] ?? [];

		if (!$operator || !is_array($conditions)) {
			throw new \InvalidArgumentException('Invalid group node structure');
		}

		if ($operator === 'AND') {
			// All conditions must be true
			foreach ($conditions as $condition) {
				if (!$this->evaluateNode($condition, $data, $depth + 1)) {
					return false; // Short-circuit on first false
				}
			}
			return true;
		} elseif ($operator === 'OR') {
			// At least one condition must be true
			foreach ($conditions as $condition) {
				if ($this->evaluateNode($condition, $data, $depth + 1)) {
					return true; // Short-circuit on first true
				}
			}
			return false;
		}

		throw new \InvalidArgumentException('Invalid operator: ' . $operator);
	}

	/**
	 * Evaluate a condition (leaf node).
	 *
	 * @param array $condition Condition node
	 * @param array $data Transaction data
	 * @return bool Evaluation result
	 */
	private function evaluateCondition(array $condition, array $data): bool {
		$field = $condition['field'] ?? null;
		$matchType = $condition['matchType'] ?? null;
		$pattern = $condition['pattern'] ?? null;

		if (!$field || !$matchType || $pattern === null) {
			throw new \InvalidArgumentException('Invalid condition structure');
		}

		// Validate field
		if (!in_array($field, self::VALID_FIELDS, true)) {
			throw new \InvalidArgumentException('Invalid field: ' . $field);
		}

		// Get field value from transaction data
		if (!isset($data[$field])) {
			return false; // Field not present = no match
		}

		$value = $data[$field];

		return $this->matchValue($value, $matchType, $pattern, $field);
	}

	/**
	 * Match a value against a pattern based on matchType.
	 *
	 * @param mixed $value Value to test
	 * @param string $matchType Match type
	 * @param mixed $pattern Pattern to match against
	 * @param string $field Field name (for type determination)
	 * @return bool Match result
	 */
	private function matchValue($value, string $matchType, $pattern, string $field): bool {
		// Determine field type and delegate to appropriate matcher
		if ($field === 'amount') {
			return $this->matchNumeric((float)$value, $matchType, $pattern);
		} elseif ($field === 'date') {
			return $this->matchDate((string)$value, $matchType, $pattern);
		} elseif ($field === 'account') {
			return $this->matchAccount($value, $matchType, $pattern);
		} else {
			// String fields (description, vendor, reference, notes, type, account_type, source)
			return $this->matchString((string)$value, $matchType, (string)$pattern);
		}
	}

	/**
	 * Match on the transaction's bank account by exact id.
	 *
	 * An account is an entity, not free text, so the only meaningful test is
	 * exact id equality — the UI stores the chosen account's id as the pattern.
	 * "is not this account" is expressed with the condition's negate flag, not a
	 * separate match type. Account ids are positive integers, so a missing value
	 * (e.g. an import into an account that doesn't exist yet) or a non-positive
	 * pattern never matches.
	 *
	 * @param mixed $value Transaction account id
	 * @param string $matchType Match type (only 'equals' is valid)
	 * @param mixed $pattern Account id to match against
	 * @return bool Match result
	 */
	private function matchAccount($value, string $matchType, $pattern): bool {
		if (!in_array($matchType, self::ACCOUNT_MATCH_TYPES, true)) {
			$this->logger->warning('Invalid account match type', ['matchType' => $matchType]);
			return false;
		}

		$target = (int)$pattern;
		if ($target <= 0) {
			return false;
		}

		return (int)$value === $target;
	}

	/**
	 * Match string values.
	 *
	 * @param string $value Value to test
	 * @param string $matchType Match type
	 * @param string $pattern Pattern to match
	 * @return bool Match result
	 */
	private function matchString(string $value, string $matchType, string $pattern): bool {
		// Validate match type
		if (!in_array($matchType, self::STRING_MATCH_TYPES, true)) {
			$this->logger->warning('Invalid string match type', ['matchType' => $matchType]);
			return false;
		}

		switch ($matchType) {
			case 'contains':
				return stripos($value, $pattern) !== false;
			case 'starts_with':
				return stripos($value, $pattern) === 0;
			case 'ends_with':
				$patternLen = strlen($pattern);
				return $patternLen === 0 || strcasecmp(substr($value, -$patternLen), $pattern) === 0;
			case 'equals':
				return strcasecmp($value, $pattern) === 0;
			case 'regex':
				$regex = $this->normalizeRegexPattern($pattern);
				if ($regex === null) {
					$this->logger->warning('Invalid regex pattern', ['pattern' => $pattern]);
					return false;
				}
				$result = @preg_match($regex, $value);
				if ($result === false) {
					$this->logger->warning('Invalid regex pattern', ['pattern' => $pattern]);
					return false;
				}
				return $result === 1;
			default:
				return false;
		}
	}

	private function normalizeRegexPattern(string $pattern): ?string {
		$trimmed = trim($pattern);
		if ($trimmed === '') {
			return null;
		}

		if (preg_match('#^/(.*)/([a-zA-Z]*)$#s', $trimmed, $matches) === 1) {
			return $matches[0];
		}

		return '/' . $trimmed . '/i';
	}

	/**
	 * Match numeric values.
	 *
	 * @param float $value Value to test
	 * @param string $matchType Match type
	 * @param mixed $pattern Pattern to match (number or array for 'between')
	 * @return bool Match result
	 */
	private function matchNumeric(float $value, string $matchType, $pattern): bool {
		// Validate match type
		if (!in_array($matchType, self::NUMERIC_MATCH_TYPES, true)) {
			$this->logger->warning('Invalid numeric match type', ['matchType' => $matchType]);
			return false;
		}

		switch ($matchType) {
			case 'equals':
				$target = (float)$pattern;
				// Use epsilon comparison for floating point
				return abs($value - $target) < 0.01;
			case 'greater_than':
				return $value > (float)$pattern;
			case 'less_than':
				return $value < (float)$pattern;
			case 'between':
				$range = self::parseRange($pattern);
				if ($range === null || !is_numeric($range['min']) || !is_numeric($range['max'])) {
					$this->logger->warning('Invalid between pattern for numeric match', ['pattern' => $pattern]);
					return false;
				}
				$min = (float)$range['min'];
				$max = (float)$range['max'];
				return $value >= $min && $value <= $max;
			default:
				return false;
		}
	}

	/**
	 * Match date values.
	 *
	 * @param string $value Date string (Y-m-d format)
	 * @param string $matchType Match type
	 * @param mixed $pattern Pattern to match (date string or array for 'between')
	 * @return bool Match result
	 */
	private function matchDate(string $value, string $matchType, $pattern): bool {
		// Validate match type
		if (!in_array($matchType, self::DATE_MATCH_TYPES, true)) {
			$this->logger->warning('Invalid date match type', ['matchType' => $matchType]);
			return false;
		}

		// Parse dates
		$valueTime = strtotime($value);
		if ($valueTime === false) {
			return false;
		}

		switch ($matchType) {
			case 'equals':
				$patternTime = strtotime((string)$pattern);
				if ($patternTime === false) {
					return false;
				}
				// Compare dates only (ignore time)
				return date('Y-m-d', $valueTime) === date('Y-m-d', $patternTime);
			case 'before':
				$patternTime = strtotime((string)$pattern);
				if ($patternTime === false) {
					return false;
				}
				return $valueTime < $patternTime;
			case 'after':
				$patternTime = strtotime((string)$pattern);
				if ($patternTime === false) {
					return false;
				}
				return $valueTime > $patternTime;
			case 'between':
				$range = self::parseRange($pattern);
				if ($range === null) {
					$this->logger->warning('Invalid between pattern for date match', ['pattern' => $pattern]);
					return false;
				}
				$minTime = strtotime((string)$range['min']);
				$maxTime = strtotime((string)$range['max']);
				if ($minTime === false || $maxTime === false) {
					return false;
				}
				return $valueTime >= $minTime && $valueTime <= $maxTime;
			default:
				return false;
		}
	}

	/**
	 * Read a 'between' pattern as ['min' => ..., 'max' => ...].
	 *
	 * The canonical stored shape is an array, but the visual rule builder's
	 * pattern box is a text input, so rules saved from it hold the JSON text
	 * the user typed (e.g. '{"min": 10, "max": 100}'). Both are accepted, so
	 * rules already saved that way match without a migration.
	 *
	 * @param mixed $pattern
	 * @return array{min: int|float|string, max: int|float|string}|null Null when the pattern isn't a usable range
	 */
	public static function parseRange($pattern): ?array {
		if (is_string($pattern)) {
			$pattern = json_decode($pattern, true);
		}
		if (!is_array($pattern) || !array_key_exists('min', $pattern) || !array_key_exists('max', $pattern)) {
			return null;
		}
		$min = $pattern['min'];
		$max = $pattern['max'];
		foreach ([$min, $max] as $bound) {
			if (!is_int($bound) && !is_float($bound) && !(is_string($bound) && trim($bound) !== '')) {
				return null;
			}
		}
		return ['min' => $min, 'max' => $max];
	}

	/**
	 * Rewrite a criteria tree into its canonical stored shape: every 'between'
	 * pattern given as JSON text becomes a ['min', 'max'] array. Anything that
	 * doesn't parse is left untouched for validate() to report.
	 *
	 * @param array $criteria Criteria tree ({version, root})
	 * @return array
	 */
	public static function normalizeCriteria(array $criteria): array {
		if (isset($criteria['root']) && is_array($criteria['root'])) {
			$criteria['root'] = self::normalizeNode($criteria['root'], 0);
		}
		return $criteria;
	}

	private static function normalizeNode(array $node, int $depth): array {
		if ($depth > self::MAX_DEPTH) {
			return $node;
		}
		if (isset($node['conditions']) && is_array($node['conditions'])) {
			foreach ($node['conditions'] as $i => $child) {
				if (is_array($child)) {
					$node['conditions'][$i] = self::normalizeNode($child, $depth + 1);
				}
			}
			return $node;
		}
		if (($node['matchType'] ?? null) === 'between' && isset($node['pattern']) && is_string($node['pattern'])) {
			$range = self::parseRange($node['pattern']);
			if ($range !== null) {
				$node['pattern'] = $range;
			}
		}
		return $node;
	}

	/**
	 * Validate criteria tree structure.
	 *
	 * @param array $criteria Criteria tree
	 * @return array ['valid' => bool, 'errors' => string[]]
	 */
	public function validate(array $criteria): array {
		$errors = [];

		// Check for root node
		if (!isset($criteria['root'])) {
			$errors[] = 'Missing root node';
			return ['valid' => false, 'errors' => $errors];
		}

		// Validate root node
		try {
			$this->validateNode($criteria['root'], 0, $errors);
		} catch (\Exception $e) {
			$errors[] = $e->getMessage();
		}

		return [
			'valid' => empty($errors),
			'errors' => $errors
		];
	}

	/**
	 * Recursively validate a node in the criteria tree.
	 *
	 * @param array $node Node to validate
	 * @param int $depth Current depth
	 * @param array &$errors Error accumulator
	 */
	private function validateNode(array $node, int $depth, array &$errors): void {
		// Check depth
		if ($depth > self::MAX_DEPTH) {
			$errors[] = 'Criteria tree exceeds maximum depth of ' . self::MAX_DEPTH;
			return;
		}

		if (isset($node['operator'])) {
			// Group node
			if (!in_array($node['operator'], ['AND', 'OR'], true)) {
				$errors[] = 'Invalid operator: ' . $node['operator'];
			}

			if (!isset($node['conditions']) || !is_array($node['conditions'])) {
				$errors[] = 'Group node missing conditions array';
			} else {
				foreach ($node['conditions'] as $condition) {
					$this->validateNode($condition, $depth + 1, $errors);
				}
			}
		} elseif (isset($node['type']) && $node['type'] === 'condition') {
			// Leaf condition
			if (!isset($node['field']) || !in_array($node['field'], self::VALID_FIELDS, true)) {
				$errors[] = 'Invalid or missing field: ' . ($node['field'] ?? 'null');
			}

			if (!isset($node['matchType'])) {
				$errors[] = 'Missing matchType';
			}

			if (!isset($node['pattern'])) {
				$errors[] = 'Missing pattern';
			}

			// Validate matchType for field type
			$field = $node['field'] ?? null;
			$matchType = $node['matchType'] ?? null;

			if ($field && $matchType) {
				$validTypes = [];
				if ($field === 'amount') {
					$validTypes = self::NUMERIC_MATCH_TYPES;
				} elseif ($field === 'date') {
					$validTypes = self::DATE_MATCH_TYPES;
				} elseif ($field === 'account') {
					$validTypes = self::ACCOUNT_MATCH_TYPES;
				} else {
					$validTypes = self::STRING_MATCH_TYPES;
				}

				if (!in_array($matchType, $validTypes, true)) {
					$errors[] = "Invalid matchType '$matchType' for field '$field'";
				}
			}

			// Reject an invalid regex pattern at save time so it surfaces as a
			// clear error, instead of silently never matching at run time. This
			// accepts both bare patterns and full '/pattern/flags' literals.
			if ($matchType === 'regex' && isset($node['pattern']) && is_string($node['pattern'])) {
				$regex = $this->normalizeRegexPattern($node['pattern']);
				if ($regex === null || @preg_match($regex, '') === false) {
					$errors[] = "Invalid regex pattern: '" . $node['pattern'] . "'";
				}
			}

			// A 'between' range that can't be read never matches anything, so
			// refuse it at save time rather than store an inert rule.
			if ($matchType === 'between' && isset($node['pattern']) && ($field === 'amount' || $field === 'date')) {
				$range = self::parseRange($node['pattern']);
				$usable = $range !== null && ($field === 'amount'
					? is_numeric($range['min']) && is_numeric($range['max'])
					: strtotime((string)$range['min']) !== false && strtotime((string)$range['max']) !== false);
				if (!$usable) {
					$errors[] = "Invalid 'between' pattern for field '$field': expected min and max";
				}
			}
		} else {
			$errors[] = 'Unknown node type';
		}
	}
}
