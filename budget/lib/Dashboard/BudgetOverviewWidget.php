<?php

declare(strict_types=1);

namespace OCA\Budget\Dashboard;

use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\CurrencyConversionService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Nextcloud dashboard widget: total balance and this month's budget status.
 * Server-rendered via IAPIWidgetV2 — no app JS bundle required.
 */
class BudgetOverviewWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget, IButtonWidget {

    public function __construct(
        private AccountService $accountService,
        private BudgetAlertService $budgetAlertService,
        private AmountFormatter $amountFormatter,
        private CurrencyConversionService $currencyConversion,
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string {
        return 'budget-overview';
    }

    public function getTitle(): string {
        return $this->l->t('Budget: Overview');
    }

    public function getOrder(): int {
        return 21;
    }

    public function getIconClass(): string {
        return 'icon-budget-widget';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('budget', 'app-dark.svg')
        );
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('budget.page.index') . '#/dashboard';
    }

    public function load(): void {
        Util::addStyle('budget', 'dashboard');
    }

    /**
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        return $this->buildItems($userId);
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $items = $this->buildItems($userId);
        return new WidgetItems(
            $items,
            empty($items) ? $this->l->t('No accounts yet') : ''
        );
    }

    /**
     * @return WidgetButton[]
     */
    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->getUrl(),
                $this->l->t('Open Budget')
            ),
        ];
    }

    /**
     * @return WidgetItem[]
     */
    private function buildItems(string $userId): array {
        $items = [];

        $accountSummary = $this->accountService->getSummary($userId);
        if (($accountSummary['accountCount'] ?? 0) === 0) {
            return [];
        }

        // Everything in this widget is shown in the user's base currency.
        $base = $this->currencyConversion->getBaseCurrency($userId);

        // getSummary()'s totalBalance is a raw cross-currency sum; convert each
        // currency's subtotal to the base currency so multi-currency accounts
        // aren't mixed under one symbol. Currencies with no exchange rate are
        // left out of the total and named, rather than silently distorting it.
        [$totalInBase, $unconverted] = $this->totalInBaseCurrency($userId, $accountSummary, $base);
        $balanceSubtitle = $this->amountFormatter->format($totalInBase, $base);
        if (!empty($unconverted)) {
            $balanceSubtitle .= ' ' . $this->l->t('(excludes %1$s — no exchange rate)', [implode(', ', $unconverted)]);
        }

        $items[] = new WidgetItem(
            $this->l->t('Total balance'),
            $balanceSubtitle,
            $this->getUrl(),
            $this->getIconUrl(),
            'balance'
        );

        $budget = $this->budgetAlertService->getSummary($userId);
        if (($budget['totalCategories'] ?? 0) > 0) {
            $items[] = new WidgetItem(
                $this->l->t('Budget this month'),
                $this->l->t('%1$s of %2$s spent (%3$s%%)', [
                    $this->amountFormatter->format((float) $budget['totalSpent'], $base),
                    $this->amountFormatter->format((float) $budget['totalBudget'], $base),
                    (string) $budget['overallPercentage'],
                ]),
                $this->getUrl(),
                $this->getIconUrl(),
                'budget'
            );

            $overCount = (int) ($budget['overBudgetCount'] ?? 0);
            $warningCount = (int) ($budget['warningCount'] ?? 0);
            if ($overCount > 0 || $warningCount > 0) {
                $parts = [];
                if ($overCount > 0) {
                    $parts[] = $this->l->n('%n category over budget', '%n categories over budget', $overCount);
                }
                if ($warningCount > 0) {
                    $parts[] = $this->l->n('%n warning', '%n warnings', $warningCount);
                }
                $items[] = new WidgetItem(
                    $this->l->t('Attention'),
                    implode(', ', $parts),
                    $this->urlGenerator->linkToRouteAbsolute('budget.page.index') . '#/budget',
                    $this->getIconUrl(),
                    'alerts'
                );
            }
        }

        return $items;
    }

    /**
     * Sum all account balances into the base currency, converting each
     * currency's subtotal where an exchange rate exists.
     *
     * @param array $summary AccountService::getSummary() result
     * @return array{0: float, 1: string[]} [total in base currency, currencies that couldn't be converted]
     */
    private function totalInBaseCurrency(string $userId, array $summary, string $base): array {
        $breakdown = $summary['currencyBreakdown'] ?? [];
        if (empty($breakdown)) {
            // Older/empty summaries: fall back to the raw total (single-currency safe)
            return [(float) ($summary['totalBalance'] ?? 0), []];
        }

        $total = 0.0;
        $unconverted = [];
        foreach ($breakdown as $currency => $amount) {
            $currency = (string) $currency;
            $amount = (float) $amount;
            if ($currency === '' || strtoupper($currency) === strtoupper($base)) {
                $total += $amount;
            } elseif ($this->currencyConversion->canConvert($currency, $userId)) {
                $total += $this->currencyConversion->convertToBaseFloat($amount, $currency, $userId);
            } else {
                $unconverted[] = $currency;
            }
        }

        return [round($total, 2), $unconverted];
    }
}
