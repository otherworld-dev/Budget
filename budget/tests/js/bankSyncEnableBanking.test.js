/**
 * Enable Banking in the bank-sync connect wizard: key entry (or reuse of the
 * keys already stored server-side), bank list without secrets, and a connect
 * request that leaves the redirect URL to the server.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? String(params[k]) : m)),
    translatePlural: (_app, singular, plural, count) => String(count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
}));

vi.mock('../../src/modules/bank-sync/bankSyncStatus.js', () => ({
    refreshBankSyncNav: vi.fn(() => Promise.resolve({ enabled: true })),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, apiFetch: vi.fn() };
});

import BankSyncModule from '../../src/modules/bank-sync/BankSyncModule.js';
import { apiFetch } from '../../src/utils/api.js';

const BASE = '/apps/budget/api/bank-sync';
const SETTINGS_URL = `${BASE}/enablebanking/settings`;
const INSTITUTIONS_URL = `${BASE}/providers/enablebanking/institutions`;
const PEM = '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----';
const BANKS = [
    { id: 'Sparkasse', name: 'Sparkasse <b>', logo: 'https://logo.example/s.png', country: 'DE', maximumConsentValidity: 7776000 },
    { id: 'DKB', name: 'DKB', logo: null, country: 'DE', maximumConsentValidity: 15552000 },
];

let module;
let settings;

const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const calls = (method) => apiFetch.mock.calls.filter(([, options = {}]) => (options.method || 'GET') === method);

function buildDom() {
    document.body.innerHTML = `
        <div id="bank-sync-modal" style="display:none">
            <div id="bank-sync-step-1">
                <select id="bank-sync-provider">
                    <option value=""></option><option value="gocardless"></option>
                    <option value="enablebanking"></option><option value="simplefin"></option>
                </select>
                <input id="bank-sync-name">
                <div id="simplefin-fields"><input id="bank-sync-setup-token"></div>
                <div id="gocardless-fields"><input id="bank-sync-secret-id"><input id="bank-sync-secret-key"></div>
                <div id="enablebanking-fields" style="display:none">
                    <div id="bank-sync-eb-keys-stored" style="display:none"><a id="bank-sync-eb-replace-keys" href="#"></a></div>
                    <div id="bank-sync-eb-key-inputs">
                        <input id="bank-sync-eb-application-id">
                        <textarea id="bank-sync-eb-private-key"></textarea>
                        <input type="file" id="bank-sync-eb-private-key-file">
                    </div>
                    <input id="bank-sync-eb-callback-url" readonly>
                    <button id="bank-sync-eb-copy-callback"></button>
                </div>
                <div id="bank-sync-step1-error"></div>
                <button id="bank-sync-step1-next">Next</button>
            </div>
            <div id="bank-sync-step-2" style="display:none">
                <select id="bank-sync-country"></select>
                <input id="bank-sync-institution-search">
                <div id="bank-sync-institutions-loading"></div>
                <div id="bank-sync-institutions-grid"></div>
                <div id="bank-sync-step2-error"></div>
                <button id="bank-sync-step2-connect" disabled>Connect</button>
            </div>
            <div id="bank-sync-step-3" style="display:none">
                <p id="bank-sync-auth-text-eb" style="display:none"></p>
                <p class="bank-sync-auth-text-default"></p>
                <div id="bank-sync-step3-error"></div><div id="bank-sync-step3-success"></div>
            </div>
        </div>`;
}

const show = (id) => document.getElementById(id).style.display !== 'none';

beforeEach(() => {
    buildDom();
    globalThis.OC = { getLanguage: () => 'en-GB' };
    window.open = vi.fn(() => ({}));
    settings = { hasKeys: false, applicationId: null, callbackUrl: 'https://nc.example/apps/budget/bank-sync/callback' };
    apiFetch.mockReset();
    apiFetch.mockImplementation((url, options = {}) => {
        const method = options.method || 'GET';
        if (url === SETTINGS_URL && method === 'GET') return Promise.resolve(settings);
        if (url === SETTINGS_URL && method === 'PUT') return Promise.resolve({});
        if (url === INSTITUTIONS_URL) return Promise.resolve(BANKS);
        if (url === `${BASE}/connections` && method === 'POST') {
            return Promise.resolve({ connection: { id: 42 }, mappings: [], authorizationUrl: 'https://bank.example/auth' });
        }
        if (url === `${BASE}/connections/7/reauthorize`) return Promise.resolve({ authorizationUrl: 'https://bank.example/re' });
        return Promise.reject(new Error(`unexpected ${method} ${url}`));
    });
    module = new BankSyncModule({});
    module.setupEventListeners();
});

async function pickEnableBanking(name = 'My DE bank') {
    const select = document.getElementById('bank-sync-provider');
    select.value = 'enablebanking';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await tick();
    document.getElementById('bank-sync-name').value = name;
}

describe('Enable Banking wizard', () => {
    it('skips key fields when keys are stored', async () => {
        settings = { ...settings, hasKeys: true, applicationId: 'app-1' };
        await pickEnableBanking();

        expect(show('enablebanking-fields')).toBe(true);
        expect(show('gocardless-fields')).toBe(false);
        expect(show('bank-sync-eb-keys-stored')).toBe(true);
        expect(show('bank-sync-eb-key-inputs')).toBe(false);
        expect(document.getElementById('bank-sync-eb-callback-url').value).toBe(settings.callbackUrl);

        await module.handleStep1Next();
        expect(calls('PUT')).toHaveLength(0);
        expect(show('bank-sync-step-2')).toBe(true);

        // "Replace keys" brings the inputs back, prefilled with the application ID
        document.getElementById('bank-sync-eb-replace-keys').click();
        expect(show('bank-sync-eb-key-inputs')).toBe(true);
        expect(document.getElementById('bank-sync-eb-application-id').value).toBe('app-1');
    });

    it('shows key fields and no notice when no keys are stored', async () => {
        await pickEnableBanking();
        expect(show('bank-sync-eb-keys-stored')).toBe(false);
        expect(show('bank-sync-eb-key-inputs')).toBe(true);
    });

    it('PUTs keys before loading banks when none are stored', async () => {
        await pickEnableBanking();
        document.getElementById('bank-sync-eb-application-id').value = ' app-1 ';
        document.getElementById('bank-sync-eb-private-key').value = PEM;

        await module.handleStep1Next();

        const urls = apiFetch.mock.calls.map(([url, o = {}]) => `${o.method || 'GET'} ${url}`);
        expect(urls.indexOf(`PUT ${SETTINGS_URL}`)).toBeGreaterThan(-1);
        expect(urls.indexOf(`PUT ${SETTINGS_URL}`)).toBeLessThan(urls.indexOf(`POST ${INSTITUTIONS_URL}`));
        expect(calls('PUT')[0][1].body).toEqual({ applicationId: 'app-1', privateKey: PEM });
        expect(show('bank-sync-step-2')).toBe(true);
    });

    it('stays on step 1 and shows the server error when the key is rejected', async () => {
        await pickEnableBanking();
        document.getElementById('bank-sync-eb-application-id').value = 'app-1';
        document.getElementById('bank-sync-eb-private-key').value = 'junk';
        const put = apiFetch.getMockImplementation();
        apiFetch.mockImplementation((url, options = {}) =>
            options.method === 'PUT' ? Promise.reject(new Error('Invalid private key')) : put(url, options));

        await module.handleStep1Next();

        expect(document.getElementById('bank-sync-step1-error').textContent).toContain('Invalid private key');
        expect(calls('POST')).toHaveLength(0);
        expect(show('bank-sync-step-2')).toBe(false);
    });

    it('loads institutions from the enablebanking endpoint without secrets', async () => {
        settings = { ...settings, hasKeys: true, applicationId: 'app-1' };
        await pickEnableBanking();
        await module.handleStep1Next();

        const [, options] = calls('POST').find(([url]) => url === INSTITUTIONS_URL);
        expect(options.body).toEqual({ country: 'DE' });
        expect(document.getElementById('bank-sync-country').value).toBe('DE');

        const tiles = document.querySelectorAll('.bank-institution-tile');
        expect(tiles).toHaveLength(2);
        // names are escaped, logos are plain <img> tags loaded by the browser
        expect(document.querySelector('.bank-institution-name').innerHTML).toBe('Sparkasse &lt;b&gt;');
        expect(tiles[0].querySelector('img').getAttribute('src')).toBe('https://logo.example/s.png');
    });

    it('connect sends provider enablebanking and no redirectUrl', async () => {
        settings = { ...settings, hasKeys: true, applicationId: 'app-1' };
        await pickEnableBanking('Mein Konto');
        await module.handleStep1Next();
        document.querySelector('.bank-institution-tile[data-institution-id="DKB"]').click();

        await module.handleStep2Connect();

        const [, options] = calls('POST').find(([url]) => url === `${BASE}/connections`);
        expect(options.body).toEqual({
            provider: 'enablebanking',
            name: 'Mein Konto',
            institutionId: 'DKB',
            country: 'DE',
            maximumConsentValidity: 15552000,
        });
        expect(window.open).toHaveBeenCalledWith('https://bank.example/auth', '_blank');
        expect(show('bank-sync-step-3')).toBe(true);
        expect(show('bank-sync-auth-text-eb')).toBe(true);
        expect(document.querySelector('.bank-sync-auth-text-default').style.display).toBe('none');
    });

    it('reauthorize for enablebanking posts institution without redirectUrl', async () => {
        settings = { ...settings, hasKeys: true, applicationId: 'app-1' };
        module.connections = [{ connection: { id: 7, name: 'Mein Konto', provider: 'enablebanking', status: 'expired' }, mappings: [] }];

        await module.startReauthorize(7);

        // keys are stored, so the wizard opens at the bank list
        expect(document.getElementById('bank-sync-modal').style.display).toBe('flex');
        expect(show('bank-sync-step-2')).toBe(true);
        expect(document.getElementById('bank-sync-provider').value).toBe('enablebanking');

        document.querySelector('.bank-institution-tile[data-institution-id="Sparkasse"]').click();
        await module.handleStep2Connect();

        const [, options] = calls('POST').find(([url]) => url === `${BASE}/connections/7/reauthorize`);
        expect(options.body).toEqual({ institutionId: 'Sparkasse', country: 'DE', maximumConsentValidity: 7776000 });
        expect(window.open).toHaveBeenCalledWith('https://bank.example/re', '_blank');
    });

    it('reauthorize for gocardless still asks for credentials and sends redirectUrl', async () => {
        module.connections = [{ connection: { id: 7, name: 'Old', provider: 'gocardless', status: 'expired' }, mappings: [] }];

        await module.startReauthorize(7);

        expect(show('bank-sync-step-1')).toBe(true);
        expect(show('gocardless-fields')).toBe(true);
        expect(show('enablebanking-fields')).toBe(false);
        expect(calls('GET')).toHaveLength(0);

        module._selectedInstitutionId = 'BANK_X';
        await module.handleStep2Connect();
        const [, options] = calls('POST')[0];
        expect(options.body.redirectUrl).toBeTruthy();
        expect(options.body.institutionId).toBe('BANK_X');
    });
});
