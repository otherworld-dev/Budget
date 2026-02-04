<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\CriteriaEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CriteriaEvaluatorTest extends TestCase {
	private CriteriaEvaluator $evaluator;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->evaluator = new CriteriaEvaluator($this->logger);
	}

	// ===== Basic Condition Tests =====

	public function testStringContainsMatch(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'contains',
				'pattern' => 'amazon',
				'negate' => false
			]
		];

		$transaction = ['description' => 'Amazon Prime Subscription'];

		$result = $this->evaluator->evaluate($criteria, $transaction, 2);
		$this->assertTrue($result, 'Should match when description contains "amazon"');
	}

	public function testStringContainsNoMatch(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'contains',
				'pattern' => 'walmart',
				'negate' => false
			]
		];

		$transaction = ['description' => 'Amazon Prime Subscription'];

		$result = $this->evaluator->evaluate($criteria, $transaction, 2);
		$this->assertFalse($result, 'Should not match when description does not contain "walmart"');
	}

	public function testStringStartsWith(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'vendor',
				'matchType' => 'starts_with',
				'pattern' => 'AMZN',
				'negate' => false
			]
		];

		$transaction = ['vendor' => 'AMZN Marketplace'];

		$result = $this->evaluator->evaluate($criteria, $transaction, 2);
		$this->assertTrue($result);
	}

	public function testStringEndsWith(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'reference',
				'matchType' => 'ends_with',
				'pattern' => '123',
				'negate' => false
			]
		];

		$transaction = ['reference' => 'CHECK-00123'];

		$result = $this->evaluator->evaluate($criteria, $transaction, 2);
		$this->assertTrue($result);
	}

	public function testStringEquals(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'vendor',
				'matchType' => 'equals',
				'pattern' => 'AWS',
				'negate' => false
			]
		];

		$transaction = ['vendor' => 'AWS'];

		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['vendor'] = 'aws'; // Case insensitive
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['vendor'] = 'AWS Inc';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testRegexMatch(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'regex',
				'pattern' => '^ORDER-\d{5}$',
				'negate' => false
			]
		];

		$transaction = ['description' => 'ORDER-12345'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['description'] = 'ORDER-123';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['description'] = 'INVOICE-12345';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testInvalidRegexReturnsFalse(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'regex',
				'pattern' => '[invalid(regex',
				'negate' => false
			]
		];

		$transaction = ['description' => 'test'];

		// Invalid regex should log error and return false
		$this->logger->expects($this->once())
			->method('error');

		$result = $this->evaluator->evaluate($criteria, $transaction, 2);
		$this->assertFalse($result);
	}

	// ===== Numeric Match Tests =====

	public function testNumericEquals(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'amount',
				'matchType' => 'equals',
				'pattern' => '50.00',
				'negate' => false
			]
		];

		$transaction = ['amount' => 50.00];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 50.01;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testNumericGreaterThan(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'amount',
				'matchType' => 'greater_than',
				'pattern' => '100',
				'negate' => false
			]
		];

		$transaction = ['amount' => 150.00];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] => 100.00;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 50.00;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testNumericLessThan(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'amount',
				'matchType' => 'less_than',
				'pattern' => '100',
				'negate' => false
			]
		];

		$transaction = ['amount' => 50.00];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 100.00;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 150.00;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testNumericBetween(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'amount',
				'matchType' => 'between',
				'pattern' => '{"min": 10, "max": 100}',
				'negate' => false
			]
		];

		$transaction = ['amount' => 50.00];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 10.00; // Inclusive
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 100.00; // Inclusive
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 9.99;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['amount'] = 100.01;
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	// ===== Date Match Tests =====

	public function testDateEquals(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'date',
				'matchType' => 'equals',
				'pattern' => '2024-01-15',
				'negate' => false
			]
		];

		$transaction = ['date' => '2024-01-15'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-16';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testDateBefore(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'date',
				'matchType' => 'before',
				'pattern' => '2024-01-15',
				'negate' => false
			]
		];

		$transaction = ['date' => '2024-01-10'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-15';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-20';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testDateAfter(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'date',
				'matchType' => 'after',
				'pattern' => '2024-01-15',
				'negate' => false
			]
		];

		$transaction = ['date' => '2024-01-20'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-15'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-10'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testDateBetween(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'date',
				'matchType' => 'between',
				'pattern' => '{"min": "2024-01-01", "max": "2024-12-31"}',
				'negate' => false
			]
		];

		$transaction = ['date' => '2024-06-15'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-01-01'; // Inclusive
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2024-12-31'; // Inclusive
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2023-12-31';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['date'] = '2025-01-01';
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	// ===== Negation Tests =====

	public function testNegation(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'contains',
				'pattern' => 'refund',
				'negate' => true // NOT contains
			]
		];

		$transaction = ['description' => 'Amazon Purchase'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		$transaction['description'] = 'Amazon Refund'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	// ===== Boolean Logic Tests =====

	public function testAndOperator(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [
					[
						'type' => 'condition',
						'field' => 'description',
						'matchType' => 'contains',
						'pattern' => 'amazon',
						'negate' => false
					],
					[
						'type' => 'condition',
						'field' => 'amount',
						'matchType' => 'greater_than',
						'pattern' => '50',
						'negate' => false
					]
				]
			]
		];

		// Both conditions true
		$transaction = ['description' => 'Amazon', 'amount' => 100.00];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// First true, second false
		$transaction = ['description' => 'Amazon', 'amount' => 25.00];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		// First false, second true
		$transaction = ['description' => 'Walmart', 'amount' => 100.00];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		// Both false
		$transaction = ['description' => 'Walmart', 'amount' => 25.00];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testOrOperator(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'OR',
				'conditions' => [
					[
						'type' => 'condition',
						'field' => 'description',
						'matchType' => 'contains',
						'pattern' => 'amazon',
						'negate' => false
					],
					[
						'type' => 'condition',
						'field' => 'vendor',
						'matchType' => 'equals',
						'pattern' => 'AWS',
						'negate' => false
					]
				]
			]
		];

		// Both conditions true
		$transaction = ['description' => 'Amazon', 'vendor' => 'AWS'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// First true, second false
		$transaction = ['description' => 'Amazon', 'vendor' => 'Other'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// First false, second true
		$transaction = ['description' => 'Other', 'vendor' => 'AWS'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// Both false
		$transaction = ['description' => 'Other', 'vendor' => 'Other'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testNestedGroups(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'OR',
				'conditions' => [
					[
						'operator' => 'AND',
						'conditions' => [
							[
								'type' => 'condition',
								'field' => 'description',
								'matchType' => 'contains',
								'pattern' => 'amazon',
								'negate' => false
							],
							[
								'type' => 'condition',
								'field' => 'amount',
								'matchType' => 'greater_than',
								'pattern' => '50',
								'negate' => false
							]
						]
					],
					[
						'operator' => 'AND',
						'conditions' => [
							[
								'type' => 'condition',
								'field' => 'vendor',
								'matchType' => 'equals',
								'pattern' => 'AWS',
								'negate' => false
							],
							[
								'type' => 'condition',
								'field' => 'description',
								'matchType' => 'contains',
								'pattern' => 'refund',
								'negate' => true // NOT refund
							]
						]
					]
				]
			]
		];

		// First group matches: amazon AND amount > 50
		$transaction = ['description' => 'Amazon Purchase', 'amount' => 100.00, 'vendor' => 'Other'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// Second group matches: AWS AND NOT refund
		$transaction = ['description' => 'AWS Service', 'amount' => 25.00, 'vendor' => 'AWS'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));

		// Second group fails due to refund
		$transaction = ['description' => 'AWS Refund', 'amount' => 25.00, 'vendor' => 'AWS'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));

		// Neither group matches
		$transaction = ['description' => 'Walmart', 'amount' => 25.00, 'vendor' => 'Other'];
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	// ===== Edge Cases =====

	public function testEmptyPattern(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'contains',
				'pattern' => '',
				'negate' => false
			]
		];

		$transaction = ['description' => 'Amazon'];
		// Empty pattern should match everything
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testMissingField(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'vendor',
				'matchType' => 'contains',
				'pattern' => 'amazon',
				'negate' => false
			]
		];

		$transaction = ['description' => 'Amazon']; // Missing 'vendor' field

		// Missing field should be treated as empty string
		$this->assertFalse($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	public function testDeepNesting(): void {
		// Test nesting limit (5 levels deep)
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [
					[
						'operator' => 'OR',
						'conditions' => [
							[
								'operator' => 'AND',
								'conditions' => [
									[
										'operator' => 'OR',
										'conditions' => [
											[
												'operator' => 'AND',
												'conditions' => [
													[
														'type' => 'condition',
														'field' => 'description',
														'matchType' => 'contains',
														'pattern' => 'test',
														'negate' => false
													]
												]
											]
										]
									]
								]
							]
						]
					]
				]
			]
		];

		$transaction = ['description' => 'test'];
		$this->assertTrue($this->evaluator->evaluate($criteria, $transaction, 2));
	}

	// ===== V1 Compatibility Tests =====

	public function testV1FormatEvaluation(): void {
		$criteria = [
			'field' => 'description',
			'matchType' => 'contains',
			'pattern' => 'amazon'
		];

		$transaction = ['description' => 'Amazon Purchase'];

		$result = $this->evaluator->evaluate($criteria, $transaction, 1);
		$this->assertTrue($result);
	}

	// ===== Validation Tests =====

	public function testValidateCriteriaSuccess(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => [
					[
						'type' => 'condition',
						'field' => 'description',
						'matchType' => 'contains',
						'pattern' => 'amazon',
						'negate' => false
					]
				]
			]
		];

		$result = $this->evaluator->validate($criteria);
		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
	}

	public function testValidateEmptyConditions(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'operator' => 'AND',
				'conditions' => []
			]
		];

		$result = $this->evaluator->validate($criteria);
		$this->assertFalse($result['valid']);
		$this->assertNotEmpty($result['errors']);
	}

	public function testValidateMissingPattern(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'contains',
				'pattern' => '',
				'negate' => false
			]
		];

		$result = $this->evaluator->validate($criteria);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('pattern', strtolower($result['errors'][0]));
	}

	public function testValidateInvalidRegex(): void {
		$criteria = [
			'version' => 2,
			'root' => [
				'type' => 'condition',
				'field' => 'description',
				'matchType' => 'regex',
				'pattern' => '[invalid(regex',
				'negate' => false
			]
		];

		$result = $this->evaluator->validate($criteria);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('regex', strtolower($result['errors'][0]));
	}
}
