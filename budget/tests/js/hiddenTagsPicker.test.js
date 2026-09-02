/**
 * Hidden tags stay off the pickers for new entries (#373).
 *
 * A tag can be marked hidden once it has served its purpose (a finished
 * trip, a closed project). The transaction form must then stop offering it
 * — but a transaction that already carries the tag must keep showing it,
 * checked, because the form saves the full set of checked boxes on every
 * change: leaving the box out would silently strip the tag on the next edit.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import TagSetsModule from '../../src/modules/tagsets/TagSetsModule.js';

const NYC = { id: 10, name: '2026 NYC', color: '#111111', hidden: true };
const LOCAL = { id: 11, name: 'Local', color: '#222222', hidden: false };
const OLD_PROJECT = { id: 20, name: 'Old project', color: '#333333', hidden: true, tagSetId: 1 };
const ACTIVE_PROJECT = { id: 21, name: 'Active project', color: '#444444', hidden: false, tagSetId: 1 };

function makeModule({ transactionTags = [] } = {}) {
    const mod = Object.create(TagSetsModule.prototype);
    mod.app = { globalTags: [], transactionTags: {}, selectedCategoryTagSets: [] };
    mod.loadGlobalTags = vi.fn(async () => { mod.globalTags = [NYC, LOCAL]; });
    mod.loadTagSetsForCategory = vi.fn(async () => [
        { id: 1, name: 'Project', tags: [OLD_PROJECT, ACTIVE_PROJECT] },
    ]);
    mod.loadTransactionTags = vi.fn(async () => transactionTags);
    mod.saveTransactionTags = vi.fn();
    return mod;
}

const checkboxValues = () =>
    Array.from(document.querySelectorAll('#transaction-tags-container input[type="checkbox"]'))
        .map(cb => parseInt(cb.value));

beforeEach(() => {
    document.body.innerHTML = '<div id="transaction-tags-container"></div>';
});

describe('transaction tag picker and hidden tags', () => {
    it('does not offer hidden global or tag-set tags on a new transaction', async () => {
        const mod = makeModule();

        await mod.renderTransactionTagSelectors(5, null);

        expect(checkboxValues()).toEqual([LOCAL.id, ACTIVE_PROJECT.id]);
    });

    it('keeps a hidden tag the transaction already carries, checked', async () => {
        const mod = makeModule({ transactionTags: [NYC, OLD_PROJECT] });

        await mod.renderTransactionTagSelectors(5, 42);

        expect(checkboxValues()).toEqual([NYC.id, LOCAL.id, OLD_PROJECT.id, ACTIVE_PROJECT.id]);
        const nyc = document.querySelector('#transaction-tags-container input[value="10"]');
        expect(nyc.checked).toBe(true);
        const oldProject = document.querySelector('#transaction-tags-container input[value="20"]');
        expect(oldProject.checked).toBe(true);
    });

    it('still says no tags are available when every tag is hidden', async () => {
        const mod = makeModule();
        mod.loadGlobalTags = vi.fn(async () => { mod.globalTags = [NYC]; });
        mod.loadTagSetsForCategory = vi.fn(async () => [{ id: 1, name: 'Project', tags: [OLD_PROJECT] }]);

        await mod.renderTransactionTagSelectors(5, null);

        expect(checkboxValues()).toEqual([]);
        expect(document.getElementById('transaction-tags-container').textContent).toContain('No tags available');
    });
});
