import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import ImportModule, { appPresetNote } from '../../src/modules/import/ImportModule.js';

const PRESETS = [
    { id: 'toshl', name: 'Toshl Finance', description: 'server text', options: { accountColumn: 'Account' }, isPreset: true },
    { id: 'ynab', name: 'YNAB', description: 'server text', options: { accountColumn: 'Account' }, isPreset: true },
    { id: 'mint', name: 'Mint', description: 'server text', options: { accountColumn: 'Account Name' }, isPreset: true },
];

function makeModule() {
    const mod = new ImportModule({
        data: {},
        accounts: [],
        categories: [],
        settings: {},
        getPrimaryCurrency: () => 'USD',
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
    });
    mod.presets = PRESETS;
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <input type="file" id="import-file-input">
        <details id="import-switch-from">
            <button type="button" class="import-switch-app" data-preset="ynab" aria-pressed="false"><strong>YNAB</strong></button>
            <button type="button" class="import-switch-app" data-preset="mint" aria-pressed="false"><strong>Mint</strong></button>
            <p id="import-switch-selected" hidden></p>
        </details>
        <div id="import-step-2">
            <div class="mapping-container"></div>
            <div class="mapping-options"></div>
        </div>
        <button id="next-step-btn"></button>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    vi.restoreAllMocks();
});

describe('Switching from another app', () => {
    it('remembers the app picked before upload and opens the file picker', () => {
        const mod = makeModule();
        const input = document.getElementById('import-file-input');
        const click = vi.spyOn(input, 'click').mockImplementation(() => {});
        const ynab = document.querySelector('[data-preset="ynab"]');

        mod.choosePendingPreset(ynab);

        expect(mod.pendingPreset).toBe('ynab');
        expect(ynab.getAttribute('aria-pressed')).toBe('true');
        expect(document.querySelector('[data-preset="mint"]').getAttribute('aria-pressed')).toBe('false');
        expect(document.getElementById('import-switch-selected').hidden).toBe(false);
        expect(document.getElementById('import-switch-selected').textContent).toContain('YNAB');
        expect(click).toHaveBeenCalledOnce();
    });

    it('drops the choice when the same app is clicked again', () => {
        const mod = makeModule();
        vi.spyOn(document.getElementById('import-file-input'), 'click').mockImplementation(() => {});
        const ynab = document.querySelector('[data-preset="ynab"]');

        mod.choosePendingPreset(ynab);
        mod.choosePendingPreset(ynab);

        expect(mod.pendingPreset).toBeNull();
        expect(ynab.getAttribute('aria-pressed')).toBe('false');
        expect(document.getElementById('import-switch-selected').hidden).toBe(true);
    });

    it('pre-selects the chosen app preset once the CSV is uploaded', async () => {
        const mod = makeModule();
        mod.pendingPreset = 'mint';
        mod.showPresetSelector();

        // The server recognised another app, but the user's pick wins
        await mod.applyPresetForUpload({ suggestedPreset: 'ynab' });

        expect(document.getElementById('import-preset').value).toBe('preset:mint');
        expect(mod.selectedPreset).toBe('mint');
        expect(document.getElementById('preset-detected').hidden).toBe(true);
        expect(document.getElementById('preset-description').textContent).toBe(appPresetNote('mint'));
        expect(document.querySelector('#import-step-2 .mapping-container').style.display).toBe('none');
    });

    it('selects the preset the server detected and says so', async () => {
        const mod = makeModule();
        mod.showPresetSelector();

        await mod.applyPresetForUpload({ suggestedPreset: 'ynab' });

        expect(mod.selectedPreset).toBe('ynab');
        const detected = document.getElementById('preset-detected');
        expect(detected.hidden).toBe(false);
        expect(detected.textContent).toContain('YNAB');
    });

    it('leaves a format the user already chose alone', async () => {
        const mod = makeModule();
        mod.showPresetSelector();
        document.getElementById('import-preset').value = 'preset:toshl';

        await mod.applyPresetForUpload({ suggestedPreset: 'ynab' });

        expect(document.getElementById('import-preset').value).toBe('preset:toshl');
        expect(mod.selectedPreset).toBeNull();
    });

    it('does nothing for a file that matches no app', async () => {
        const mod = makeModule();
        mod.showPresetSelector();

        await mod.applyPresetForUpload({ suggestedPreset: null });

        expect(document.getElementById('import-preset').value).toBe('');
        expect(mod.selectedPreset).toBeNull();
    });

    it('has a translatable note for every app preset', () => {
        for (const id of ['toshl', 'firefly-iii', 'ynab', 'actual-budget', 'mint', 'monarch-money']) {
            expect(appPresetNote(id)).toBeTruthy();
        }
        expect(appPresetNote('chase_checking')).toBeNull();
    });
});
