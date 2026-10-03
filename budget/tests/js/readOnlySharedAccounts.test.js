/**
 * Accounts shared read-only.
 *
 * Anything posted into an account shared with you read-only is refused by the
 * server (transactions, bills, rules that move rows there), so a picker for new
 * activity must not offer one. The account list marks shared accounts with
 * `_shared` and `_canWrite`; an account with no `_canWrite` flag is treated as
 * writable, as before. A record that already points at one keeps it selected,
 * the same way a closed account is kept (#370).
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

import { openAccounts, pickableAccounts } from '../../src/utils/accounts.js';

const OWN = { id: 1, name: 'Current' };
const SHARED_WRITE = { id: 2, name: 'Joint', _shared: true, _canWrite: true };
const SHARED_READ = { id: 3, name: 'Parents', _shared: true, _canWrite: false };
const SHARED_UNFLAGGED = { id: 4, name: 'Old share', _shared: true };

describe('pickers for new activity and read-only shares', () => {
    it('leave out an account shared read-only', () => {
        const ids = openAccounts([OWN, SHARED_WRITE, SHARED_READ, SHARED_UNFLAGGED]).map(a => a.id);
        expect(ids).toEqual([1, 2, 4]);
    });

    it('keep a read-only share the edited record already uses', () => {
        const ids = pickableAccounts([OWN, SHARED_READ], 3).map(a => a.id);
        expect(ids).toEqual([1, 3]);
    });

    it('leave it out when the record does not use it', () => {
        const ids = pickableAccounts([OWN, SHARED_READ], 1).map(a => a.id);
        expect(ids).toEqual([1]);
    });
});

describe('a picker that only reads the account', () => {
    it('can still offer a read-only share', () => {
        const ids = pickableAccounts([OWN, SHARED_READ], [], { readOnlyShares: true }).map(a => a.id);
        expect(ids).toEqual([1, 3]);
    });
});
