<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Dashboard;

use OCA\Budget\Dashboard\BudgetOverviewWidget;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\CurrencyConversionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class BudgetOverviewWidgetTest extends TestCase {
    private BudgetOverviewWidget $widget;
    private AccountService $accountService;
    private BudgetAlertService $budgetAlertService;

    protected function setUp(): void {
        $this->accountService = $this->createMock(AccountService::class);
        $this->budgetAlertService = $this->createMock(BudgetAlertService::class);
        $amountFormatter = $this->createMock(AmountFormatter::class);
        // Base currency is USD in these tests, so amounts render with '$'
        $amountFormatter->method('format')
            ->willReturnCallback(fn(float $a, string $cur) => '$' . number_format($a, 2));
        $currencyConversion = $this->createMock(CurrencyConversionService::class);
        $currencyConversion->method('getBaseCurrency')->willReturn('USD');
        // EUR is convertible at 1.2; anything else (e.g. XYZ) is not
        $currencyConversion->method('canConvert')
            ->willReturnCallback(fn(string $cur, string $u) => strtoupper($cur) === 'EUR');
        $currencyConversion->method('convertToBaseFloat')
            ->willReturnCallback(fn(float $a, string $cur, string $u) => strtoupper($cur) === 'EUR' ? $a * 1.2 : $a);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn(string $text, array $params = []) => vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $params));
        $l->method('n')->willReturnCallback(
            fn(string $singular, string $plural, int $count) => str_replace('%n', (string) $count, $count === 1 ? $singular : $plural)
        );
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/budget/');
        $urlGenerator->method('imagePath')->willReturn('/apps/budget/img/app-dark.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn(string $p) => 'https://nc.test' . $p);

        $this->widget = new BudgetOverviewWidget(
            $this->accountService,
            $this->budgetAlertService,
            $amountFormatter,
            $currencyConversion,
            $l,
            $urlGenerator
        );
    }

    public function testStableIdAndOrder(): void {
        $this->assertSame('budget-overview', $this->widget->getId());
        $this->assertSame(21, $this->widget->getOrder());
    }

    public function testNoAccountsShowsEmptyState(): void {
        $this->accountService->method('getSummary')->willReturn(['accountCount' => 0, 'totalBalance' => 0]);

        $result = $this->widget->getItemsV2('alice');

        $this->assertCount(0, $result->getItems());
        $this->assertSame('No accounts yet', $result->getEmptyContentMessage());
    }

    public function testBalanceOnlyWhenNoBudgets(): void {
        $this->accountService->method('getSummary')->willReturn(['accountCount' => 2, 'totalBalance' => 1500.25]);
        $this->budgetAlertService->method('getSummary')->willReturn(['totalCategories' => 0]);

        $items = $this->widget->getItems('alice');

        $this->assertCount(1, $items);
        $this->assertSame('Total balance', $items[0]->getTitle());
        $this->assertSame('$1,500.25', $items[0]->getSubtitle());
    }

    public function testBudgetLineWhenCategoriesBudgeted(): void {
        $this->accountService->method('getSummary')->willReturn(['accountCount' => 1, 'totalBalance' => 100.0]);
        $this->budgetAlertService->method('getSummary')->willReturn([
            'totalCategories' => 4,
            'totalSpent' => 250.0,
            'totalBudget' => 500.0,
            'overallPercentage' => 50,
            'overBudgetCount' => 0,
            'warningCount' => 0,
        ]);

        $items = $this->widget->getItems('alice');

        $this->assertCount(2, $items);
        $this->assertSame('Budget this month', $items[1]->getTitle());
        $this->assertStringContainsString('$250.00 of $500.00 spent (50%)', $items[1]->getSubtitle());
    }

    public function testMultiCurrencyBalanceConvertsToBase(): void {
        // 100 USD + 50 EUR (×1.2 = 60 USD) = 160 USD; raw totalBalance is ignored
        $this->accountService->method('getSummary')->willReturn([
            'accountCount' => 2,
            'totalBalance' => 150.0,
            'currencyBreakdown' => ['USD' => 100.0, 'EUR' => 50.0],
        ]);
        $this->budgetAlertService->method('getSummary')->willReturn(['totalCategories' => 0]);

        $items = $this->widget->getItems('alice');

        $this->assertSame('$160.00', $items[0]->getSubtitle());
    }

    public function testUnconvertibleCurrencyExcludedAndNoted(): void {
        $this->accountService->method('getSummary')->willReturn([
            'accountCount' => 2,
            'totalBalance' => 150.0,
            'currencyBreakdown' => ['USD' => 100.0, 'XYZ' => 50.0],
        ]);
        $this->budgetAlertService->method('getSummary')->willReturn(['totalCategories' => 0]);

        $items = $this->widget->getItems('alice');

        // XYZ has no rate: kept out of the total, named in the subtitle
        $this->assertStringContainsString('$100.00', $items[0]->getSubtitle());
        $this->assertStringContainsString('XYZ', $items[0]->getSubtitle());
    }

    public function testAttentionLineWhenOverBudget(): void {
        $this->accountService->method('getSummary')->willReturn(['accountCount' => 1, 'totalBalance' => 100.0]);
        $this->budgetAlertService->method('getSummary')->willReturn([
            'totalCategories' => 4,
            'totalSpent' => 600.0,
            'totalBudget' => 500.0,
            'overallPercentage' => 120,
            'overBudgetCount' => 2,
            'warningCount' => 1,
        ]);

        $items = $this->widget->getItems('alice');

        $this->assertCount(3, $items);
        $this->assertSame('Attention', $items[2]->getTitle());
        $this->assertSame('2 categories over budget, 1 warning', $items[2]->getSubtitle());
        $this->assertStringContainsString('#/budget', $items[2]->getLink());
    }
}
