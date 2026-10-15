/**
 * A pension schedule row names the account it is paid from with t(), which
 * escapes its placeholders itself. The row escaped the result again, so an
 * account called "B&Q" showed as "from B&amp;Q".
 *
 * The l10n mock escapes placeholders the way the real library does (unless
 * `{ escape: false }` is passed), so double escaping is visible here.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

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

vi.mock('../../src/utils/chart.js', () => ({ default: vi.fn() }));

import PensionsModule from '../../src/modules/pensions/PensionsModule.js';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('pension schedule rows', () => {
    it('show the source account name once escaped, as text', () => {
        document.body.innerHTML = '<div id="pension-recurring-list"></div>';
        const mod = Object.create(PensionsModule.prototype);
        mod.app = {
            settings: {},
            accounts: [{ id: 3, name: 'B&Q <b>card</b>' }],
            currentPension: { id: 1, name: 'Work', currency: 'GBP', isDefinedContribution: true },
        };

        mod.renderPensionRecurring([{
            id: 7, amount: 200, frequency: 'monthly', nextDueDate: '2099-10-15',
            autoPostEnabled: false, isActive: true, sourceAccountId: 3,
        }]);

        const row = document.querySelector('.recurring-item');
        expect(row.textContent).toContain('from B&Q <b>card</b>');
        expect(row.textContent).not.toContain('&amp;');
        expect(row.querySelector('b')).toBeNull();
    });
});
