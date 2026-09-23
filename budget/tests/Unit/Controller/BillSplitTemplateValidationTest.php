<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * BillController::validateSplitTemplate() adds the parts through
 * MoneyCalculator (#274) rather than a float running total.
 */
class BillSplitTemplateValidationTest extends TestCase {
	private BillController $controller;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$reflection = new \ReflectionClass(BillController::class);
		$this->controller = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('l');
		$property->setValue($this->controller, $l);
	}

	private function validate(array $amounts, float $billAmount): array {
		$splits = array_map(static fn($amount) => ['amount' => $amount, 'categoryId' => 1], $amounts);
		return (new \ReflectionMethod($this->controller, 'validateSplitTemplate'))
			->invoke($this->controller, $splits, $billAmount);
	}

	public function testPartsThatAddUpToTheBillAreValid(): void {
		$this->assertTrue($this->validate([33.33, 33.33, 33.34], 100.0)['valid']);
		$this->assertTrue($this->validate(['0.1', '0.2'], 0.3)['valid']);
	}

	public function testPartsAWholeCentShortAreRejected(): void {
		$result = $this->validate([50.0, 49.98], 100.0);

		$this->assertFalse($result['valid']);
		$this->assertSame('Split amounts must equal the bill amount', $result['error']);
	}

	public function testCryptoPartsAreNotTruncatedBeforeTheComparison(): void {
		// At 2dp each part would truncate to 0.00 and the pair would fail
		$this->assertTrue($this->validate([0.004, 0.004], 0.008)['valid']);
	}
}
