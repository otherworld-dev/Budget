/**
 * The bills and income tiles and the settings they offer.
 *
 * Upcoming Bills was fetched with the server's default 30-day window, so a
 * 60 or 90-day Look ahead showed nothing past 30 days. Both bills tiles
 * ignored their Account and Rows settings: they always listed the first five
 * bills of every account while the header named the account picked. Bills
 * Due Soon left transfers out while Upcoming Bills and the Nextcloud widget
 * kept them, listed inactive bills that still had a due date, and showed
 * every amount in the default currency.
 *
 * Income Tracking rendered the summary endpoint as if it were a list, so it
 * always said "No recurring income set up".
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const bill = (name, due, overrides = {}) => ({ name, nextDueDate: due, amount: 10, currency: 'GBP', accountId: 1, ...overrides });

function makeDashboard(widgetData, tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [{ id: 1, name: 'Current', currency: 'GBP' }, { id: 2, name: 'Euro', currency: 'EUR' }],
        dashboardConfig: { widgets: { tileSettings, instances: {} } },
        widgetData,
        widgetDataLoaded: {},
    };
    return mod;
}

function jsonFetch(routes) {
    return vi.fn(async (url) => {
        const key = Object.keys(routes).find(k => String(url).includes(k));
        return {
            ok: true,
            status: 200,
            headers: { get: () => 'application/json' },
            json: async () => (key ? routes[key] : {}),
        };
    });
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="bills-due-soon-list"></div>
        <div id="upcoming-bills"></div>
        <div id="income-tracking-content"></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 7, 24));
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.useRealTimers();
});

const names = (id) => [...document.querySelectorAll(`#${id} .bill-widget-name`)].map(el => el.textContent);

describe('Upcoming Bills', () => {
    it('asks the server for the widest look-ahead the tile offers', () => {
        expect(makeDashboard({}).upcomingBillsUrl()).toBe('/apps/budget/api/bills/upcoming?days=90');
    });

    it('shows as many rows as the tile is set to', () => {
        const bills = Array.from({ length: 9 }, (_, i) => bill(`Bill ${i}`, '2026-09-01'));
        makeDashboard({}, { upcomingBills: { rowCount: 8 } }).updateUpcomingBillsWidget(bills);

        expect(names('upcoming-bills')).toHaveLength(8);
    });

    it('lists only the bills of the account it is set to, paying from it or into it', () => {
        makeDashboard({}, { upcomingBills: { accountId: 2 } }).updateUpcomingBillsWidget([
            bill('Rent', '2026-09-01'),
            bill('Gym', '2026-09-02', { accountId: 2, currency: 'EUR' }),
            bill('To savings', '2026-09-03', { accountId: 1, destinationAccountId: 2, isTransfer: true }),
        ]);

        expect(names('upcoming-bills')).toEqual(['Gym', 'To savings']);
    });
});

describe('Bills Due Soon', () => {
    it('shows each bill in its own currency', () => {
        makeDashboard({ billsDueSoon: [bill('Gym', '2026-09-01', { amount: 50, currency: 'USD' })] }).updateBillsDueSoonWidget();

        expect(document.querySelector('#bills-due-soon-list .bill-widget-amount').textContent).toBe('$50.00');
    });

    it('applies the Rows and Account settings', () => {
        const bills = [
            ...Array.from({ length: 7 }, (_, i) => bill(`Own ${i}`, '2026-09-01')),
            bill('Euro gym', '2026-09-02', { accountId: 2 }),
        ];
        makeDashboard({ billsDueSoon: bills }, { billsDueSoon: { rowCount: 6 } }).updateBillsDueSoonWidget();
        expect(names('bills-due-soon-list')).toHaveLength(6);

        makeDashboard({ billsDueSoon: bills }, { billsDueSoon: { accountId: 2 } }).updateBillsDueSoonWidget();
        expect(names('bills-due-soon-list')).toEqual(['Euro gym']);
    });

    it('fetches the active bills, transfers included, as Upcoming Bills lists them', async () => {
        global.fetch = jsonFetch({ '/api/bills': [] });
        const dash = makeDashboard({});

        await dash.loadWidgetData('billsDueSoon', true);

        const url = String(global.fetch.mock.calls[0][0]);
        expect(url).toContain('activeOnly=true');
        expect(url).not.toContain('isTransfer');
    });
});

describe('Income Tracking', () => {
    const summary = { monthlyTotal: 2700, baseCurrency: 'GBP', expectedThisMonth: 1, receivedThisMonth: 1, activeCount: 2 };
    const incomes = [
        { id: 1, name: 'Salary', amount: 2000, frequency: 'monthly', nextExpectedDate: '2026-08-28', accountId: 1, currency: 'GBP', isActive: true },
        { id: 2, name: 'Rent from flat', amount: 1000, frequency: 'monthly', nextExpectedDate: '2026-09-01', accountId: 2, currency: 'EUR', isActive: true },
    ];

    it('fetches the summary and the incomes, scoped to the tile account', async () => {
        global.fetch = jsonFetch({ '/api/recurring-income/summary': summary, '/api/recurring-income': incomes });
        const dash = makeDashboard({}, { incomeTracking: { accountId: 2 } });

        await dash.loadWidgetData('incomeTracking', true);

        const urls = global.fetch.mock.calls.map(c => String(c[0]));
        expect(urls.some(u => u.includes('/api/recurring-income/summary') && u.includes('accountId=2'))).toBe(true);
        expect(dash.widgetData.incomeTracking.incomes.map(i => i.id)).toEqual([2]);
    });

    it('shows the monthly total, how much has come in this month and the incomes in their currency', () => {
        makeDashboard({ incomeTracking: { summary, incomes } }).updateIncomeTrackingWidget();

        const html = document.getElementById('income-tracking-content').textContent;
        expect(html).not.toContain('No recurring income set up');
        expect(html).toContain('£2,700.00');
        expect(html).toContain('1 of 2 received this month');
        expect(html).toContain('Rent from flat');
        expect(html).toContain('€1,000.00');
    });

    it('says when there is no recurring income', () => {
        makeDashboard({ incomeTracking: { summary: { ...summary, activeCount: 0, monthlyTotal: 0 }, incomes: [] } }).updateIncomeTrackingWidget();

        expect(document.getElementById('income-tracking-content').textContent).toContain('No recurring income set up');
    });
});
