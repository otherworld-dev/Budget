/**
 * The radios in Link to bill and in Mark Paid's Existing Transaction Found
 * (#399).
 *
 * Nextcloud's input rule (core/css/inputs.css) gives every input a 36px
 * height, a minimum of the clickable area and a margin, and on hover,
 * focus and press a 2px box-shadow ring in the page colour. A radio came
 * out 13x36, and the ticked one, focused by the click that ticked it,
 * stood in a tall white frame on the grey option, a black one in the dark
 * theme. The radios now get a radio's size, no ring and a margin that sets
 * them level with the option's first line, from a rule that wins over
 * Nextcloud's in every state. Nextcloud also takes the browser's focus
 * outline off inputs, so keyboard focus gets an outline of its own.
 */

import { describe, it, expect, afterEach } from 'vitest';

import { chooseBill } from '../../src/utils/billLinkDialog.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';
import { RULES } from './helpers/stylesheet.js';
import { cascadedDeclarations, specificity, useTheme } from './helpers/cascade.js';

/**
 * Nextcloud 35's input rules, as their specificity: the sizing
 * ('input:not([type=range], .input-field__input, …)', 0-1-1) and the ring
 * (the same with ':not(:disabled):hover', ':focus' or ':active', 0-3-1)
 */
const NEXTCLOUD_SIZING = 101;
const NEXTCLOUD_RING = 301;

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
            { transaction: { id: 1711111, date: '2026-09-28', description: 'Monatsrechnung', amount: 100 }, score: 80, matchReasons: ['exact_amount', 'same_day'] },
            { transaction: { id: 1711200, date: '2026-09-30', description: 'Diverses', amount: 80 }, score: 20, matchReasons: ['approximate_amount'] },
        ], {});
        return document.getElementById('matching-tx-modal');
    },
};

/** The strongest selector of the rules setting `property` to `value` on `el` */
function strongest(el, property, value) {
    return Math.max(-1, ...RULES
        .filter(rule => !rule.media && rule.declarations[property] === value)
        .flatMap(rule => rule.selectors.filter((s) => {
            try {
                return el.matches(s);
            } catch {
                return false;
            }
        }).map(specificity)));
}

/** The four margins in px, from a margin shorthand */
function margins(value) {
    const parts = value.split(/\s+/).map(v => (v === '0' ? 0 : Number(/^(\d+(?:\.\d+)?)px$/.exec(v)?.[1])));
    const [top, right = top, bottom = top, left = right] = parts;
    return { top, right, bottom, left };
}

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

describe.each(Object.keys(DIALOGS))('%s', (name) => {
    function radios() {
        useTheme('light');
        const list = [...DIALOGS[name]().querySelectorAll('.matching-tx-option input[type="radio"]')];
        expect(list.length).toBeGreaterThanOrEqual(2);
        return list;
    }

    it('sizes each radio as a radio, over Nextcloud\'s sizing', () => {
        for (const radio of radios()) {
            expect(cascadedDeclarations(radio, 'light')).toMatchObject({ height: 'auto', 'min-height': '0', 'flex-shrink': '0' });
            expect(strongest(radio, 'height', 'auto')).toBeGreaterThan(NEXTCLOUD_SIZING);
            expect(strongest(radio, 'min-height', '0')).toBeGreaterThan(NEXTCLOUD_SIZING);
        }
    });

    it('draws no ring round a radio, at rest or under the pointer, focused or pressed', () => {
        for (const radio of radios()) {
            expect(cascadedDeclarations(radio, 'light')['box-shadow']).toBe('none');
            expect(cascadedDeclarations(radio, 'light', { hover: true })['box-shadow']).toBe('none');
            // Nextcloud's ring comes with :hover, :focus and :active (0-3-1)
            expect(strongest(radio, 'box-shadow', 'none')).toBeGreaterThan(NEXTCLOUD_RING);
        }
    });

    it('sets each radio level with its option\'s first line, not indented', () => {
        for (const radio of radios()) {
            const declared = cascadedDeclarations(radio, 'light').margin;
            expect(declared, 'a margin of its own').toBeDefined();
            const margin = margins(declared);
            expect(strongest(radio, 'margin', declared)).toBeGreaterThan(NEXTCLOUD_SIZING);
            // A 13px radio on a 24px line
            expect(margin.top).toBeGreaterThanOrEqual(4);
            expect(margin.top).toBeLessThanOrEqual(7);
            expect(margin.left).toBe(0);
        }
    });

    it('still shows where keyboard focus is', () => {
        for (const radio of radios()) {
            const focusRules = RULES.filter(rule => !rule.media && rule.selectors.some((s) => {
                if (!s.endsWith(':focus-visible')) return false;
                try {
                    return radio.matches(s.replace(/:focus-visible$/, ''));
                } catch {
                    return false;
                }
            }));
            const outline = Object.assign({}, ...focusRules.map(rule => rule.declarations)).outline;
            expect(outline).toMatch(/^\d+px solid /);
        }
    });
});
