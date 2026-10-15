/**
 * Items shared with you read-only offer no actions that change them.
 *
 * The server refuses every write to a bill, a recurring income or an
 * account shared read-only ("This shared item is read-only"), but the
 * lists still offered Edit, Delete, Duplicate and Match transfer on the
 * transactions in such an account, Mark Paid, Skip and Edit on the bill,
 * and Mark Received, Skip and Edit on the income. Those are left out now.
 * Share expense stays: it is your own record, not a change to the row.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getLanguage: () => 'en',
    getLocale: () => 'en',
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import { renderTransactionRow, transactionMenuActions } from '../../src/modules/transactions/transactionRow.js';
import { billRowState } from '../../src/utils/billDates.js';
import { incomeRowState } from '../../src/utils/incomeStatus.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

const OWN = { id: 1, name: 'Current', currency: 'GBP' };
const READ = { id: 2, name: 'Joint', currency: 'GBP', _shared: true, _canWrite: false };
const WRITE = { id: 3, name: 'Household', currency: 'GBP', _shared: true, _canWrite: true };

const READ_ONLY = { _shared: true, _canWrite: false, _canManage: false };
const READ_WRITE = { _shared: true, _canWrite: true, _canManage: false };

const TODAY = '2026-10-04';

afterEach(() => {
    document.body.innerHTML = '';
});

function row(accountId, variant = 'ledger', extra = {}) {
    const html = renderTransactionRow({
        id: 9, accountId, date: '2026-10-01', description: 'Rent', amount: 700, type: 'debit',
        categoryId: null, ...extra,
    }, {
        variant,
        accounts: [OWN, READ, WRITE],
        categories: [],
        currency: 'GBP',
        formatCurrency: (v) => '£' + Number(v).toFixed(2),
        formatDate: (d) => d,
    });
    document.body.innerHTML = `<table><tbody>${html}</tbody></table>`;
    return document.querySelector('tr');
}

describe('a transaction in an account shared read-only', () => {
    it('has no Edit button and no cell to edit in the list', () => {
        const tr = row(2);
        expect(tr.querySelector('.transaction-edit-btn')).toBeNull();
        expect(tr.querySelectorAll('.editable-cell')).toHaveLength(0);
        // The ⋮ menu stays, for Share expense
        expect(tr.querySelector('.more-actions-btn')).not.toBeNull();
        expect(tr.dataset.readOnly).toBe('1');
    });

    it('has no Edit or Delete button in the account register', () => {
        const tr = row(2, 'register');
        expect(tr.querySelector('.edit-transaction-btn')).toBeNull();
        expect(tr.querySelector('.delete-transaction-btn')).toBeNull();
        expect(tr.dataset.readOnly).toBe('1');
    });

    it('offers only Share expense in its menu', () => {
        expect(transactionMenuActions({ id: 9, linkedTransactionId: null }, READ)).toEqual(['share']);
        expect(transactionMenuActions({ id: 9, linkedTransactionId: 4 }, READ)).toEqual(['share']);
    });

    it('does not open the edit form when its card is tapped on a phone', () => {
        row(2);
        document.querySelector('table').id = 'transactions-table';
        window.matchMedia = vi.fn().mockImplementation(query => ({ matches: true, media: query }));
        const mod = Object.create(TransactionsModule.prototype);
        mod.editTransaction = vi.fn();
        mod.startInlineEdit = vi.fn();
        mod.closeAllInlineEditors = vi.fn();
        mod.setupInlineEditingListeners();

        document.querySelector('.description-column').click();

        expect(mod.editTransaction).not.toHaveBeenCalled();
        expect(mod.startInlineEdit).not.toHaveBeenCalled();
    });
});

describe('a transaction you can write to', () => {
    it('keeps its actions, own account or shared to write', () => {
        for (const accountId of [1, 3]) {
            const tr = row(accountId);
            expect(tr.querySelector('.transaction-edit-btn')).not.toBeNull();
            expect(tr.querySelectorAll('.editable-cell').length).toBeGreaterThan(0);
            expect(tr.dataset.readOnly).toBeUndefined();
        }
        expect(transactionMenuActions({ id: 9, linkedTransactionId: null }, WRITE)).toEqual(['duplicate', 'share', 'match', 'delete']);
        expect(transactionMenuActions({ id: 9, linkedTransactionId: 4 }, OWN)).toEqual(['duplicate', 'share', 'unlink', 'delete']);
    });

    it('keeps Edit and Delete in the account register', () => {
        const tr = row(1, 'register');
        expect(tr.querySelector('.edit-transaction-btn')).not.toBeNull();
        expect(tr.querySelector('.delete-transaction-btn')).not.toBeNull();
    });
});

describe('bill and transfer rows', () => {
    const bill = (flags = {}) => ({ id: 5, name: 'Phone', amount: 25, frequency: 'monthly', nextDueDate: '2026-10-20', isActive: true, ...flags });

    it('cannot be paid, skipped or edited when shared read-only', () => {
        const state = billRowState(bill(READ_ONLY), TODAY, {});
        expect(state).toMatchObject({ canPay: false, canSkip: false, canWrite: false });
    });

    it('can be paid and skipped when shared to write, and when your own', () => {
        expect(billRowState(bill(READ_WRITE), TODAY, {})).toMatchObject({ canPay: true, canSkip: true, canWrite: true });
        expect(billRowState(bill(), TODAY, {})).toMatchObject({ canPay: true, canSkip: true, canWrite: true });
    });

    function renderBill(flags) {
        document.body.innerHTML = '<div id="bills-list"></div><div id="empty-bills"></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, accounts: [], categories: [] };
        mod.renderBills([{ ...bill(flags), canMarkUnpaid: true }]);
        return [...document.querySelectorAll('.bill-actions button')].map(b => b.className.match(/bill-(paid|skip|unpaid|edit|delete)-btn/)?.[1]);
    }

    it('shows no actions on a bill shared read-only', () => {
        expect(renderBill(READ_ONLY)).toEqual([]);
    });

    it('shows everything but Delete on a bill shared to write', () => {
        expect(renderBill(READ_WRITE)).toEqual(['paid', 'skip', 'unpaid', 'edit']);
    });

    function renderTransfer(flags) {
        document.body.innerHTML = '<div id="transfers-list"></div><div id="empty-transfers"></div>';
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {} };
        mod.transfers = [{ ...bill(flags), isTransfer: true, canMarkUnpaid: true }];
        mod.renderTransfers();
        return [...document.querySelectorAll('.bill-actions button')].map(b => b.className.match(/transfer-(paid|skip|unpaid|edit|delete)-btn/)?.[1]);
    }

    it('shows no actions on a transfer shared read-only', () => {
        expect(renderTransfer(READ_ONLY)).toEqual([]);
    });

    it('keeps Delete on a transfer for Full control or your own only', () => {
        expect(renderTransfer(READ_WRITE)).toEqual(['paid', 'skip', 'unpaid', 'edit']);
        expect(renderTransfer({})).toEqual(['paid', 'skip', 'unpaid', 'edit', 'delete']);
    });
});

describe('recurring income rows', () => {
    const income = (flags = {}) => ({ id: 1, name: 'Salary', amount: 2000, frequency: 'monthly', nextExpectedDate: '2026-10-28', isActive: true, ...flags });

    it('cannot be received, skipped or edited when shared read-only', () => {
        expect(incomeRowState(income(READ_ONLY), TODAY, {})).toMatchObject({ canReceive: false, canSkip: false, canWrite: false });
        expect(incomeRowState(income(READ_WRITE), TODAY, {})).toMatchObject({ canReceive: true, canSkip: true, canWrite: true });
    });

    function renderIncome(flags) {
        document.body.innerHTML = '<div id="income-list"></div><div id="empty-income"></div>';
        const mod = Object.create(IncomeModule.prototype);
        mod.app = { settings: {}, accounts: [] };
        mod.renderRecurringIncome([income(flags)]);
        return [...document.querySelectorAll('.income-actions button')].map(b => b.className.match(/income-(received|skip|edit|delete)-btn/)?.[1]);
    }

    it('shows no actions on income shared read-only', () => {
        expect(renderIncome(READ_ONLY)).toEqual([]);
    });

    it('shows everything but Delete on income shared to write', () => {
        expect(renderIncome(READ_WRITE)).toEqual(['received', 'skip', 'edit']);
    });
});
