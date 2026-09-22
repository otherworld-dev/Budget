/**
 * "What's new" popup: which release notes a user is shown after an update.
 *
 * The notes are hand-written per release in src/whatsnew.json and shown once,
 * the first time the app loads on a version the user has not seen yet.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

const tick = () => new Promise(resolve => setTimeout(resolve, 0));

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import {
    compareVersions,
    entriesToShow,
    latestEntries,
    installedVersion,
    MAX_ENTRIES,
} from '../../src/utils/whatsNew.js';
import { whatsNewDialog } from '../../src/utils/dialogs.js';
import WHATS_NEW from '../../src/whatsnew.json';

const entry = (version, title = `Release ${version}`) => ({ version, title, items: ['Something changed'] });

describe('compareVersions', () => {
    it('orders by each numeric part, not as text', () => {
        expect(compareVersions('2.10.0', '2.9.0')).toBeGreaterThan(0);
        expect(compareVersions('2.9.0', '2.10.0')).toBeLessThan(0);
        expect(compareVersions('2.53.1', '2.53.0')).toBeGreaterThan(0);
        expect(compareVersions('3.0.0', '2.99.99')).toBeGreaterThan(0);
    });

    it('treats a missing part as zero', () => {
        expect(compareVersions('2.54', '2.54.0')).toBe(0);
        expect(compareVersions('2.54.0', '2.54.0')).toBe(0);
    });
});

describe('entriesToShow', () => {
    const entries = [entry('2.52.0'), entry('2.53.0'), entry('2.54.0'), entry('2.55.0')];

    it('shows the notes for the version just installed', () => {
        const shown = entriesToShow(entries, { currentVersion: '2.54.0', lastSeen: '2.53.0', isNewUser: false });
        expect(shown.map(e => e.version)).toEqual(['2.54.0']);
    });

    it('shows every release the user skipped, newest first', () => {
        const shown = entriesToShow(entries, { currentVersion: '2.55.0', lastSeen: '2.52.0', isNewUser: false });
        expect(shown.map(e => e.version)).toEqual(['2.55.0', '2.54.0', '2.53.0']);
    });

    it('never shows notes for a version newer than the one installed', () => {
        const shown = entriesToShow(entries, { currentVersion: '2.54.0', lastSeen: '2.53.0', isNewUser: false });
        expect(shown.map(e => e.version)).not.toContain('2.55.0');
    });

    it('shows nothing once the installed version has been seen', () => {
        expect(entriesToShow(entries, { currentVersion: '2.54.0', lastSeen: '2.54.0', isNewUser: false })).toEqual([]);
    });

    it('shows nothing for a release that has no notes', () => {
        // A patch release nobody wrote an entry for: 2.54.0 was already seen.
        expect(entriesToShow(entries, { currentVersion: '2.54.1', lastSeen: '2.54.0', isNewUser: false })).toEqual([]);
    });

    it('shows a patch release its own notes when it has them', () => {
        const withPatch = [...entries, entry('2.54.1')];
        const shown = entriesToShow(withPatch, { currentVersion: '2.54.1', lastSeen: '2.54.0', isNewUser: false });
        expect(shown.map(e => e.version)).toEqual(['2.54.1']);
    });

    it('shows a brand-new user nothing', () => {
        expect(entriesToShow(entries, { currentVersion: '2.55.0', lastSeen: '', isNewUser: true })).toEqual([]);
    });

    it('shows an existing user who has never seen the popup the latest notes', () => {
        // The release that introduces the popup: nobody has a last-seen version yet.
        const shown = entriesToShow(entries, { currentVersion: '2.54.0', lastSeen: '', isNewUser: false });
        expect(shown.map(e => e.version)[0]).toBe('2.54.0');
    });

    it('caps a long gap at a few releases', () => {
        const many = Array.from({ length: 10 }, (_, i) => entry(`2.${40 + i}.0`));
        const shown = entriesToShow(many, { currentVersion: '2.49.0', lastSeen: '2.30.0', isNewUser: false });
        expect(shown).toHaveLength(MAX_ENTRIES);
        expect(shown[0].version).toBe('2.49.0');
    });

    it('shows nothing when the installed version is unknown', () => {
        expect(entriesToShow(entries, { currentVersion: '', lastSeen: '2.53.0', isNewUser: false })).toEqual([]);
    });

    it('shows nothing after a downgrade', () => {
        expect(entriesToShow(entries, { currentVersion: '2.53.0', lastSeen: '2.55.0', isNewUser: false })).toEqual([]);
    });
});

describe('latestEntries', () => {
    it('lists the newest notes up to the installed version, for the Help page', () => {
        const entries = [entry('2.52.0'), entry('2.54.0'), entry('2.53.0'), entry('2.55.0')];
        expect(latestEntries(entries, '2.54.0').map(e => e.version)).toEqual(['2.54.0', '2.53.0', '2.52.0']);
    });

    it('is empty when there are no notes yet', () => {
        expect(latestEntries([], '2.54.0')).toEqual([]);
    });
});

describe('installedVersion', () => {
    it('reads the version the page was rendered with', () => {
        document.body.innerHTML = '<div id="app-content" data-app-version="2.54.0"></div>';
        expect(installedVersion()).toBe('2.54.0');
    });

    it('is empty when the page does not carry one', () => {
        document.body.innerHTML = '<div id="app-content"></div>';
        expect(installedVersion()).toBe('');
    });
});

describe('src/whatsnew.json', () => {
    // Hand-edited at release time, so check its shape here rather than in the browser.
    it('is a list of well-formed entries', () => {
        expect(Array.isArray(WHATS_NEW)).toBe(true);
        for (const e of WHATS_NEW) {
            expect(e.version).toMatch(/^\d+\.\d+\.\d+$/);
            expect(typeof e.title).toBe('string');
            expect(e.title.trim()).not.toBe('');
            expect(Array.isArray(e.items)).toBe(true);
            expect(e.items.length).toBeGreaterThan(0);
            for (const item of e.items) {
                expect(typeof item).toBe('string');
                expect(item.trim()).not.toBe('');
            }
            if (e.link !== undefined) {
                expect(e.link).toMatch(/^https:\/\//);
            }
        }
    });

    it('has one entry per version', () => {
        const versions = WHATS_NEW.map(e => e.version);
        expect(new Set(versions).size).toBe(versions.length);
    });
});

describe('whatsNewDialog', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('lists each release with its title, items and link', async () => {
        const done = whatsNewDialog([
            { version: '2.55.0', title: 'Newer', items: ['First <b>thing</b>', 'Second'], link: 'https://budget.otherworld.dev/docs/bills.html' },
            { version: '2.54.0', title: 'Older', items: ['Third'] },
        ]);
        await tick();

        const headings = [...document.querySelectorAll('.whats-new-heading')].map(h => h.textContent);
        expect(headings[0]).toContain('Newer');
        expect(headings[0]).toContain('2.55.0');
        expect(headings[1]).toContain('Older');

        const items = [...document.querySelectorAll('.whats-new-items li')].map(li => li.textContent);
        // Text only: markup in the notes is shown, never rendered.
        expect(items).toEqual(['First <b>thing</b>', 'Second', 'Third']);
        expect(document.querySelector('.whats-new-items b')).toBeNull();

        const links = document.querySelectorAll('.whats-new-link');
        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe('https://budget.otherworld.dev/docs/bills.html');
        expect(links[0].getAttribute('target')).toBe('_blank');
        expect(links[0].getAttribute('rel')).toContain('noopener');

        document.querySelector('.budget-dialog-confirm').click();
        await done;
        expect(document.querySelector('.budget-dialog')).toBeNull();
    });

    it('drops a link that is not https', async () => {
        const done = whatsNewDialog([{ version: '2.55.0', title: 'T', items: ['x'], link: 'javascript:alert(1)' }]);
        await tick();
        expect(document.querySelector('.whats-new-link')).toBeNull();
        document.querySelector('.budget-dialog-confirm').click();
        await done;
    });

    it('closes on Escape', async () => {
        const done = whatsNewDialog([entry('2.55.0')]);
        await tick();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await done;
        expect(document.querySelector('.budget-dialog')).toBeNull();
    });

    it('lets Enter follow a focused link instead of closing', async () => {
        const done = whatsNewDialog([{ version: '2.55.0', title: 'T', items: ['x'], link: 'https://budget.otherworld.dev/' }]);
        await tick();
        const link = document.querySelector('.whats-new-link');
        link.focus();
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        link.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
        expect(document.querySelector('.budget-dialog')).not.toBeNull();
        document.querySelector('.budget-dialog-confirm').click();
        await done;
    });
});

describe('HelpModule.showWhatsNewIfUpdated', () => {
    let HelpModule;
    let fetchMock;

    beforeEach(async () => {
        document.body.innerHTML = '<div id="app-content" data-app-version="99.0.0"></div>';
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        fetchMock = vi.fn().mockResolvedValue({ ok: true });
        global.fetch = fetchMock;
        HelpModule = (await import('../../src/modules/help/HelpModule.js')).default;
    });

    const appWith = (overrides) => ({
        settingsLoaded: true,
        settings: {},
        accounts: [{ id: 1 }],
        ...overrides,
    });

    it('records the installed version for a new user without showing anything', async () => {
        const app = appWith({ accounts: [] });
        await new HelpModule(app).showWhatsNewIfUpdated();

        expect(document.querySelector('.budget-dialog')).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/apps/budget/api/settings/whats_new_seen');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body)).toEqual({ value: '99.0.0' });
        expect(app.settings.whats_new_seen).toBe('99.0.0');
    });

    it('does nothing once the installed version has been seen', async () => {
        await new HelpModule(appWith({ settings: { whats_new_seen: '99.0.0' } })).showWhatsNewIfUpdated();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does nothing when the settings failed to load', async () => {
        await new HelpModule(appWith({ settingsLoaded: false })).showWhatsNewIfUpdated();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('records the version only after the popup is dismissed', async () => {
        if (WHATS_NEW.length === 0) return; // nothing to pop up until the first entry is written
        const done = new HelpModule(appWith({})).showWhatsNewIfUpdated();
        await tick();
        expect(document.querySelector('.budget-dialog')).not.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
        document.querySelector('.budget-dialog-confirm').click();
        await done;
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
