/**
 * Settings save as they change (no Save button).
 *
 * The Settings page had one Save button above eleven sections, so a change
 * near the bottom was easy to leave unsaved. Each control now saves itself on
 * change: one PUT carrying just that setting, and on failure the control goes
 * back to the stored value so the page never shows something that isn't saved.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));
vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
}));
vi.mock('../../src/utils/datepicker.js', () => ({ initDatePickers: vi.fn() }));

import SettingsModule from '../../src/modules/settings/SettingsModule.js';
import { showError } from '../../src/utils/notifications.js';
import { initDatePickers } from '../../src/utils/datepicker.js';

const tick = () => new Promise(resolve => setTimeout(resolve, 0));

function mount() {
    document.body.innerHTML = `
        <div id="settings-view">
            <div class="setting-item">
                <select id="setting-date-format" class="setting-input">
                    <option value="Y-m-d">Y-m-d</option>
                    <option value="d/m/Y">d/m/Y</option>
                </select>
            </div>
            <div class="setting-item">
                <input type="checkbox" id="setting-digest-enabled" class="setting-input">
            </div>
        </div>`;
}

function makeModule() {
    const app = { settings: { date_format: 'Y-m-d', digest_enabled: 'false' } };
    const mod = new SettingsModule(app);
    mod.setupAutoSave();
    return mod;
}

function change(el) {
    el.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    globalThis.OC = { generateUrl: (p) => p, requestToken: 'token' };
    mount();
});

afterEach(() => {
    vi.clearAllMocks();
    delete globalThis.fetch;
    document.body.innerHTML = '';
});

describe('settings auto-save', () => {
    it('saves only the setting that changed', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ settings: { date_format: 'd/m/Y' } }),
        });
        const mod = makeModule();
        const select = document.getElementById('setting-date-format');

        select.value = 'd/m/Y';
        change(select);
        await tick();

        expect(globalThis.fetch).toHaveBeenCalledOnce();
        const [, options] = globalThis.fetch.mock.calls[0];
        expect(options.method).toBe('PUT');
        expect(JSON.parse(options.body)).toEqual({ date_format: 'd/m/Y' });
        expect(mod.settings.date_format).toBe('d/m/Y');
        // Date pickers follow a new date format straight away.
        expect(initDatePickers).toHaveBeenCalled();
        expect(document.querySelector('.setting-saved').textContent).toBe('Saved');
    });

    it('sends checkboxes as "true"/"false"', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ settings: {} }) });
        makeModule();
        const box = document.getElementById('setting-digest-enabled');

        box.checked = true;
        change(box);
        await tick();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ digest_enabled: 'true' });
    });

    it('puts the control back when the save fails', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({ error: 'Invalid value' }),
        });
        const mod = makeModule();
        const select = document.getElementById('setting-date-format');

        select.value = 'd/m/Y';
        change(select);
        await tick();
        await tick();

        expect(showError).toHaveBeenCalledWith('Invalid value');
        expect(select.value).toBe('Y-m-d');
        expect(mod.settings.date_format).toBe('Y-m-d');
    });

    it('ignores changes to controls that are not settings', async () => {
        globalThis.fetch = vi.fn();
        makeModule();
        const other = document.createElement('input');
        document.getElementById('settings-view').appendChild(other);

        change(other);
        await tick();

        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});
