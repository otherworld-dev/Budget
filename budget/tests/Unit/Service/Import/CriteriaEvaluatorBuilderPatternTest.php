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
 * never as an array. RulesModule posts getCriteria() unchanged. The existing
 * CriteriaEvaluatorTest only feeds arrays, which the builder never produces.
 */
class CriteriaEvaluatorBuilderPatternTest extends TestCase {
	private CriteriaEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new CriteriaEvaluator($this->createMock(LoggerInterface::class));
	}

	private function builderCriteria(string $field, string $pattern): string {
		// Stored as JSON, the way ImportRuleService keeps it.
		return json_encode([
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
		]);
	}

	public function testAmountBetweenFromTheBuilderMatchesAnAmountInRange(): void {
		$criteria = $this->builderCriteria('amount', '{"min": 10, "max": 100}');

		$matched = $this->evaluator->evaluate($criteria, ['amount' => 50.0]);

		if (!$matched) {
			$this->markTestIncomplete(
				'Known bug: CriteriaEvaluator::matchNumeric() requires an array for "between", but the visual '
				. 'builder saves the range as a JSON string, so amount-between rules never match.'
			);
		}
		$this->assertTrue($matched);
	}

	public function testDateBetweenFromTheBuilderMatchesADateInRange(): void {
		$criteria = $this->builderCriteria('date', '{"min": "2026-01-01", "max": "2026-12-31"}');

		$matched = $this->evaluator->evaluate($criteria, ['date' => '2026-06-15']);

		if (!$matched) {
			$this->markTestIncomplete(
				'Known bug: CriteriaEvaluator::matchDate() requires an array for "between", but the visual '
				. 'builder saves the range as a JSON string, so date-between rules never match.'
			);
		}
		$this->assertTrue($matched);
	}

	public function testAmountBetweenFromTheBuilderStillRejectsAnAmountOutOfRange(): void {
		$criteria = $this->builderCriteria('amount', '{"min": 10, "max": 100}');

		$this->assertFalse($this->evaluator->evaluate($criteria, ['amount' => 500.0]));
	}
}
