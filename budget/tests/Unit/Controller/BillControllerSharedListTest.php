<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\UpcomingBillsService;
use OCA\Budget\Service\ValidationService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bills other people shared, as the Bills and Transfers lists and
 * their summary cards get them.
 */
class BillControllerSharedListTest extends TestCase {
	private BillService $service;
	private GranularShareService $shares;
	private BillController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(BillService::class);
		$this->service->method('enrichBillsWithCurrency')->willReturnArgument(0);
		$this->shares = $this->createMock(GranularShareService::class);
		$this->controller = new BillController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->createMock(ValidationService::class),
			$this->shares,
			$this->createMock(BillSuggestionService::class),
			$this->createMock(UpcomingBillsService::class),
			$this->createMock(IL10N::class),
			'alice',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function shared(int $id, bool $isTransfer, bool $isActive = true, bool $canMarkUnpaid = false): array {
		return [
			'id' => $id,
			'userId' => 'bob',
			'accountId' => 9,
			'isTransfer' => $isTransfer,
			'isActive' => $isActive,
			'canMarkUnpaid' => $canMarkUnpaid,
			'currency' => null,
			'_shared' => true,
		];
	}

	/**
	 * Shared bills were merged in with no currency, so a partner's euro bill
	 * showed with the viewer's pound sign.
	 */
	public function testSharedBillsComeWithTheirAccountsCurrency(): void {
		$this->service->method('findByType')->willReturn([]);
		$this->shares->method('getSharedBills')->willReturn([$this->shared(1, false)]);
		$this->service->expects($this->once())->method('enrichSharedBillsWithCurrency')
			->willReturnCallback(fn (array $bills) => array_map(fn ($b) => ['currency' => 'EUR'] + $b, $bills));

		$data = $this->controller->index(false, false, true)->getData();

		$this->assertSame('EUR', $data[0]['currency']);
	}

	/**
	 * The merge ignored the list's filters: shared transfers turned up on the
	 * Bills page and shared bills on the Transfers page.
	 */
	public function testSharedBillsFollowTheListsFilters(): void {
		$this->service->method('findByType')->willReturn([]);
		$this->service->method('findActive')->willReturn([]);
		$this->service->method('enrichSharedBillsWithCurrency')->willReturnArgument(0);
		$this->shares->method('getSharedBills')->willReturn([
			$this->shared(1, false),
			$this->shared(2, true),
			$this->shared(3, false, false),
			$this->shared(4, false, false, true),
		]);

		$ids = fn ($response) => array_column($response->getData(), 'id');

		$this->assertSame([1, 4], $ids($this->controller->index(false, false, true)), 'Bills page');
		$this->assertSame([2], $ids($this->controller->index(false, true, true)), 'Transfers page');
		$this->assertSame([1, 2], $ids($this->controller->index(true)), 'active only');
		$this->assertSame([1, 3, 4], $ids($this->controller->index(false, false)), 'every bill');
	}

	public function testTheSummaryIsForTheKindThePageAsksFor(): void {
		$this->shares->method('getSharedBillEntities')->willReturn([]);
		$this->service->expects($this->exactly(2))->method('getMonthlySummary')
			->willReturnCallback(fn (string $user, array $shared, ?bool $isTransfer) => ['isTransfer' => $isTransfer]);

		$this->assertSame(['isTransfer' => false], $this->controller->summary()->getData());
		$this->assertSame(['isTransfer' => true], $this->controller->summary('true')->getData());
	}
}
