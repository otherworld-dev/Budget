/**
 * The Transactions list's Actions column at 1440 px and below (#399,
 * browser round 3). Since Description has a minimum width the table
 * scrolls sideways inside its container, and with Balance shown the
 * Actions column (edit, the ⋮ menu) sat past the right edge. It now
 * stays pinned to the container's right edge, header and cells, on an
 * opaque fill so the scrolled columns don't show through, above phone
 * width only (phones show the rows as cards).
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { cascadedDeclarations, useTheme, THEMES } from './helpers/cascade.js';

const WIDE = { media: ['(min-width: 641px)'] };

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transactions-view"><div class="table-container"><div class="table-scroll">
            <table id="transactions-table" class="transactions-table enhanced">
                <thead><tr><th class="description-column"></th><th class="actions-column"></th></tr></thead>
                <tbody>
                    <tr class="transaction-row" id="plain"><td class="description-column"></td><td class="actions-column"></td></tr>
                    <tr class="transaction-row is-linked" id="linked"><td class="description-column"></td><td class="actions-column"></td></tr>
                    <tr class="transaction-row scheduled-transaction" id="scheduled"><td class="description-column"></td><td class="actions-column"><div class="transaction-actions"></div></td></tr>
                    <tr class="transaction-row kbd-cursor" id="cursor"><td class="description-column"></td><td class="actions-column"></td></tr>
                </tbody>
            </table>
        </div></div></div>`;
});

afterEach(() => {
    document.body.innerHTML = '';
});

const header = () => document.querySelector('#transactions-table th.actions-column');
const cell = (row) => document.querySelector(`#${row} td.actions-column`);

describe('the Transactions list\'s Actions column above phone width', () => {
    it('stays at the right edge, header and cells', () => {
        for (const el of [header(), cell('plain')]) {
            const declared = cascadedDeclarations(el, 'light', WIDE);
            expect(declared.position).toBe('sticky');
            expect(declared.right).toBe('0');
        }
    });

    it('keeps the header on top of the page as well, above the other header cells', () => {
        const actions = cascadedDeclarations(header(), 'light', WIDE);
        const other = cascadedDeclarations(document.querySelector('th.description-column'), 'light', WIDE);
        expect(actions.top).toBe('0');
        expect(Number(actions['z-index'])).toBeGreaterThan(Number(other['z-index']));
    });

    it('sits above the cells scrolled under it', () => {
        const declared = cascadedDeclarations(cell('plain'), 'light', WIDE);
        expect(Number(declared['z-index'])).toBeGreaterThan(0);
    });

    for (const theme of THEMES) {
        it(`has an opaque fill in ${theme}`, () => {
            useTheme(theme);
            expect(cascadedDeclarations(cell('plain'), theme, WIDE).background).toBe('var(--color-main-background)');
            expect(cascadedDeclarations(header(), theme, WIDE).background).toBe('var(--color-background-hover)');
        });

        it(`follows the row's hover in ${theme}`, () => {
            useTheme(theme);
            expect(cascadedDeclarations(cell('plain'), theme, { ...WIDE, hover: true }).background)
                .toBe('var(--color-background-hover)');
        });
    }

    it('follows the keyboard cursor row', () => {
        expect(cascadedDeclarations(cell('cursor'), 'light', WIDE).background).toBe('var(--color-background-hover)');
    });

    it('keeps a linked row\'s tint, on an opaque base', () => {
        expect(cascadedDeclarations(cell('linked'), 'light', WIDE).background).toBe('var(--color-primary-element-light)');
        expect(cascadedDeclarations(cell('linked'), 'light', { ...WIDE, hover: true }).background)
            .toMatch(/, var\(--color-main-background\)$/);
    });

    it('fades a scheduled row\'s buttons, not the cell, so nothing shows through it', () => {
        expect(cascadedDeclarations(cell('scheduled'), 'light', WIDE).opacity).toBe('1');
        const inner = document.querySelector('#scheduled .transaction-actions');
        expect(cascadedDeclarations(inner, 'light', WIDE).opacity).toBe('0.65');
    });
});

describe('on a phone', () => {
    it('leaves the Actions column where the card puts it', () => {
        expect(cascadedDeclarations(cell('plain'), 'light').position).not.toBe('sticky');
        expect(cascadedDeclarations(header(), 'light').right).toBeUndefined();
    });
});
