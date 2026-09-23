/**
 * Client-side router (src/core/Router.js).
 *
 * Covers switching views, calling each view's loader, keeping the left nav
 * highlight in step, browser back/forward through history entries, the
 * dashboard card links, and the mobile navigation drawer.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
}));

import Router from '../../src/core/Router.js';

const VIEWS = ['dashboard', 'accounts', 'transactions', 'settings', 'import'];

function renderShell() {
    document.body.innerHTML = `
        <div id="budget-nav-toggle-wrapper">
            <button id="budget-nav-toggle"></button>
            <span id="nav-toggle-icon-open"></span>
            <span id="nav-toggle-icon-close" style="display: none;"></span>
        </div>
        <div id="nav-backdrop"></div>
        <ul id="app-navigation">
            <li class="app-navigation-entry" data-id="dashboard"><a href="#dashboard">Dashboard</a></li>
            <li class="app-navigation-entry" data-id="accounts"><a href="#accounts">Accounts</a></li>
            <li class="app-navigation-entry" data-id="transactions"><a href="#transactions">Transactions</a></li>
            <li class="app-navigation-entry" data-id="help"><a href="https://example.test/docs" target="_blank">Help</a></li>
        </ul>
        ${VIEWS.map(v => `<div id="${v}-view" class="view"></div>`).join('')}
        <div id="some-modal" class="modal" style="display: flex;"></div>
        <a class="card-link" href="#transactions"><span class="inner">View all</span></a>
        <a class="card-link" href="https://example.test/external">External</a>
    `;
}

function makeApp() {
    const app = { currentView: null };
    Object.values(Router.VIEW_LOADERS).forEach(loader => {
        app[loader] = vi.fn();
    });
    return app;
}

function activeViews() {
    return Array.from(document.querySelectorAll('.view.active')).map(v => v.id);
}

function activeNav() {
    return Array.from(document.querySelectorAll('.app-navigation-entry.active')).map(e => e.dataset.id);
}

/**
 * Click an external link and report whether the router let it through. The
 * last listener on the way up records the verdict and then cancels the
 * click, because jsdom cannot follow a link to another document.
 */
function clickExternal(el) {
    let letThrough = null;
    window.addEventListener('click', (e) => {
        letThrough = !e.defaultPrevented;
        e.preventDefault();
    }, { once: true });
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    return letThrough;
}

// setupNavigation() attaches listeners to document and window, which outlive
// a test. Record them so each test starts with no router listening.
let registered = [];

beforeEach(() => {
    renderShell();
    window.history.replaceState(null, '', '#');
    registered = [];
    for (const target of [document, window]) {
        const original = target.addEventListener.bind(target);
        vi.spyOn(target, 'addEventListener').mockImplementation((type, fn, opts) => {
            registered.push([target, type, fn, opts]);
            return original(type, fn, opts);
        });
    }
});

afterEach(() => {
    registered.forEach(([target, type, fn, opts]) => target.removeEventListener(type, fn, opts));
    vi.restoreAllMocks();
});

describe('Router.showView', () => {
    it('shows only the requested view and records it as current', () => {
        const app = makeApp();
        const router = new Router(app);
        document.getElementById('dashboard-view').classList.add('active');

        router.showView('accounts');

        expect(activeViews()).toEqual(['accounts-view']);
        expect(app.currentView).toBe('accounts');
    });

    it('calls the loader for that view, and only that one', () => {
        const app = makeApp();

        new Router(app).showView('transactions');

        expect(app.loadTransactions).toHaveBeenCalledOnce();
        expect(app.loadDashboard).not.toHaveBeenCalled();
        expect(app.loadAccounts).not.toHaveBeenCalled();
    });

    it('clears inline display styles left on hidden views', () => {
        const stale = document.getElementById('dashboard-view');
        stale.style.display = 'block';

        new Router(makeApp()).showView('accounts');

        expect(stale.style.display).toBe('');
    });

    it('highlights the matching nav entry and clears the rest', () => {
        const router = new Router(makeApp());
        router.showView('accounts');

        router.showView('transactions');

        expect(activeNav()).toEqual(['transactions']);
    });

    it('clears every nav highlight for a view with no nav entry', () => {
        const router = new Router(makeApp());
        router.showView('accounts');

        router.showView('settings');

        expect(activeNav()).toEqual([]);
    });

    it('shows a view that has markup but no loader without failing', () => {
        const app = makeApp();

        new Router(app).showView('import');

        expect(activeViews()).toEqual(['import-view']);
        expect(app.currentView).toBe('import');
    });

    it('ignores an unknown view: no loader, no current-view change, no history entry', () => {
        const app = makeApp();
        app.currentView = 'dashboard';
        const push = vi.spyOn(window.history, 'pushState');

        new Router(app).showView('no-such-view');

        expect(app.currentView).toBe('dashboard');
        Object.values(Router.VIEW_LOADERS).forEach(loader => expect(app[loader]).not.toHaveBeenCalled());
        expect(push).not.toHaveBeenCalled();
    });

    it('refreshes the help panel when the app provides the hook', () => {
        const app = makeApp();
        app._updateHelpContent = vi.fn();

        new Router(app).showView('accounts');

        expect(app._updateHelpContent).toHaveBeenCalledOnce();
    });

    it('works when the app has no help panel hook', () => {
        expect(() => new Router(makeApp()).showView('accounts')).not.toThrow();
    });
});

