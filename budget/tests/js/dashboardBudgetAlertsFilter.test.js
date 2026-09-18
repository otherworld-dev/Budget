/**
 * Choosing which categories the Budget Alerts tile shows (#389).
 *
 * Since #269 a category with no budget of its own falls back to the amount its
 * bills commit it to, so bills the user never budgeted against turned up on the
 * tile reading "239% over". The filter is two plain user settings, applied
 * server-side so the notifications and the digest agree with the tile; the
 * frontend reads them to draw the picker and to explain an empty tile.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace('%n', count),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(settings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings,
        categories: [],
        dashboardConfig: { widgets: { tileSettings: {}, instances: {} } },
    };
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod.getPrimaryCurrency = () => 'GBP';
    return mod;
}

function mountTile() {
    document.body.innerHTML = `
        <div id="budget-alerts-card"><div id="budget-alerts"></div></div>
    `;
    return {
        card: document.getElementById('budget-alerts-card'),
        container: document.getElementById('budget-alerts'),
    };
}

const ALERT = {
    categoryId: 4,
    categoryName: 'Groceries',
    budgetAmount: 400,
    spent: 500,
    percentage: 125,
    severity: 'danger',
};

describe('DashboardModule.getBudgetAlertFilter', () => {
    it('covers every budget when nothing is configured', () => {
        expect(makeDashboard().getBudgetAlertFilter()).toEqual({ scope: 'all', muted: [] });
    });

    it('reads the narrower scope', () => {
        const dash = makeDashboard({ budget_alert_scope: 'manual' });

        expect(dash.getBudgetAlertFilter().scope).toBe('manual');
    });

    it('reads the muted categories', () => {
        const dash = makeDashboard({ budget_alert_muted_categories: '[3,7]' });

        expect(dash.getBudgetAlertFilter().muted).toEqual([3, 7]);
    });

    it('treats a malformed muted setting as nothing muted', () => {
        const dash = makeDashboard({ budget_alert_muted_categories: 'not json' });

        expect(dash.getBudgetAlertFilter().muted).toEqual([]);
    });
});

describe('DashboardModule.updateBudgetAlertsWidget', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('hides the tile when there is nothing to alert about', () => {
        const { card } = mountTile();

        makeDashboard().updateBudgetAlertsWidget([]);

        expect(card.style.display).toBe('none');
    });

    it('draws the alerts it is given', () => {
        const { card, container } = mountTile();

        makeDashboard().updateBudgetAlertsWidget([ALERT]);

        expect(card.style.display).toBe('');
        expect(container.textContent).toContain('Groceries');
    });

    /**
     * Muting everything would otherwise hide the tile, and the gear with it,
     * leaving no way back from the dashboard.
     */
    it('keeps the tile up and says so when muting silenced everything', () => {
        const { card, container } = mountTile();
        const dash = makeDashboard({ budget_alert_muted_categories: '[3,7]' });

        dash.updateBudgetAlertsWidget([]);

        expect(card.style.display).toBe('');
        expect(container.textContent).toMatch(/muted/i);
    });

    it('keeps the tile up when the narrower scope silenced everything', () => {
        const { card, container } = mountTile();
        const dash = makeDashboard({ budget_alert_scope: 'manual' });

        dash.updateBudgetAlertsWidget([]);

        expect(card.style.display).toBe('');
        expect(container.textContent).not.toBe('');
    });
});

describe('DashboardModule._budgetAlertsTileConfigHtml', () => {
    const STATUSES = [
        { categoryId: 4, categoryName: 'Groceries', budgetAmount: 400, budgetSource: 'manual' },
        { categoryId: 9, categoryName: 'EKZ', budgetAmount: 56.03, budgetSource: 'recurring' },
    ];

    function render(dash, statuses = STATUSES) {
        document.body.innerHTML = `<div id="list">${dash._budgetAlertsTileConfigHtml(statuses)}</div>`;
        return document.getElementById('list');
    }

    it('lists every category that has a budget in play', () => {
        const list = render(makeDashboard());

        expect(list.textContent).toContain('Groceries');
        expect(list.textContent).toContain('EKZ');
    });

    it('ticks a category that is free to alert', () => {
        const list = render(makeDashboard());

        expect(list.querySelector('input[data-category-id="4"]').checked).toBe(true);
    });

    it('unticks a muted category', () => {
        const list = render(makeDashboard({ budget_alert_muted_categories: '[9]' }));

        expect(list.querySelector('input[data-category-id="9"]').checked).toBe(false);
    });

    it('says which budgets came from a bill rather than from the user', () => {
        const list = render(makeDashboard());
        const derived = list.querySelector('[data-category-id="9"]');

        expect(derived.textContent).toMatch(/bill/i);
    });

    it('escapes a category name', () => {
        const list = render(makeDashboard(), [
            { categoryId: 1, categoryName: '<img src=x>', budgetAmount: 10, budgetSource: 'manual' },
        ]);

        expect(list.querySelector('img')).toBeNull();
    });
});
