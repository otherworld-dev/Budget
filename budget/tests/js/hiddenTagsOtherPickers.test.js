/**
 * Hidden tags stay off every picker that assigns tags to something new
 * (#373): the bill form, the transfer form, the savings-goal link and the
 * rule action builder. Each keeps a hidden tag the item already carries —
 * bills and transfers save the full set of checked boxes, a goal keeps its
 * linked tag, and a rule keeps the tags it already adds.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

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

vi.mock('../../src/modules/rules/components/ActionBuilder.css', () => ({}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import SavingsModule from '../../src/modules/savings/SavingsModule.js';
import { ActionBuilder } from '../../src/modules/rules/components/ActionBuilder.js';

const NYC = { id: 10, name: '2026 NYC', color: '#111111', hidden: true, tagSetId: null };
const LOCAL = { id: 11, name: 'Local', color: '#222222', hidden: false, tagSetId: null };
const OLD_PROJECT = { id: 20, name: 'Old project', color: '#333333', hidden: true, tagSetId: 1 };
const ACTIVE_PROJECT = { id: 21, name: 'Active project', color: '#444444', hidden: false, tagSetId: 1 };
const TAG_SETS = [{ id: 1, name: 'Project', tags: [OLD_PROJECT, ACTIVE_PROJECT] }];

function stubTagFetch() {
    global.OC = { generateUrl: (u) => u, requestToken: 'token' };
    global.fetch = vi.fn(async (url) => ({
        ok: true,
        json: async () => (String(url).includes('/tags/global') ? [NYC, LOCAL] : TAG_SETS),
    }));
}

const checkboxValues = (selector) =>
    Array.from(document.querySelectorAll(selector)).map(cb => parseInt(cb.value));

afterEach(() => {
    delete global.fetch;
    delete global.OC;
});

describe('bill form tag picker', () => {
    beforeEach(() => {
        stubTagFetch();
        document.body.innerHTML = '<div id="bill-tags-container"></div>';
    });

    it('does not offer hidden tags on a new bill', async () => {
        const mod = Object.create(BillsModule.prototype);
        mod.app = {};

        await mod.loadBillTagSets(5, null);

        expect(checkboxValues('.bill-tag-checkbox')).toEqual([LOCAL.id, ACTIVE_PROJECT.id]);
    });

    it('keeps hidden tags the bill already carries', async () => {
        const mod = Object.create(BillsModule.prototype);
        mod.app = {};

        await mod.loadBillTagSets(5, { id: 1, tagIds: [NYC.id, OLD_PROJECT.id] });

        expect(checkboxValues('.bill-tag-checkbox')).toEqual([NYC.id, LOCAL.id, OLD_PROJECT.id, ACTIVE_PROJECT.id]);
        expect(document.querySelector('.bill-tag-checkbox[value="10"]').checked).toBe(true);
    });
});

describe('transfer form tag picker', () => {
    beforeEach(() => {
        stubTagFetch();
        document.body.innerHTML = '<div id="transfer-tags-container"></div>';
    });

    it('does not offer hidden tags on a new transfer', async () => {
        const mod = Object.create(TransfersModule.prototype);
        mod.app = {};

        await mod.loadTransferTagSets(5, null);

        expect(checkboxValues('.transfer-tag-checkbox')).toEqual([LOCAL.id, ACTIVE_PROJECT.id]);
    });

    it('keeps hidden tags the transfer already carries', async () => {
        const mod = Object.create(TransfersModule.prototype);
        mod.app = {};

        await mod.loadTransferTagSets(5, { id: 1, tagIds: [OLD_PROJECT.id] });

        expect(checkboxValues('.transfer-tag-checkbox')).toEqual([LOCAL.id, OLD_PROJECT.id, ACTIVE_PROJECT.id]);
        expect(document.querySelector('.transfer-tag-checkbox[value="20"]').checked).toBe(true);
    });
});

describe('savings goal tag link', () => {
    const optionValues = () =>
        Array.from(document.querySelectorAll('#goal-tag option')).map(o => o.value).filter(v => v !== '');

    beforeEach(() => {
        stubTagFetch();
        document.body.innerHTML = '<select id="goal-tag"></select>';
    });

    it('does not list hidden tags for a new goal', async () => {
        const mod = Object.create(SavingsModule.prototype);
        mod.app = {};
        mod._allTagSets = [];

        await mod.populateGoalTagDropdown();

        expect(optionValues()).toEqual([String(LOCAL.id), String(ACTIVE_PROJECT.id)]);
    });

    it('adds back the hidden tag a goal is already linked to', async () => {
        const mod = Object.create(SavingsModule.prototype);
        mod.app = {};
        mod._allTagSets = [];
        await mod.populateGoalTagDropdown();

        mod.ensureGoalTagOption(NYC.id);

        expect(optionValues()).toContain(String(NYC.id));
        const option = document.querySelector('#goal-tag option[value="10"]');
        expect(option.textContent).toContain('2026 NYC');
    });

    it('leaves the dropdown alone when the linked tag is already listed', async () => {
        const mod = Object.create(SavingsModule.prototype);
        mod.app = {};
        mod._allTagSets = [];
        await mod.populateGoalTagDropdown();

        mod.ensureGoalTagOption(LOCAL.id);

        expect(document.querySelectorAll('#goal-tag option[value="11"]').length).toBe(1);
    });
});

describe('rule action builder tag picker', () => {
    const builderWith = (tagSets) => {
        const ab = Object.create(ActionBuilder.prototype);
        ab.options = { tagSets };
        return ab;
    };
    const tagIdsIn = (html) => {
        const el = document.createElement('div');
        el.innerHTML = html;
        return Array.from(el.querySelectorAll('.tag-select')).map(cb => parseInt(cb.dataset.tagId));
    };

    it('does not offer hidden tags on a new add-tags action', () => {
        const ab = builderWith([{ id: 'global', name: 'Tags', tags: [NYC, LOCAL] }, ...TAG_SETS]);

        const html = ab.renderTagsAction({ type: 'add_tags', value: [] }, 0);

        expect(tagIdsIn(html)).toEqual([LOCAL.id, ACTIVE_PROJECT.id]);
    });

    it('keeps hidden tags an existing action already adds', () => {
        const ab = builderWith([{ id: 'global', name: 'Tags', tags: [NYC, LOCAL] }, ...TAG_SETS]);

        const html = ab.renderTagsAction({ type: 'add_tags', value: [NYC.id] }, 0);

        expect(tagIdsIn(html)).toEqual([NYC.id, LOCAL.id, ACTIVE_PROJECT.id]);
    });
});
