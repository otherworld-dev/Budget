/**
 * Bills Due Soon used to filter by its forward-horizon setting inside
 * loadWidgetData, so the cached widgetData was already filtered at the old
 * horizon and changing the setting rendered nothing new until the page
 * reloaded. It now caches the unfiltered bills and filters at render time
 * in updateBillsDueSoonWidget, the same way Upcoming Bills already did --
 * so both tiles can re-render a settings change from cache, with no refetch.
 *
 * Upcoming Bills has its own gap: its refresh-map entry calls the renderer
 * with no argument, and the renderer's only source for the array was the
 * one-time initial-load call -- so the very first thing a user does with its
 * new Look Ahead setting blanks the tile. The initial load now also caches
 * the array, and the renderer falls back to that cache when called bare.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const bill = (name, due) => ({ name, nextDueDate: due, amount: 10, currency: 'GBP' });

function makeDashboard(widgetData, tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        dashboardConfig: { widgets: { tileSettings, instances: {} } },
        widgetData,
        widgetDataLoaded: {},
    };
    mod.formatCurrency = (v) => `GBP${Number(v).toFixed(2)}`;
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="bills-due-soon-list"></div>
        <div id="upcoming-bills"></div>
    `;
    // Any fetch during these tests would mean a settings change forced a
    // refetch instead of re-rendering the cached, unfiltered bills.
    global.fetch = vi.fn(() => { throw new Error('should not refetch'); });
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    vi.useRealTimers();
});

describe('Bills Due Soon horizon', () => {
    // Near: 8 days out, inside every horizon offered. Far: 52 days out,
    // inside the 90-day horizon but outside the default 30-day one.
    const bills = [bill('Near', '2026-09-01'), bill('Far', '2026-10-15')];

    it('renders only bills within the default 30-day horizon', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 24));
        const dash = makeDashboard({ billsDueSoon: bills });

        dash.updateBillsDueSoonWidget();

        const html = document.getElementById('bills-due-soon-list').innerHTML;
        expect(html).toContain('Near');
        expect(html).not.toContain('Far');
    });

    it('renders the wider horizon after a settings change, with no refetch', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 24));
        const dash = makeDashboard({ billsDueSoon: bills }, { billsDueSoon: { forwardHorizon: 30 } });

        dash.updateBillsDueSoonWidget();
        expect(document.getElementById('bills-due-soon-list').innerHTML).not.toContain('Far');

        // This is what setting the "Look ahead" dropdown to 90 does.
        dash.dashboardConfig.widgets.tileSettings.billsDueSoon.forwardHorizon = 90;
        dash.refreshTileAfterSettingsChange('billsDueSoon', 'widgets');

        const html = document.getElementById('bills-due-soon-list').innerHTML;
        expect(html).toContain('Near');
        expect(html).toContain('Far');
        expect(global.fetch).not.toHaveBeenCalled();
    });
});

describe('Upcoming Bills renders from cache with no argument', () => {
    it('falls back to widgetData.upcomingBills when called bare', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 24));
        const dash = makeDashboard({ upcomingBills: [bill('Rent', '2026-09-01')] });

        dash.updateUpcomingBillsWidget();

        expect(document.getElementById('upcoming-bills').innerHTML).toContain('Rent');
    });

    it('re-renders the wider horizon via the settings-change refresh map, with no refetch', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 24));
        const bills = [bill('Near', '2026-09-01'), bill('Far', '2026-10-15')];
        const dash = makeDashboard({ upcomingBills: bills }, { upcomingBills: { forwardHorizon: 30 } });

        dash.refreshTileAfterSettingsChange('upcomingBills', 'widgets');
        expect(document.getElementById('upcoming-bills').innerHTML).not.toContain('Far');

        dash.dashboardConfig.widgets.tileSettings.upcomingBills.forwardHorizon = 90;
        dash.refreshTileAfterSettingsChange('upcomingBills', 'widgets');

        const html = document.getElementById('upcoming-bills').innerHTML;
        expect(html).toContain('Near');
        expect(html).toContain('Far');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('still shows the empty state when there is nothing cached', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 24));
        const dash = makeDashboard({});

        dash.updateUpcomingBillsWidget();

        expect(document.getElementById('upcoming-bills').innerHTML).toContain('No upcoming bills');
    });
});
