/**
 * Repair Data's list of incomes an older version left expected on a
 * payment it had already recorded (#399): each row names the receipt and
 * the date the income moves on to.
 *
 * The l10n mock escapes placeholders the way the real library does, so a
 * name is checked to come out as text, escaped once.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const fill = (text, params = {}, options = {}) => String(text).replace(/\{(\w+)\}/g,
        (m, k) => (k in params ? (options?.escape === false ? String(params[k]) : esc(params[k])) : m));
    return {
        translate: (_app, text, params, _count, options) => fill(text, params, options),
        translatePlural: (_app, singular, plural, count, params, options) =>
            fill(count === 1 ? singular : plural, params, options),
        getLanguage: () => 'en',
        getLocale: () => 'en',
    };
});

import { stuckIncomeItem } from '../../src/utils/repairFindings.js';

const chf = (amount) => `CHF ${Number(amount).toFixed(2)}`;

describe('stuckIncomeItem', () => {
    it('lists the receipt and the date the income moves on to', () => {
        const html = stuckIncomeItem({
            incomeId: 9, name: 'Gutschrift SBB Abo Leon', amount: 100, transactionId: 1293,
            lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-09-29', settledNextExpectedDate: '2026-10-29',
        }, chf);
        const row = document.createElement('div');
        row.innerHTML = html;

        expect([...row.querySelectorAll('span')].map(s => s.textContent)).toEqual([
            'Gutschrift SBB Abo Leon', 'CHF 100.00', 'Received 2026-09-28', 'Moves on to 2026-10-29',
        ]);
    });

    it('renders the name as text', () => {
        const row = document.createElement('div');
        row.innerHTML = stuckIncomeItem({
            name: '<img src=x onerror="window.__xss=1">', amount: 1,
            lastReceivedDate: '2026-09-28', settledNextExpectedDate: '2026-10-29',
        }, chf);

        expect(row.querySelector('img')).toBeNull();
        expect(row.querySelector('span').textContent).toBe('<img src=x onerror="window.__xss=1">');
    });
});
