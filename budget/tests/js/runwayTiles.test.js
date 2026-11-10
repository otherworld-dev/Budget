/**
 * The runway dashboard tiles: one fetch shared by every tile, one setup that
 * re-renders them all, the burnRate id they replaced, and text written as
 * text.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const fill = vi.hoisted(() => (text, params = {}) =>
    String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)));

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) => fill(text, params),
    translatePlural: (_app, singular, plural, count, params = {}) => fill(count === 1 ? singular : plural, params),
    getCanonicalLocale: () => 'en',
}));

const chartInstances = vi.hoisted(() => []);
vi.mock('../../src/utils/chart.js', () => ({
    default: class {
        constructor(ctx, config) {
            this.config = config;
            this.destroyed = false;
            chartInstances.push(this);
        }
        destroy() { this.destroyed = true; }
        resize() {}
    },
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import { DASHBOARD_WIDGETS } from '../../src/config/dashboardWidgets.js';
import {
    loadRunway,
    resetRunway,
    renderRunwayTile,
    openRunwaySetup,
    setRunwayContext,
    RUNWAY_TILE_KEYS,
} from '../../src/modules/dashboard/runway/RunwayTiles.js';

function payload(over = {}) {
    return {
        window: { months: 3, effectiveMonths: 3, from: '2026-08-01', to: '2026-10-31' },
        monthlyOut: '1600.00',
        monthlyIn: '250.00',
        excluded: { count: 2, total: '1500.00', ids: [11, 12] },
        scenarios: {
            current: { state: 'running_out', monthlyDrain: '1350.00', months: 20.7, runsOutOn: '2028-07-14' },
            onBudget: { state: 'running_out', monthlyDrain: '1100.00', months: 25.2, runsOutOn: '2028-12-10' },
        },
        differenceMonths: 4.5,
        series: {
            history: [{ date: '2025-11-30', total: '35000.00' }, { date: '2026-11-10', total: '28000.00' }],
            current: [{ date: '2026-11-10', total: '28000.00' }, { date: '2027-03-01', total: '20000.00' }, { date: '2028-07-14', total: '0.00' }],
            onBudget: [{ date: '2026-11-10', total: '28000.00' }, { date: '2028-12-10', total: '0.00' }],
        },
        events: [{ date: '2027-03-01', amount: '-2000.00', description: 'Car', source: 'oneOff' }],
        currency: 'GBP',
        total: '28000.00',
        accounts: [{ id: 1, name: 'Savings', balance: '30000.00' }, { id: 3, name: 'Card', balance: '-2000.00' }],
        skippedAccounts: [],
        oneOffs: [
            { id: 5, description: 'Car <img src=x onerror="window.__xss=1">', amount: '-2000.00', date: '2027-03-01', past: false },
        ],
        settings: { accountIds: [1, 3], windowMonths: 3, includeScheduled: true, accountIdsAreDefault: true },
        ...over,
    };
}

const later = () => payload({
    scenarios: {
        current: { state: 'running_out', monthlyDrain: '1000.00', months: 30.2, runsOutOn: '2029-05-10' },
        onBudget: { state: 'running_out', monthlyDrain: '900.00', months: 33.6, runsOutOn: '2029-08-28' },
    },
    differenceMonths: 3.4,
});

/** Every request, as [method, url, parsed body]. */
let requests;
/** What GET /api/runway answers next. */
let runwayAnswers;

function respond(data, status = 200) {
    return { ok: status < 300, status, json: async () => data };
}

function installFetch() {
    requests = [];
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET';
        requests.push([method, url, init.body ? JSON.parse(init.body) : null]);
        if (url === '/apps/budget/api/runway') {
            return respond(runwayAnswers.length > 1 ? runwayAnswers.shift() : runwayAnswers[0]);
        }
        if (url === '/apps/budget/api/settings') return respond({ success: true });
        if (url.startsWith('/apps/budget/api/runway/one-offs')) {
            return respond({ id: 9, ...(init.body ? JSON.parse(init.body) : {}) }, method === 'POST' ? 201 : 200);
        }
        return respond({});
    });
}

