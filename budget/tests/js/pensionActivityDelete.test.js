/**
 * Deleting a pension entry from its activity list also deletes its bank
 * transaction. When that transaction was reconciled, the confirm now warns
 * that past reconciliations will stop matching, as the Transactions page
 * does; it used to say nothing.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showUndoNotification: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(false)),
}));

vi.mock('../../src/utils/chart.js', () => ({ default: vi.fn() }));

import PensionsModule from '../../src/modules/pensions/PensionsModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

function makeModule(activity) {
    const mod = Object.create(PensionsModule.prototype);
    mod.app = {};
    mod.pensionActivity = activity;
    return mod;
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('deleteActivityItem', () => {
    it('warns when the entry\'s bank transaction was reconciled', async () => {
        const mod = makeModule([{ type: 'transfer_in', id: 4, transactionId: 99, reconciled: true }]);

        await mod.deleteActivityItem('contribution', 4);

        expect(confirmDialog.mock.calls[0][0]).toContain('reconciled');
    });

    it('does not warn for one that wasn\'t', async () => {
        const mod = makeModule([{ type: 'transfer_in', id: 4, transactionId: 99, reconciled: false }]);

        await mod.deleteActivityItem('contribution', 4);

        expect(confirmDialog.mock.calls[0][0]).not.toContain('reconciled');
    });
});
