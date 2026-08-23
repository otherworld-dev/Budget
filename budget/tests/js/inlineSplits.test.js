/**
 * Regression coverage for the inline split editor (#358).
 *
 * The editor keeps its LAST row as an auto-computed remainder: it always holds
 * the transaction total minus the rows above it. That rule is applied by
 * refreshInlineSplitRows() -> recomputeRemainderRow(), which rewrites whichever
 * row is currently last.
 *
 * The bug these tests exist for: loadExistingSplits() appended stored splits
 * one at a time, and every append triggered that refresh. Each newly appended
 * row was momentarily the last row, so its stored amount was recalculated away
 * as soon as the next row arrived. A stored 1/3/6 of 10 rendered as 1/9.00/0.00
 * and re-saving from that view dropped the third part for being zero -- silent
 * data loss, and the save guard passed because the two survivors still summed
 * to the total.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

/** The parts of the transaction form the split editor actually touches. */
function mountForm(total = '10', type = 'debit') {
    document.body.innerHTML = `
        <form id="transaction-form">
            <input type="number" id="transaction-amount" value="${total}">
            <select id="transaction-type"><option value="${type}" selected>${type}</option></select>
            <div id="inline-splits-section">
                <span id="inline-split-remaining"></span>
                <div id="inline-splits-container"></div>
                <button type="button" id="inline-add-split-btn"></button>
            </div>
            <button type="submit">Save</button>
        </form>
    `;
}

/**
 * The real methods, without running the 200-line constructor (which wires the
 * whole transactions view). Only the collaborators the split code reaches for
 * are provided.
 */
function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { getCategoryOptions: () => '<option value="1">Groceries</option>' };
    mod._allowNegativeRemainder = false;
    return mod;
}

/** What the user would see in the Amount column, top to bottom. */
function renderedAmounts() {
    return [...document.querySelectorAll('#inline-splits-container .split-row')]
        .map(r => r.querySelector('.inline-split-amount').value);
}

function renderedRows() {
    return [...document.querySelectorAll('#inline-splits-container .split-row')].map(r => {
        const input = r.querySelector('.inline-split-amount');
        return {
            amount: input.value,
            readOnly: input.readOnly,
            min: input.getAttribute('min'),
            description: r.querySelector('.inline-split-description').value,
        };
    });
}

/** Drive loadExistingSplits() against a canned server response. */
async function loadSplits(mod, splits) {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => splits,
    });
    await mod.loadExistingSplits(42);
}

beforeEach(() => {
    global.OC = {
        generateUrl: (p) => p,
        requestToken: 'test-token',
    };
});

afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
    document.body.innerHTML = '';
});

describe('loadExistingSplits', () => {
    it('renders three stored parts at the amounts they were saved with', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'Test-1' },
            { amount: 3, categoryId: null, description: 'Test-2' },
            { amount: 6, categoryId: null, description: 'Test-3' },
        ]);

        // Before the fix this was ['1', '9.00', '0.00'].
        expect(renderedAmounts()).toEqual(['1', '3', '6.00']);
    });

    it('keeps every part of a four-part split', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'A' },
            { amount: 2, categoryId: null, description: 'B' },
            { amount: 3, categoryId: null, description: 'C' },
            { amount: 4, categoryId: null, description: 'D' },
        ]);

        expect(renderedAmounts()).toEqual(['1', '2', '3', '4.00']);
    });

    it('pairs each amount with the description it was stored against', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'Test-1' },
            { amount: 3, categoryId: null, description: 'Test-2' },
            { amount: 6, categoryId: null, description: 'Test-3' },
        ]);

        expect(renderedRows().map(r => [r.description, r.amount])).toEqual([
            ['Test-1', '1'],
            ['Test-2', '3'],
            ['Test-3', '6.00'],
        ]);
    });

    it('makes only the last row the read-only remainder', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'A' },
            { amount: 3, categoryId: null, description: 'B' },
            { amount: 6, categoryId: null, description: 'C' },
        ]);

        expect(renderedRows().map(r => r.readOnly)).toEqual([false, false, true]);
    });

    it('reports the split as fully allocated', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'A' },
            { amount: 3, categoryId: null, description: 'B' },
            { amount: 6, categoryId: null, description: 'C' },
        ]);

        expect(document.getElementById('inline-split-remaining').textContent)
            .toContain('Fully allocated');
    });

    it('restores a negative part and lifts the min constraint that would block saving', async () => {
        // A receipt with a loyalty saving: the items add up to more than was
        // paid, so one part is legitimately negative. min="0.01" on that row
        // makes the whole form unsubmittable with nothing on screen to explain
        // why, so it is dropped for negative rows only.
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 12, categoryId: null, description: 'Items' },
            { amount: -5, categoryId: null, description: 'Savings' },
            { amount: 3, categoryId: null, description: 'Tax' },
        ]);

        expect(renderedAmounts()).toEqual(['12', '-5', '3.00']);
        expect(renderedRows().map(r => r.min)).toEqual(['0.01', null, '0.01']);
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });

    it('leaves a form with an ordinary split submittable', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'A' },
            { amount: 3, categoryId: null, description: 'B' },
            { amount: 6, categoryId: null, description: 'C' },
        ]);

        // A four-part split used to leave an editable 0.00 row carrying
        // min="0.01", which the browser refused to submit while saying nothing.
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });

    it('falls back to two blank rows when a transaction has no splits', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, []);

        expect(renderedRows()).toHaveLength(2);
        expect(renderedRows()[1].readOnly).toBe(true);
    });
});

