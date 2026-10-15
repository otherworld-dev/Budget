/**
 * Deleting a whole pension. Its contributions go with it, but the bank
 * transactions that paid into or out of it stay in the accounts, and the
 * confirm says so instead of only "cannot be undone".
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

afterEach(() => {
    vi.clearAllMocks();
});

describe('deletePension', () => {
    it('says the bank transactions stay', async () => {
        const mod = Object.create(PensionsModule.prototype);
        mod.app = { pensions: [{ id: 1, name: 'Work' }] };

        await mod.deletePension(1);

        const [message] = confirmDialog.mock.calls[0];
        expect(message).toContain('bank transactions');
    });
});
