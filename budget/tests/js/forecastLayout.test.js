/**
 * The Forecast page's tidy-up: one balance chart with income and spending in
 * its tooltip, the savings rate beside the monthly averages, and a category
 * list cut to the biggest few behind "Show all".
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

const charts = [];
vi.mock('../../src/utils/chart.js', () => ({
    default: class {
        constructor(ctx, config) {
            this.config = config;
            this.data = config.data;
            charts.push(this);
        }
        destroy() {}
        update() {}
    },
}));

import ForecastModule, { CATEGORY_TRENDS_SHOWN } from '../../src/modules/forecast/ForecastModule.js';

function makeModule() {
    const mod = Object.create(ForecastModule.prototype);
    mod.app = { settings: {}, accounts: [] };
    mod.forecastCurrency = 'GBP';
    mod.formatCurrency = (v) => `£${v}`;
    mod.showAllCategories = false;
    return mod;
}

const categories = Array.from({ length: 14 }, (_, i) => ({ name: `Cat ${i + 1}`, avgMonthly: 100 - i, trend: 'stable' }));

describe('Forecast category list', () => {
    let mod;

    beforeEach(() => {
        document.body.innerHTML = '<div id="category-trends-list"></div><button id="category-trends-toggle"></button>';
        mod = makeModule();
        mod.forecastData = { categoryBreakdown: categories };
    });

    it('shows the biggest categories and offers the rest', () => {
        mod.displayCategoryTrends(categories);
        expect(document.querySelectorAll('.category-trend-item')).toHaveLength(CATEGORY_TRENDS_SHOWN);
        const toggle = document.getElementById('category-trends-toggle');
        expect(toggle.style.display).toBe('');
        expect(toggle.textContent).toBe('Show all (14)');
    });

    it('expands and collapses from the toggle', () => {
        mod.setupForecastEventListeners();
        mod.displayCategoryTrends(categories);
        const toggle = document.getElementById('category-trends-toggle');

        toggle.click();
        expect(document.querySelectorAll('.category-trend-item')).toHaveLength(14);
        expect(toggle.textContent).toBe('Show fewer');

        toggle.click();
        expect(document.querySelectorAll('.category-trend-item')).toHaveLength(CATEGORY_TRENDS_SHOWN);
    });

    it('hides the toggle when everything fits', () => {
        mod.displayCategoryTrends(categories.slice(0, 4));
        expect(document.querySelectorAll('.category-trend-item')).toHaveLength(4);
        expect(document.getElementById('category-trends-toggle').style.display).toBe('none');
    });
});

describe('Forecast balance chart', () => {
    it('draws only the balance and puts income and spending in the tooltip', () => {
        document.body.innerHTML = '<canvas id="balance-projection-chart"></canvas>';
        HTMLCanvasElement.prototype.getContext = () => ({});
        const mod = makeModule();
        const projections = [
            { yearMonth: '2026-12', month: 'Dec 2026', balance: 1500, income: 2000, expenses: 1500 },
            { yearMonth: '2027-01', month: 'Jan 2027', balance: 1700, income: 2000, expenses: 1800 },
        ];

        mod.displayBalanceProjectionChart(projections);
        const chart = charts[charts.length - 1];

        expect(chart.config.data.datasets).toHaveLength(1);
        expect(chart.config.options.scales.y1).toBeUndefined();
        const afterBody = chart.config.options.plugins.tooltip.callbacks.afterBody;
        expect(afterBody([{ dataIndex: 1 }])).toEqual(['Projected Income: £2000', 'Projected Expenses: £1800']);
        expect(afterBody([])).toEqual([]);
    });
});

describe('Forecast monthly trends', () => {
    it('shows the savings rate under the average savings', () => {
        document.body.innerHTML = '<span id="avg-savings"></span><span id="savings-rate"></span>';
        const mod = makeModule();
        mod.displayTrendsSummary(
            { avgMonthlyIncome: 4000, avgMonthlyExpenses: 3000, avgMonthlySavings: 1000, incomeDirection: 'up', expenseDirection: 'down', savingsDirection: 'stable' },
            { savingsRate: 25 }
        );
        expect(document.getElementById('avg-savings').textContent).toBe('£1000');
        expect(document.getElementById('savings-rate').textContent).toBe('25% of income saved');
    });
});
