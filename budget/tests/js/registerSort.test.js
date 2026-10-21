/**
 * Sorting an account's register by its headings.
 *
 * The register's Date, Description and Amount headings carried the
 * sortable markup but nothing listened to them, so a click did nothing in
 * the register. It reached the Transactions list's own handler instead,
 * which listened for any sortable heading on the page, and re-sorted and
 * reloaded that list. Each table now sorts itself.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => String(text),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const read = (file) => fs.readFileSync(path.resolve(here, '../..', file), 'utf8').replace(/\r\n/g, '\n');
const TEMPLATE = read('templates/index.php');
const TRANSACTIONS = read('src/modules/transactions/TransactionsModule.js');

function register() {
    document.body.innerHTML = `
        <table id="account-transactions-table"><thead><tr>
            <th tabindex="0" aria-sort="none" class="date-column sortable" data-sort="date">Date <span class="sort-indicator"></span></th>
            <th tabindex="0" aria-sort="none" class="description-column sortable" data-sort="description">Description <span class="sort-indicator"></span></th>
            <th class="vendor-column">Vendor</th>
            <th tabindex="0" aria-sort="none" class="amount-column sortable" data-sort="amount">Amount <span class="sort-indicator"></span></th>
        </tr></thead><tbody id="account-transactions-body"></tbody></table>`;
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { categories: [], accounts: [] };
    mod.currentAccount = { id: 12770 };
    mod.accountSort = { field: 'date', direction: 'desc' };
    mod.accountCurrentPage = 3;
    mod.loadAccountTransactions = vi.fn();
    return mod;
}

const heading = (field) => document.querySelector(`#account-transactions-table th[data-sort="${field}"]`);

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the register headings', () => {
    let mod;
    beforeEach(() => {
        mod = register();
    });

    it('sort by another column, ascending, from the first page', () => {
        mod.sortAccountTransactions('amount');

        expect(mod.accountSort).toEqual({ field: 'amount', direction: 'asc' });
        expect(mod.accountCurrentPage).toBe(1);
        expect(mod.loadAccountTransactions).toHaveBeenCalledWith(12770);
    });

    it('turn the order round when the same column is clicked again', () => {
        mod.sortAccountTransactions('date');

        expect(mod.accountSort).toEqual({ field: 'date', direction: 'asc' });
        mod.sortAccountTransactions('date');
        expect(mod.accountSort).toEqual({ field: 'date', direction: 'desc' });
    });

    it('show which column is sorted, and which way, to the eye and to screen readers', () => {
        mod.sortAccountTransactions('description');

        expect(heading('description').getAttribute('aria-sort')).toBe('ascending');
        expect(heading('description').querySelector('.sort-indicator').className).toBe('sort-indicator asc');
        expect(heading('date').getAttribute('aria-sort')).toBe('none');
        expect(heading('date').querySelector('.sort-indicator').className).toBe('sort-indicator');
    });

    it('answer a click once the details view is set up', () => {
        mod._accountDetailsListenersSetup = false;
        // The rest of the details view isn't in this page; only the headings matter here
        mod.setupAccountDetailsEventListeners();

        heading('amount').click();

        expect(mod.accountSort.field).toBe('amount');
        expect(mod.loadAccountTransactions).toHaveBeenCalledTimes(1);
    });

    it('can be reached and used from the keyboard', () => {
        const registerHead = TEMPLATE.slice(TEMPLATE.indexOf('id="account-transactions-table"'), TEMPLATE.indexOf('</thead>', TEMPLATE.indexOf('id="account-transactions-table"')));
        const sortable = registerHead.match(/<th[^>]*sortable[^>]*>/g);

        expect(sortable).toHaveLength(3);
        sortable.forEach(th => expect(th).toMatch(/tabindex="0"/));
        sortable.forEach(th => expect(th).toMatch(/aria-sort="none"/));
    });
});

describe('the Transactions list', () => {
    it('only sorts for its own headings', () => {
        expect(TRANSACTIONS).toContain("e.target.closest('#transactions-table th.sortable')");
        expect(TRANSACTIONS).not.toMatch(/e\.target\.closest\('\.sortable'\)/);
    });

    it('only moves its own sort arrows', () => {
        expect(TRANSACTIONS).toContain("document.querySelectorAll('#transactions-table .sort-indicator')");
    });
});
