/**
 * In-app confirm/prompt/alert dialogs (#381).
 *
 * The app used to gate every destructive action behind window.confirm(). That
 * is a silent single point of failure: after a few modal dialogs in a row,
 * Chrome and Firefox offer "Prevent this page from creating additional
 * dialogs", and once the user ticks it EVERY later confirm() returns false
 * immediately with no dialog drawn. The delete simply never happens — no
 * dialog, no error, no toast — which is exactly what #381 reported after
 * deleting a couple of junk accounts from a bad import.
 *
 * The fix is to stop asking the browser. These dialogs are built from our own
 * DOM, so suppression cannot reach them, and the promise they return is the
 * only thing a caller waits on.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count, params = {}) =>
        String(count === 1 ? singular : plural).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import { confirmDialog, promptDialog, alertDialog } from '../../src/utils/dialogs.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

/** The dialog renders asynchronously-ish; give the microtask queue a turn. */
const tick = () => new Promise(resolve => setTimeout(resolve, 0));

const dialogEl = () => document.querySelector('.budget-dialog');
const confirmBtn = () => document.querySelector('.budget-dialog-confirm');
const cancelBtn = () => document.querySelector('.budget-dialog-cancel');

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('confirmDialog', () => {
    it('resolves true when the confirm button is pressed', async () => {
        const answer = confirmDialog('Delete this account?');
        await tick();

        expect(dialogEl()).not.toBeNull();
        expect(document.body.textContent).toContain('Delete this account?');

        confirmBtn().click();
        expect(await answer).toBe(true);
    });

    it('resolves false when the cancel button is pressed', async () => {
        const answer = confirmDialog('Delete this account?');
        await tick();

        cancelBtn().click();
        expect(await answer).toBe(false);
    });

    it('never calls window.confirm, so browser dialog suppression cannot reach it', async () => {
        // A browser that has suppressed dialogs returns false from confirm()
        // without drawing anything. If the helper delegated to it, the answer
        // below would be false and the destructive action would silently vanish.
        const native = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const answer = confirmDialog('Delete this account?');
        await tick();
        confirmBtn().click();

        expect(await answer).toBe(true);
        expect(native).not.toHaveBeenCalled();
    });

    it('removes the dialog from the DOM once answered', async () => {
        const answer = confirmDialog('Delete this account?');
        await tick();
        confirmBtn().click();
        await answer;
        await tick();

        expect(dialogEl()).toBeNull();
    });

    it('cancels on Escape and confirms on Enter', async () => {
        const cancelled = confirmDialog('Delete this account?');
        await tick();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(await cancelled).toBe(false);

        const confirmed = confirmDialog('Delete this account?');
        await tick();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(await confirmed).toBe(true);
    });

    it('cancels when the backdrop is clicked', async () => {
        const answer = confirmDialog('Delete this account?');
        await tick();

        dialogEl().click();
        expect(await answer).toBe(false);
    });

    it('marks a destructive action so its confirm button reads as dangerous', async () => {
        const answer = confirmDialog('Delete this account?', { destructive: true });
        await tick();

        expect(confirmBtn().classList.contains('budget-dialog-btn--destructive')).toBe(true);

        cancelBtn().click();
        await answer;
    });

    it('honours custom titles and button labels', async () => {
        const answer = confirmDialog('Really?', {
            title: 'Delete account',
            confirmLabel: 'Delete it',
            cancelLabel: 'Keep it',
        });
        await tick();

        expect(document.querySelector('.budget-dialog-title').textContent).toBe('Delete account');
        expect(confirmBtn().textContent).toBe('Delete it');
        expect(cancelBtn().textContent).toBe('Keep it');

        cancelBtn().click();
        await answer;
    });

    it('renders newlines in the message as separate lines rather than literal \\n', async () => {
        // Several call sites compose multi-paragraph warnings with '\n\n',
        // which native confirm() rendered as line breaks.
        const answer = confirmDialog('First line.\n\nSecond line.');
        await tick();

        const body = document.querySelector('.budget-dialog-message');
        expect(body.textContent).not.toContain('\\n');
        expect(body.querySelectorAll('p').length).toBe(2);

        cancelBtn().click();
        await answer;
    });

    /**
     * t()/n() default to escape: true, because most translated strings end up
     * in innerHTML. A dialog is textContent, so a category called
     * "Food & Dining" arrived as "Food &amp; Dining" — visibly wrong, and true
     * of window.confirm() before this too.
     */
    it('shows an interpolated name as typed, not HTML-escaped', async () => {
        const answer = confirmDialog('Delete "Food &amp; Dining"? O&#39;Brien &lt;x&gt; said &quot;no&quot;.');
        await tick();

        const body = document.querySelector('.budget-dialog-message').textContent;
        expect(body).toContain('Food & Dining');
        expect(body).toContain("O'Brien <x> said \"no\".");
        expect(body).not.toContain('&amp;');

        cancelBtn().click();
        await answer;
    });

    it('decodes &amp; last, so an escaped entity survives', async () => {
        const answer = confirmDialog('Literally &amp;lt; please');
        await tick();

        expect(document.querySelector('.budget-dialog-message').textContent).toContain('&lt;');

        cancelBtn().click();
        await answer;
    });

    it('escapes markup in the message instead of rendering it', async () => {
        const answer = confirmDialog('Delete "<img src=x onerror=alert(1)>"?');
        await tick();

        const body = document.querySelector('.budget-dialog-message');
        expect(body.querySelector('img')).toBeNull();
        expect(body.textContent).toContain('<img src=x onerror=alert(1)>');

        cancelBtn().click();
        await answer;
    });

    it('queues a second dialog rather than letting two share the screen', async () => {
        const first = confirmDialog('First?');
        const second = confirmDialog('Second?');
        await tick();

        expect(document.querySelectorAll('.budget-dialog').length).toBe(1);
        expect(document.body.textContent).toContain('First?');

        confirmBtn().click();
        expect(await first).toBe(true);
        await tick();

        expect(document.body.textContent).toContain('Second?');
        cancelBtn().click();
        expect(await second).toBe(false);
    });

    it('opens a destructive dialog on Cancel, so a stray Enter does not delete', async () => {
        const answer = confirmDialog('Delete this account?', { destructive: true });
        await tick();

        expect(document.activeElement).toBe(cancelBtn());
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        expect(await answer).toBe(false);
    });

    it('opens a non-destructive dialog on Confirm', async () => {
        const answer = confirmDialog('Resume it?');
        await tick();

        expect(document.activeElement).toBe(confirmBtn());

        cancelBtn().click();
        await answer;
    });

    it('moves focus into the dialog so keyboard users are not stranded', async () => {
        const answer = confirmDialog('Delete this account?');
        await tick();

        expect(dialogEl().contains(document.activeElement)).toBe(true);

        cancelBtn().click();
        await answer;
    });
});

