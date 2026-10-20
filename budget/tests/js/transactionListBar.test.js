/**
 * The bars round the Transactions list and an account's register (#399,
 * round 6). Below about 1440 px the table is wider than its box and
 * scrolls sideways, and the bar above it (the count, rows per page,
 * paging) and the paging below it were inside the same scrolling box, so
 * they slid out of view with the table. Only a wrapper round the table
 * scrolls now; the bars stay put.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { cascadedDeclarations, THEMES, useTheme } from './helpers/cascade.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

const WIDE = { media: ['(min-width: 641px)'] };
const PHONE = { media: ['(max-width: 640px)', '(max-width: 768px)', '(max-width: 1024px)'] };

/** Whether the stylesheet makes `el` scroll (or clip) sideways at this width */
function scrollsSideways(el, options) {
    const declared = cascadedDeclarations(el, 'light', options);
    const value = declared['overflow-x'] ?? String(declared.overflow ?? '').split(/\s+/)[0];
    return ['auto', 'scroll', 'hidden', 'clip'].includes(value);
}

/**
 * The nearest element round `el` inside the view that scrolls sideways, or
 * null. #app-content, which scrolls the whole page, is never wider than the
 * window, so it is left out.
 */
function sidewaysScroller(el, options) {
    for (let node = el.parentElement; node && node.id !== 'app-content'; node = node.parentElement) {
        if (scrollsSideways(node, options)) return node;
    }
    return null;
}

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe.each([
    ['above phone width', WIDE],
    ['on a phone', PHONE],
])('the Transactions list %s', (_label, options) => {
    const table = () => document.getElementById('transactions-table');
    const container = () => document.querySelector('#transactions-view .table-container');

    it('scrolls the table sideways in a wrapper of its own inside the bordered box', () => {
        const scroller = sidewaysScroller(table(), options);

        expect(scroller).not.toBeNull();
        expect(scroller).not.toBe(container());
        expect(container().contains(scroller)).toBe(true);
        expect([...scroller.children]).toEqual([table()]);
    });

    it('keeps the bars outside it, with nothing round them scrolling sideways', () => {
        const scroller = sidewaysScroller(table(), options);
        const bars = [
            '#transactions-view .table-header',
            '#transactions-count',
            '#transactions-total',
            '#column-config-btn',
            '#column-config-dropdown',
            '#rows-per-page',
            '#prev-page-btn',
            '#page-info',
            '#next-page-btn',
            '#transactions-view .pagination-bottom',
            '#prev-page-btn-bottom',
            '#page-info-bottom',
            '#next-page-btn-bottom',
        ].map(selector => document.querySelector(selector));

        for (const bar of bars) {
            expect(bar).not.toBeNull();
            expect(scroller.contains(bar)).toBe(false);
            expect(sidewaysScroller(bar, options)).toBeNull();
        }
    });

    it('keeps the bars in the bordered box, above and below the table', () => {
        const children = [...container().children];
        const scroller = sidewaysScroller(table(), options);

        expect(children[0].classList.contains('table-header')).toBe(true);
        expect(children.indexOf(scroller)).toBe(1);
        expect(children[2].classList.contains('pagination-bottom')).toBe(true);
    });
});

describe('the Transactions list\'s bordered box', () => {
    it('is still where the reconciliation banner goes in front of', () => {
        // TransactionsModule.showReconcileResumeBanner inserts before it
        const container = document.querySelector('#transactions-view .table-container');
        expect(container.parentElement.id).toBe('transactions-view');
    });

    for (const theme of THEMES) {
        it(`rounds the bar's top corners itself in ${theme}, now the box no longer clips it`, () => {
            useTheme(theme);
            const bar = document.querySelector('#transactions-view .table-header');
            const declared = cascadedDeclarations(bar, theme, WIDE);

            expect(declared.background).toBe('var(--color-background-hover)');
            expect(declared['border-radius']).toMatch(/^calc\(var\(--border-radius-small, 4px\) - 1px\) calc\(var\(--border-radius-small, 4px\) - 1px\) 0 0$/);
        });
    }

    it('keeps the sticky Actions column and header row pinned within the table\'s own scroller', () => {
        const th = document.querySelector('#transactions-table th.actions-column');
        const td = document.createElement('td');
        td.className = 'actions-column';
        const row = document.createElement('tr');
        row.className = 'transaction-row';
        row.appendChild(td);
        document.querySelector('#transactions-table tbody').appendChild(row);

        expect(cascadedDeclarations(th, 'light', WIDE)).toMatchObject({ position: 'sticky', top: '0', right: '0' });
        expect(cascadedDeclarations(td, 'light', WIDE)).toMatchObject({ position: 'sticky', right: '0' });
        // Nothing between the cells and the wrapper scrolls, so the wrapper is what they stick to
        expect(sidewaysScroller(th, WIDE).classList.contains('table-scroll')).toBe(true);
        expect(sidewaysScroller(td, WIDE).classList.contains('table-scroll')).toBe(true);
    });
});

describe.each([
    ['above phone width', WIDE],
    ['on a phone', PHONE],
])('an account\'s register %s', (_label, options) => {
    const table = () => document.getElementById('account-transactions-table');

    it('scrolls the table sideways in a wrapper of its own', () => {
        const scroller = sidewaysScroller(table(), options);

        expect(scroller).not.toBeNull();
        expect(scroller.classList.contains('transactions-container')).toBe(false);
        expect(scroller.closest('.account-transactions-section .transactions-container')).not.toBeNull();
        expect([...scroller.children]).toEqual([table()]);
    });

    it('keeps its paging outside it, with nothing round it scrolling sideways', () => {
        const scroller = sidewaysScroller(table(), options);

        for (const selector of ['#account-transactions-pagination', '#account-prev-page', '#account-page-info', '#account-next-page']) {
            const bar = document.querySelector(selector);
            expect(bar).not.toBeNull();
            expect(scroller.contains(bar)).toBe(false);
            expect(sidewaysScroller(bar, options)).toBeNull();
        }
    });
});

describe('the Transactions list\'s bordered box, from top to bottom', () => {
    // It took the whole view's height (#280), to give the column menu and the
    // inline pickers room below a short table. The view is a stretched flex
    // item, so with the filters open the box ran on past the paging by their
    // height, an empty bordered area (#399, browser round 6). The menu is
    // outside the scrolling box now, and the pickers are fixed to the window
    // (inlineEditorPopups.test.js), so the box ends at its content.
    it.each([
        ['above phone width', WIDE],
        ['on a phone', PHONE],
    ])('ends at its content %s', (_label, options) => {
        const container = document.querySelector('#transactions-view .table-container');
        const declared = cascadedDeclarations(container, 'light', options);

        expect(declared.height ?? 'auto').toBe('auto');
        expect(declared['min-height'] ?? '0').toMatch(/^(0|auto)$/);
        expect(declared.flex ?? '').not.toMatch(/^1/);
    });

    it('leaves the column menu outside anything that scrolls, so a short table doesn\'t cut it off', () => {
        const menu = document.getElementById('column-config-dropdown');
        for (const options of [WIDE, PHONE]) {
            for (let node = menu.parentElement; node && node.id !== 'app-content'; node = node.parentElement) {
                expect(scrollsSideways(node, options)).toBe(false);
            }
        }
    });
});
