/**
 * The header row of the Transactions list and of an account's register
 * stays in view down a long list (#399, round 7). The table scrolls
 * sideways in its own box (.table-scroll), and a sticky cell sticks to its
 * nearest scrolling box, so while that box only scrolled sideways the
 * header row scrolled away with the page (since #280). The box now also
 * scrolls the rows, at most about a window high, and the header row sticks
 * to its top. The bar above and the paging below stay outside it, and on a
 * phone, where the rows are cards, nothing changes.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { cascadedDeclarations, resolve, THEMES, useTheme } from './helpers/cascade.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

const WIDE = { media: ['(min-width: 641px)'] };
const PHONE = { media: ['(max-width: 640px)', '(max-width: 768px)', '(max-width: 1024px)', '(width < 1024px)'] };

const SCROLLS = ['auto', 'scroll', 'hidden', 'clip'];

/** The overflow an element is given in one axis ('x' or 'y') */
function overflow(el, axis, options) {
    const declared = cascadedDeclarations(el, 'light', options);
    const both = String(declared.overflow ?? 'visible').split(/\s+/);
    return declared[`overflow-${axis}`] ?? (axis === 'x' ? both[0] : both[1] ?? both[0]);
}

/**
 * The nearest box round `el` that scrolls in either axis, which is what a
 * sticky cell sticks to, short of #app-content (the page's own scroller)
 */
function scroller(el, options) {
    for (let node = el.parentElement; node && node.id !== 'app-content'; node = node.parentElement) {
        if (SCROLLS.includes(overflow(node, 'x', options)) || SCROLLS.includes(overflow(node, 'y', options))) return node;
    }
    return null;
}

/**
 * A length as the browser works it out in a window `height` px high, in
 * px: var()s from Nextcloud 35's theme, vh, calc() and max()/min()
 */
