/**
 * Focus after a row action pressed from the keyboard (#399).
 *
 * Mark Paid disables its button while it runs, the browser drops focus from
 * a disabled element, and the list re-renders: pressed with Enter, focus
 * fell to the page body. Once the action is done, focus now goes back to
 * the same row: its button for the same action when it still has one, else
 * its first button. A mouse click leaves focus to the browser.
 */

import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { refocusRow } from '../../src/utils/rowFocus.js';

const LISTS = {
    bills: { list: 'bills-list', card: 'bill-card', cardAttr: 'bill-id', attr: 'bill-id' },
    transfers: { list: 'transfers-list', card: 'bill-card', cardAttr: 'bill-id', attr: 'transfer-id' },
    income: { list: 'income-list', card: 'income-card', cardAttr: 'income-id', attr: 'income-id' },
};

/** Two rows with the given buttons, as the list renders them */
function render(kind, buttons) {
    const { list, card, cardAttr, attr } = LISTS[kind];
    document.getElementById(list).innerHTML = [1, 2].map(id => `
        <div class="${card}" data-${cardAttr}="${id}">
            ${buttons.map(cls => `<button class="${cls}" data-${attr}="${id}">Act</button>`).join('')}
            <button class="edit-btn" data-${attr}="${id}">Edit</button>
        </div>`).join('');
}

/** The action as the page runs it: its button disabled, then the list re-rendered */
function action(kind, after) {
    return vi.fn(async (_id, button) => {
        button.disabled = true;
        button.blur();
        await Promise.resolve();
        render(kind, after);
    });
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0));

function press(element, detail) {
    element.focus();
    element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
}

let bills;
let transfers;
let income;

beforeAll(() => {
    document.body.innerHTML = `
        <div id="bills-list"></div>
        <div id="transfers-list"></div>
        <div id="income-list"></div>
        <input id="elsewhere">
    `;
    bills = Object.create(BillsModule.prototype);
    bills.app = { settings: {}, bills: [], accounts: undefined };
    bills.recordMissedPayment = vi.fn();
    bills.dismissUnrecordedPayment = vi.fn();
    bills.setupBillsEventListeners();

    transfers = Object.create(TransfersModule.prototype);
    transfers.app = { settings: {} };
    transfers.transfers = [];
    transfers.setupEventListeners();

    income = Object.create(IncomeModule.prototype);
    income.app = { settings: {}, recurringIncome: [] };
    income.setupIncomeEventListeners();
});

beforeEach(() => {
    bills.markBillPaid = action('bills', ['bill-skip-btn']);
    bills.skipBillPayment = action('bills', ['bill-paid-btn', 'bill-skip-btn']);
    bills.markBillUnpaid = action('bills', ['bill-paid-btn', 'bill-skip-btn']);
    transfers.markTransferPaid = action('transfers', ['transfer-skip-btn']);
    transfers.skipTransfer = action('transfers', ['transfer-paid-btn', 'transfer-skip-btn']);
    transfers.markTransferUnpaid = action('transfers', ['transfer-paid-btn']);
    income.markIncomeReceived = action('income', ['income-skip-btn']);
    income.skipIncome = action('income', ['income-received-btn', 'income-skip-btn']);
});

describe.each([
    ['Bills: Mark Paid', 'bills', ['bill-paid-btn', 'bill-skip-btn'], 'bill-paid-btn', 'bill-skip-btn'],
    ['Bills: Skip', 'bills', ['bill-paid-btn', 'bill-skip-btn'], 'bill-skip-btn', 'bill-skip-btn'],
    ['Bills: Mark Unpaid', 'bills', ['bill-unpaid-btn'], 'bill-unpaid-btn', 'bill-paid-btn'],
    ['Transfers: Mark Paid', 'transfers', ['transfer-paid-btn', 'transfer-skip-btn'], 'transfer-paid-btn', 'transfer-skip-btn'],
    ['Transfers: Skip', 'transfers', ['transfer-paid-btn', 'transfer-skip-btn'], 'transfer-skip-btn', 'transfer-skip-btn'],
    ['Transfers: Mark Unpaid', 'transfers', ['transfer-unpaid-btn'], 'transfer-unpaid-btn', 'transfer-paid-btn'],
    ['Income: Mark Received', 'income', ['income-received-btn', 'income-skip-btn'], 'income-received-btn', 'income-skip-btn'],
    ['Income: Skip', 'income', ['income-received-btn', 'income-skip-btn'], 'income-skip-btn', 'income-skip-btn'],
])('%s', (_name, kind, before, pressed, focused) => {
    const { attr } = LISTS[kind];
    const find = (cls, id) => document.querySelector(`#${LISTS[kind].list} .${cls}[data-${attr}="${id}"]`);

    it('puts focus back on the same row when pressed from the keyboard', async () => {
        render(kind, before);

        press(find(pressed, 2), 0);
        await settle();

        expect(document.activeElement).toBe(find(focused, 2));
    });

    it('leaves focus alone after a mouse click', async () => {
        render(kind, before);

        press(find(pressed, 2), 1);
        await settle();

        expect(document.activeElement).toBe(document.body);
    });
});

describe('refocusRow', () => {
    beforeEach(() => {
        render('bills', ['bill-paid-btn']);
    });

    it('leaves focus where something else put it, such as a dialog', () => {
        const elsewhere = document.getElementById('elsewhere');
        elsewhere.focus();

        expect(refocusRow('.bill-card[data-bill-id="1"]', '.bill-paid-btn')).toBe(false);
        expect(document.activeElement).toBe(elsewhere);
    });

    it('takes the first enabled button when the action\'s own is disabled', () => {
        document.activeElement?.blur();
        document.querySelector('.bill-paid-btn[data-bill-id="1"]').disabled = true;

        refocusRow('.bill-card[data-bill-id="1"]', '.bill-paid-btn');

        expect(document.activeElement).toBe(document.querySelector('.edit-btn[data-bill-id="1"]'));
    });

    it('focuses the row itself when it has no button left', () => {
        document.activeElement?.blur();
        const row = document.querySelector('.bill-card[data-bill-id="1"]');
        row.querySelectorAll('button').forEach(button => button.remove());

        refocusRow('.bill-card[data-bill-id="1"]', '.bill-paid-btn');

        expect(document.activeElement).toBe(row);
    });

    it('does nothing when the row is gone', () => {
        document.activeElement?.blur();

        expect(refocusRow('.bill-card[data-bill-id="9"]', '.bill-paid-btn')).toBe(false);
    });
});
