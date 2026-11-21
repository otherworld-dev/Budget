/**
 * The Uncategorized hero tile (#493).
 *
 * It counted the rows of /api/transactions/uncategorized, which stop at a
 * limit of 100, so a ledger with thousands of uncategorised transactions
 * showed 100. It now shows the server's count, the same number the
 * transactions list gives filtered to Uncategorized.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

let requested;

function makeDashboard() {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        dashboardConfig: { widgets: { tileSettings: {} } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [],
    };
    return mod;
}

beforeEach(() => {
    requested = [];
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async (url) => {
        requested.push(url);
        return { ok: true, json: async () => ({ count: 3412 }) };
    });
    document.body.innerHTML = '<div id="uncategorized-count-content"></div>';
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
    document.body.innerHTML = '';
});

describe('Uncategorized tile', () => {
    it('asks the server for a count rather than a capped row list', async () => {
        const dash = makeDashboard();

        await dash.loadWidgetData('uncategorizedCount');

        expect(requested).toHaveLength(1);
        expect(requested[0]).toContain('/apps/budget/api/transactions/uncategorized/count');
        expect(requested[0]).not.toContain('limit=');
        expect(dash.widgetData.uncategorizedCount).toEqual({ count: 3412 });
    });

    it('shows a count above 100', () => {
        const dash = makeDashboard();
        dash.app.widgetData.uncategorizedCount = { count: 3412 };

        dash.updateUncategorizedCountWidget();

        const value = document.querySelector('#uncategorized-count-content .widget-stat-value');
        expect(value.textContent).toBe('3412');
    });

    it('shows 0 when the count is missing', () => {
        const dash = makeDashboard();
        dash.app.widgetData.uncategorizedCount = null;

        dash.updateUncategorizedCountWidget();

        const value = document.querySelector('#uncategorized-count-content .widget-stat-value');
        expect(value.textContent).toBe('0');
    });
});
