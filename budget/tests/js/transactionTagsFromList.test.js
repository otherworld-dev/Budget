/**
 * The transactions list's tags come with the list.
 *
 * Every page load asked for each row's tags separately: 25 to 250 requests
 * a page, around ten seconds of server time for a long one. The list
 * answer now carries a map of transaction id to tags, and the page reads
 * them from there.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TagSetsModule from '../../src/modules/tagsets/TagSetsModule.js';

const HOLIDAY = { id: 3, name: 'Holiday', color: '#00aa00' };

afterEach(() => {
    delete global.fetch;
});

function makeModule(transactions) {
    const mod = Object.create(TagSetsModule.prototype);
    mod.app = { transactions, transactionTags: { 99: [HOLIDAY] } };
    return mod;
}

describe('the tags of a page of transactions', () => {
    it('come from the list answer, with no request per row', async () => {
        global.fetch = vi.fn();
        const mod = makeModule([{ id: 1 }, { id: 2 }]);

        // Keys arrive as strings in JSON
        await mod.loadAllTransactionTags({ 1: [HOLIDAY] });

        expect(global.fetch).not.toHaveBeenCalled();
        expect(mod.app.transactionTags).toEqual({ 1: [HOLIDAY], 2: [] });
    });

    it('are empty for every row when the answer has none', async () => {
        const mod = makeModule([{ id: 1 }]);

        await mod.loadAllTransactionTags(undefined);

        expect(mod.app.transactionTags).toEqual({ 1: [] });
    });
});
