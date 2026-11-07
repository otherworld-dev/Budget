/**
 * A statement with nothing left to tick (#473).
 *
 * Finish needs a zero difference, and the adjustment that closes one was
 * only offered once something had been ticked, because before that the
 * difference is the whole account history. When every row the statement
 * covers is already reconciled, though, nothing can be ticked, so a
 * difference left only Leave and Cancel session. The adjustment is offered
 * then too.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class ApiError extends Error {},
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function render(overrides) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.formatCurrency = (v) => Number(v).toFixed(2);
    mod.formatDate = (d) => d;
    mod.reconcileSession = {
        session: { accountId: 7, statementBalance: 130, startingBalance: 150, statementDate: '2026-04-30' },
        tickedIds: [],
        tickedSum: 0,
        tickedCount: 0,
        untickedCount: 0,
        difference: -20,
        isBalanced: false,
        ...overrides,
    };
    mod.renderReconcileBar();
    return mod;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the reconciliation bar', () => {
    it('offers an adjustment when nothing is left to tick and the difference is not zero', () => {
        render({});

        expect(document.getElementById('finish-reconcile-btn').disabled).toBe(true);
        const adjust = document.getElementById('adjust-reconcile-btn');
        expect(adjust).not.toBeNull();
        expect(adjust.textContent).toContain('20.00');
    });

    it('still holds the adjustment back before anything is ticked while rows are left to tick', () => {
        render({ untickedCount: 12 });

        expect(document.getElementById('adjust-reconcile-btn')).toBeNull();
        expect(document.getElementById('tick-all-reconcile-btn')).not.toBeNull();
    });

    it('offers no adjustment once balanced', () => {
        render({ difference: 0, isBalanced: true });

        expect(document.getElementById('finish-reconcile-btn').disabled).toBe(false);
        expect(document.getElementById('adjust-reconcile-btn')).toBeNull();
    });
});
