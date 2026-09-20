/**
 * Share expense dialog: one transaction split between several people (#391).
 *
 * The dialog used to split with one contact at a time, 50/50 or a typed
 * amount, and reopening it on a split transaction only offered to add
 * another. It now lists everyone, works out each share, reopens on the
 * splits already saved, and saves them all together in one request.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

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
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(async () => true),
}));

import SharedExpensesModule from '../../src/modules/shared-expenses/SharedExpensesModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { showWarning } from '../../src/utils/notifications.js';

const CONTACTS = [
    { id: 1, name: 'Alice' },
    { id: 2, name: 'Bob' },
    { id: 3, name: 'Carol' },
];

const transaction = (overrides = {}) => ({
    id: 10,
    accountId: 1,
    date: '2026-09-01',
    description: 'Tiles',
    amount: 90,
    type: 'debit',
    ...overrides,
});

function mountDialog() {
    document.body.innerHTML = `
        <div id="share-expense-modal" style="display: none;">
            <form id="share-expense-form">
                <input type="hidden" id="share-transaction-id">
                <span id="share-transaction-date"></span>
                <span id="share-transaction-desc"></span>
                <span id="share-transaction-amount"></span>
                <select id="share-split-type">
                    <option value="equal">Equally</option>
                    <option value="percent">By percentage</option>
                    <option value="amount">By amount</option>
                </select>
                <div id="share-people"></div>
                <small id="share-settled-note" style="display: none;"></small>
                <p id="share-summary"></p>
                <input type="text" id="share-notes">
                <button type="submit">Save Split</button>
                <button type="button" class="cancel-btn">Cancel</button>
            </form>
        </div>
        <table id="transactions-table"><tbody></tbody></table>`;
}

/** Answer the dialog's GET with $shares and record every PUT */
function serveShares(shares) {
    global.fetch = vi.fn(async (url, options = {}) => ({
        ok: true,
        json: async () => (options.method === 'PUT' ? [] : shares),
    }));
}

const putBody = () => {
    const call = global.fetch.mock.calls.find(([, options]) => options?.method === 'PUT');
    return call ? JSON.parse(call[1].body) : null;
};

const row = (person) => document.querySelector(`.share-person-row[data-person="${person}"]`);
const amountOf = (person) => row(person).querySelector('.share-person-amount').textContent;

function tick(person, checked = true) {
    const box = row(person).querySelector('.share-person-check');
    box.checked = checked;
    box.dispatchEvent(new Event('change', { bubbles: true }));
}

