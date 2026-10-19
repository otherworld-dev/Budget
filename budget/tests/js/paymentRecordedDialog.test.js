/**
 * Mark Paid and Mark Received on an occurrence the ledger already has a
 * payment for (#399).
 *
 * The reporter's CembraPay bills, made due again by a switch to One-Time,
 * were paid a second time with one click: nothing asked. The server now
 * refuses (409, payment_recorded / receipt_recorded) and the page asks
 * whether to use that payment, record another one, or leave it.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { askAboutRecordedPayment, sendPayment } from '../../src/utils/paymentRecordedDialog.js';

const EXISTING = { id: 1350727, date: '2026-09-27', amount: 151.67 };
const modal = () => document.getElementById('payment-recorded-modal');
const click = (selector) => modal().querySelector(selector).click();

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
});

describe('askAboutRecordedPayment', () => {
    it('names the payment already there', () => {
        askAboutRecordedPayment(EXISTING, {}, { currency: 'CHF' });

        expect(modal().textContent).toContain('This bill already has a payment on');
        expect(modal().textContent).toContain('151.67');
        expect(modal().querySelector('.confirm-btn').textContent).toBe('Use that payment');
        expect(modal().querySelector('.confirm-btn').classList.contains('primary')).toBe(true);
        expect(modal().querySelector('.another-btn').textContent).toBe('Record another payment');
        expect(modal().querySelector('.cancel-btn').textContent).toBe('Cancel');
    });

    it('speaks of income on the Income page', () => {
        askAboutRecordedPayment(EXISTING, {}, { kind: 'income' });

        expect(modal().textContent).toContain('This income already has a payment on');
    });

    it('resolves "use" for Use that payment', async () => {
        const answer = askAboutRecordedPayment(EXISTING, {});
        click('.confirm-btn');

        expect(await answer).toBe('use');
        expect(modal()).toBeNull();
    });

    it('resolves "another" for Record another payment', async () => {
        const answer = askAboutRecordedPayment(EXISTING, {});
        click('.another-btn');

        expect(await answer).toBe('another');
    });

    it('resolves null for Cancel, the close button, the backdrop and Escape', async () => {
        for (const leave of [
            () => click('.cancel-btn'),
            () => click('.close-btn'),
            () => modal().click(),
            () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })),
        ]) {
            const answer = askAboutRecordedPayment(EXISTING, {});
            leave();
            expect(await answer).toBeNull();
            expect(modal()).toBeNull();
        }
    });

    it('shows the date and amount as text, never as markup', () => {
        askAboutRecordedPayment({ id: 1, date: '<img src=x onerror=alert(1)>', amount: 5 }, {}, { currency: '<b>X</b>' });

        expect(modal().querySelector('img')).toBeNull();
        expect(modal().querySelector('b')).toBeNull();
        expect(modal().textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('replaces a dialog still open for another bill', () => {
        askAboutRecordedPayment(EXISTING, {});
        askAboutRecordedPayment(EXISTING, {});

        expect(document.querySelectorAll('#payment-recorded-modal')).toHaveLength(1);
    });

    it('answers the dialog it replaces with null and leaves no key listener of it behind', async () => {
        // The replaced dialog's bill waits on its answer, holding that bill's
        // lock: unanswered, its buttons did nothing until the page reloaded
        const first = askAboutRecordedPayment(EXISTING, {});
        const second = askAboutRecordedPayment(EXISTING, {});
        const pending = new Promise((resolve) => setTimeout(() => resolve('still pending'), 50));

        expect(await Promise.race([first, pending])).toBeNull();
        click('.confirm-btn');
        expect(await second).toBe('use');

        const escape = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true });
        document.dispatchEvent(escape);
        expect(escape.defaultPrevented).toBe(false);
    });
});

describe('sendPayment', () => {
    let requests;

    function serve(...responses) {
        requests = [];
        global.fetch = vi.fn(async (url, init = {}) => {
            requests.push({ url, body: init.body ? JSON.parse(init.body) : null });
            const [status, data] = responses.shift() ?? [200, {}];
            return {
                ok: status < 300,
                status,
                headers: { get: () => null },
                json: async () => data,
            };
        });
    }

    const conflict = (code = 'payment_recorded') => [409, { error: 'This bill already has a payment on 2026-09-27.', code, existing: EXISTING }];

    it('pays straight away when nothing is recorded', async () => {
        serve([200, { bill: { id: 5 } }]);

        const sent = await sendPayment('/apps/budget/api/bills/5/paid', { recordPayment: true, dueDate: '2026-09-28' }, {});

        expect(sent).toEqual({ result: { bill: { id: 5 } }, usedPayment: null });
        expect(requests).toHaveLength(1);
        expect(modal()).toBeNull();
    });

    it('marks the bill paid with the payment already there, booking nothing', async () => {
        serve(conflict(), [200, { bill: { id: 5 } }]);

        const sending = sendPayment('/apps/budget/api/bills/5/paid', { recordPayment: true, dueDate: '2026-09-28' }, {});
        await vi.waitFor(() => expect(modal()).not.toBeNull());
        click('.confirm-btn');
        const sent = await sending;

        expect(requests[1].body).toEqual({ recordPayment: false, paidDate: '2026-09-27', dueDate: '2026-09-28' });
        expect(sent.usedPayment).toEqual(EXISTING);
    });

    it('records another payment only when asked to', async () => {
        serve(conflict(), [200, { bill: { id: 5 } }]);

        const sending = sendPayment('/apps/budget/api/bills/5/paid', { recordPayment: true, dueDate: '2026-09-28' }, {});
        await vi.waitFor(() => expect(modal()).not.toBeNull());
        click('.another-btn');
        const sent = await sending;

        expect(requests[1].body).toEqual({ recordPayment: true, dueDate: '2026-09-28', confirmSecondPayment: true });
        expect(sent.usedPayment).toBeNull();
    });

    it('sends nothing more when the user leaves it', async () => {
        serve(conflict());

        const sending = sendPayment('/apps/budget/api/bills/5/paid', { recordPayment: true, dueDate: '2026-09-28' }, {});
        await vi.waitFor(() => expect(modal()).not.toBeNull());
        click('.cancel-btn');

        expect(await sending).toBeNull();
        expect(requests).toHaveLength(1);
    });

    it('receives an income with the credit already there', async () => {
        serve(conflict('receipt_recorded'), [200, { id: 9 }]);

        const sending = sendPayment('/apps/budget/api/recurring-income/9/received',
            { receivedDate: '2026-10-19', createTransaction: true, expectedDate: '2026-09-29' }, {}, { kind: 'income' });
        await vi.waitFor(() => expect(modal()).not.toBeNull());
        click('.confirm-btn');
        await sending;

        expect(requests[1].body).toEqual({ createTransaction: false, receivedDate: '2026-09-27', expectedDate: '2026-09-29' });
    });

    it('passes any other refusal on', async () => {
        serve([400, { error: 'This payment was already recorded. Reload the page to see the next one.' }]);

        await expect(sendPayment('/apps/budget/api/bills/5/paid', { recordPayment: true }, {}))
            .rejects.toThrow('This payment was already recorded. Reload the page to see the next one.');
        expect(modal()).toBeNull();
    });

    it('does not take a bill\'s answer on the Income page', async () => {
        serve(conflict('payment_recorded'));

        await expect(sendPayment('/apps/budget/api/recurring-income/9/received', { createTransaction: true }, {}, { kind: 'income' }))
            .rejects.toThrow('This bill already has a payment on 2026-09-27.');
    });
});
