/**
 * Phone layouts that the stylesheet's own order used to cancel.
 *
 * The Accounts page's one-column phone rules sat with the dashboard's media
 * queries near the top of css/style.css, before the page's base rules. A
 * media query adds no specificity, so the later base rule
 * (`grid-template-columns: repeat(3, 1fr)`) won at every width: a phone
 * showed three 114px account cards and a summary row 734px wide. The Budget
 * page's column header was hidden on phones by CSS, but the page set it to
 * `display: grid` inline. The Categories toolbar didn't wrap, so German
 * labels ran it off the screen.
 *
 * jsdom applies no media queries, so this resolves the cascade itself: for
 * one selector and property, the last declaration whose media query matches
 * the width wins (the selectors compared are identical, so specificity is
 * equal).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const CSS = fs.readFileSync(path.resolve(__dirname, '../../css/style.css'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/** Every rule as {media, selectors, declarations}, in source order. */
function parse(text, media = null, out = []) {
    let i = 0;
    while (i < text.length) {
        const open = text.indexOf('{', i);
        if (open === -1) break;
        const head = text.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (j < text.length && depth) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}') depth--;
            j++;
        }
        const body = text.slice(open + 1, j - 1);
        if (head.startsWith('@media')) {
            parse(body, head, out);
        } else if (!head.startsWith('@')) {
            const declarations = {};
            body.split(';').forEach(d => {
                const k = d.indexOf(':');
                if (k > 0) declarations[d.slice(0, k).trim()] = d.slice(k + 1).trim();
            });
            out.push({ media, selectors: head.split(',').map(s => s.trim().replace(/\s+/g, ' ')), declarations });
        }
        i = j;
    }
    return out;
}

const RULES = parse(CSS);

function matches(media, width) {
    if (!media) return true;
    if (!/^@media\s*\(\s*(max|min)-width/.test(media) && !/and \(/.test(media)) return false;
    const max = media.match(/max-width:\s*(\d+)px/);
    const min = media.match(/min-width:\s*(\d+)px/);
    if (/hover|prefers|height/.test(media)) return false;
    return (!max || width <= +max[1]) && (!min || width >= +min[1]);
}

/** The value of `property` for `selector` at a viewport `width`. */
function winning(selector, property, width) {
    let value;
    RULES.forEach(rule => {
        if (rule.selectors.includes(selector) && property in rule.declarations && matches(rule.media, width)) {
            value = rule.declarations[property];
        }
    });
    return value;
}

describe('Accounts page columns', () => {
    it('are one column on a phone', () => {
        expect(winning('.accounts-grid', 'grid-template-columns', 390)).toBe('1fr');
        expect(winning('.accounts-summary-header', 'grid-template-columns', 390)).toBe('1fr');
    });

    it('are two on a tablet and three on a desktop', () => {
        expect(winning('.accounts-grid', 'grid-template-columns', 1000)).toBe('repeat(2, 1fr)');
        expect(winning('.accounts-grid', 'grid-template-columns', 1400)).toBe('repeat(3, 1fr)');
        expect(winning('.accounts-summary-header', 'grid-template-columns', 1400)).toBe('repeat(3, 1fr)');
    });
});

describe('Rows of buttons on a phone', () => {
    it('wrap rather than run off the screen', () => {
        expect(winning('.categories-actions', 'flex-wrap', 390)).toBe('wrap');
        // German's "Bezahlt kennzeichnen" pushed Delete off a bill card
        expect(winning('.bill-actions', 'flex-wrap', 390)).toBe('wrap');
        expect(winning('.income-actions', 'flex-wrap', 390)).toBe('wrap');
    });
});

describe('Help page system information', () => {
    it('keeps a long value inside the box', () => {
        expect(winning('.system-info-table', 'table-layout', 390)).toBe('fixed');
    });
});

describe('Budget page column header', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('is hidden on phones by the stylesheet', () => {
        expect(winning('.budget-tree-header', 'display', 390)).toBe('none');
        expect(winning('.budget-tree-header', 'display', 1400)).toBe('grid');
    });

    it('is not forced visible by the page', async () => {
        vi.doMock('@nextcloud/l10n', () => ({
            translate: (_app, text) => text,
            translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
        }));
        const { default: CategoriesModule } = await import('../../src/modules/categories/CategoriesModule.js');
        document.body.innerHTML = `
            <div class="budget-tree-header" style="display: none;"></div>
            <div id="budget-tree"></div>
            <div id="empty-budget"></div>`;
        const mod = Object.create(CategoriesModule.prototype);
        mod.app = { settings: {} };
        mod.budgetType = 'expense';
        mod.categorySpending = {};
        mod._budgetTree = [{ id: 1, name: 'Food', type: 'expense', budgetAmount: 100, budgetPeriod: 'monthly', children: [] }];
        mod.formatCurrency = (v) => String(v);

        mod.renderBudgetTree();

        // Left to the stylesheet: a grid on wider screens, hidden on a phone
        expect(document.querySelector('.budget-tree-header').style.display).toBe('');
    });
});
