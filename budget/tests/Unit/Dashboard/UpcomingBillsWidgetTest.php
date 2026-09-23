<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Dashboard;

use OCA\Budget\Dashboard\UpcomingBillsWidget;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BillService;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class UpcomingBillsWidgetTest extends TestCase {
	private UpcomingBillsWidget $widget;
	private BillService $billService;
	private AmountFormatter $amountFormatter;

	protected function setUp(): void {
		$this->billService = $this->createMock(BillService::class);
		$this->amountFormatter = $this->createMock(AmountFormatter::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf($text, $params));
		$l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count) => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/budget/');
		$urlGenerator->method('imagePath')->willReturn('/apps/budget/img/app-dark.svg');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $p) => 'https://nc.test' . $p);

		$this->amountFormatter->method('formatForUser')
			->willReturnCallback(fn (string $u, float $a) => '$' . number_format($a, 2));

		$this->widget = new UpcomingBillsWidget(
			$this->billService,
			$this->amountFormatter,
			$l,
			$urlGenerator
		);
	}

	private function makeBill(int $id, string $name, float $amount, string $dueDate): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setName($name);
		$bill->setAmount($amount);
		$bill->setNextDueDate($dueDate);
		return $bill;
	}

	public function testStableIdAndOrder(): void {
		$this->assertSame('budget-upcoming-bills', $this->widget->getId());
		$this->assertSame(20, $this->widget->getOrder());
		$this->assertSame('icon-budget-widget', $this->widget->getIconClass());
	}

	public function testItemsCappedAtFive(): void {
		$bills = [];
		for ($i = 1; $i <= 8; $i++) {
			$bills[] = $this->makeBill($i, "Bill {$i}", 10.0 * $i, date('Y-m-d', strtotime("+{$i} days")));
		}
		$this->billService->method('findUpcoming')->with('alice', 60)->willReturn($bills);
		$this->billService->method('enrichBillsWithCurrency')->willReturnArgument(0);

		$items = $this->widget->getItems('alice');

		$this->assertCount(5, $items);
		$this->assertSame('Bill 1', $items[0]->getTitle());
		$this->assertSame('1', $items[0]->getSinceId());
	}

	public function testEmptyStateMessage(): void {
		$this->billService->method('findUpcoming')->willReturn([]);
		$this->billService->method('enrichBillsWithCurrency')->willReturnArgument(0);

		$result = $this->widget->getItemsV2('alice');

		$this->assertCount(0, $result->getItems());
		$this->assertSame('No upcoming bills', $result->getEmptyContentMessage());
	}

	public function testDueDateWording(): void {
		$bills = [
			$this->makeBill(1, 'Overdue', 5.0, date('Y-m-d', strtotime('-3 days'))),
			$this->makeBill(2, 'Today', 5.0, date('Y-m-d')),
			$this->makeBill(3, 'Future', 5.0, date('Y-m-d', strtotime('+3 days'))),
		];
		$this->billService->method('findUpcoming')->willReturn($bills);
		$this->billService->method('enrichBillsWithCurrency')->willReturnArgument(0);

		$items = $this->widget->getItems('alice');

		$this->assertStringContainsString('overdue', $items[0]->getSubtitle());
		$this->assertStringContainsString('due today', $items[1]->getSubtitle());
		$this->assertStringContainsString('due in 3 days', $items[2]->getSubtitle());
		$this->assertStringContainsString('$5.00', $items[0]->getSubtitle());
	}

	public function testMoreButtonLinksToBills(): void {
		$buttons = $this->widget->getWidgetButtons('alice');

		$this->assertCount(1, $buttons);
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		$this->assertStringContainsString('#/bills', $buttons[0]->getLink());
	}
}
