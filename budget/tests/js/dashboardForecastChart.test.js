/**
 * Cash Flow Forecast and Year-over-Year each had a card, a canvas and a fetch,
 * but no renderer at all -- their update functions were referenced once with
 * optional chaining and defined nowhere, so both tiles stayed blank. These
 * tests cover the renderers that fill them.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

const chartInstances = [];
vi.mock('chart.js/auto', () => ({
    default: class {
        constructor(ctx, config) {
            this.config = config;
            this.destroyed = false;
            chartInstances.push(this);
        }
        destroy() { this.destroyed = true; }
    },
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(widgetData = {}, tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        dashboardConfig: { widgets: { tileSettings } },
        widgetData,
        widgetDataLoaded: {},
        accounts: [],
        charts: {},
        getPrimaryCurrency: () => 'GBP',
    };
    mod.formatCurrency = (v) => 'GBP' + Number(v).toFixed(2);
    return mod;
}

beforeEach(() => {
    chartInstances.length = 0;
    document.body.innerHTML = `
        <div id="cash-flow-forecast-card" class="dashboard-card" data-widget-id="cashFlowForecast">
            <div class="chart-container"><canvas id="cash-flow-forecast-chart"></canvas></div>
            <div id="cash-flow-forecast-empty" class="chart-empty-state"></div>
        </div>`;
    HTMLCanvasElement.prototype.getContext = () => ({});
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('updateCashFlowForecastWidget', () => {
    const forecast = {
        currentBalance: 1000,
        monthlyProjections: [
            { month: 'Sep 2026', balance: 1200, income: 3000, expenses: 2800, savings: 200 },
            { month: 'Oct 2026', balance: 1450, income: 3000, expenses: 2750, savings: 250 },
        ],
    };

    it('plots the projected balance for each month', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();

        const { data } = chartInstances[0].config;
        expect(data.datasets[0].data).toEqual([1000, 1200, 1450]);
    });

    it('anchors the line at today before the first projection', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();

        const { data } = chartInstances[0].config;
        expect(data.labels[0]).toBe('Now');
        expect(data.labels).toHaveLength(3);
    });

    it('draws a line chart', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();

        expect(chartInstances[0].config.type).toBe('line');
    });

    it('shows the empty state when there is nothing to project', () => {
        const dash = makeDashboard({ cashFlowForecast: { monthlyProjections: [] } });

        dash.updateCashFlowForecastWidget();

        expect(chartInstances).toHaveLength(0);
        expect(document.getElementById('cash-flow-forecast-empty').style.display).toBe('flex');
    });

    it('survives having no data at all', () => {
        const dash = makeDashboard({});

        expect(() => dash.updateCashFlowForecastWidget()).not.toThrow();
    });

    it('destroys the previous chart before redrawing', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();
        dash.updateCashFlowForecastWidget();

        expect(chartInstances[0].destroyed).toBe(true);
        expect(chartInstances).toHaveLength(2);
    });

    it('has no projection behind the "Now" anchor', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();

        const cb = chartInstances[0].config.options.plugins.tooltip.callbacks;
        expect(cb.afterBody([{ dataIndex: 0 }])).toBe('');
    });

    it('shows income and expenses for the first projection', () => {
        const dash = makeDashboard({ cashFlowForecast: forecast });

        dash.updateCashFlowForecastWidget();

        const cb = chartInstances[0].config.options.plugins.tooltip.callbacks;
        expect(cb.afterBody([{ dataIndex: 1 }])).toHaveLength(2);
    });
});

describe('updateYoyComparisonWidget', () => {
    const payload = {
        type: 'year',
        years: [
            { year: 2026, income: 50000, expenses: 42000, isCurrent: true },
            { year: 2025, income: 47000, expenses: 44000, isCurrent: false },
        ],
    };

    beforeEach(() => {
        document.body.innerHTML += `
            <div id="yoy-comparison-card" class="dashboard-card" data-widget-id="yoyComparison">
                <div class="chart-container"><canvas id="yoy-comparison-chart"></canvas></div>
                <div id="yoy-comparison-empty" class="chart-empty-state"></div>
            </div>`;
    });

    it('plots income and expenses per year', () => {
        const dash = makeDashboard({ yoyComparison: payload });

        dash.updateYoyComparisonWidget();

        const { data } = chartInstances[0].config;
        expect(data.datasets).toHaveLength(2);
        expect(data.datasets[0].data).toEqual([47000, 50000]);
        expect(data.datasets[1].data).toEqual([44000, 42000]);
    });

    it('puts the oldest year first so the chart reads left to right', () => {
        const dash = makeDashboard({ yoyComparison: payload });

        dash.updateYoyComparisonWidget();

        expect(chartInstances[0].config.data.labels).toEqual(['2025', '2026']);
    });

    it('draws a bar chart', () => {
        const dash = makeDashboard({ yoyComparison: payload });

        dash.updateYoyComparisonWidget();

        expect(chartInstances[0].config.type).toBe('bar');
    });

    it('shows the legend by default', () => {
        const dash = makeDashboard({ yoyComparison: payload });

        dash.updateYoyComparisonWidget();

        expect(chartInstances[0].config.options.plugins.legend.display).toBe(true);
    });

    it('hides the legend when the tile setting turns it off', () => {
        const dash = makeDashboard({ yoyComparison: payload }, { yoyComparison: { showLegend: false } });

        dash.updateYoyComparisonWidget();

        expect(chartInstances[0].config.options.plugins.legend.display).toBe(false);
    });

    it('shows the empty state when there are no years', () => {
        const dash = makeDashboard({ yoyComparison: { type: 'year', years: [] } });

        dash.updateYoyComparisonWidget();

        expect(chartInstances).toHaveLength(0);
        expect(document.getElementById('yoy-comparison-empty').style.display).toBe('flex');
    });

    it('survives having no data at all', () => {
        const dash = makeDashboard({});

        expect(() => dash.updateYoyComparisonWidget()).not.toThrow();
    });

    it('does not mutate the source array when reversing it for display', () => {
        const dash = makeDashboard({ yoyComparison: payload });

        dash.updateYoyComparisonWidget();

        expect(payload.years[0].year).toBe(2026);
    });
});
