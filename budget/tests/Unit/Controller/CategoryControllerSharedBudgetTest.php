<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CategoryController;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\SharedBudgetService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Budget page and categories shared with the viewer: it shows the owner's
 * budget for the month, and a Full control recipient's edit lands where the
 * owner's own edit would.
 */
class CategoryControllerSharedBudgetTest extends TestCase {
	private CategoryService&MockObject $service;
	private GranularShareService&MockObject $shares;
	private CategoryController $controller;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});
		$this->service = $this->createMock(CategoryService::class);
		$this->shares = $this->createMock(GranularShareService::class);

		$this->controller = new CategoryController(
			$this->createMock(IRequest::class),
			$this->service,
			new ValidationService($l),
			$this->shares,
			$this->createMock(RecurringBudgetService::class),
			new SharedBudgetService($this->service, $this->shares),
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	private static function budget(float $amount): array {
		return ['amount' => $amount, 'period' => 'monthly', 'rollover' => false, 'carried' => 0.0, 'available' => $amount];
	}

	public function testEffectiveBudgetsAddTheOwnersBudgetsForSharedCategories(): void {
		$this->service->method('hasSnapshot')->willReturn(false);
		$this->shares->method('getVisibleAccountIds')->willReturnMap([
			['user1', [3]],
			['owner1', [8, 9]],
		]);
		$this->shares->method('getSharedCategories')->with('user1')->willReturn([
			['id' => 70, 'userId' => 'owner1'],
		]);
		$own = [5 => self::budget(100.0)];
		// owner1's whole set comes back; only what is shared may show
		$this->service->method('resolveEffectiveBudgets')->willReturnMap([
			['user1', '2026-08', [3], $own],
			['owner1', '2026-08', [8, 9], [70 => self::budget(40.0), 71 => self::budget(999.0)]],
		]);
		// Ready to assign stays on this user's own budgets
		$this->service->expects($this->once())->method('getReadyToAssign')
			->with('user1', '2026-08', [3], $own)
			->willReturn(['amount' => 1.0]);

		$data = $this->controller->effectiveBudgets('2026-08')->getData();

		$this->assertSame([5, 70], array_keys($data['budgets']));
		$this->assertSame(40.0, $data['budgets'][70]['amount']);
	}

	public function testWithoutFullControlTheBudgetIsTheOwners(): void {
		$this->shares->method('resolveOwner')->willReturn('owner1');
		$this->shares->method('canManage')->willReturn(false);
		$this->service->expects($this->never())->method('update');
		$this->service->expects($this->never())->method('updateSnapshotBudget');

		$response = $this->controller->updateSharedBudget(70, '2026-08', 50.0);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAnEditGoesToTheOwnersAdjustmentForTheMonth(): void {
		$this->shares->method('resolveOwner')->willReturn('owner1');
		$this->shares->method('canManage')->willReturn(true);
		$this->service->method('hasSnapshot')->with('owner1', '2026-08')->willReturn(true);
		$this->service->expects($this->once())->method('updateSnapshotBudget')
			->with('owner1', 70, '2026-08', 50.0, 'weekly');
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->updateSharedBudget(70, '2026-08', 50.0, 'weekly');

		$this->assertSame(['target' => 'adjustment'], $response->getData());
	}

	public function testWithoutAnAdjustmentAnEditGoesToTheCategory(): void {
		$this->shares->method('resolveOwner')->willReturn('owner1');
		$this->shares->method('canManage')->willReturn(true);
		$this->service->method('hasSnapshot')->willReturn(false);
		$this->service->expects($this->once())->method('update')
			->with(70, 'owner1', ['budgetAmount' => 50.0]);

		$response = $this->controller->updateSharedBudget(70, '2026-08', 50.0);

		$this->assertSame(['target' => 'category'], $response->getData());
	}

	public function testAnUnknownCategoryIsNotFound(): void {
		$this->shares->method('resolveOwner')->willReturn(null);

		$response = $this->controller->updateSharedBudget(70, '2026-08', 50.0);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testABadMonthIsRefused(): void {
		$response = $this->controller->updateSharedBudget(70, 'August', 50.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
