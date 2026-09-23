/**
 * The main transactions list and an account's register render their rows
 * through one function. They used to have a copy each and drifted: the main
 * list glued "Transfer" to the account name outside t(), and the register
 * never showed the pending, no-forecast or pension badges.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';

const ACCOUNTS = [{ id: 1, name: 'Current' }, { id: 2, name: 'Savings' }];
const CATEGORIES = [{ id: 7, name: 'Food', color: '#f00' }];

function row(tx, variant, extra = {}) {
    const tbody = document.createElement('tbody');
    tbody.innerHTML = renderTransactionRow(tx, {
        variant,
        accounts: ACCOUNTS,
        categories: CATEGORIES,
        currency: 'GBP',
        formatCurrency: (v) => '£' + Number(v).toFixed(2),
        formatDate: (d) => d,
        ...extra,
    });
    return tbody.querySelector('tr');
}

const base = { id: 5, accountId: 1, date: '2026-03-01', description: 'Lunch', type: 'debit', amount: 12.5, categoryId: 7 };

describe('renderTransactionRow', () => {
    it.each(['ledger', 'register'])('%s: shows every badge the transaction carries', (variant) => {
        const tr = row({
            ...base,
            status: 'pending',
            excludedFromForecast: true,
            pensionContribId: 3,
            linkedTransactionId: 9,
            linkedAccountId: 2,
        }, variant);

        const badges = tr.querySelector('.transaction-badges');
        expect(badges.querySelector('.pending-badge')).not.toBeNull();
        expect(badges.querySelector('.forecast-excluded-badge')).not.toBeNull();
        expect(badges.querySelector('.pension-indicator')).not.toBeNull();
        expect(tr.classList.contains('pending-transaction')).toBe(true);
        expect(tr.classList.contains('is-linked')).toBe(true);
    });

    it.each(['ledger', 'register'])('%s: the transfer badge is one translated string', (variant) => {
        const tr = row({ ...base, linkedTransactionId: 9, linkedAccountId: 2 }, variant);

        const badge = tr.querySelector('.linked-indicator');
        expect(badge.textContent.trim()).toContain('Transfer → Savings');
        expect(badge.dataset.linkedId).toBe('9');
        expect(badge.dataset.linkedAccountId).toBe('2');
    });

    it.each(['ledger', 'register'])('%s: a debit reads with its sign and the running balance', (variant) => {
        const tr = row(base, variant, { balance: -3 });

        const amount = tr.querySelector('.amount-column');
        expect(amount.textContent).toContain('-£12.50');
        expect(tr.querySelector('.transaction-balance').className).toContain('negative');
    });

    it('ledger: keeps the inline-edit cells, select box and menu the handlers use', () => {
        const tr = row(base, 'ledger', { selected: true, tagIds: [4, 6], tagsHtml: '<span class="tag-chip">x</span>' });

        expect(tr.querySelector('.transaction-checkbox').checked).toBe(true);
        expect(tr.querySelector('.date-column').dataset.field).toBe('date');
        expect(tr.querySelector('.category-column').classList.contains('editable-cell')).toBe(true);
        expect(tr.querySelector('.tags-column').dataset.value).toBe('4,6');
        expect(tr.querySelector('.account-name').textContent).toBe('Current');
        expect(tr.querySelector('.amount.negative')).not.toBeNull();
        expect(tr.querySelector('.transaction-edit-btn')).not.toBeNull();
        expect(tr.querySelector('.more-actions-btn')).not.toBeNull();
    });

    it('register: keeps its own cells, tags slot and edit/delete buttons', () => {
        const tr = row(base, 'register');

        expect(tr.querySelector('.description-main').textContent).toBe('Lunch');
        expect(tr.querySelector('.category-name').textContent.trim()).toBe('Food');
        expect(tr.querySelector('.transaction-tags-display').dataset.transactionId).toBe('5');
        expect(tr.querySelector('.transaction-amount.debit')).not.toBeNull();
        expect(tr.querySelector('.edit-transaction-btn')).not.toBeNull();
        expect(tr.querySelector('.delete-transaction-btn')).not.toBeNull();
        expect(tr.querySelector('.transaction-checkbox')).toBeNull();
    });

    it('a scheduled row marks its balance as projected in both lists', () => {
        for (const variant of ['ledger', 'register']) {
            const tr = row({ ...base, status: 'scheduled' }, variant, { balance: 10 });
            expect(tr.querySelector('.transaction-balance').className).toContain('projected');
            expect(tr.querySelector('.scheduled-badge')).not.toBeNull();
        }
    });
});
