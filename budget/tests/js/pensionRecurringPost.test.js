/**
 * Post now on a scheduled pension contribution, and the pension forms.
 *
 * Post now had no confirm and no guard, so a double click posted twice and
 * moved the schedule on two cycles. It now asks first, names the occurrence
 * the row showed (the server refuses one already posted), ignores clicks
 * while a post is in flight and offers an undo. The schedule, contribution
 * and withdrawal forms likewise ignore a second submit while one is saving.
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

import PensionsModule from '../../src/modules/pensions/PensionsModule.js';
import { showError, showUndoNotification } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const schedule = { id: 7, amount: 200, frequency: 'monthly', nextDueDate: '2026-10-01', autoPostEnabled: false, isActive: true, sourceAccountId: null };

function makeModule() {
    const mod = Object.create(PensionsModule.prototype);
    mod.app = {
        settings: {},
        accounts: [],
        charts: {},
        pensions: [{ id: 1, name: 'Work', currency: 'GBP', isDefinedContribution: true }],
        currentPension: { id: 1, name: 'Work', currency: 'GBP', isDefinedContribution: true },
    };
    mod.recurringSchedules = [schedule];
    mod.loadPensions = vi.fn(async () => {});
    mod.renderPensions = vi.fn();
    mod.showPensionDetails = vi.fn(async () => {});
    mod.loadPensionRecurring = vi.fn(async () => {});
    mod.closeRecurringModal = vi.fn();
    mod.closeContributionModal = vi.fn();
    mod.closeWithdrawalModal = vi.fn();
    return mod;
}

const ok = (body = {}) => ({ ok: true, json: async () => body });

/** A fetch that stays pending until release() is called */
function pendingFetch() {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const fetch = vi.fn(async () => { await gate; return ok({ id: 7 }); });
    return { fetch, release: () => release() };
}

beforeEach(() => {
    document.body.innerHTML = `
        <form id="pension-recurring-form">
            <input name="amount" value="200"><input name="frequency" value="monthly">
            <input name="nextDueDate" value="2026-11-01"><input name="sourceAccountId" value="">
            <input type="checkbox" name="autoPostEnabled"><input name="note" value="">
            <input name="pensionId" value="1"><button type="submit">Save</button>
        </form>
        <form id="pension-contribution-form">
            <input name="amount" value="50"><input name="date" value="2026-10-02">
            <input name="note" value=""><input name="sourceAccountId" value="">
            <input name="pensionId" value="1"><button type="submit">Save</button>
        </form>
        <form id="pension-withdrawal-form">
            <input name="amount" value="50"><input name="date" value="2026-10-02">
            <input name="note" value=""><input name="destAccountId" value="">
            <input name="pensionId" value="1"><button type="submit">Save</button>
        </form>`;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('postRecurringNow', () => {
    it('asks first and names the occurrence the row showed', async () => {
        const mod = makeModule();
        global.fetch = vi.fn(async () => ok({ id: 7 }));

        await mod.postRecurringNow(7);

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/apps/budget/api/pensions/recurring/7/post');
        expect(JSON.parse(init.body).expectedDate).toBe('2026-10-01');
    });

    it('posts nothing when the confirm is declined', async () => {
        const mod = makeModule();
        global.fetch = vi.fn(async () => ok({ id: 7 }));
        confirmDialog.mockResolvedValueOnce(false);

        await mod.postRecurringNow(7);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('ignores a second click while the first post is in flight', async () => {
        const mod = makeModule();
        const { fetch, release } = pendingFetch();
        global.fetch = fetch;

        const first = mod.postRecurringNow(7);
        await Promise.resolve();
        await Promise.resolve();
        const second = mod.postRecurringNow(7);
        release();
        await Promise.all([first, second]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('offers an undo that reverts through the server', async () => {
        const mod = makeModule();
        global.fetch = vi.fn(async () => ok({ id: 7 }));

        await mod.postRecurringNow(7);
        const [, undo] = showUndoNotification.mock.calls[0];
        global.fetch.mockClear();
        await undo();

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/pensions/recurring/7/unpost',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('shows the server\'s reason when it refuses', async () => {
        const mod = makeModule();
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'This contribution was already posted. Reload the page to see the next one.' }),
        }));

        await mod.postRecurringNow(7);

        expect(showError).toHaveBeenCalledWith('This contribution was already posted. Reload the page to see the next one.');
    });
});

describe('the pension forms ignore a second submit while saving', () => {
    it.each([
        ['saveRecurring', '/apps/budget/api/pensions/1/recurring'],
        ['saveContribution', '/apps/budget/api/pensions/1/contributions'],
        ['saveWithdrawal', '/apps/budget/api/pensions/1/withdrawals'],
    ])('%s', async (method, url) => {
        const mod = makeModule();
        const { fetch, release } = pendingFetch();
        global.fetch = fetch;

        const first = mod[method]();
        const second = mod[method]();
        release();
        await Promise.all([first, second]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe(url);
    });
});
