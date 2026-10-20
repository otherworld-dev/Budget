/**
 * Checkboxes clicked in the import's Review step and in Configure columns
 * (#399, browser round 3).
 *
 * Nextcloud's input rule (core/css/inputs.css) gives every input a 36px
 * height and, on hover, focus and press, a 2px box-shadow ring in the page
 * colour. A checkbox the click had just focused stood in a tall white
 * frame on the grey filter bar (Show duplicates, Show uncategorized), a
 * black one in the dark theme: the same fault 15d0d5d2 fixed for the
 * payment dialogs' radios. These checkboxes now get no ring, from a rule
 * that wins over Nextcloud's in every state, and an outline of their own
 * for keyboard focus. The import's filters also get a checkbox's size;
 * Configure columns keeps Nextcloud's height, which spaces its rows.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { RULES } from './helpers/stylesheet.js';
import { cascadedDeclarations, specificity, useTheme, THEMES } from './helpers/cascade.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
).replace(/<\?php[\s\S]*?\?>/g, '');

/**
 * Nextcloud 35's input rules, as their specificity: the sizing
 * ('input:not([type=range], .input-field__input, …)', 0-1-1) and the ring
 * (the same with ':not(:disabled):hover', ':focus' or ':active', 0-3-1)
 */
const NEXTCLOUD_SIZING = 101;
const NEXTCLOUD_RING = 301;

const IMPORT_FILTERS = {
    'Show duplicates': '#show-duplicates',
    'Show uncategorized': '#show-uncategorized',
    'Import flagged duplicates too': '#import-duplicates',
};

const BOXES = {
    ...IMPORT_FILTERS,
    'Configure columns: Balance': '#col-toggle-balance',
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

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

describe.each(Object.entries(BOXES))('%s', (name, selector) => {
    const box = () => {
        const el = document.querySelector(selector);
        expect(el?.type).toBe('checkbox');
        return el;
    };

    for (const theme of THEMES) {
        it(`has no ring at rest or under the pointer, focused or pressed, in ${theme}`, () => {
            useTheme(theme);
            expect(cascadedDeclarations(box(), theme)['box-shadow']).toBe('none');
            expect(cascadedDeclarations(box(), theme, { hover: true })['box-shadow']).toBe('none');
            expect(strongest(box(), 'box-shadow', 'none')).toBeGreaterThan(NEXTCLOUD_RING);
        });
    }

    it('still shows where keyboard focus is', () => {
        const focusRules = RULES.filter(rule => !rule.media && rule.selectors.some((s) => {
            if (!s.endsWith(':focus-visible')) return false;
            try {
                return box().matches(s.replace(/:focus-visible$/, ''));
            } catch {
                return false;
            }
        }));
        const outline = Object.assign({}, ...focusRules.map(rule => rule.declarations)).outline;
        expect(outline).toMatch(/^\d+px solid /);
    });
});

describe.each(Object.entries(IMPORT_FILTERS))('the import filter %s', (name, selector) => {
    it('is sized as a checkbox, over Nextcloud\'s sizing', () => {
        const box = document.querySelector(selector);
        expect(cascadedDeclarations(box, 'light')).toMatchObject({ height: 'auto', 'min-height': '0', 'flex-shrink': '0' });
        expect(strongest(box, 'height', 'auto')).toBeGreaterThan(NEXTCLOUD_SIZING);
        expect(strongest(box, 'min-height', '0')).toBeGreaterThan(NEXTCLOUD_SIZING);
    });
});
