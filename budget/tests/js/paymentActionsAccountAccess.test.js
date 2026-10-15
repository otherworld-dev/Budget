/**
 * Paying, skipping and reverting a bill, transfer or income need write
 * access to the accounts the payment goes into, not only to the bill.
 *
 * The lists offered Mark Paid, Skip and Mark Unpaid on a bill whose account
 * was shared with you read-only, or whose share had ended, and Mark Received
 * on income into such an account; the server refuses them ("This bill uses
 * an account you can no longer change..."). The same went for a transfer
 * whose destination was read-only or gone, and for Record transaction on
 * the unrecorded payments card. They're offered now only when you can
 * write the bill, its account (if it has one) and a transfer's destination.
 * Skip on income doesn't touch the account and stays. Your own bill or
 * income says what to do instead.
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

import { billRowState } from '../../src/utils/billDates.js';
import { incomeRowState } from '../../src/utils/incomeStatus.js';
import { billAccountsWritable, incomeAccountWritable, canWriteAccountId } from '../../src/utils/accounts.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';

const OWN = { id: 1, name: 'Current', currency: 'GBP' };
const READ = { id: 2, name: 'Joint', currency: 'GBP', _shared: true, _canWrite: false, userId: 'owen' };
const WRITE = { id: 3, name: 'Household', currency: 'GBP', _shared: true, _canWrite: true, userId: 'owen' };
const ACCOUNTS = [OWN, READ, WRITE];
const GONE = 99; // an account the user can't see: a share that ended, or the owner's own

const SHARED_WRITE = { _shared: true, _canWrite: true, _canManage: false, userId: 'owen' };
const SHARED_READ = { _shared: true, _canWrite: false, _canManage: false, userId: 'owen' };

const TODAY = '2026-10-04';
const BILL_HINT = 'This bill uses an account you can no longer change. Edit the bill and choose another account.';
const INCOME_HINT = 'This income uses an account you can no longer change. Edit it and choose another account.';

const bill = (fields = {}) => ({
    id: 5, name: 'Phone', amount: 25, frequency: 'monthly', nextDueDate: '2026-10-20', isActive: true, canMarkUnpaid: true, ...fields,
});
const transfer = (fields = {}) => bill({ isTransfer: true, accountId: 1, destinationAccountId: 3, ...fields });
const income = (fields = {}) => ({
    id: 1, name: 'Salary', amount: 2000, frequency: 'monthly', nextExpectedDate: '2026-10-28', isActive: true, ...fields,
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe('which accounts can be written', () => {
    it('your own and ones shared to write; not read-only or unknown ones', () => {
        expect([1, 2, 3, GONE].map(id => canWriteAccountId(ACCOUNTS, id))).toEqual([true, false, true, false]);
        expect(canWriteAccountId(ACCOUNTS, '3')).toBe(true);
    });

    it('a bill needs its account, if any, and a transfer its destination too', () => {
        expect(billAccountsWritable(bill(), ACCOUNTS)).toBe(true);
        expect(billAccountsWritable(bill({ accountId: 3 }), ACCOUNTS)).toBe(true);
        expect(billAccountsWritable(bill({ accountId: 2 }), ACCOUNTS)).toBe(false);
        expect(billAccountsWritable(bill({ accountId: GONE }), ACCOUNTS)).toBe(false);
        expect(billAccountsWritable(transfer(), ACCOUNTS)).toBe(true);
        expect(billAccountsWritable(transfer({ destinationAccountId: 2 }), ACCOUNTS)).toBe(false);
        expect(billAccountsWritable(transfer({ destinationAccountId: null }), ACCOUNTS)).toBe(false);
        expect(billAccountsWritable(transfer({ accountId: GONE }), ACCOUNTS)).toBe(false);
    });

    it('income needs its account only when it has one', () => {
        expect(incomeAccountWritable(income(), ACCOUNTS)).toBe(true);
        expect(incomeAccountWritable(income({ accountId: 3 }), ACCOUNTS)).toBe(true);
        expect(incomeAccountWritable(income({ accountId: 2 }), ACCOUNTS)).toBe(false);
        expect(incomeAccountWritable(income({ accountId: GONE }), ACCOUNTS)).toBe(false);
    });
});

describe('a bill row', () => {
    const state = (fields) => billRowState(bill(fields), TODAY, {}, ACCOUNTS);

    it('offers its payment actions on your own bill into an account you can write', () => {
        for (const accountId of [null, 1, 3]) {
            expect(state({ accountId })).toMatchObject({ canPay: true, canSkip: true, canUnpay: true, canWrite: true, accountHint: null });
        }
    });

    it('offers none on your own bill into a read-only or unknown account, and says what to do', () => {
        for (const accountId of [2, GONE]) {
            expect(state({ accountId })).toMatchObject({ canPay: false, canSkip: false, canUnpay: false, canWrite: true, accountHint: BILL_HINT });
        }
    });

    it('offers none on a bill shared to write whose account you can\'t write, with no hint', () => {
        expect(state({ ...SHARED_WRITE, accountId: GONE })).toMatchObject({ canPay: false, canSkip: false, canUnpay: false, canWrite: true, accountHint: null });
        expect(state({ ...SHARED_WRITE, accountId: 3 })).toMatchObject({ canPay: true, canSkip: true, canUnpay: true });
    });

    it('offers none on a bill shared read-only, whatever its account', () => {
        expect(state({ ...SHARED_READ, accountId: 3 })).toMatchObject({ canPay: false, canSkip: false, canUnpay: false, canWrite: false, accountHint: null });
    });

    it('keeps Mark Unpaid only where the server has a payment to revert', () => {
        expect(state({ accountId: 1, canMarkUnpaid: false }).canUnpay).toBe(false);
    });
});

describe('a transfer row', () => {
    const state = (fields) => billRowState(transfer(fields), TODAY, {}, ACCOUNTS);

    it('needs its destination too', () => {
        expect(state({})).toMatchObject({ canPay: true, canSkip: true, canUnpay: true });
        expect(state({ destinationAccountId: 2 })).toMatchObject({ canPay: false, canSkip: false, canUnpay: false, accountHint: BILL_HINT });
    });

    it('with no destination left offers no payment actions and says why', () => {
        expect(state({ destinationAccountId: null })).toMatchObject({ canPay: false, canSkip: false, canUnpay: false, canWrite: true, accountHint: BILL_HINT });
    });
});

describe('an income row', () => {
    const state = (fields) => incomeRowState(income(fields), TODAY, {}, ACCOUNTS);

    it('offers Mark Received only into an account you can write, and Skip either way', () => {
        expect(state({ accountId: 1 })).toMatchObject({ canReceive: true, canSkip: true, accountHint: null });
        expect(state({ accountId: null })).toMatchObject({ canReceive: true, canSkip: true });
        expect(state({ accountId: 2 })).toMatchObject({ canReceive: false, canSkip: true, canWrite: true, accountHint: INCOME_HINT });
        expect(state({ accountId: GONE })).toMatchObject({ canReceive: false, canSkip: true, accountHint: INCOME_HINT });
    });

    it('shared to write: Skip stays, Mark Received needs the account, no hint', () => {
        expect(state({ ...SHARED_WRITE, accountId: GONE })).toMatchObject({ canReceive: false, canSkip: true, canWrite: true, accountHint: null });
        expect(state({ ...SHARED_WRITE, accountId: 3 })).toMatchObject({ canReceive: true, canSkip: true });
    });

    it('shared read-only: nothing', () => {
        expect(state({ ...SHARED_READ, accountId: 3 })).toMatchObject({ canReceive: false, canSkip: false, canWrite: false, accountHint: null });
    });
});

describe('the pages', () => {
    const buttons = (selector, pattern) => [...document.querySelectorAll(selector)].map(b => b.className.match(pattern)?.[1]);
    const hint = () => document.querySelector('.bill-account-hint')?.textContent.trim() ?? null;

    it('Bills: no payment actions on a bill into a read-only account, and the hint', () => {
        document.body.innerHTML = '<div id="bills-list"></div><div id="empty-bills"></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS, categories: [] };

        mod.renderBills([bill({ accountId: 2 })]);
        expect(buttons('.bill-actions button', /bill-(paid|skip|unpaid|edit|delete)-btn/)).toEqual(['edit', 'delete']);
        expect(hint()).toBe(BILL_HINT);

        mod.renderBills([bill({ accountId: 1 })]);
        expect(buttons('.bill-actions button', /bill-(paid|skip|unpaid|edit|delete)-btn/)).toEqual(['paid', 'skip', 'unpaid', 'edit', 'delete']);
        expect(hint()).toBeNull();
    });

    it('Transfers: no payment actions without a destination, and the hint', () => {
        document.body.innerHTML = '<div id="transfers-list"></div><div id="empty-transfers"></div>';
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS };

        mod.transfers = [transfer({ destinationAccountId: null })];
        mod.renderTransfers();
        expect(buttons('.bill-actions button', /transfer-(paid|skip|unpaid|edit|delete)-btn/)).toEqual(['edit', 'delete']);
        expect(hint()).toBe(BILL_HINT);

        mod.transfers = [transfer()];
        mod.renderTransfers();
        expect(buttons('.bill-actions button', /transfer-(paid|skip|unpaid|edit|delete)-btn/)).toEqual(['paid', 'skip', 'unpaid', 'edit', 'delete']);
        expect(hint()).toBeNull();
    });

    it('Income: Skip but no Mark Received into a read-only account, and the hint', () => {
        document.body.innerHTML = '<div id="income-list"></div><div id="empty-income"></div>';
        const mod = Object.create(IncomeModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS };

        mod.renderRecurringIncome([income({ accountId: 2 })]);
        expect(buttons('.income-actions button', /income-(received|skip|edit|delete)-btn/)).toEqual(['skip', 'edit', 'delete']);
        expect(hint()).toBe(INCOME_HINT);
    });

    it('the unrecorded payments card keeps only Dismiss on a bill into an account you can\'t write', async () => {
        document.body.innerHTML = `
            <div id="unrecorded-payments-card" style="display: none;">
                <span id="unrecorded-payments-count"></span>
                <div id="unrecorded-payments-list"></div>
            </div>`;
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const items = [
            { billId: 7, name: 'Garage', amount: 33.5, lastPaidDate: '2026-09-25', accountId: 2, canMarkUnpaid: true },
            { billId: 8, name: 'Savings', amount: 50, lastPaidDate: '2026-09-25', accountId: 1, isTransfer: true, destinationAccountId: null, canMarkUnpaid: true },
            { billId: 9, name: 'Gym', amount: 20, lastPaidDate: '2026-09-25', accountId: 1, canMarkUnpaid: true },
        ];
        global.fetch = vi.fn(async () => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => ({ items, count: 3 }) }));
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS };

        await mod.loadUnrecordedPayments();

        const rows = [...document.querySelectorAll('.unrecorded-payment-row')].map(row => ({
            buttons: [...row.querySelectorAll('button')].map(b => b.className.match(/unrecorded-payment-(record|unpaid|dismiss|assign)/)?.[1]),
            hint: row.querySelector('.bill-account-hint')?.textContent.trim() ?? null,
        }));
        expect(rows).toEqual([
            { buttons: ['dismiss'], hint: BILL_HINT },
            { buttons: ['dismiss'], hint: BILL_HINT },
            { buttons: ['record', 'unpaid', 'dismiss'], hint: null },
        ]);
    });
});
