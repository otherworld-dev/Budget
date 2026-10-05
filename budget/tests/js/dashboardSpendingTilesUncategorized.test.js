/**
 * The dashboard's category tiles after a settings change.
 *
 * The spending report gained a last row for money with no category, for
 * the Reports page. The Spending by Category and Top Categories tiles first
 * draw from the dashboard summary, which has no such row, but redraw from
 * the spending report once a tile setting changes. So unticking one
 * category added every uncategorized purchase as an "Unknown" slice and the
 * tile's total jumped. The redraw now shows categories only, like the
 * first draw.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace('%n', count),
}));

const chartInstances = [];
vi.mock('../../src/utils/chart.js', () => ({
    default: class {
        constructor(ctx, config) {
            this.config = config;
            chartInstances.push(this);
        }
        destroy() {}
    },
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const CATEGORIES = [
    { id: 1, name: 'Housing', type: 'expense', parentId: null, color: '#111' },
    { id: 5, name: 'Food', type: 'expense', parentId: null, color: '#555' },
];

// /api/reports/spending's answer, uncategorized row last
const REPORT = {
    data: [
        { id: 1, name: 'Housing', color: '#111', total: 210 },
        { id: 5, name: 'Food', color: '#555', total: 349.34 },
        { id: null, name: null, color: null, total: 1183.54, uncategorized: true },
    ],
};

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        categories: CATEGORIES,
        dashboardConfig: { widgets: { tileSettings } },
        charts: {},
        openTransactionsForCategory: vi.fn(),
    };
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod._tileRangeParams = () => ({ startDate: '2026-04-01', endDate: '2026-09-30' });
    return mod;
}

beforeEach(() => {
    chartInstances.length = 0;
    document.body.innerHTML = `
        <canvas id="spending-chart"></canvas>
        <div id="spending-chart-legend"></div>
        <div id="top-categories-list"></div>`;
    HTMLCanvasElement.prototype.getContext = () => ({});
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    global.fetch = vi.fn(async () => ({
        ok: true,
        status: 200,
        headers: { get: () => null },
        json: async () => JSON.parse(JSON.stringify(REPORT)),
    }));
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe('Spending by Category redrawn after a settings change', () => {
    it('shows the categories only, as on its first draw', async () => {
        await makeDashboard({ spendingChart: { hiddenCategories: [1] } }).refreshSpendingChart('spendingChart');

        expect(chartInstances[0].config.data.labels).toEqual(['Food']);
        expect(document.querySelector('.spending-breakdown-header').textContent).toContain('£349.34');
        expect(document.getElementById('spending-chart-legend').textContent).not.toContain('Unknown');
    });
});

describe('Top Categories redrawn after a settings change', () => {
    it('lists the categories only', async () => {
        await makeDashboard().refreshTopCategoriesWidget('topCategories');

        const names = [...document.querySelectorAll('#top-categories-list .category-name')].map(el => el.textContent);
        expect(names).toEqual(['Food', 'Housing']);
    });
});
