/**
 * Repair Data names a duplicate payment by its own vendor, else its own
 * description (#399), and the note names the payment it repeats.
 *
 * The scan used to send the other payment's vendor for a payment with
 * none, so once that other row had one, the reporter's 26 Mar row read
 * 'Christian Shop AG 91.58 CHF 2026-03-26 Also recorded as Christian Shop
 * AG on 2026-03-28': the other row's name twice and its own description
 * nowhere. The scan now sends the payment's own vendor only
 * (RepairService::duplicateFinding()); these are the findings it sends.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const fill = (text, params = {}, options = {}) => String(text).replace(/\{(\w+)\}/g,
        (m, k) => (k in params ? (options?.escape === false ? String(params[k]) : esc(params[k])) : m));
    return {
        translate: (_app, text, params, _count, options) => fill(text, params, options),
        translatePlural: (_app, singular, plural, count, params, options) =>
            fill((count === 1 ? singular : plural).replace(/%n/g, String(count)), params, options),
        getLanguage: () => 'en',
        getLocale: () => 'en',
    };
});

import { duplicateItem } from '../../src/utils/repairFindings.js';

const chf = (amount) => `${Number(amount).toFixed(2)} CHF`;

/** The 26 Mar row as the scan sends it once the 28 Mar row has a vendor */
const christian = (overrides = {}) => ({
    duplicateId: 1599732, originalId: 1599850, amount: 91.58, originalAmount: 91.59,
    date: '2026-03-26', originalDate: '2026-03-28',
    vendor: null, description: 'Uhrenschmuck24 Christian (own text of the 26 Mar row)',
    originalDescription: 'Christian Shop AG', originalReconciled: false,
    accountId: 11580, accountName: 'Lohnkonto', billId: null,
    reason: 'auto_gen_duplicates_manual', selected: false,
    ...overrides,
});

function row(finding) {
    const modal = document.createElement('div');
    modal.id = 'repair-data-modal';
    modal.innerHTML = `<div class="repair-category-details">${duplicateItem(finding, chf)}</div>`;
    document.body.appendChild(modal);
    return modal.querySelector('.repair-item');
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('a duplicate whose pair has a vendor', () => {
    it('goes by its own description, and the note by the other row\'s vendor', () => {
        const item = row(christian());

        expect(item.querySelector('.repair-item-select span').textContent)
            .toBe('Uhrenschmuck24 Christian (own text of the 26 Mar row)');
        expect(item.querySelector('.repair-item-note').textContent)
            .toBe('Also recorded as Christian Shop AG on 2026-03-28');
    });

    it('shows the other row\'s name once, in the note', () => {
        const text = row(christian()).textContent;

        expect(text.split('Christian Shop AG')).toHaveLength(2);
    });

    it('goes by its own vendor when it has one', () => {
        const item = row(christian({ vendor: 'Uhrenschmuck24', description: 'Rechnung 0326' }));

        expect(item.querySelector('.repair-item-select span').textContent).toBe('Uhrenschmuck24');
    });

    it('with no vendor or description of its own, goes by the other row\'s name', () => {
        // The 2.x pending row of a deleted bill: the scan paired the two on
        // the bill's name, so the other row's name is this payment's too
        const item = row(christian({ description: '', originalDescription: 'Christian Uhrenschmuck24' }));

        expect(item.querySelector('.repair-item-select span').textContent).toBe('Christian Uhrenschmuck24');
    });
});