const runwayGets = () => requests.filter(([m, u]) => m === 'GET' && u === '/apps/budget/api/runway');

function tileMarkup() {
    return `
        <div class="hero-card" data-widget-id="runway" data-widget-category="hero">
            <span data-runway-part="value" class="hero-value">--</span>
            <span data-runway-part="until" class="hero-subtext"></span>
            <span data-runway-part="budget" class="hero-subtext"></span>
            <span data-runway-part="drain" class="hero-subtext"></span>
        </div>
        ${['runwayChart', 'runwayOneOffs', 'runwayScenarios', 'runwayBreakdown', 'runwayOverview'].map((key) => `
            <div class="dashboard-card" data-widget-id="${key}" data-widget-category="widget">
                <div class="card-header"><h3>${key}</h3></div>
                <div class="runway-tile-body" data-runway-body></div>
            </div>`).join('')}
        <div id="tile-settings-specific"></div>`;
}

function makeDashboard() {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { runway_alert_months: '1' },
        dashboardConfig: { widgets: { tileSettings: {} } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [
            { id: 1, name: 'Savings', type: 'savings', balance: 30000, currency: 'GBP' },
            { id: 2, name: 'Pension', type: 'investment', balance: 9000, currency: 'GBP' },
            { id: 3, name: 'Card', type: 'credit_card', balance: -2000, currency: 'GBP' },
        ],
        charts: {},
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

const flush = () => new Promise((r) => setTimeout(r, 0));

beforeEach(() => {
    chartInstances.length = 0;
    runwayAnswers = [payload()];
    installFetch();
    resetRunway();
    document.body.innerHTML = tileMarkup();
    HTMLCanvasElement.prototype.getContext = () => ({});
    const charts = {};
    setRunwayContext({
        accounts: () => makeDashboard().app.accounts,
        settings: () => ({ runway_alert_months: '1' }),
        charts: () => charts,
    });
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    window.__xss = undefined;
});

describe('registry', () => {
    it('drops burnRate and adds the six runway tiles, off by default, under Forecasting', () => {
        expect(DASHBOARD_WIDGETS.hero.burnRate).toBeUndefined();
        const defs = [DASHBOARD_WIDGETS.hero.runway, ...['runwayChart', 'runwayOneOffs', 'runwayScenarios', 'runwayBreakdown', 'runwayOverview']
            .map((k) => DASHBOARD_WIDGETS.widgets[k])];
        for (const def of defs) {
            expect(def).toBeDefined();
            expect(def.defaultVisible).toBe(false);
            expect(def.category).toBe('forecasting');
            expect(def.settingsSchema).toEqual({ runwaySetup: true });
        }
        expect(DASHBOARD_WIDGETS.hero.runway.id).toBe('hero-runway');
        expect(RUNWAY_TILE_KEYS).toHaveLength(6);
    });
});

describe('loadRunway', () => {
    it('makes one request however many tiles ask', async () => {
        const dash = makeDashboard();

        await Promise.all([
            dash.loadWidgetData('runway'),
            dash.loadWidgetData('runwayChart'),
            dash.loadWidgetData('runwayScenarios'),
        ]);

        expect(runwayGets()).toHaveLength(1);
        expect(dash.widgetDataLoaded.runway).toBe(true);
        expect(dash.widgetDataLoaded.runwayChart).toBe(true);
        expect(dash.widgetDataLoaded.runwayScenarios).toBe(true);
    });

    it('asks again when forced', async () => {
        await loadRunway();
        await loadRunway();
        await loadRunway(true);

        expect(runwayGets()).toHaveLength(2);
    });

    it('asks again on the next dashboard load', async () => {
        const dash = makeDashboard();
        await dash.loadWidgetData('runway');
        dash._resetRunwayTiles();
        expect(dash.widgetDataLoaded.runway).toBeUndefined();

        await dash.loadWidgetData('runway');

        expect(runwayGets()).toHaveLength(2);
    });

    it('lets a failed request be retried', async () => {
        global.fetch = vi.fn(async () => respond({ error: 'boom' }, 500));
        await expect(loadRunway()).rejects.toThrow();
        installFetch();

        await loadRunway();

        expect(runwayGets()).toHaveLength(1);
    });
});

describe('renderRunwayTile', () => {
    it('writes the months and until phrases as text', () => {
        renderRunwayTile('runway', payload());

        const card = document.querySelector('[data-widget-id="runway"]');
        const value = card.querySelector('[data-runway-part="value"]');
        const until = card.querySelector('[data-runway-part="until"]');
        expect(value.textContent).toBe('About 21 months');
        expect(until.textContent).toBe('until around Jul 2028');
        expect(value.children).toHaveLength(0);
        expect(until.children).toHaveLength(0);
        expect(card.querySelector('[data-runway-part="budget"]').textContent).toContain('About 25 months');
        expect(card.querySelector('[data-runway-part="drain"]').textContent).toContain('£1,350.00');
    });

    it('shows a one-off description as text, not markup', () => {
        renderRunwayTile('runwayOneOffs', payload());

        const body = document.querySelector('[data-widget-id="runwayOneOffs"] [data-runway-body]');
        expect(body.textContent).toContain('Car <img src=x onerror="window.__xss=1">');
        expect(body.querySelector('img')).toBeNull();
    });

    it('puts both scenarios and the difference on the scenarios tile', () => {
        renderRunwayTile('runwayScenarios', payload());

        const text = document.querySelector('[data-widget-id="runwayScenarios"]').textContent;
        expect(text).toContain('About 21 months');
        expect(text).toContain('About 25 months');
        expect(text).toContain('About 4½ more months on budget');
    });

    it('draws the chart once and replaces it on a re-render', () => {
        renderRunwayTile('runwayChart', payload());
        renderRunwayTile('runwayChart', payload());

        expect(chartInstances).toHaveLength(2);
        expect(chartInstances[0].destroyed).toBe(true);
        const labels = chartInstances[1].config.data.datasets.map((d) => d.label);
        expect(labels).toContain('At current spending');
        expect(labels).toContain('On budget');
    });

    it('says so when the runway could not be loaded', () => {
        renderRunwayTile('runwayBreakdown', null);

        expect(document.querySelector('[data-widget-id="runwayBreakdown"]').textContent)
            .toContain('could not be loaded');
    });
});

describe('openRunwaySetup', () => {
    it('re-renders every rendered runway tile from the new payload after saving', async () => {
        renderRunwayTile('runway', payload());
        renderRunwayTile('runwayScenarios', payload());
        renderRunwayTile('runwayOverview', payload());
        runwayAnswers = [later()];
        const onSaved = vi.fn();

        const container = document.getElementById('tile-settings-specific');
        await openRunwaySetup(onSaved, container);
        container.querySelector('[data-runway-setting="window"]').value = '6';
        container.querySelector('[data-runway-action="save"]').click();
        await vi.waitFor(() => expect(onSaved).toHaveBeenCalled());

        expect(document.querySelector('[data-widget-id="runway"] [data-runway-part="value"]').textContent).toBe('About 30 months');
        expect(document.querySelector('[data-widget-id="runwayScenarios"]').textContent).toContain('About 34 months');
        expect(document.querySelector('[data-widget-id="runwayOverview"]').textContent).toContain('About 30 months');
    });

    it('keeps the default account set when the ticks were not changed', async () => {
        const container = document.getElementById('tile-settings-specific');
        await openRunwaySetup(() => {}, container);
        expect(container.textContent).toContain('Using the default accounts');
        container.querySelector('[data-runway-action="save"]').click();
        await vi.waitFor(() => expect(requests.some(([m]) => m === 'PUT')).toBe(true));

        const [, , body] = requests.find(([m]) => m === 'PUT');
        expect(body).not.toHaveProperty('runway_account_ids');
        expect(body).toEqual({
            runway_window_months: '3',
            runway_include_scheduled: 'true',
            runway_alert_months: '1',
        });
    });

    it('writes the ticked accounts once they change', async () => {
        const container = document.getElementById('tile-settings-specific');
        await openRunwaySetup(() => {}, container);
        const pension = container.querySelector('input[data-runway-account="2"]');
        expect(pension.checked).toBe(false);
        expect(container.querySelector('input[data-runway-account="1"]').checked).toBe(true);
        pension.checked = true;
        container.querySelector('[data-runway-action="save"]').click();
        await vi.waitFor(() => expect(requests.some(([m]) => m === 'PUT')).toBe(true));

        const [, , body] = requests.find(([m]) => m === 'PUT');
        expect(JSON.parse(body.runway_account_ids)).toEqual([1, 2, 3]);
    });

    it('shows a card balance as taken off', async () => {
        const container = document.getElementById('tile-settings-specific');
        await openRunwaySetup(() => {}, container);

        const row = container.querySelector('input[data-runway-account="3"]').closest('label');
        expect(row.textContent).toContain('taken off');
    });
});

describe('one-off editor', () => {
    it('sends a decimal-comma amount as a plain signed decimal', async () => {
        renderRunwayTile('runwayOneOffs', payload());
        const body = document.querySelector('[data-widget-id="runwayOneOffs"] [data-runway-body]');
        body.querySelector('[data-runway-action="add"]').click();

        const form = body.querySelector('form');
        form.querySelector('[name="description"]').value = 'Boiler';
        form.querySelector('[name="direction"]').value = 'out';
        form.querySelector('[name="amount"]').value = '864,27';
        form.querySelector('[name="date"]').value = '2027-01-15';
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(requests.some(([m]) => m === 'POST')).toBe(true));

        const [, url, sent] = requests.find(([m]) => m === 'POST');
        expect(url).toBe('/apps/budget/api/runway/one-offs');
        expect(sent).toEqual({ description: 'Boiler', amount: '-864.27', date: '2027-01-15' });
        await flush();
        expect(runwayGets().length).toBeGreaterThanOrEqual(1);
    });

    it('sends only the changed fields when editing', async () => {
        renderRunwayTile('runwayOneOffs', payload());
        const body = document.querySelector('[data-widget-id="runwayOneOffs"] [data-runway-body]');
        body.querySelector('[data-runway-action="edit"]').click();

        const form = body.querySelector('form');
        expect(form.querySelector('[name="direction"]').value).toBe('out');
        expect(form.querySelector('[name="amount"]').value).toBe('2000.00');
        form.querySelector('[name="amount"]').value = '2500';
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(requests.some(([m]) => m === 'PUT')).toBe(true));

        const [, url, sent] = requests.find(([m]) => m === 'PUT');
        expect(url).toBe('/apps/budget/api/runway/one-offs/5');
        expect(sent).toEqual({ amount: '-2500' });
    });
});

describe('a saved dashboard that still names burnRate', () => {
    it('loads without errors and offers the runway tile instead', async () => {
        document.body.innerHTML += '<div id="add-tiles-menu-list"></div>';
        const dash = makeDashboard();
        const saved = JSON.stringify({
            order: ['netWorth', 'burnRate', 'income'],
            visibility: { netWorth: true, burnRate: true, income: true },
        });

        const hero = dash.parseDashboardConfig(saved, 'hero');
        dash.app.dashboardConfig = { hero, widgets: dash.parseDashboardConfig('', 'widgets') };
        dash.app.needsLazyLoad = (k) => dash.needsLazyLoad(k);
        dash.app.loadWidgetData = (k) => dash.loadWidgetData(k);

        await expect(dash.applyDashboardVisibility()).resolves.toBeUndefined();
        expect(() => dash.applyDashboardOrder()).not.toThrow();
        expect(() => dash.updateAddTilesMenu()).not.toThrow();

        const menu = document.getElementById('add-tiles-menu-list');
        const offered = [...menu.querySelectorAll('.add-tile-btn')].map((b) => b.dataset.widgetId);
        expect(offered).toContain('runway');
        expect(offered).not.toContain('burnRate');
        expect(hero.order).toContain('runway');
    });
});
