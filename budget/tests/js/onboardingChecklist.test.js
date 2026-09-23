/**
 * The dashboard's first-run checklist and the sample-data banner.
 *
 * The server says whether the checklist shows and which steps are done; the
 * module renders that, steps the empty tiles back while it shows, offers
 * sample data only to an empty budget, and clears sample data only after the
 * in-app confirm.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    whatsNewDialog: vi.fn(),
}));

import OnboardingModule, { checklistSteps } from '../../src/modules/onboarding/OnboardingModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { showSuccess } from '../../src/utils/notifications.js';

const NONE_DONE = { currency: false, categories: false, account: false, transactions: false, budget: false };

function makeApp() {
    return {
        showView: vi.fn(),
        showAccountModal: vi.fn(),
        loadInitialData: vi.fn(async () => {}),
        categoriesModule: { createDefaultCategories: vi.fn(async () => {}) },
    };
}

function respond(body, ok = true, status = 200) {
    return Promise.resolve({ ok, status, json: () => Promise.resolve(body) });
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="sample-data-banner" hidden></div>
        <li id="bank-sync-nav" style="display: none;"></li>
        <div id="dashboard-view">
            <div id="onboarding-checklist" hidden></div>
            <div class="dashboard-grid"></div>
        </div>`;
    globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    vi.clearAllMocks();
});

afterEach(() => {
    delete globalThis.fetch;
});

describe('checklistSteps', () => {
    it('lists the five steps in order with their done state', () => {
        const steps = checklistSteps({ ...NONE_DONE, currency: true, account: true });

        expect(steps.map(s => s.id)).toEqual(['currency', 'categories', 'account', 'transactions', 'budget']);
        expect(steps.map(s => s.done)).toEqual([true, false, true, false, false]);
    });

    it('offers bank sync only when it is enabled', () => {
        const off = checklistSteps(NONE_DONE).find(s => s.id === 'transactions');
        const on = checklistSteps(NONE_DONE, { bankSyncEnabled: true }).find(s => s.id === 'transactions');

        expect(off.actions.map(a => a.action)).toEqual(['import']);
        expect(on.actions.map(a => a.action)).toEqual(['import', 'bank-sync']);
    });

    it('links only guides that exist', () => {
        for (const step of checklistSteps(NONE_DONE)) {
            expect(step.guide).toMatch(/^https:\/\/budget\.otherworld\.dev\/docs\/[a-z-]+\.html$/);
        }
    });
});

describe('OnboardingModule', () => {
    it('shows the checklist and steps the tiles back', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: true, sampleData: false, canLoadSampleData: true, steps: NONE_DONE }));
        const mod = new OnboardingModule(makeApp());

        await mod.load();

        const container = document.getElementById('onboarding-checklist');
        expect(container.hidden).toBe(false);
        expect(container.querySelectorAll('.onboarding-step')).toHaveLength(5);
        expect(container.textContent).toContain('0 of 5 done');
        expect(document.getElementById('dashboard-view').classList.contains('onboarding-active')).toBe(true);
        expect(container.querySelector('[data-action="sample"]')).not.toBeNull();
    });

    it('stays out of the way for anyone the server does not show it to', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: false, sampleData: false, canLoadSampleData: false, steps: null }));
        const mod = new OnboardingModule(makeApp());

        await mod.load();

        expect(document.getElementById('onboarding-checklist').hidden).toBe(true);
        expect(document.getElementById('dashboard-view').classList.contains('onboarding-active')).toBe(false);
        expect(document.getElementById('sample-data-banner').hidden).toBe(true);
    });

    it('leaves a failed load hidden', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        const mod = new OnboardingModule(makeApp());

        await mod.load();

        expect(document.getElementById('onboarding-checklist').hidden).toBe(true);
    });

    it('offers no sample data once the user has their own', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: true, sampleData: false, canLoadSampleData: false, steps: { ...NONE_DONE, account: true } }));
        const mod = new OnboardingModule(makeApp());

        await mod.load();

        const container = document.getElementById('onboarding-checklist');
        expect(container.querySelector('[data-action="sample"]')).toBeNull();
        // A done step has no buttons left
        expect(container.querySelector('[data-step="account"] button')).toBeNull();
    });

    it('brings the tiles back on request', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: true, sampleData: false, canLoadSampleData: true, steps: NONE_DONE }));
        const mod = new OnboardingModule(makeApp());
        await mod.load();

        document.querySelector('[data-action="tiles"]').click();

        expect(document.getElementById('dashboard-view').classList.contains('onboarding-tiles-shown')).toBe(true);
    });

    it('creates the default categories through the Categories page logic', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: true, sampleData: false, canLoadSampleData: true, steps: NONE_DONE }));
        const app = makeApp();
        const mod = new OnboardingModule(app);
        await mod.load();

        await mod.handleAction('categories');

        expect(app.categoriesModule.createDefaultCategories).toHaveBeenCalledTimes(1);
    });

    it('shows the banner while sample data is loaded, and clears it after the confirm', async () => {
        globalThis.fetch = vi.fn(() => respond({ show: false, sampleData: true, canLoadSampleData: false, steps: NONE_DONE }));
        const app = makeApp();
        const mod = new OnboardingModule(app);
        await mod.load();

        const banner = document.getElementById('sample-data-banner');
        expect(banner.hidden).toBe(false);
        expect(banner.textContent).toContain('You\'re looking at sample data');

        globalThis.fetch = vi.fn(() => respond({ success: true }));
        await mod.clearSampleData();

        expect(confirmDialog).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ destructive: true }));
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/apps/budget/api/onboarding/sample-data');
        expect(init.method).toBe('DELETE');
        expect(JSON.parse(init.body)).toEqual({ confirmed: true });
        expect(showSuccess).toHaveBeenCalled();
        expect(app.loadInitialData).toHaveBeenCalled();
        expect(app.showView).toHaveBeenCalledWith('dashboard');
    });

    it('does nothing when the clear is not confirmed', async () => {
        confirmDialog.mockResolvedValueOnce(false);
        globalThis.fetch = vi.fn();
        const mod = new OnboardingModule(makeApp());

        await mod.clearSampleData();

        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('loads sample data and lands back on the dashboard', async () => {
        globalThis.fetch = vi.fn(() => respond({ success: true }, true, 201));
        const app = makeApp();
        const mod = new OnboardingModule(app);

        await mod.loadSampleData();

        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/apps/budget/api/onboarding/sample-data');
        expect(init.method).toBe('POST');
        expect(app.showView).toHaveBeenCalledWith('dashboard');
    });
});
