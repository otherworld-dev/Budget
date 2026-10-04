/**
 * Opening a row in an account someone shared with you asks only for what
 * you can read.
 *
 * Receipts belong to the account's owner, so the edit form's request for
 * them came back 404 every time; the receipts section now stays hidden for
 * such a row. Its tag sets were asked for by its category even when that
 * category wasn't shared with you, which came back 400.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import TagSetsModule from '../../src/modules/tagsets/TagSetsModule.js';

const OWN = { id: 1, name: 'Current', userId: 'bob' };
const JOINT = { id: 3, name: 'Joint', userId: 'alice', _shared: true, _canWrite: true };

let requests;

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    requests = [];
    global.fetch = vi.fn(async (url) => {
        requests.push(url);
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => [] };
    });
    document.body.innerHTML = `
        <div id="transaction-attachments-group" style="display: none;">
            <div id="transaction-attachments-list"></div>
        </div>
        <div id="transaction-tags-container"></div>`;
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
});

describe('receipts', () => {
    function makeModule() {
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = { accounts: [OWN, JOINT], attachmentCounts: {} };
        mod._pendingAttachments = [];
        mod._attachmentsBound = true;
        return mod;
    }

    it('are neither shown nor asked for on a row in an account shared with you', () => {
        makeModule().setupAttachmentsSection(52, JOINT);

        expect(requests).toEqual([]);
        expect(document.getElementById('transaction-attachments-group').style.display).toBe('none');
    });

    it('load as before on your own row', () => {
        makeModule().setupAttachmentsSection(52, OWN);

        expect(requests).toEqual(['/apps/budget/api/transactions/52/attachments']);
        expect(document.getElementById('transaction-attachments-group').style.display).toBe('');
    });
});

describe('tag sets', () => {
    it('are not asked for a category that isn\'t shared with you', async () => {
        const mod = Object.create(TagSetsModule.prototype);
        mod.app = { categories: [{ id: 12, name: 'Groceries' }], globalTags: [], transactionTags: {} };

        await mod.renderTransactionTagSelectors(29, 52);

        expect(requests.some(url => url.includes('/tag-sets'))).toBe(false);
    });

    it('are asked for a category you can see', async () => {
        const mod = Object.create(TagSetsModule.prototype);
        mod.app = { categories: [{ id: 12, name: 'Groceries' }], globalTags: [], transactionTags: {} };

        await mod.renderTransactionTagSelectors(12, 52);

        expect(requests).toContain('/apps/budget/api/tag-sets?categoryId=12');
    });
});