function type(person, value) {
    const input = row(person).querySelector('.share-person-value');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function chooseMethod(method) {
    const select = document.getElementById('share-split-type');
    select.value = method;
    select.dispatchEvent(new Event('change'));
}

async function save(mod) {
    await mod.saveShareExpense();
}

function makeModule() {
    return new SharedExpensesModule({
        settings: {},
        contacts: CONTACTS,
        accounts: [{ id: 1, currency: 'GBP' }],
        loadSharedTransactionIds: vi.fn(),
        renderEnhancedTransactionsTable: vi.fn(),
    });
}

describe('share expense dialog', () => {
    let mod;

    beforeEach(() => {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        mountDialog();
        mod = makeModule();
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
    });

    it('splits a new expense equally with everyone ticked, you included', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction());

        expect(document.getElementById('share-split-type').value).toBe('equal');
        tick(1);
        tick(2);

        expect(amountOf(1)).toBe('£30.00');
        expect(amountOf('me')).toBe('£30.00');
        expect(document.getElementById('share-summary').textContent)
            .toBe('The others owe you £60.00. Your share is £30.00.');

        await save(mod);

        expect(global.fetch).toHaveBeenCalledWith('/apps/budget/api/shared/transactions/10/shares',
            expect.objectContaining({ method: 'PUT' }));
        expect(putBody()).toEqual({
            splits: [{ contactId: 1, amount: '30.00' }, { contactId: 2, amount: '30.00' }],
            notes: null,
        });
    });

    it('leaves you out when your own row is unticked', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction());
        tick(1);
        tick(2);
        tick('me', false);

        await save(mod);

        expect(putBody().splits.map(s => s.amount)).toEqual(['45.00', '45.00']);
    });

    it('splits money coming in the other way round', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction({ amount: 200, type: 'credit' }));
        tick(1);

        expect(document.getElementById('share-summary').textContent)
            .toBe('You owe the others £100.00. Your part is £100.00.');
        await save(mod);

        expect(putBody().splits).toEqual([{ contactId: 1, amount: '-100.00' }]);
    });

    it('spreads percentages evenly until one is typed, then holds them to 100%', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction());
        chooseMethod('percent');
        tick(1);
        tick(2);

        expect(row('me').querySelector('.share-person-value').value).toBe('33.34');
        expect(row(1).querySelector('.share-person-value').value).toBe('33.33');

        type('me', '50');
        type(1, '30');
        type(2, '10');
        expect(document.getElementById('share-summary').textContent)
            .toBe('The percentages add up to 90% and need to add up to 100%.');

        await save(mod);
        expect(showWarning).toHaveBeenCalled();
        expect(putBody()).toBeNull();

        type(2, '20');
        await save(mod);
        expect(putBody().splits).toEqual([
            { contactId: 1, amount: '27.00' },
            { contactId: 2, amount: '18.00' },
        ]);
    });

    it('carries the amounts over when switching to By amount', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction());
        tick(1);
        tick(2);
        chooseMethod('amount');

        expect(row(1).querySelector('.share-person-value').value).toBe('30.00');
        expect(amountOf('me')).toBe('£30.00');

        type(2, '50');
        expect(amountOf('me')).toBe('£10.00');
    });

    it('reopens on the saved splits, with settled ones locked and left out of the total', async () => {
        serveShares([
            { id: 5, contactId: 1, amount: 45, isSettled: false, notes: 'Bathroom' },
            { id: 6, contactId: 3, amount: 30, isSettled: true, notes: null },
        ]);
        await mod.showShareExpenseModal(transaction());

        expect(document.getElementById('share-split-type').value).toBe('amount');
        expect(document.getElementById('share-notes').value).toBe('Bathroom');
        expect(row(1).querySelector('.share-person-check').checked).toBe(true);
        expect(row(1).querySelector('.share-person-value').value).toBe('45.00');

        // Only people in the split get a box to type in
        expect(row(2).querySelector('.share-person-value')).toBeNull();

        const settled = row(3);
        expect(settled.classList.contains('settled')).toBe(true);
        expect(settled.querySelector('.share-person-check').disabled).toBe(true);
        expect(settled.querySelector('.share-person-value')).toBeNull();
        expect(document.getElementById('share-settled-note').textContent)
            .toBe('£30.00 of this has already been settled, so it is left out of the split.');

        // £90 less the £30 settled leaves £60, of which Alice owes £45
        expect(amountOf('me')).toBe('£15.00');

        type(1, '61');
        expect(document.getElementById('share-summary').textContent)
            .toBe('The amounts add up to £1.00 more than there is to split.');
    });

    it('removes the split once everyone is unticked, after asking', async () => {
        serveShares([{ id: 5, contactId: 1, amount: 45, isSettled: false, notes: null }]);
        await mod.showShareExpenseModal(transaction());
        tick(1, false);

        expect(document.getElementById('share-summary').textContent)
            .toBe('Nobody is ticked, so saving removes this split.');

        await save(mod);

        expect(confirmDialog).toHaveBeenCalled();
        expect(putBody()).toEqual({ splits: [], notes: null });
    });

    it('does not save a new split with nobody ticked', async () => {
        serveShares([]);
        await mod.showShareExpenseModal(transaction());

        await save(mod);

        expect(showWarning).toHaveBeenCalledWith('Tick at least one person to split with.');
        expect(putBody()).toBeNull();
    });
});
