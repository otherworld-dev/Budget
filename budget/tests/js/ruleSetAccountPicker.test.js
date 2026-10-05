/**
 * The accounts a rule's Set Account offers.
 *
 * A rule may only move a row into one of its owner's own accounts: the
 * server refuses anything else when the rule is saved. The picker still
 * listed accounts shared with you, so choosing one failed on save. It now
 * lists the rule owner's own open accounts (yours, for your own rules),
 * and keeps the account a rule already names so editing it doesn't lose it.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { ruleTargetAccounts } from '../../src/utils/accounts.js';
import { ActionBuilder } from '../../src/modules/rules/components/ActionBuilder.js';

const OWN = { id: 1, name: 'Current', userId: 'bob' };
const OWN_CLOSED = { id: 2, name: 'Old savings', userId: 'bob', closed: true };
const SHARED_WRITE = { id: 3, name: 'Joint', userId: 'alice', _shared: true, _canWrite: true };
const SHARED_READ = { id: 4, name: 'Parents', userId: 'alice', _shared: true, _canWrite: false };
const ACCOUNTS = [OWN, OWN_CLOSED, SHARED_WRITE, SHARED_READ];

const ids = (accounts) => accounts.map(a => a.id);

describe('ruleTargetAccounts', () => {
    it('lists only your own open accounts for your own rule', () => {
        expect(ids(ruleTargetAccounts(ACCOUNTS, null, null))).toEqual([1]);
    });

    it('keeps the account the rule already names, even one it may no longer use', () => {
        expect(ids(ruleTargetAccounts(ACCOUNTS, null, 3))).toEqual([1, 3]);
        expect(ids(ruleTargetAccounts(ACCOUNTS, null, '2'))).toEqual([1, 2]);
    });

    it('lists the owner\'s accounts for a rule shared with you', () => {
        expect(ids(ruleTargetAccounts(ACCOUNTS, 'alice', null))).toEqual([3, 4]);
    });

    it('lists your own open accounts for a saved rule of yours', () => {
        expect(ids(ruleTargetAccounts(ACCOUNTS, 'bob', null))).toEqual([1]);
    });
});

describe('the Set Account action', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    function options(accountOwner = null, value = '') {
        document.body.innerHTML = '<div id="builder"></div>';
        // eslint-disable-next-line no-new
        new ActionBuilder(document.getElementById('builder'), {
            version: 2,
            actions: [{ type: 'set_account', value, behavior: 'always' }],
        }, { accounts: ACCOUNTS, accountOwner });
        return [...document.querySelectorAll('select.action-value option')].map(o => o.value).filter(Boolean);
    }

    it('offers your own open accounts', () => {
        expect(options()).toEqual(['1']);
    });

    it('offers the owner\'s accounts on a rule shared with you', () => {
        expect(options('alice')).toEqual(['3', '4']);
    });

    it('keeps the account the rule moves rows to', () => {
        expect(options(null, 3)).toEqual(['1', '3']);
    });
});