describe('saveInlineSplits', () => {
    it('posts every part of a freshly loaded split', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'Test-1' },
            { amount: 3, categoryId: null, description: 'Test-2' },
            { amount: 6, categoryId: null, description: 'Test-3' },
        ]);

        const post = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        global.fetch = post;
        await mod.saveInlineSplits(42);

        expect(post).toHaveBeenCalledOnce();
        const sent = JSON.parse(post.mock.calls[0][1].body).splits;
        // The whole point: three parts in, three parts out. Before the fix the
        // third arrived as 0.00, was filtered out as an empty row, and only two
        // were ever stored.
        expect(sent.map(s => s.amount)).toEqual([1, 3, 6]);
    });

    it('posts a negative part rather than dropping it', async () => {
        mountForm('10');
        const mod = makeModule();

        await loadSplits(mod, [
            { amount: 12, categoryId: null, description: 'Items' },
            { amount: -5, categoryId: null, description: 'Savings' },
            { amount: 3, categoryId: null, description: 'Tax' },
        ]);

        const post = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        global.fetch = post;
        await mod.saveInlineSplits(42);

        const sent = JSON.parse(post.mock.calls[0][1].body).splits;
        expect(sent.map(s => s.amount)).toEqual([12, -5, 3]);
    });
});

describe('addInlineSplitRow', () => {
    it('recomputes the remainder immediately when adding a row by hand', () => {
        // The interactive path must NOT defer: clicking "+ Add Row" has to
        // settle the new remainder there and then.
        mountForm('10');
        const mod = makeModule();

        mod.initInlineSplitRows();
        document.querySelectorAll('.inline-split-amount')[0].value = '4';
        mod.recomputeRemainderRow();
        expect(renderedAmounts()).toEqual(['4', '6.00']);

        mod.addInlineSplitRow();

        // Rows 1 and 2 keep what they hold; the new last row takes the rest.
        expect(renderedAmounts()).toEqual(['4', '6.00', '0.00']);
        expect(renderedRows().map(r => r.readOnly)).toEqual([false, false, true]);
    });

    it('leaves the remainder alone while deferring, and settles it on refresh', () => {
        mountForm('10');
        const mod = makeModule();

        mod.addInlineSplitRow(true, { amount: 1, categoryId: null, description: 'A' }, { defer: true });
        mod.addInlineSplitRow(false, { amount: 3, categoryId: null, description: 'B' }, { defer: true });
        mod.addInlineSplitRow(false, { amount: 6, categoryId: null, description: 'C' }, { defer: true });

        // Nothing has been recalculated yet: all three still hold what was
        // passed in.
        expect(renderedAmounts()).toEqual(['1', '3', '6']);

        mod.refreshInlineSplitRows();

        // One settle pass, and the stored set reconciles to the total, so the
        // last row lands back on its own value.
        expect(renderedAmounts()).toEqual(['1', '3', '6.00']);
    });

    it('corrects the last row when a stored set does not add up to the total', () => {
        // Data drift: the parts total 9 against a transaction of 10. The
        // remainder row is authoritative, so it absorbs the difference rather
        // than leaving the user with a split that cannot be saved.
        mountForm('10');
        const mod = makeModule();

        return loadSplits(mod, [
            { amount: 1, categoryId: null, description: 'A' },
            { amount: 3, categoryId: null, description: 'B' },
            { amount: 5, categoryId: null, description: 'C' },
        ]).then(() => {
            expect(renderedAmounts()).toEqual(['1', '3', '6.00']);
        });
    });

    it('marks the first row as the one that cannot be removed', () => {
        mountForm('10');
        const mod = makeModule();

        mod.initInlineSplitRows();

        const removeButtons = [...document.querySelectorAll('.split-remove-btn')];
        expect(removeButtons[0].disabled).toBe(true);
        expect(removeButtons[1].disabled).toBe(false);
    });
});
