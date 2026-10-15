/**
 * Choosing which categories the Spending by Category tile shows (#413).
 *
 * The tile keeps a list of the categories the user unticked in its own
 * settings, so duplicates can differ and a category made later shows up
 * without anyone ticking it. Unticking a parent hides its whole branch, the
 * way excluded_from_reports does, and the rows go before the top-level rollup
 * so the two settings agree. The total and percentages cover what is shown.
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
import {
    hiddenCategoryBranch,
    withoutHiddenCategories,
    categoryPickerRows,
} from '../../src/utils/categoryVisibility.js';

// Housing > Rent, Housing > Energy > Electricity; Food; Salary (income)
const CATEGORIES = [
    { id: 1, name: 'Housing', type: 'expense', parentId: null, sortOrder: 1, color: '#111' },
    { id: 2, name: 'Rent', type: 'expense', parentId: 1, sortOrder: 0, color: '#222' },
    { id: 3, name: 'Energy', type: 'expense', parentId: 1, sortOrder: 1, color: '#333' },
    { id: 4, name: 'Electricity', type: 'expense', parentId: 3, sortOrder: 0, color: '#444' },
    { id: 5, name: 'Food', type: 'expense', parentId: null, sortOrder: 0, color: '#555' },
    { id: 6, name: 'Salary', type: 'income', parentId: null, sortOrder: 2, color: '#666' },
];

const SPENDING = [
    { id: 2, name: 'Rent', color: '#222', total: 600 },
    { id: 4, name: 'Electricity', color: '#444', total: 100 },
    { id: 3, name: 'Energy', color: '#333', total: 50 },
    { id: 5, name: 'Food', color: '#555', total: 250 },
];

describe('hiddenCategoryBranch', () => {
    it('hides nothing when nothing is unticked', () => {
        expect(hiddenCategoryBranch([], CATEGORIES).size).toBe(0);
        expect(hiddenCategoryBranch(undefined, CATEGORIES).size).toBe(0);
    });

    it('hides a leaf on its own', () => {
        expect([...hiddenCategoryBranch([5], CATEGORIES)]).toEqual([5]);
    });

    it('hides everything under an unticked parent, at any depth', () => {
        expect([...hiddenCategoryBranch([1], CATEGORIES)].sort()).toEqual([1, 2, 3, 4]);
    });

    it('accepts ids saved as strings', () => {
        expect(hiddenCategoryBranch(['3'], CATEGORIES).has(4)).toBe(true);
    });

    it('keeps a hidden id whose category has since been deleted, harmlessly', () => {
        expect(hiddenCategoryBranch([99], CATEGORIES).has(99)).toBe(true);
    });

    it('does not loop on a damaged parent cycle', () => {
        const cyclic = [
            { id: 7, name: 'A', type: 'expense', parentId: 8 },
            { id: 8, name: 'B', type: 'expense', parentId: 7 },
        ];
        expect([...hiddenCategoryBranch([7], cyclic)].sort()).toEqual([7, 8]);
    });
});

describe('withoutHiddenCategories', () => {
    it('drops the hidden rows and keeps the rest in order', () => {
        const rows = withoutHiddenCategories(SPENDING, [5], CATEGORIES);
        expect(rows.map(r => r.id)).toEqual([2, 4, 3]);
    });

    it('drops a whole branch for an unticked parent', () => {
        const rows = withoutHiddenCategories(SPENDING, [3], CATEGORIES);
        expect(rows.map(r => r.id)).toEqual([2, 5]);
    });

    it('reads a row category from categoryId too', () => {
        const rows = withoutHiddenCategories([{ categoryId: 5, total: 1 }, { categoryId: 2, total: 1 }], [5], CATEGORIES);
        expect(rows.map(r => r.categoryId)).toEqual([2]);
    });

    it('returns the rows untouched when nothing is hidden', () => {
        expect(withoutHiddenCategories(SPENDING, [], CATEGORIES)).toEqual(SPENDING);
    });
});

describe('categoryPickerRows', () => {
    it('lists expense categories as a tree, parents before their children', () => {
        const rows = categoryPickerRows(CATEGORIES);
        expect(rows.map(r => [r.name, r.depth])).toEqual([
            ['Food', 0],
            ['Housing', 0],
            ['Rent', 1],
            ['Energy', 1],
            ['Electricity', 2],
        ]);
    });

    it('leaves income categories out', () => {
        expect(categoryPickerRows(CATEGORIES).some(r => r.name === 'Salary')).toBe(false);
    });

    it('treats a category whose parent is missing as top level', () => {
        const rows = categoryPickerRows([{ id: 9, name: 'Orphan', type: 'expense', parentId: 404 }]);
        expect(rows).toEqual([{ id: 9, name: 'Orphan', depth: 0, parentId: 404 }]);
    });
});

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
    return mod;
}

function legendNames() {
    return [...document.querySelectorAll('.spending-category-name')].map(el => el.textContent);
}

describe('DashboardModule.updateSpendingChart with hidden categories', () => {
    beforeEach(() => {
        chartInstances.length = 0;
        document.body.innerHTML = `
            <canvas id="spending-chart"></canvas>
            <div id="spending-chart-legend"></div>`;
        HTMLCanvasElement.prototype.getContext = () => ({});
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('shows every category when nothing is hidden', () => {
        makeDashboard().updateSpendingChart(SPENDING.map(r => ({ ...r })));

        expect(legendNames()).toEqual(['Rent', 'Food', 'Electricity', 'Energy']);
        expect(document.querySelector('.spending-hidden-note')).toBeNull();
    });

    it('leaves the hidden categories out of the chart and the legend', () => {
        makeDashboard({ spendingChart: { hiddenCategories: [5] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })));

        expect(legendNames()).toEqual(['Rent', 'Electricity', 'Energy']);
        expect(chartInstances[0].config.data.labels).toEqual(['Rent', 'Electricity', 'Energy']);
    });

    it('totals only what is shown, so the percentages add up to 100', () => {
        makeDashboard({ spendingChart: { hiddenCategories: [5] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })));

        const header = document.querySelector('.spending-breakdown-header').textContent;
        expect(header).toContain('£750.00');
        const percentages = [...document.querySelectorAll('.spending-percentage')]
            .map(el => parseFloat(el.textContent));
        expect(percentages).toEqual([80, 13.3, 6.7]);
    });

    it('says how many categories with spending it is leaving out', () => {
        makeDashboard({ spendingChart: { hiddenCategories: [3] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })));

        expect(document.querySelector('.spending-hidden-note').textContent).toContain('2 categories hidden');
    });

    it('drops a hidden subcategory before rolling up to top level', () => {
        makeDashboard({ spendingChart: { topLevelOnly: true, hiddenCategories: [3] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })));

        expect(legendNames()).toEqual(['Housing', 'Food']);
        const amounts = [...document.querySelectorAll('.spending-amount')].map(el => el.textContent);
        expect(amounts).toEqual(['£600.00', '£250.00']);
    });

    it('keeps the hidden subcategories out of a rolled-up slice drill-down', () => {
        const dash = makeDashboard({ spendingChart: { topLevelOnly: true, hiddenCategories: [3] } });
        dash.updateSpendingChart(SPENDING.map(r => ({ ...r })));

        chartInstances[0].config.options.onClick({}, [{ index: 0 }]);

        expect(dash.app.openTransactionsForCategory).toHaveBeenCalledWith([1, 2], expect.any(Object));
    });

    it('reads a duplicate tile from its own settings', () => {
        document.body.innerHTML = `
            <div class="dashboard-card" data-widget-id="spendingChart__2">
                <canvas></canvas><div class="spending-legend"></div>
            </div>`;
        makeDashboard({ spendingChart: { hiddenCategories: [5] }, spendingChart__2: { hiddenCategories: [2] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })), 'spendingChart__2');

        expect(legendNames()).toEqual(['Food', 'Electricity', 'Energy']);
    });

    it('says so when every category with spending is hidden', () => {
        makeDashboard({ spendingChart: { hiddenCategories: [1, 5] } })
            .updateSpendingChart(SPENDING.map(r => ({ ...r })));

        expect(legendNames()).toEqual([]);
        expect(document.querySelector('.spending-hidden-note').textContent).toContain('4 categories hidden');
    });
});

describe('the Spending by Category tile settings picker', () => {
    function pickerDashboard(tileSettings = {}) {
        const dash = makeDashboard(tileSettings);
        dash.saveDashboardVisibility = vi.fn();
        dash.refreshTileAfterSettingsChange = vi.fn();
        document.body.innerHTML = '<div id="tile-settings-modal-list"></div>';
        return dash;
    }

    function boxes() {
        return Object.fromEntries(
            [...document.querySelectorAll('#tile-settings-modal-list input[type="checkbox"]')]
                .map(cb => [cb.dataset.categoryId, { checked: cb.checked, disabled: cb.disabled }])
        );
    }

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('lists every expense category ticked when nothing is hidden', () => {
        pickerDashboard().renderSpendingTileCategoryList('spendingChart');

        const all = boxes();
        expect(Object.keys(all).sort()).toEqual(['1', '2', '3', '4', '5']);
        expect(Object.values(all).every(b => b.checked && !b.disabled)).toBe(true);
    });

    it('unticks a saved hidden category and greys out what it hides', () => {
        pickerDashboard({ spendingChart: { hiddenCategories: [3] } })
            .renderSpendingTileCategoryList('spendingChart');

        const all = boxes();
        expect(all['3']).toEqual({ checked: false, disabled: false });
        expect(all['4']).toEqual({ checked: false, disabled: true });
        expect(all['2']).toEqual({ checked: true, disabled: false });
    });

    it('saves an untick to the tile and redraws it', () => {
        const dash = pickerDashboard();
        dash.renderSpendingTileCategoryList('spendingChart');

        const food = document.querySelector('input[data-category-id="5"]');
        food.checked = false;
        food.dispatchEvent(new Event('change'));

        expect(dash.dashboardConfig.widgets.tileSettings.spendingChart.hiddenCategories).toEqual([5]);
        expect(dash.saveDashboardVisibility).toHaveBeenCalled();
        expect(dash.refreshTileAfterSettingsChange).toHaveBeenCalledWith('spendingChart', 'widgets');
    });

    it('saves a re-tick by taking the category off the hidden list', () => {
        const dash = pickerDashboard({ spendingChart__2: { hiddenCategories: [5, 3] } });
        dash.renderSpendingTileCategoryList('spendingChart__2');

        const energy = document.querySelector('input[data-category-id="3"]');
        energy.checked = true;
        energy.dispatchEvent(new Event('change'));

        expect(dash.dashboardConfig.widgets.tileSettings.spendingChart__2.hiddenCategories).toEqual([5]);
        expect(boxes()['4']).toEqual({ checked: true, disabled: false });
    });
});
