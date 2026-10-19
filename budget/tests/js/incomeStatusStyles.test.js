/**
 * The Income page's status pills and card edges (#399). style.css styled
 * Received, Expected Soon and Upcoming only, so an Overdue, Completed or
 * Inactive income came out as bare text on a plain card, and an overdue
 * income didn't stand out at all. Each status the page shows is styled like
 * its counterpart on Bills: Overdue like Overdue (red pill, red edge),
 * Expected Soon like Due Soon, Received, Completed and Inactive like Paid,
 * Upcoming like Upcoming.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { incomeRowState } from '../../src/utils/incomeStatus.js';
import { declarationsNaming } from './helpers/stylesheet.js';

const settings = { date_format: 'Y-m-d' };
const TODAY = '2026-10-19';

/** One income in each state the page can show, and the bill status it matches */
const CASES = [
    { bill: 'overdue', income: { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-09-27', nextExpectedDate: '2026-10-03' } },
    { bill: 'due-soon', income: { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-09-25', nextExpectedDate: '2026-10-25' } },
    { bill: 'paid', income: { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-10-15', nextExpectedDate: '2026-11-15' } },
    { bill: 'upcoming', income: { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-08-30', nextExpectedDate: '2026-11-30' } },
    { bill: 'paid', income: { frequency: 'one-time', isActive: false, lastReceivedDate: '2026-09-28', startDate: '2026-09-29', nextExpectedDate: null } },
    { bill: 'paid', income: { frequency: 'monthly', isActive: false, lastReceivedDate: '2026-06-20', nextExpectedDate: '2026-07-20' } },
];

/** A card as IncomeModule / BillsModule render it, inside the app */
function card(kind, status) {
    const app = document.createElement('div');
    app.id = 'app-content';
    app.innerHTML = `
        <div class="${kind}-card ${status}" data-status="${status}">
            <div class="${kind}-details">
                <div class="${kind}-status ${status}"><span class="status-badge">${status}</span></div>
            </div>
        </div>`;
    document.body.appendChild(app);
    return { card: app.querySelector(`.${kind}-card`), badge: app.querySelector('.status-badge') };
}

/** What the status class adds, with the two spellings of white made one */
function added(el, status) {
    const out = declarationsNaming(el, `.${status}`);
    return Object.fromEntries(Object.entries(out).map(([k, v]) => [k, v.replace(/\bwhite\b/g, '#fff')]));
}

describe('income status styles', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('covers every status the page shows', () => {
        const statuses = CASES.map(c => incomeRowState(c.income, TODAY, settings).status);

        expect(statuses).toEqual(['overdue', 'expected-soon', 'received', 'upcoming', 'completed', 'inactive']);
    });

    it.each(CASES.map(c => [incomeRowState(c.income, TODAY, settings).status, c.bill]))(
        'gives an income that is %s the edge and pill of a bill that is %s',
        (status, billStatus) => {
            const income = card('income', status);
            const bill = card('bill', billStatus);

            const billCard = added(bill.card, billStatus);
            const billBadge = added(bill.badge, billStatus);
            // The bills' own rules, so a match below means something
            expect(billCard['border-left']).toBeDefined();
            expect(billBadge.background).toBeDefined();

            expect(added(income.card, status)).toEqual(billCard);
            expect(added(income.badge, status)).toEqual(billBadge);
        }
    );

    it('makes an overdue income red, as on Bills', () => {
        const { card: el, badge } = card('income', 'overdue');

        expect(added(el, 'overdue')['border-left']).toBe('4px solid var(--color-error)');
        expect(added(badge, 'overdue').background).toBe('var(--color-error)');
    });
});
