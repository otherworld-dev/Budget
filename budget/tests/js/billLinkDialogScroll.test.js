/**
 * The Link to bill dialog with a long list of bills (#399).
 *
 * The reporter keeps 61 bills on Lohnkonto, and the dialog scrolled as one
 * 5,000px box: Cancel and Link sat below the last bill, out of sight when
 * it opened, and after picking a bill near the top the user had to scroll
 * past all the others to press Link. The header and footer now stay in view
 * and only the list scrolls, as in the duplicates dialog.
 *
 * jsdom does no layout, so this checks what the layout rests on: the
 * footer outside the list, and the rules in style.css that keep the box
 * from scrolling and let the list take what space is left.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { chooseBill } from '../../src/utils/billLinkDialog.js';

const CSS = fs.readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../css/style.css'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/** The declarations of the rules for exactly this selector, media queries included, later ones winning */
function style(selector) {
    const out = {};
    const walk = (text) => {
        let k = 0;
        while (k < text.length) {
            const open = text.indexOf('{', k);
            if (open === -1) break;
            const head = text.slice(k, open).trim();
            let depth = 1;
            let j = open + 1;
            while (j < text.length && depth) {
                if (text[j] === '{') depth++;
                else if (text[j] === '}') depth--;
                j++;
            }
            const body = text.slice(open + 1, j - 1);
            if (head.startsWith('@media')) {
                walk(body);
            } else if (head.split(',').map(s => s.trim().replace(/\s+/g, ' ')).includes(selector)) {
                for (const d of body.split(';').map(s => s.trim()).filter(Boolean)) {
                    const colon = d.indexOf(':');
                    out[d.slice(0, colon).trim()] = d.slice(colon + 1).trim();
                }
            }
            k = j;
        }
    };
    walk(CSS);
    return out;
}

const bills = (count) => Array.from({ length: count }, (_, n) => ({
    id: n + 1, name: `Bill ${String(n + 1).padStart(2, '0')}`, accountId: 3, isActive: true, amount: 10 + n, nextDueDate: '2026-10-28',
}));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Link to bill with 61 bills', () => {
    it('keeps the header and the buttons out of the list that scrolls', () => {
        chooseBill(bills(61), {});
        const modal = document.querySelector('#bill-link-modal .budget-modal');
        const list = modal.querySelector('.matching-tx-list');

        expect(list.querySelectorAll('input[name="bill-link-choice"]')).toHaveLength(61);
        expect([...modal.children].map(el => el.className)).toEqual(['budget-modal-header', 'budget-modal-body', 'budget-modal-footer']);
        expect(list.contains(modal.querySelector('.confirm-btn'))).toBe(false);
        expect(list.contains(modal.querySelector('.budget-modal-header'))).toBe(false);
    });

    it('never scrolls the dialog box itself, which lays its three parts out in a column', () => {
        const box = style('#bill-link-modal .budget-modal');

        expect(box.display).toBe('flex');
        expect(box['flex-direction']).toBe('column');
        expect(box.overflow).toBe('hidden');
        // The cap comes from .budget-modal itself
        expect(style('.budget-modal')['max-height']).toBe('90vh');
    });

    it('lets the body shrink to the space left and scrolls the list inside it', () => {
        const body = style('#bill-link-modal .budget-modal-body');
        const list = style('#bill-link-modal .matching-tx-list');

        // A flex item won't shrink below its content without min-height 0
        expect(body['min-height']).toBe('0');
        expect(body.display).toBe('flex');
        expect(body['flex-direction']).toBe('column');
        expect(body.flex).toMatch(/^1 1 /);
        expect(list.flex).toMatch(/^1 1 /);
        expect(list['overflow-y']).toBe('auto');
    });

    it('leaves the other dialogs that share the markup alone', () => {
        // Mark Paid's dialog uses the same list class; its list doesn't
        // scroll on its own (its body does, matchingDialogFooterInView.test.js)
        expect(style('.matching-tx-list')['overflow-y']).toBeUndefined();
        expect(style('#matching-tx-modal .matching-tx-list')).toEqual({});
    });
});
