/**
 * The dashboard's Budget Progress tile drew its bars 0px tall at every size.
 * The shared `.budget-progress-bar { flex: 1 }` stretches a bar along the
 * Budget page's rows, but the tile stacks a header and the bar down a flex
 * column, where `flex: 1 1 0%` makes the bar's height 0.
 *
 * jsdom lays nothing out, so this resolves the stylesheet's cascade for the
 * tile's own markup: the declarations whose selectors match the element, by
 * specificity, then source order, with the media queries of the width given.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [],
        categories: [],
        dashboardConfig: { widgets: { tileSettings } },
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

const REPORT = [
    { categoryId: 1, categoryName: 'Groceries', type: 'expense', budgeted: 300, spent: 155.25 },
    { categoryId: 2, categoryName: 'Fuel', type: 'expense', budgeted: 80, spent: 40 },
    { categoryId: 3, categoryName: 'Gym', type: 'expense', budgeted: 108.33, spent: 20 },
    { categoryId: 4, categoryName: 'Holidays', type: 'expense', budgeted: 200, spent: 120 },
    { categoryId: 5, categoryName: 'Car', type: 'expense', budgeted: 100, spent: 0 },
    { categoryId: 6, categoryName: 'Salary', type: 'income', budgeted: 2000, spent: -2000 },
];

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

// ---- The stylesheet's cascade for the tile's bar ------------------------

const CSS = fs.readFileSync(path.resolve(__dirname, '../../css/style.css'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

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
            out.push({ media, selectors: head.split(',').map(s => s.trim()), declarations });
        }
        i = j;
    }
    return out;
}

const RULES = parse(CSS);

function mediaMatches(media, width) {
    if (!media) return true;
    if (/hover|prefers|height|print|orientation/.test(media)) return false;
    const max = media.match(/max-width:\s*(\d+)px/);
    const min = media.match(/min-width:\s*(\d+)px/);
    return (!max || width <= +max[1]) && (!min || width >= +min[1]);
}

function specificity(selector) {
    const s = selector.replace(/::[\w-]+/g, '');
    return [
        (s.match(/#[\w-]+/g) || []).length,
        (s.match(/\.[\w-]+|\[[^\]]*\]|:(?!not)[\w-]+/g) || []).length,
        (s.replace(/[#.:][\w-]+|\[[^\]]*\]/g, '').match(/(^|[\s>+~(])[a-z][\w-]*/gi) || []).length,
    ];
}

const higher = (a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2];

/** The winning `property` on `element` at a viewport `width`. */
function resolved(element, property, width) {
    let best = null;
    RULES.forEach((rule, order) => {
        if (!(property in rule.declarations) || !mediaMatches(rule.media, width)) return;
        rule.selectors.forEach(selector => {
            let hit = false;
            try { hit = element.matches(selector); } catch (e) { /* a selector jsdom can't parse */ }
            if (!hit) return;
            const spec = specificity(selector);
            if (!best || higher(spec, best.spec) > 0 || (higher(spec, best.spec) === 0 && order >= best.order)) {
                best = { spec, order, value: rule.declarations[property] };
            }
        });
    });
    return best?.value;
}

describe('the Budget Progress tile\'s bars', () => {
    it('keep their height down the tile\'s column, at any width', () => {
        document.body.innerHTML = '<div class="dashboard-widget"><div id="budget-progress" class="budget-widget"></div></div>';
        makeDashboard().updateBudgetProgressWidget(REPORT);
        const bar = document.querySelector('#budget-progress .budget-widget-item .budget-progress-bar');

        expect(bar).not.toBeNull();
        for (const width of [1400, 390]) {
            expect(resolved(bar, 'flex', width)).toBe('none');
            expect(resolved(bar, 'height', width)).toBe('8px');
        }
    });

    it('still stretch along a row elsewhere', () => {
        document.body.innerHTML = '<div class="budget-progress-wrapper"><div class="budget-progress-bar"></div></div>';

        expect(resolved(document.querySelector('.budget-progress-bar'), 'flex', 1400)).toBe('1');
    });
});
