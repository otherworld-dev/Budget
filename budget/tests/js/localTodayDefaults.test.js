/**
 * Dates the page fills in for "today" are the user's local date.
 *
 * A reconciliation adjustment was dated with toISOString(), the UTC date.
 * In Los Angeles from 17:00 that is tomorrow, so the adjustment was stored
 * scheduled, the ticked sum left it out, the difference stayed put and
 * Finish was refused; each retry added another (#399 review, F91). The
 * interest-rate form's default effective date had the same mistake.
 */

import { describe, it, expect, beforeAll, afterAll, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(async () => ({ id: 99 })),
    ApiError: class ApiError extends Error {},
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(),
    promptDialog: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { apiFetch } from '../../src/utils/api.js';
import { promptDialog } from '../../src/utils/dialogs.js';

const tz = process.env.TZ;

beforeAll(() => {
    process.env.TZ = 'America/Los_Angeles';
});

afterAll(() => {
    process.env.TZ = tz;
});

beforeEach(() => {
    vi.useFakeTimers();
    // 20:30 on the 27th in Los Angeles; already the 28th in UTC
    vi.setSystemTime(new Date('2026-09-28T03:30:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
});

describe('a reconciliation adjustment', () => {
    it('is dated the user\'s today', async () => {
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = { loadTransactions: vi.fn(async () => {}) };
        mod.reconcileSession = { session: { accountId: 7, statementBalance: 990 } };
        mod.formatCurrency = (v) => String(v);
        mod.renderReconcileBar = vi.fn();

        await mod.createReconciliationAdjustment('debit', 10);

        const [, request] = apiFetch.mock.calls.find(([url]) => url === '/apps/budget/api/transactions');
        expect(request.body.date).toBe('2026-09-27');
    });
});

describe('an interest rate change', () => {
    it('defaults its effective date to the user\'s today', async () => {
        const mod = Object.create(AccountsModule.prototype);
        promptDialog.mockResolvedValueOnce('4.5').mockResolvedValueOnce(null);

        await mod.showAddRateChangeModal(7);

        expect(promptDialog.mock.calls[1][1].defaultValue).toBe('2026-09-27');
    });
});
