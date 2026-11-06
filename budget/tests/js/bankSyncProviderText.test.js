/**
 * What a bank provider sends is text, never markup.
 *
 * The account mappings list put a mapping's last balance and currency into
 * innerHTML as they came from the provider, so a provider (or anyone able
 * to answer in its place) could run script in the Budget page. The bank
 * authorization link was opened with window.open(url, '_blank'), leaving the
 * bank's page a handle on this window and whatever scheme the link had.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

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

import BankSyncModule, { isHttpsUrl, openAuthorizationUrl } from '../../src/modules/bank-sync/BankSyncModule.js';

const HOSTILE = '<img src=x onerror="window.__pwned=1">';

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
    delete window.__pwned;
});

function renderMappings(mappings) {
    document.body.innerHTML = '<div id="bank-mappings-list"></div>';
    const mod = Object.create(BankSyncModule.prototype);
    mod.app = { accounts: [{ id: 1, name: 'Current', type: 'checking', currency: 'GBP' }] };
    mod.renderMappings(mappings, 5);
    return document.getElementById('bank-mappings-list');
}

describe('the account mappings list', () => {
    it('shows a hostile balance and currency as text', () => {
        const list = renderMappings([{
            id: 10,
            externalAccountId: 'ext-a',
            externalAccountName: 'Joint',
            enabled: false,
            budgetAccountId: null,
            lastCurrency: HOSTILE,
            lastBalance: HOSTILE,
        }]);

        expect(list.querySelector('img')).toBeNull();
        expect(list.querySelector('small').textContent).toBe(`${HOSTILE} ${HOSTILE}`);
    });

    it('still shows an ordinary balance', () => {
        const list = renderMappings([{
            id: 10,
            externalAccountId: 'ext-a',
            externalAccountName: 'Joint',
            enabled: false,
            budgetAccountId: null,
            lastCurrency: 'GBP',
            lastBalance: '1234.50',
        }]);

        expect(list.querySelector('small').textContent).toBe('GBP 1234.50');
    });

    it('shows a hostile account name as text', () => {
        const list = renderMappings([{
            id: 10,
            externalAccountId: HOSTILE,
            externalAccountName: HOSTILE,
            enabled: false,
            budgetAccountId: null,
        }]);

        expect(list.querySelector('img')).toBeNull();
        expect(list.querySelector('strong').textContent).toBe(HOSTILE);
    });
});

describe('the bank authorization link', () => {
    it('is only followed when it is https', () => {
        expect(isHttpsUrl('https://ob.gocardless.com/psd2/start/abc')).toBe(true);
        expect(isHttpsUrl('HTTPS://bank.example/auth')).toBe(true);
        expect(isHttpsUrl('http://bank.example/auth')).toBe(false);
        expect(isHttpsUrl('javascript:alert(1)')).toBe(false);
        expect(isHttpsUrl('data:text/html,<script>alert(1)</script>')).toBe(false);
        expect(isHttpsUrl('/relative')).toBe(false);
        expect(isHttpsUrl(null)).toBe(false);
        expect(isHttpsUrl('')).toBe(false);
    });

    it('opens with no opener and no referrer, and the fallback link matches', () => {
        document.body.innerHTML = `
            <p id="bank-sync-auth-link-fallback" style="display: none;">
                <a id="bank-sync-auth-link" href="#" target="_blank" rel="noopener">here</a>
            </p>`;
        const open = vi.spyOn(window, 'open').mockReturnValue(null);

        openAuthorizationUrl('https://ob.gocardless.com/psd2/start/abc');

        expect(open).toHaveBeenCalledWith('https://ob.gocardless.com/psd2/start/abc', '_blank', 'noopener,noreferrer');
        const link = document.getElementById('bank-sync-auth-link');
        expect(link.getAttribute('href')).toBe('https://ob.gocardless.com/psd2/start/abc');
        expect(link.rel).toBe('noopener noreferrer');
        expect(document.getElementById('bank-sync-auth-link-fallback').style.display).toBe('block');
    });
});
