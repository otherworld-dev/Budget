/**
 * The balance trend line on each account tile.
 *
 * Each tile asked the transactions list for `?account=&startDate=&endDate=`,
 * names the list doesn't read, so every request fetched the latest 100 rows
 * of all accounts; the answer is an object, never the array the code
 * waited for, so no line was ever drawn. The tiles now draw the account's
 * last week from its balance history, the same daily balances the
 * dashboard's account chart uses.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

let requests;

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    requests = [];
    global.fetch = vi.fn(async (url) => {
        requests.push(url);
        const history = [100, 100, 90, 90, 140, 140, 120].map((balance, i) => ({ date: `2026-10-0${i + 1}`, balance }));
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => history };
    });
    document.body.innerHTML = `
        <div class="account-sparkline" data-account-id="3"><svg><path class="sparkline-path neutral" d="M0,16 L80,16"></path></svg></div>`;
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
});

describe('account tile sparklines', () => {
    it('draw the account\'s last week from its balance history', async () => {
        const mod = Object.create(AccountsModule.prototype);

        await mod.loadAccountSparklines([{ id: 3, balance: 120 }]);

        expect(requests).toEqual(['/apps/budget/api/accounts/3/balance-history?days=7']);
        const path = document.querySelector('.sparkline-path');
        expect(path.getAttribute('d').split(' L')).toHaveLength(7);
        expect(path.classList.contains('positive')).toBe(true);
    });
});
