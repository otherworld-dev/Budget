/**
 * Bills by Month is wider than its card at 1440px and at 800px (1,449px of
 * table), and scrolling it sideways took the bill names with it, leaving
 * rows of amounts with nothing to say whose they were (#399). The name
 * column stays put on the left, header and footer included, on a solid
 * background so the months scroll under it rather than through it.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { declarationsFor } from './helpers/stylesheet.js';

/** Nextcloud's own colours for what the column may sit on: all solid. */
const SOLID = {
    light: { '--color-main-background': '#ffffff', '--color-background-hover': '#f5f5f5' },
    dark: { '--color-main-background': '#171717', '--color-background-hover': '#292929' },
};

/** Whether a background value is solid in a theme: a solid colour, or a mix of solid colours. */
function solid(value, theme) {
    if (!value) return false;
    const vars = [...value.matchAll(/var\((--[\w-]+)\)/g)].map(m => m[1]);
    if (/rgba|transparent|hsla/.test(value)) return false;
    if (/^color-mix\(/.test(value)) {
        return vars.length > 0 && vars.every(v => v in SOLID[theme]) && !/transparent/.test(value);
    }
    if (vars.length === 1) return vars[0] in SOLID[theme] && value.trim() === `var(${vars[0]})`;
    return /^#[0-9a-f]{6}$/i.test(value.trim());
}

const background = decl => decl['background-color'] ?? decl.background;

describe('the Bills by Month name column', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div class="table-responsive">
                <table class="data-table bills-calendar-table">
                    <thead><tr><th class="bill-name-col">Bill</th><th>Jan</th></tr></thead>
                    <tbody><tr><td class="bill-name-col">Rent</td><td class="month-cell has-bill paid">1,200.00</td></tr></tbody>
                    <tfoot>
                        <tr class="totals-row"><td class="bill-name-col">Monthly Totals</td><td class="total-cell">1,200.00</td></tr>
                        <tr class="totals-row balance-after-row"><td class="bill-name-col">Projected balance</td><td class="balance-cell">5.00</td></tr>
                    </tfoot>
                </table>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    const cells = () => ({
        header: document.querySelector('thead .bill-name-col'),
        name: document.querySelector('tbody .bill-name-col'),
        totals: document.querySelector('.totals-row:not(.balance-after-row) .bill-name-col'),
        balance: document.querySelector('.balance-after-row .bill-name-col'),
    });

    it('stays on the left while the months scroll', () => {
        for (const [what, cell] of Object.entries(cells())) {
            const decl = declarationsFor(cell);
            expect(decl.position, what).toBe('sticky');
            expect(decl['inset-inline-start'] ?? decl.left, what).toMatch(/^0(px)?$/);
            expect(Number(decl['z-index']), what).toBeGreaterThan(0);
        }
    });

    it('sits on a solid background in both themes, so the months pass under it', () => {
        for (const [what, cell] of Object.entries(cells())) {
            const value = background(declarationsFor(cell));
            expect(solid(value, 'light'), `${what}: ${value}`).toBe(true);
            expect(solid(value, 'dark'), `${what}: ${value}`).toBe(true);
        }
    });

    it('keeps the header above the names', () => {
        const { header, name } = cells();
        expect(Number(declarationsFor(header)['z-index'])).toBeGreaterThan(Number(declarationsFor(name)['z-index']));
    });

    it('leaves the month cells to scroll', () => {
        const month = document.querySelector('.month-cell');
        expect(declarationsFor(month).position).toBeUndefined();
    });
});
