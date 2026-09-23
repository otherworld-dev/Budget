/**
 * Only the owner can delete an account.
 *
 * Write access to a shared account covers its transactions; it used to also
 * let the recipient delete the account and its whole ledger. The server now
 * answers 403, so a shared account shows no delete button and no bulk-select
 * box (the bulk toolbar only deletes).
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.selectedAccountIds = new Set();
    mod.getAccountTypeInfo = () => ({ label: 'Checking', color: '#000', icon: 'icon-folder' });
    mod.getAccountHealthStatus = () => ({ class: 'ok', tooltip: '' });
    mod.formatCurrency = (v) => String(v);
    mod.getPrimaryCurrency = () => 'GBP';
    mod.accountField = (account, key) => account[key];
    mod.formatLastReconciled = () => '';
    mod.visibleAccountColumns = () => [];
    return mod;
}

const getField = (account, key) => account[key];

function render(html) {
    const host = document.createElement('div');
    host.innerHTML = html;
    return host;
}

const own = { id: 1, name: 'Mine', type: 'checking', balance: 10, currency: 'GBP' };
const shared = { ...own, id: 9, name: 'Theirs', _shared: true };

describe.each([
    ['card', (mod, account) => mod.renderAccountCard(account, getField, { status: false, sparkline: false }, [])],
    ['row', (mod, account) => mod.renderAccountRow(account, getField, {}, [])],
])('account %s', (_name, renderOne) => {
    it('offers delete and bulk selection on an account the viewer owns', () => {
        const host = render(renderOne(makeModule(), own));

        expect(host.querySelector('.delete-account-btn')).not.toBeNull();
        expect(host.querySelector('.account-select-checkbox')).not.toBeNull();
    });

    it('offers neither on an account shared with the viewer', () => {
        const host = render(renderOne(makeModule(), shared));

        expect(host.querySelector('.delete-account-btn')).toBeNull();
        expect(host.querySelector('.account-select-checkbox')).toBeNull();
        // Editing is still the recipient's to do with write access
        expect(host.querySelector('.edit-account-btn')).not.toBeNull();
    });
});
