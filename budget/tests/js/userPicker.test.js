/**
 * The share and contact forms find a Nextcloud user by typing, not from a
 * pre-filled list: with user enumeration off the server lists nobody for
 * `query=*`, but still answers a search for someone's exact name or id.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
}));

import { attachUserPicker } from '../../src/utils/userPicker.js';

const USERS = [
    { uid: 'alice', displayName: 'Alice Archer' },
    { uid: 'albert', displayName: 'Albert Ames' },
];

let input;

beforeEach(() => {
    vi.useFakeTimers();
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => USERS }));
    document.body.innerHTML = '<form><label for="who">Who</label><input id="who" type="text"></form>';
    input = document.getElementById('who');
});

afterEach(() => {
    vi.useRealTimers();
    delete global.fetch;
    delete global.OC;
});

async function type(text) {
    input.focus();
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(350);
}

const key = (k) => input.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
const list = () => document.querySelector('[role="listbox"]');
const options = () => [...document.querySelectorAll('[role="option"]')];

describe('attachUserPicker', () => {
    it('is a labelled combobox that controls its listbox', () => {
        attachUserPicker(input);

        expect(input.getAttribute('role')).toBe('combobox');
        expect(input.getAttribute('aria-expanded')).toBe('false');
        expect(input.getAttribute('aria-controls')).toBe(list().id);
        expect(list().hidden).toBe(true);
    });

    it('waits for two characters, then searches for what was typed', async () => {
        attachUserPicker(input);

        await type('a');
        expect(global.fetch).not.toHaveBeenCalled();

        await type('al');
        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/apps/budget/api/shared/users/search?query=al');
        expect(options().map(o => o.textContent)).toEqual(['Alice Archer (alice)', 'Albert Ames (albert)']);
        expect(input.getAttribute('aria-expanded')).toBe('true');
    });

    it('debounces: a burst of typing makes one request', async () => {
        attachUserPicker(input);
        input.focus();
        for (const text of ['al', 'ali', 'alic']) {
            input.value = text;
            input.dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(50);
        }
        await vi.advanceTimersByTimeAsync(350);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toContain('query=alic');
    });

    it('picks with the arrow keys and Enter', async () => {
        const onSelect = vi.fn();
        const picker = attachUserPicker(input, { onSelect });
        await type('al');

        key('ArrowDown');
        key('ArrowDown');
        expect(input.getAttribute('aria-activedescendant')).toBe(options()[1].id);
        expect(options()[1].getAttribute('aria-selected')).toBe('true');

        key('Enter');
        expect(onSelect).toHaveBeenCalledWith(USERS[1]);
        expect(picker.selected()).toEqual(USERS[1]);
        expect(input.value).toBe('Albert Ames (albert)');
        expect(list().hidden).toBe(true);
    });

    it('picks with the mouse', async () => {
        const onSelect = vi.fn();
        attachUserPicker(input, { onSelect });
        await type('al');

        options()[0].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

        expect(onSelect).toHaveBeenCalledWith(USERS[0]);
    });

    it('Escape closes the list without reaching the dialog around it', async () => {
        attachUserPicker(input);
        await type('al');
        const outer = vi.fn();
        document.addEventListener('keydown', outer);

        key('Escape');

        expect(list().hidden).toBe(true);
        expect(outer).not.toHaveBeenCalled();
        document.removeEventListener('keydown', outer);
    });

    it('says so when nobody matches', async () => {
        global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => [] }));
        attachUserPicker(input);

        await type('zz');

        expect(options()).toHaveLength(0);
        expect(list().textContent).toContain('Nextcloud user not found');
    });

    it('editing the text drops an earlier pick', async () => {
        const onSelect = vi.fn();
        const picker = attachUserPicker(input, { onSelect });
        await type('al');
        key('ArrowDown');
        key('Enter');

        await type('bo');

        expect(picker.selected()).toBeNull();
        expect(onSelect).toHaveBeenLastCalledWith(null);
    });
});