describe('promptDialog', () => {
    it('resolves the typed value', async () => {
        const answer = promptDialog('Enter tag name:');
        await tick();

        const input = document.querySelector('.budget-dialog-input');
        input.value = 'Groceries';
        confirmBtn().click();

        expect(await answer).toBe('Groceries');
    });

    it('resolves null when cancelled, matching window.prompt', async () => {
        const answer = promptDialog('Enter tag name:');
        await tick();

        cancelBtn().click();
        expect(await answer).toBeNull();
    });

    it('seeds the input with a default value', async () => {
        const answer = promptDialog('Effective date', { defaultValue: '2026-01-07' });
        await tick();

        expect(document.querySelector('.budget-dialog-input').value).toBe('2026-01-07');

        cancelBtn().click();
        await answer;
    });

    it('never calls window.prompt', async () => {
        const native = vi.spyOn(window, 'prompt').mockReturnValue(null);

        const answer = promptDialog('Enter tag name:');
        await tick();
        document.querySelector('.budget-dialog-input').value = 'Rent';
        confirmBtn().click();

        expect(await answer).toBe('Rent');
        expect(native).not.toHaveBeenCalled();
    });
});

describe('alertDialog', () => {
    it('resolves once acknowledged and offers no cancel button', async () => {
        const answer = alertDialog('Cannot remove the last condition.');
        await tick();

        expect(cancelBtn()).toBeNull();

        confirmBtn().click();
        await expect(answer).resolves.toBeUndefined();
    });

    it('never calls window.alert', async () => {
        const native = vi.spyOn(window, 'alert').mockImplementation(() => {});

        const answer = alertDialog('Cannot remove the last condition.');
        await tick();
        confirmBtn().click();
        await answer;

        expect(native).not.toHaveBeenCalled();
    });
});

/**
 * The reported symptom, end to end (#381): the account delete button appeared
 * dead. Nothing disables it — the click was reaching deleteAccount(), which
 * bailed on a confirm() the browser had stopped drawing.
 */
describe('account deletion survives a browser that suppresses native dialogs', () => {
    it('deletes once the in-app dialog is confirmed, with window.confirm stubbed false', async () => {
        // Exactly what a suppressed browser does: no dialog, instant false.
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', deletedTransactions: 0 }),
        }));
        global.fetch = fetchMock;

        const mod = Object.create(AccountsModule.prototype);
        mod.app = { loadDashboard: vi.fn(async () => {}) };
        mod.loadAccounts = vi.fn(async () => {});
        mod.loadInitialData = vi.fn(async () => {});

        const done = mod.deleteAccount(7);
        await tick();

        confirmBtn().click();
        await done;

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toContain('/apps/budget/api/accounts/7');
        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');

        delete global.OC;
        delete global.fetch;
    });

    it('still respects a genuine cancel', async () => {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const fetchMock = vi.fn();
        global.fetch = fetchMock;

        const mod = Object.create(AccountsModule.prototype);
        const done = mod.deleteAccount(7);
        await tick();

        cancelBtn().click();
        await done;

        expect(fetchMock).not.toHaveBeenCalled();

        delete global.OC;
        delete global.fetch;
    });
});
