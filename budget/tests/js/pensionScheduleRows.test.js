/**
 * The scheduled contributions list on a pension.
 *
 * A manual schedule whose date had passed looked exactly like one still to
 * come, so a missed contribution went unnoticed. A schedule on a pension
 * changed to defined benefit or state was hidden altogether while it went
 * on posting. Rows now say when a contribution is due or overdue, which
 * account it comes from and when auto-post is off or the schedule paused,
 * and the list stays visible wherever a schedule exists.
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
    showUndoNotification: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
}));

vi.mock('../../src/utils/chart.js', () => ({ default: vi.fn() }));

import PensionsModule, { scheduleRowState } from '../../src/modules/pensions/PensionsModule.js';

const base = { id: 7, amount: 200, frequency: 'monthly', nextDueDate: '2026-10-15', autoPostEnabled: false, isActive: true, sourceAccountId: null };

describe('scheduleRowState', () => {
    it('is overdue once the date has passed', () => {
        expect(scheduleRowState({ ...base, nextDueDate: '2026-08-15' }, '2026-09-28').status).toBe('overdue');
    });

    it('is due on the day', () => {
        expect(scheduleRowState({ ...base, nextDueDate: '2026-09-28' }, '2026-09-28').status).toBe('due');
    });

    it('is upcoming before then', () => {
        const state = scheduleRowState(base, '2026-09-28');
        expect(state.status).toBe('upcoming');
        expect(state.canPost).toBe(true);
    });

    it('cannot be posted while paused', () => {
        const state = scheduleRowState({ ...base, isActive: false }, '2026-09-28');
        expect(state.status).toBe('paused');
        expect(state.canPost).toBe(false);
    });
});

function makeModule() {
    const mod = Object.create(PensionsModule.prototype);
    mod.app = {
        settings: {},
        accounts: [{ id: 3, name: 'Current account' }],
        charts: {},
        pensions: [],
        currentPension: { id: 1, name: 'Work', currency: 'GBP', isDefinedContribution: true },
    };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="pension-recurring-section"><button id="add-recurring-btn"></button>
        <div id="pension-recurring-list"></div></div>`;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('renderPensionRecurring', () => {
    it('marks an overdue manual schedule and names its account', () => {
        const mod = makeModule();
        mod.renderPensionRecurring([{ ...base, nextDueDate: '2020-01-15', sourceAccountId: 3 }]);

        const row = document.querySelector('.recurring-item');
        expect(row.classList.contains('overdue')).toBe(true);
        expect(row.textContent).toContain('Overdue');
        expect(row.textContent).toContain('Current account');
    });

    it('offers no Post now on a paused schedule', () => {
        const mod = makeModule();
        mod.renderPensionRecurring([{ ...base, isActive: false }]);

        expect(document.querySelector('.recurring-post-btn')).toBeNull();
        expect(document.querySelector('.recurring-item').textContent).toContain('Paused');
    });
});

describe('a pension with no pot', () => {
    it('still lists schedules it has, without offering new ones', async () => {
        const mod = makeModule();
        mod.app.currentPension = { id: 2, name: 'Final salary', isDefinedContribution: false };
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => [{ ...base }] }));

        await mod.loadPensionRecurring(2, false);

        expect(document.getElementById('pension-recurring-section').style.display).toBe('');
        expect(document.getElementById('add-recurring-btn').style.display).toBe('none');
        expect(document.querySelectorAll('.recurring-item')).toHaveLength(1);
        delete global.fetch;
        delete global.OC;
    });

    it('hides the section when it has none', async () => {
        const mod = makeModule();
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => [] }));

        await mod.loadPensionRecurring(2, false);

        expect(document.getElementById('pension-recurring-section').style.display).toBe('none');
        delete global.fetch;
        delete global.OC;
    });
});