describe('Router history', () => {
    it('pushes a history entry and updates the hash for a new view', () => {
        const push = vi.spyOn(window.history, 'pushState');

        new Router(makeApp()).showView('accounts');

        expect(push).toHaveBeenCalledWith({ view: 'accounts' }, '', '#accounts');
        expect(window.location.hash).toBe('#accounts');
    });

    it('replaces rather than pushes when re-selecting the current view', () => {
        const router = new Router(makeApp());
        router.showView('accounts');
        const push = vi.spyOn(window.history, 'pushState');
        const replace = vi.spyOn(window.history, 'replaceState');

        router.showView('accounts');

        expect(push).not.toHaveBeenCalled();
        expect(replace).toHaveBeenCalledWith({ view: 'accounts' }, '', '#accounts');
    });

    it('treats a deep-link hash for the same view as the current view', () => {
        window.history.replaceState(null, '', '#/transactions?account=3');
        const push = vi.spyOn(window.history, 'pushState');

        new Router(makeApp()).showView('transactions');

        expect(push).not.toHaveBeenCalled();
    });

    it('does not touch history when told not to', () => {
        const push = vi.spyOn(window.history, 'pushState');
        const replace = vi.spyOn(window.history, 'replaceState');

        new Router(makeApp()).showView('accounts', { history: false });

        expect(push).not.toHaveBeenCalled();
        expect(replace).not.toHaveBeenCalled();
    });

    it.each([
        ['#accounts', 'accounts'],
        ['#/accounts', 'accounts'],
        ['#/transactions?account=3&from=2026-01-01', 'transactions'],
        ['#savings-goals', 'savings-goals'],
        ['', null],
        ['#', null],
        ['#123', null],
    ])('reads the view from hash %s', (hash, expected) => {
        window.history.replaceState(null, '', hash === '' ? window.location.pathname : hash);

        expect(new Router(makeApp()).viewFromHash()).toBe(expected);
    });

    it('re-renders the view from the history state on back/forward without a new entry', () => {
        const app = makeApp();
        const router = new Router(app);
        router.setupNavigation();
        const push = vi.spyOn(window.history, 'pushState');

        window.dispatchEvent(new PopStateEvent('popstate', { state: { view: 'transactions' } }));

        expect(activeViews()).toEqual(['transactions-view']);
        expect(app.loadTransactions).toHaveBeenCalledOnce();
        expect(push).not.toHaveBeenCalled();
    });

    it('falls back to the hash on popstate when the entry has no state', () => {
        const app = makeApp();
        new Router(app).setupNavigation();
        window.history.replaceState(null, '', '#accounts');

        window.dispatchEvent(new PopStateEvent('popstate', { state: null }));

        expect(app.currentView).toBe('accounts');
    });

    it('falls back to the dashboard on popstate with neither state nor hash', () => {
        const app = makeApp();
        new Router(app).setupNavigation();
        window.history.replaceState(null, '', window.location.pathname);

        window.dispatchEvent(new PopStateEvent('popstate', { state: null }));

        expect(app.currentView).toBe('dashboard');
    });

    it('closes an open modal when the user navigates back', () => {
        new Router(makeApp()).setupNavigation();

        window.dispatchEvent(new PopStateEvent('popstate', { state: { view: 'accounts' } }));

        expect(document.getElementById('some-modal').style.display).toBe('none');
    });
});

