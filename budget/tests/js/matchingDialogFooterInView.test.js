/**
 * Mark Paid's Existing Transaction Found keeps Cancel and Confirm in view
 * (#399, browser round 3).
 *
 * The dialog scrolled as one box with its footer inside: at 1440x900 with
 * five candidates, Cancel and Confirm sat below the fold, and scrolling to
 * them took the title away. The header and footer now stay put and only
 * the body scrolls, as in the Repair Data report (1bad39cf) and Link to
 * bill.
 *
 * jsdom does no layout, so this checks what the layout rests on: the
 * footer outside the body, and the rules that reach the dialog's box,
 * body, header and footer.
 */

import { describe, it, expect, beforeAll, afterAll, vi } from 'vitest';
import { declarationsFor } from './helpers/stylesheet.js';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';

let parts;

beforeAll(() => {
    const candidates = [1, 2, 3, 4, 5].map(n => ({
        transaction: { id: 1711110 + n, date: `2026-09-2${n}`, description: `Monatsrechnung ${n}`, amount: 100 },
        score: 80 - n * 10,
        matchReasons: ['exact_amount'],
    }));
    showMatchingTransactionDialog({ id: 17046, name: 'Corner Card SA', amount: 100, currency: 'CHF', frequency: 'monthly' }, candidates, {});
    const dialog = document.getElementById('matching-tx-modal');
    parts = {
        box: dialog.querySelector('.budget-modal'),
        header: dialog.querySelector('.budget-modal-header'),
        body: dialog.querySelector('.budget-modal-body'),
        footer: dialog.querySelector('.budget-modal-footer'),
    };
    document.body.insertAdjacentHTML('beforeend', `
        <div class="budget-modal-overlay" id="some-other-dialog">
            <div class="budget-modal"><div class="budget-modal-body"></div></div>
        </div>`);
});

afterAll(() => {
    document.body.innerHTML = '';
});

describe('Existing Transaction Found', () => {
    it('keeps its buttons in a footer outside the body', () => {
        expect(parts.body.contains(parts.footer)).toBe(false);
        expect(parts.footer.querySelector('.confirm-btn')).not.toBeNull();
        expect(parts.body.querySelectorAll('.matching-tx-option')).toHaveLength(7);
    });

    it('lays its box out as a column that doesn\'t scroll itself, within the window', () => {
        const box = declarationsFor(parts.box);

        expect(box.display).toBe('flex');
        expect(box['flex-direction']).toBe('column');
        expect(box.overflow).toBe('hidden');
        expect(box['max-height']).toBe('90vh');
    });

    it('scrolls only its body', () => {
        const body = declarationsFor(parts.body);

        expect(body['overflow-y']).toBe('auto');
        expect(body['min-height']).toBe('0');
        expect(body.flex).toBe('1 1 auto');
    });

    it('never shrinks the header or the footer with its buttons', () => {
        expect(declarationsFor(parts.header).flex).toBe('0 0 auto');
        expect(declarationsFor(parts.footer).flex).toBe('0 0 auto');
    });

    it('leaves the other dialogs as they were', () => {
        const other = declarationsFor(document.querySelector('#some-other-dialog .budget-modal'));

        expect(other.display).toBeUndefined();
        expect(other['overflow-y']).toBe('auto');
    });
});
