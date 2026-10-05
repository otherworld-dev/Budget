/**
 * The dashboard's Budget Progress tile forgot its saved date range, account
 * and "exclude shared" on every page load. The dashboard re-fetches the
 * tiles that have such settings after its first draw, and this one wasn't
 * in the list, so it always showed the current month for every account.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [],
        categories: [],
        dashboardConfig: { widgets: { tileSettings } },
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('Budget Progress settings after a reload', () => {
    it('re-fetches the tile with its saved range, account or exclude-shared', async () => {
        for (const saved of [{ dateRange: '6m' }, { accountId: 3 }, { excludeShared: true }]) {
            const mod = makeDashboard({ budgetProgress: saved });
            mod.refreshBudgetProgressWidget = vi.fn(async () => {});

            await mod.refreshSavedWidgetSelections();

            expect(mod.refreshBudgetProgressWidget).toHaveBeenCalledWith('budgetProgress');
        }
    });

    it('leaves a tile without such settings to the first draw', async () => {
        const mod = makeDashboard({ budgetProgress: { topLevelOnly: true } });
        mod.refreshBudgetProgressWidget = vi.fn(async () => {});

        await mod.refreshSavedWidgetSelections();

        expect(mod.refreshBudgetProgressWidget).not.toHaveBeenCalled();
    });
});
