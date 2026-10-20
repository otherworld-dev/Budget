/**
 * A double click in Existing Transaction Found or Link to bill (#399,
 * browser round 3). The second press of a real double click selects the
 * word under the pointer, and on the candidate or bill row it lands on
 * that highlighted 'CHF' or a date. The option rows (the labels) now take
 * no text selection; the rest of each dialog's text still does.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { declarationsFor } from './helpers/stylesheet.js';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { chooseBill } from '../../src/utils/billLinkDialog.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';

const DIALOGS = {
    'Link to bill': () => {
        chooseBill([
            { id: 17046, name: 'Corner Card SA', amount: 100, accountId: 3, frequency: 'one-time', startDate: '2026-09-28', isActive: true },
            { id: 47, name: 'CSX Pro Credit Cards', amount: 100, accountId: 3, frequency: 'monthly', nextDueDate: '2026-10-28', isActive: true },
        ], {}, { id: 1711111, accountId: 3, amount: 100 });
        return document.getElementById('bill-link-modal');
    },
    'Existing Transaction Found': () => {
        showMatchingTransactionDialog({ id: 17046, name: 'Corner Card SA', amount: 100, currency: 'CHF' }, [
            { transaction: { id: 1711111, date: '2026-09-28', description: 'Monatsrechnung', amount: 100 }, score: 80, matchReasons: ['exact_amount'] },
        ], {});
        return document.getElementById('matching-tx-modal');
    },
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe.each(Object.keys(DIALOGS))('%s', (name) => {
    it('takes no text selection on its option rows', () => {
        const options = [...DIALOGS[name]().querySelectorAll('.matching-tx-option')];
        expect(options.length).toBeGreaterThanOrEqual(2);
        for (const option of options) {
            expect(option.tagName).toBe('LABEL');
            const declared = declarationsFor(option);
            expect(declared['user-select']).toBe('none');
            expect(declared['-webkit-user-select']).toBe('none');
        }
    });

    it('leaves the rest of its text selectable', () => {
        const dialog = DIALOGS[name]();
        for (const el of [dialog.querySelector('.budget-modal'), dialog.querySelector('.budget-modal-body'), dialog.querySelector('h2')]) {
            expect(declarationsFor(el)['user-select']).toBeUndefined();
        }
    });
});

describe('other dialogs with the same rows', () => {
    it('are left as they were', () => {
        document.body.innerHTML = '<div id="some-other-dialog"><label class="matching-tx-option"></label></div>';
        expect(declarationsFor(document.querySelector('.matching-tx-option'))['user-select']).toBeUndefined();
    });
});
