/**
 * The share configuration panel lists what the owner can share (#392).
 *
 * Bills, recurring income and savings goals were read from lists the app only
 * fills when their own pages are opened, and a section with nothing in it is
 * left out. Opening Sharing straight after loading the app therefore showed no
 * Bills section at all, so bills could not be shared.
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

import SharingModule from '../../src/modules/sharing/SharingModule.js';

const shared = { _shared: true, _sharedBy: 'partner' };

const api = {
    '/apps/budget/api/shares/3/items': {},
    '/apps/budget/api/shares/3/auto-config': {},
    '/apps/budget/api/bills': [
        { id: 1, name: 'Rent', isTransfer: false },
        { id: 2, name: 'Monthly savings', isTransfer: true },
        { id: 3, name: 'Partner phone', isTransfer: false, ...shared },
    ],
    '/apps/budget/api/recurring-income': [
        { id: 11, name: 'Salary' },
        { id: 12, name: 'Partner salary', ...shared },
    ],
    '/apps/budget/api/savings-goals': [
        { id: 21, name: 'Holiday' },
        { id: 22, name: 'Partner car', ...shared },
    ],
    '/apps/budget/api/import-rules': [],
    '/apps/budget/api/projects': [],
};

function listed(panel, type) {
    return [...panel.querySelectorAll(`.share-config-section[data-type="${type}"] input[data-entity-id]`)]
        .map(input => Number(input.dataset.entityId));
}

describe('share configuration lists', () => {
    let mod;
    let panel;

    beforeEach(() => {
        document.body.innerHTML = '<div id="share-config-3"></div>';
        panel = document.getElementById('share-config-3');
        mod = Object.create(SharingModule.prototype);
        // What the app holds straight after loading: accounts and categories,
        // and none of the lists the Bills, Income and Goals pages fill
        mod.app = {
            accounts: [{ id: 5, name: 'Current account' }, { id: 6, name: 'Joint account', ...shared }],
            categoryTree: [],
            bills: [],
            recurringIncome: [],
            savingsGoals: [],
        };
        mod.fetchApi = vi.fn(async url => {
            if (!(url in api)) {
                throw new Error(`unexpected ${url}`);
            }
            return api[url];
        });
    });

    it('offers bills, transfers included, without the Bills page being opened first', async () => {
        await mod.loadConfigPanel(3);

        expect(listed(panel, 'bill')).toEqual([1, 2]);
    });

    it('offers recurring income and savings goals without their pages being opened first', async () => {
        await mod.loadConfigPanel(3);

        expect(listed(panel, 'recurring_income')).toEqual([11]);
        expect(listed(panel, 'savings_goal')).toEqual([21]);
    });

    it('reads bills fresh rather than from the Bills page, which leaves transfers out', async () => {
        mod.app.bills = [{ id: 1, name: 'Rent', isTransfer: false }];

        await mod.loadConfigPanel(3);

        expect(listed(panel, 'bill')).toEqual([1, 2]);
    });

    it('leaves out accounts someone else shared with the owner', async () => {
        // The server refuses the whole section if one of them is ticked
        await mod.loadConfigPanel(3);

        expect(listed(panel, 'account')).toEqual([5]);
    });
});
