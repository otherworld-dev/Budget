<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\RecurringIncomeController;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\ValidationService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The Income page's rows come with the currency of the account they're paid into. */
class RecurringIncomeControllerCurrencyTest extends TestCase {
	public function testOwnAndSharedIncomeComeWithTheirCurrency(): void {
		$own = new RecurringIncome();
		$own->setId(1);
		$service = $this->createMock(RecurringIncomeService::class);
		$service->method('findAll')->willReturn([$own]);
		$service->expects($this->once())->method('enrichWithCurrency')->with([$own], 'alice')
			->willReturnCallback(function (array $incomes) {
				$incomes[0]->setCurrency('EUR');
				return $incomes;
			});
		$service->expects($this->once())->method('enrichSharedWithCurrency')
			->willReturnCallback(fn (array $rows) => array_map(fn ($r) => ['currency' => 'USD'] + $r, $rows));
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedRecurringIncome')->willReturn([['id' => 2, 'userId' => 'bob', 'accountId' => 9, '_shared' => true]]);

		$controller = new RecurringIncomeController(
			$this->createMock(IRequest::class),
			$service,
			$this->createMock(ValidationService::class),
			$shares,
			$this->createMock(IL10N::class),
			'alice',
			$this->createMock(LoggerInterface::class)
		);

		$data = $controller->index()->getData();

		$this->assertSame(['EUR', 'USD'], array_column($data, 'currency'));
	}
}