function px(value, height) {
    const vars = { '--header-height': '44px', '--body-container-margin': 'calc(4px * 2)' };
    const expr = resolve(value, vars)
        .replace(/(\d+(?:\.\d+)?)vh/g, (m, n) => String(Number(n) * height / 100))
        .replace(/(\d+(?:\.\d+)?)px/g, '$1')
        .replace(/\bcalc\(/g, '(')
        .replace(/\b(max|min)\(/g, 'Math.$1(');
    expect(expr).toMatch(/^[\d\s.+\-*/(),Mathmaxin]+$/);
    return Function(`return (${expr});`)();
}

/** A table's header cells and the box the table scrolls in */
const parts = (id) => {
    const table = document.getElementById(id);
    return { table, cells: [...table.querySelectorAll('thead th')], box: table.parentElement };
};

const TABLES = [
    ['the Transactions list', 'transactions-table', 'var(--color-background-hover)'],
    ['an account\'s register', 'account-transactions-table', 'var(--color-background-dark)'],
];

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe.each(TABLES)('%s above phone width', (_label, id, fill) => {
    it('keeps the header row at the top of the box the rows scroll in', () => {
        const { cells, box } = parts(id);

        expect(box.classList.contains('table-scroll')).toBe(true);
        for (const cell of cells) {
            expect(cascadedDeclarations(cell, 'light', WIDE)).toMatchObject({ position: 'sticky', top: '0' });
            // Nothing between the cell and the box scrolls, so the box is what it sticks to
            expect(scroller(cell, WIDE)).toBe(box);
        }
    });

    it('scrolls the rows in that box, sideways and down', () => {
        const { box } = parts(id);

        expect(overflow(box, 'x', WIDE)).toBe('auto');
        expect(overflow(box, 'y', WIDE)).toBe('auto');
    });

    it('keeps the box a window high at most, so a long list scrolls inside it', () => {
        const { box } = parts(id);
        const maxHeight = cascadedDeclarations(box, 'light', WIDE)['max-height'];

        expect(maxHeight).toBeDefined();
        expect(maxHeight).toMatch(/vh/);
    });

    it.each([800, 900])('leaves room in an %s px window for the title, the bar and the paging', (height) => {
        // Measured on Nextcloud 35 at 1280 x 800: the window's top bar (44 px)
        // and bottom margin (8 px), the view's title down to the list's box
        // (83 px), the bar (52 px), the paging and its gap (56 px), borders
        const room = height - 44 - 8 - 83 - 52 - 56 - 2;
        const { box } = parts(id);
        const maxHeight = px(cascadedDeclarations(box, 'light', WIDE)['max-height'], height);

        expect(maxHeight).toBeLessThanOrEqual(room);
        // and still shows a good run of rows (about 49 px each)
        expect(maxHeight).toBeGreaterThanOrEqual(room - 20);
    });

    it('keeps a useful height in a short window', () => {
        const { box } = parts(id);
        expect(px(cascadedDeclarations(box, 'light', WIDE)['max-height'], 400)).toBeGreaterThanOrEqual(240);
    });

    it('keeps a row the keyboard moves to out from under the header row', () => {
        // KeyboardShortcuts scrolls the cursor row into view ('nearest'),
        // which would leave a row above it under the sticky header
        const { box } = parts(id);
        const padding = cascadedDeclarations(box, 'light', WIDE)['scroll-padding-top'];

        expect(padding).toBeDefined();
        // The list's header row is 49 px high, the register's 43 px
        expect(px(padding, 800)).toBeGreaterThanOrEqual(49);
    });

    for (const theme of THEMES) {
        it(`gives the header cells an opaque fill in ${theme}, above the rows scrolled under them`, () => {
            useTheme(theme);
            const { cells } = parts(id);

            for (const cell of cells) {
                const declared = cascadedDeclarations(cell, theme, WIDE);
                expect(declared.background).toBe(fill);
                expect(Number(declared['z-index'])).toBeGreaterThan(1);
            }
        });
    }

    it('leaves the paging, and the list\'s bar, outside the box', () => {
        const { box } = parts(id);
        const bars = id === 'transactions-table'
            ? ['#transactions-view .table-header', '#column-config-dropdown', '#transactions-view .pagination-bottom']
            : ['#account-transactions-pagination'];

        for (const selector of bars) {
            const bar = document.querySelector(selector);
            expect(bar).not.toBeNull();
            expect(box.contains(bar)).toBe(false);
            expect(scroller(bar, WIDE)).toBeNull();
        }
    });
});

describe('the Transactions list\'s header row line', () => {
    // The table's borders are collapsed, and a collapsed border belongs to
    // the table, not the cell: it stayed where the row started and the rows
    // showed through the gap under the stuck header (seen in Chromium)
    it('is drawn inside the header cells, so it moves with them', () => {
        for (const th of document.querySelectorAll('#transactions-table thead th')) {
            const declared = cascadedDeclarations(th, 'light', WIDE);
            expect(declared['border-bottom'] ?? 'none').toMatch(/^(none|0)$/);
            expect(declared['box-shadow']).toMatch(/^inset 0 -2px 0 var\(--color-border\)/);
        }
    });
});

describe('the Transactions list\'s Actions header cell above phone width', () => {
    it('sticks to the box\'s right edge and its top, above the other header cells', () => {
        const th = document.querySelector('#transactions-table th.actions-column');
        const other = document.querySelector('#transactions-table th.date-column');
        const declared = cascadedDeclarations(th, 'light', WIDE);

        expect(declared).toMatchObject({ position: 'sticky', top: '0', right: '0' });
        expect(Number(declared['z-index'])).toBeGreaterThan(Number(cascadedDeclarations(other, 'light', WIDE)['z-index']));
    });
});

describe.each(TABLES)('%s on a phone', (_label, id) => {
    it('leaves the box as it was: no height limit, scrolling sideways only', () => {
        const { box } = parts(id);
        const declared = cascadedDeclarations(box, 'light', PHONE);

        expect(declared['max-height'] ?? 'none').toBe('none');
        expect(overflow(box, 'x', PHONE)).toBe('auto');
        expect(['visible', undefined]).toContain(declared['overflow-y']);
    });
});

describe('an account\'s register paging', () => {
    // The register sits under the account's details, so the page scrolls to
    // it, and where the box's rows start scrolling depends on where the
    // pointer was. When they reached the end the paging could still be
    // under the window's edge (8 px at 1280 x 800, more with the pointer
    // lower down). It sticks to the bottom of the page's view instead, so
    // it's on screen whenever the register is, and moves on with the page.
    const paging = () => document.getElementById('account-transactions-pagination');

    it('sticks to the bottom of the view above phone width, outside the rows\' box', () => {
        const declared = cascadedDeclarations(paging(), 'light', WIDE);

        expect(declared).toMatchObject({ position: 'sticky', bottom: '0' });
        // Nothing round it scrolls short of the page, so the page's view is what it sticks to
        expect(scroller(paging(), WIDE)).toBeNull();
    });

    for (const theme of THEMES) {
        it(`has an opaque fill in ${theme}, above the rows and the header row it can cover`, () => {
            useTheme(theme);
            const declared = cascadedDeclarations(paging(), theme, WIDE);
            const th = cascadedDeclarations(document.querySelector('#account-transactions-table th'), theme, WIDE);

            expect(declared.background).toBe('var(--color-main-background)');
            expect(Number(declared['z-index'])).toBeGreaterThan(Number(th['z-index']));
        });
    }

    it('is left as it was on a phone', () => {
        expect(cascadedDeclarations(paging(), 'light', PHONE).position ?? 'static').toBe('static');
    });
});