describe('Router navigation links', () => {
    it('navigates in-app on a nav link click and prevents the default jump', () => {
        const app = makeApp();
        new Router(app).setupNavigation();
        const link = document.querySelector('[data-id="accounts"] a');
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });

        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(app.currentView).toBe('accounts');
        expect(app.loadAccounts).toHaveBeenCalledOnce();
    });

    it('closes open modals when a nav link is clicked', () => {
        new Router(makeApp()).setupNavigation();

        document.querySelector('[data-id="accounts"] a').click();

        expect(document.getElementById('some-modal').style.display).toBe('none');
    });

    it('lets an external nav link (Help & Docs) open normally', () => {
        const app = makeApp();
        new Router(app).setupNavigation();
        const link = document.querySelector('[data-id="help"] a');

        expect(clickExternal(link)).toBe(true);
        expect(app.currentView).toBeNull();
        expect(app.loadHelpView).not.toHaveBeenCalled();
    });

    it('closes the mobile drawer after picking a view', () => {
        const router = new Router(makeApp());
        router.setupNavigation();
        router.openMobileNavigation();

        document.querySelector('[data-id="transactions"] a').click();

        expect(document.getElementById('app-navigation').classList.contains('nav-open')).toBe(false);
    });

    it('follows a dashboard card link, even when the click lands on a child element', () => {
        const app = makeApp();
        new Router(app).setupNavigation();
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });

        document.querySelector('.card-link .inner').dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(app.currentView).toBe('transactions');
    });

    it('leaves an external card link alone', () => {
        const app = makeApp();
        new Router(app).setupNavigation();

        expect(clickExternal(document.querySelector('.card-link[href^="https"]'))).toBe(true);
        expect(app.currentView).toBeNull();
    });

    it('ignores clicks outside any card link', () => {
        const app = makeApp();
        new Router(app).setupNavigation();

        document.getElementById('dashboard-view').click();

        expect(app.currentView).toBeNull();
    });
});

describe('Router mobile navigation', () => {
    const nav = () => document.getElementById('app-navigation');

    it('opens the drawer and swaps the toggle icon', () => {
        new Router(makeApp()).openMobileNavigation();

        expect(nav().classList.contains('nav-open')).toBe(true);
        expect(document.getElementById('budget-nav-toggle-wrapper').classList.contains('nav-open')).toBe(true);
        expect(document.getElementById('nav-backdrop').classList.contains('active')).toBe(true);
        expect(document.getElementById('nav-toggle-icon-open').style.display).toBe('none');
        expect(document.getElementById('nav-toggle-icon-close').style.display).toBe('');
    });

    it('closes the drawer and restores the toggle icon', () => {
        const router = new Router(makeApp());
        router.openMobileNavigation();

        router.closeMobileNavigation();

        expect(nav().classList.contains('nav-open')).toBe(false);
        expect(document.getElementById('nav-backdrop').classList.contains('active')).toBe(false);
        expect(document.getElementById('nav-toggle-icon-open').style.display).toBe('');
        expect(document.getElementById('nav-toggle-icon-close').style.display).toBe('none');
    });

    it('the toggle button opens then closes the drawer', () => {
        new Router(makeApp()).setupNavigation();
        const toggle = document.getElementById('budget-nav-toggle');

        toggle.click();
        expect(nav().classList.contains('nav-open')).toBe(true);

        toggle.click();
        expect(nav().classList.contains('nav-open')).toBe(false);
    });

    it('tapping the backdrop closes the drawer', () => {
        const router = new Router(makeApp());
        router.setupNavigation();
        router.openMobileNavigation();

        document.getElementById('nav-backdrop').click();

        expect(nav().classList.contains('nav-open')).toBe(false);
    });

    it('does nothing when the page has no toggle markup', () => {
        document.getElementById('budget-nav-toggle').remove();
        document.getElementById('nav-backdrop').remove();
        const router = new Router(makeApp());

        expect(() => router.setupNavigation()).not.toThrow();
        expect(() => router.openMobileNavigation()).not.toThrow();
        expect(() => router.closeMobileNavigation()).not.toThrow();
    });
});

describe('Router.reloadCurrentView', () => {
    it('re-runs the loader of the current view', () => {
        const app = makeApp();
        app.currentView = 'accounts';

        new Router(app).reloadCurrentView();

        expect(app.loadAccounts).toHaveBeenCalledOnce();
    });

    it('never reloads the settings view the user is editing', () => {
        const app = makeApp();
        app.currentView = 'settings';

        new Router(app).reloadCurrentView();

        expect(app.loadSettingsView).not.toHaveBeenCalled();
    });

    it('does nothing for a view without a loader', () => {
        const app = makeApp();
        app.currentView = 'import';

        expect(() => new Router(app).reloadCurrentView()).not.toThrow();
        Object.values(Router.VIEW_LOADERS).forEach(loader => expect(app[loader]).not.toHaveBeenCalled());
    });
});

describe('Router.VIEW_LOADERS', () => {
    it('maps every view to a distinct app method name', () => {
        const loaders = Object.values(Router.VIEW_LOADERS);

        expect(new Set(loaders).size).toBe(loaders.length);
        loaders.forEach(name => expect(name).toMatch(/^load[A-Z]\w+$/));
    });
});
