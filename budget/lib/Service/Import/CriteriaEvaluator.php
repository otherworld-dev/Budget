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

	/** Valid fields for matching */
	private const VALID_FIELDS = ['description', 'vendor', 'reference', 'notes', 'amount', 'date', 'account_type'];

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
		} else {
			// String fields (description, vendor, reference, notes, account_type)
			return $this->matchString((string)$value, $matchType, (string)$pattern);
		}
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
				// Suppress warnings for invalid regex
				$result = @preg_match('/' . $pattern . '/i', $value);
				if ($result === false) {
					$this->logger->warning('Invalid regex pattern', ['pattern' => $pattern]);
					return false;
				}
				return $result === 1;

			default:
				return false;
		}
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
				if (!is_array($pattern) || !isset($pattern['min']) || !isset($pattern['max'])) {
					$this->logger->warning('Invalid between pattern for numeric match', ['pattern' => $pattern]);
					return false;
				}
				$min = (float)$pattern['min'];
				$max = (float)$pattern['max'];
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
				if (!is_array($pattern) || !isset($pattern['min']) || !isset($pattern['max'])) {
					$this->logger->warning('Invalid between pattern for date match', ['pattern' => $pattern]);
					return false;
				}
				$minTime = strtotime((string)$pattern['min']);
				$maxTime = strtotime((string)$pattern['max']);
				if ($minTime === false || $maxTime === false) {
					return false;
				}
				return $valueTime >= $minTime && $valueTime <= $maxTime;

			default:
				return false;
		}
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
				} else {
					$validTypes = self::STRING_MATCH_TYPES;
				}

				if (!in_array($matchType, $validTypes, true)) {
					$errors[] = "Invalid matchType '$matchType' for field '$field'";
				}
			}
		} else {
			$errors[] = 'Unknown node type';
		}
	}
}
