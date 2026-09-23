<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CategoryController;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Budget page's effective-budgets call also carries the month's
 * "Ready to assign" figure, for the same month and account scope.
 */
class CategoryControllerReadyToAssignTest extends TestCase {
	public function testEffectiveBudgetsIncludesReadyToAssignForTheSameMonthAndScope(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$service = $this->createMock(CategoryService::class);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->with('user1')->willReturn([3, 4]);

		$budgets = [5 => ['amount' => 100.0, 'period' => 'monthly', 'rollover' => false, 'carried' => 0.0, 'available' => 100.0]];
		$service->method('hasSnapshot')->willReturn(false);
		$service->expects($this->once())->method('resolveEffectiveBudgets')
			->with('user1', '2026-08', [3, 4])->willReturn($budgets);
		$service->expects($this->once())->method('getReadyToAssign')
			->with('user1', '2026-08', [3, 4], $budgets)
			->willReturn(['month' => '2026-08', 'income' => 250.0, 'budgeted' => 100.0, 'amount' => 150.0]);

		$controller = new CategoryController(
			$this->createMock(IRequest::class),
			$service,
			new ValidationService($l),
			$shares,
			$this->createMock(RecurringBudgetService::class),
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->effectiveBudgets('2026-08');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame($budgets, $data['budgets']);
		$this->assertSame(150.0, $data['readyToAssign']['amount']);
	}
}
