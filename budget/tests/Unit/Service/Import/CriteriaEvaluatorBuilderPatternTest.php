<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\CriteriaEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Criteria exactly as the visual rule builder saves them.
 *
 * The builder's pattern box is a text input, so a "between" range reaches
 * the server as the JSON text the user typed (e.g. '{"min": 10, "max": 100}'),
 * never as an array. RulesModule posts getCriteria() unchanged. New saves are
 * normalised to the array form, but rules saved before that hold the text and
 * must still match without a migration.
 */
class CriteriaEvaluatorBuilderPatternTest extends TestCase {
	private CriteriaEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new CriteriaEvaluator($this->createMock(LoggerInterface::class));
	}

	private function builderTree(string $field, $pattern): array {
		return [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [[
					'type' => 'condition',
					'field' => $field,
					'matchType' => 'between',
					'pattern' => $pattern,
					'negate' => false,
				]],
			],
		];
	}

	private function builderCriteria(string $field, string $pattern): string {
		// Stored as JSON, the way ImportRuleService keeps it.
		return json_encode($this->builderTree($field, $pattern));
	}

	public function testAmountBetweenFromTheBuilderMatchesAnAmountInRange(): void {
		$criteria = $this->builderCriteria('amount', '{"min": 10, "max": 100}');

		$this->assertTrue($this->evaluator->evaluate($criteria, ['amount' => 50.0]));
	}

	public function testDateBetweenFromTheBuilderMatchesADateInRange(): void {
		$criteria = $this->builderCriteria('date', '{"min": "2026-01-01", "max": "2026-12-31"}');

		$this->assertTrue($this->evaluator->evaluate($criteria, ['date' => '2026-06-15']));
	}

	public function testAmountBetweenFromTheBuilderStillRejectsAnAmountOutOfRange(): void {
		$criteria = $this->builderCriteria('amount', '{"min": 10, "max": 100}');

		$this->assertFalse($this->evaluator->evaluate($criteria, ['amount' => 500.0]));
	}

	public function testAmountBetweenStartingAtZeroMatches(): void {
		$criteria = $this->builderCriteria('amount', '{"min": 0, "max": 100}');

		$this->assertTrue($this->evaluator->evaluate($criteria, ['amount' => 0.0]));
		$this->assertTrue($this->evaluator->evaluate($criteria, ['amount' => 100.0]));
	}

	public function testUnreadableBetweenTextNeverMatches(): void {
		foreach (['10-100', '{"min": 10}', '{"min": "abc", "max": 5}', '[]', ''] as $pattern) {
			$criteria = $this->builderCriteria('amount', $pattern);
			$this->assertFalse($this->evaluator->evaluate($criteria, ['amount' => 50.0]), $pattern);
		}
	}

	public function testNormalizeTurnsBuilderRangesIntoArraysAtAnyDepth(): void {
		$tree = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [
					['type' => 'condition', 'field' => 'amount', 'matchType' => 'between', 'pattern' => '{"min": 0, "max": 100}', 'negate' => false],
					['operator' => 'OR', 'conditions' => [
						['type' => 'condition', 'field' => 'date', 'matchType' => 'between', 'pattern' => '{"min": "2026-01-01", "max": "2026-12-31"}', 'negate' => false],
						['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => '{"min": 1, "max": 2}', 'negate' => false],
					]],
				],
			],
		];

		$normalized = CriteriaEvaluator::normalizeCriteria($tree);

		$this->assertSame(['min' => 0, 'max' => 100], $normalized['root']['conditions'][0]['pattern']);
		$this->assertSame(['min' => '2026-01-01', 'max' => '2026-12-31'], $normalized['root']['conditions'][1]['conditions'][0]['pattern']);
		// Only 'between' patterns are touched
		$this->assertSame('{"min": 1, "max": 2}', $normalized['root']['conditions'][1]['conditions'][1]['pattern']);
	}

	public function testNormalizeLeavesAnUnreadableRangeForValidateToReport(): void {
		$normalized = CriteriaEvaluator::normalizeCriteria($this->builderTree('amount', '10-100'));

		$this->assertSame('10-100', $normalized['root']['conditions'][0]['pattern']);
	}

	public function testValidateAcceptsBothRangeShapes(): void {
		$this->assertTrue($this->evaluator->validate($this->builderTree('amount', '{"min": 0, "max": 100}'))['valid']);
		$this->assertTrue($this->evaluator->validate($this->builderTree('amount', ['min' => 0, 'max' => 100]))['valid']);
		$this->assertTrue($this->evaluator->validate($this->builderTree('date', ['min' => '2026-01-01', 'max' => '2026-12-31']))['valid']);
	}

	public function testValidateRejectsARangeThatCanNeverMatch(): void {
		foreach ([
			['amount', '10-100'],
			['amount', '{"min": 10}'],
			['amount', '{"min": "ten", "max": 100}'],
			['date', '{"min": "not a date", "max": "2026-12-31"}'],
		] as [$field, $pattern]) {
			$result = $this->evaluator->validate($this->builderTree($field, $pattern));
			$this->assertFalse($result['valid'], $pattern);
			$this->assertStringContainsString("Invalid 'between' pattern", implode(' ', $result['errors']));
		}
	}
}
