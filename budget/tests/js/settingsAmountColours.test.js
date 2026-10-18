/**
 * The money-in / money-out colour controls on the Settings page (#432).
 *
 * A scheme select, four colour pickers that only show for Custom, a contrast
 * warning per picker and a live preview. Picking a colour saves the canonical
 * JSON through the hidden setting-amount-color-custom input; a faint colour
 * warns but is never refused (the server only checks the format).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));
vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showInfo: vi.fn(),
}));
vi.mock('../../src/utils/datepicker.js', () => ({ initDatePickers: vi.fn() }));
vi.mock('../../src/utils/amountColours.js', async (importOriginal) => ({
    ...(await importOriginal()),
    applyAmountColours: vi.fn(),
}));

import SettingsModule from '../../src/modules/settings/SettingsModule.js';
import { applyAmountColours } from '../../src/utils/amountColours.js';

const PRESET_JSON = '{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}';

function mount() {
    document.body.innerHTML = `
        <div id="settings-view">
            <div class="setting-item">
                <select id="setting-amount-color-scheme" class="setting-input">
                    <option value="default">Default</option>
                    <option value="colorblind">Colour-blind friendly (blue and orange)</option>
                    <option value="custom">Custom</option>
                </select>
                <input type="hidden" id="setting-amount-color-custom" class="setting-input">
            </div>
            <div class="setting-item" id="amount-color-custom-rows" hidden>
                ${['light-positive', 'light-negative', 'dark-positive', 'dark-negative'].map((k) => `
                <div class="amount-color-row">
                    <input type="color" id="amount-color-${k}" class="amount-color-picker">
                    <span class="amount-color-contrast-warning" hidden>Hard to read on this background</span>
                </div>`).join('')}
            </div>
            <div id="amount-color-preview"></div>
        </div>`;
}

function makeModule(settings = {}) {
    const app = { settings: { amount_color_scheme: 'default', amount_color_custom: PRESET_JSON, ...settings } };
    const mod = new SettingsModule(app);
    mod.setupAutoSave();
    mod.saveSetting = vi.fn().mockResolvedValue(undefined);
    return mod;
}

const el = (id) => document.getElementById(id);
const rows = () => el('amount-color-custom-rows');
const warningFor = (id) => el(id).parentElement.querySelector('.amount-color-contrast-warning');

function pick(id, value) {
    el(id).value = value;
    el(id).dispatchEvent(new Event('change', { bubbles: true }));
}

function chooseScheme(value) {
    el('setting-amount-color-scheme').value = value;
    el('setting-amount-color-scheme').dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    globalThis.OC = { generateUrl: (p) => p, requestToken: 'token' };
    mount();
});

afterEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
});

describe('amount colour controls', () => {
    it('shows the custom rows only for Custom', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'default', amount_color_custom: PRESET_JSON });
        expect(rows().hidden).toBe(true);

        chooseScheme('custom');
        expect(rows().hidden).toBe(false);

        chooseScheme('colorblind');
        expect(rows().hidden).toBe(true);
    });

    it('fills the pickers from the stored JSON', async () => {
        const mod = makeModule();
        await mod.populateSettings({
            amount_color_scheme: 'custom',
            amount_color_custom: '{"lightPositive":"#112233","lightNegative":"#445566","darkPositive":"#778899","darkNegative":"#aabbcc"}',
        });
        expect(el('amount-color-light-positive').value).toBe('#112233');
        expect(el('amount-color-light-negative').value).toBe('#445566');
        expect(el('amount-color-dark-positive').value).toBe('#778899');
        expect(el('amount-color-dark-negative').value).toBe('#aabbcc');
    });

    it('falls back to the preset colours when the stored JSON is unusable', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: 'not json' });
        expect(el('amount-color-light-positive').value).toBe('#0b62a4');
        expect(el('amount-color-dark-negative').value).toBe('#f5a35c');
    });

    it('a picker change saves the canonical JSON', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });

        pick('amount-color-light-negative', '#AA2200');

        const hidden = el('setting-amount-color-custom');
        expect(hidden.value).toBe('{"lightPositive":"#0b62a4","lightNegative":"#aa2200","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}');
        expect(mod.saveSetting).toHaveBeenCalledOnce();
        expect(mod.saveSetting).toHaveBeenCalledWith(hidden);
    });

    it('a faint light colour shows the warning and still saves', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });
        expect(warningFor('amount-color-light-negative').hidden).toBe(true);

        pick('amount-color-light-negative', '#fefefe');

        expect(warningFor('amount-color-light-negative').hidden).toBe(false);
        expect(warningFor('amount-color-light-positive').hidden).toBe(true);
        expect(mod.saveSetting).toHaveBeenCalledOnce();
        expect(JSON.parse(el('setting-amount-color-custom').value).lightNegative).toBe('#fefefe');

        pick('amount-color-light-negative', '#a84800');
        expect(warningFor('amount-color-light-negative').hidden).toBe(true);
    });

    it('a dark colour warns when it fails on either dark surface', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });

        // Near-black on dark: unreadable on both #171717 and #292929.
        pick('amount-color-dark-positive', '#202020');
        expect(warningFor('amount-color-dark-positive').hidden).toBe(false);

        // Reads on #171717 (4.9:1) but not on the lighter #292929 (4.0:1).
        pick('amount-color-dark-negative', '#868686');
        expect(warningFor('amount-color-dark-negative').hidden).toBe(false);

        pick('amount-color-dark-negative', '#f5a35c');
        expect(warningFor('amount-color-dark-negative').hidden).toBe(true);
    });

    it('a dark picker is not judged against white', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });
        pick('amount-color-dark-positive', '#ffffff');
        expect(warningFor('amount-color-dark-positive').hidden).toBe(true);
    });

    it('applies the scheme when a colour setting is saved', () => {
        const mod = makeModule({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });
        mod.applySettingSideEffects({ amount_color_custom: PRESET_JSON });
        expect(applyAmountColours).toHaveBeenCalledWith('custom', PRESET_JSON);

        applyAmountColours.mockClear();
        mod.applySettingSideEffects({ amount_color_scheme: 'custom' });
        expect(applyAmountColours).toHaveBeenCalledOnce();
    });

    it('does not touch the colours when an unrelated setting is saved', () => {
        const mod = makeModule();
        mod.applySettingSideEffects({ date_format: 'd/m/Y' });
        expect(applyAmountColours).not.toHaveBeenCalled();
    });

    it('reset re-applies Default', () => {
        const mod = makeModule({ amount_color_scheme: 'default', amount_color_custom: PRESET_JSON });
        mod.applySettingSideEffects({ amount_color_scheme: 'default', amount_color_custom: PRESET_JSON });
        expect(applyAmountColours).toHaveBeenCalledWith('default', PRESET_JSON);
    });

    it('the preview follows the pickers without saving', async () => {
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });
        const preview = el('amount-color-preview');

        // jsdom has no matchMedia and the body is not marked dark: light mode.
        expect(preview.style.getPropertyValue('--budget-color-positive')).toBe('#0b62a4');

        el('amount-color-light-positive').value = '#123456';
        el('amount-color-light-positive').dispatchEvent(new Event('input', { bubbles: true }));

        expect(preview.style.getPropertyValue('--budget-color-positive')).toBe('#123456');
        expect(preview.style.getPropertyValue('--budget-color-negative')).toBe('#a84800');
        expect(mod.saveSetting).not.toHaveBeenCalled();
        expect(applyAmountColours).not.toHaveBeenCalled();
    });

    it('the preview uses the dark colours in the dark theme', async () => {
        document.body.setAttribute('data-theme-dark', '');
        const mod = makeModule();
        await mod.populateSettings({ amount_color_scheme: 'custom', amount_color_custom: PRESET_JSON });
        expect(el('amount-color-preview').style.getPropertyValue('--budget-color-positive')).toBe('#6cb4ee');
        document.body.removeAttribute('data-theme-dark');
    });

    it('the preview shows the preset for Colour-blind and the app defaults for Default', async () => {
        const mod = makeModule();
        const preview = el('amount-color-preview');

        await mod.populateSettings({ amount_color_scheme: 'colorblind', amount_color_custom: PRESET_JSON });
        expect(preview.style.getPropertyValue('--budget-color-negative')).toBe('#a84800');

        await mod.populateSettings({ amount_color_scheme: 'default', amount_color_custom: PRESET_JSON });
        expect(preview.style.getPropertyValue('--budget-color-positive')).toBe('');
        expect(preview.style.getPropertyValue('--budget-color-negative')).toBe('');
    });
});
