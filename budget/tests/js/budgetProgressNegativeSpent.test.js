/**
 * Dashboard Budget Progress tile with net-negative spending (#361).
 *
 * The tile's data (/api/reports/budget) has been netted since #360, so a
 * category whose refunds exceed its spending arrives with a negative spent.
 * An unclamped percentage renders `width: -20%` — invalid CSS, dropped —
 * and `.budget-progress-fill` has no base width, so the fill paints the
 * bar FULL for a category that is actually in credit.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard() {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        dashboardConfig: { widgets: { tileSettings: {}, instances: {} } },
    };
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = '<div id="budget-progress"></div>';
});

describe('updateBudgetProgressWidget', () => {
    it('clamps the fill to zero for a net-negative category', () => {
        makeDashboard().updateBudgetProgressWidget([
            { categoryName: 'Phone', budgeted: 200, spent: -40, color: '#123456' },
        ]);

        const html = document.getElementById('budget-progress').innerHTML;
        expect(html).toContain('width: 0%');
        expect(html).not.toContain('width: -');
    });

    it('leaves ordinary spending untouched', () => {
        makeDashboard().updateBudgetProgressWidget([
            { categoryName: 'Phone', budgeted: 200, spent: 50, color: '#123456' },
        ]);

        expect(document.getElementById('budget-progress').innerHTML).toContain('width: 25%');
    });
});
