/**
 * A long reconciliation history opens on its newest two rows (#418), with a
 * toggle under the table for the rest. Two or fewer show as before, with no
 * toggle.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, vars) => text.replace(/{(\w+)}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

// Newest first, as the server sends them
const sessions = (count) => Array.from({ length: count }, (_, i) => ({
    statementDate: `2026-09-${String(30 - i).padStart(2, '0')}`,
    statementBalance: 10,
    reconciledCount: 3,
    completedAt: '2026-10-01',
}));

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: {} };
    mod.formatCurrency = (v) => String(v);
    mod.formatDate = (v) => String(v);
    return mod;
}

async function load(mod, count) {
    global.fetch = vi.fn(async () => ({
        ok: true, status: 200, headers: { get: () => null },
        json: async () => sessions(count),
    }));
    await mod.loadReconciliationHistory(1);
}

const visibleDates = () => Array.from(document.querySelectorAll('#recon-history-body tr'))
    .filter((row) => !row.hidden)
    .map((row) => row.cells[0].textContent);
const toggle = () => document.getElementById('recon-history-toggle');
const toggleShown = () => toggle().style.display !== 'none';

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    document.body.innerHTML = `
        <div id="recon-history-section" style="display: none;">
            <table><tbody id="recon-history-body"></tbody></table>
            <button id="recon-history-toggle" aria-expanded="false" style="display: none;"></button>
        </div>`;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe('the reconciliation history', () => {
    it('shows only the newest two of a longer history', async () => {
        await load(makeModule(), 5);

        expect(visibleDates()).toEqual(['2026-09-30', '2026-09-29']);
        expect(toggleShown()).toBe(true);
        expect(toggle().textContent).toBe('Show all (5)');
        expect(toggle().getAttribute('aria-expanded')).toBe('false');
    });

    it('shows the rest from the toggle, and folds them away again', async () => {
        await load(makeModule(), 5);

        toggle().click();
        expect(visibleDates()).toHaveLength(5);
        expect(toggle().textContent).toBe('Show fewer');
        expect(toggle().getAttribute('aria-expanded')).toBe('true');

        toggle().click();
        expect(visibleDates()).toEqual(['2026-09-30', '2026-09-29']);
        expect(toggle().textContent).toBe('Show all (5)');
    });

    it('has no toggle with two or fewer', async () => {
        await load(makeModule(), 2);

        expect(visibleDates()).toHaveLength(2);
        expect(toggleShown()).toBe(false);
    });

    it('opens folded on the next account, and one click still opens it', async () => {
        const mod = makeModule();
        await load(mod, 5);
        toggle().click();

        await load(mod, 4);
        expect(visibleDates()).toHaveLength(2);
        expect(toggle().textContent).toBe('Show all (4)');

        toggle().click();
        expect(visibleDates()).toHaveLength(4);
    });

    it('drops a toggle left over from a longer history', async () => {
        const mod = makeModule();
        await load(mod, 5);

        await load(mod, 1);
        expect(visibleDates()).toHaveLength(1);
        expect(toggleShown()).toBe(false);
    });
});
