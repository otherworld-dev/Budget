/**
 * The Transactions list and an account's register on a laptop screen (#399,
 * browser round 2, linkform B2 and B6, csv bug 9).
 *
 * Both tables are table-layout: fixed with a width on every column but
 * Description, which got what the others left: 0 px in the list with an
 * account filter (Balance shown) at 1280 px and below, and 0 px in the
 * register at 1024 px. Each table now has a minimum width, the visible
 * columns plus a minimum for Description, so it scrolls sideways inside its
 * container instead. Above that width nothing changes.
 *
 * In the register the reference ran on the description's line in bold, its
 * Balance column cut "-437.23 CHF", and long category pills were cut
 * mid-word with no ellipsis.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { RULES, declarationsFor } from './helpers/stylesheet.js';
import { applyTransactionColumnVisibility } from '../../src/utils/transactionColumns.js';
import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';
import { PHONE_CARD_QUERY } from '../../src/utils/dom.js';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

const DESCRIPTION_MINIMUM = 180;
const ALL_SHOWN = { date: true, description: true, vendor: true, category: true, tags: true, amount: true, balance: true, account: true };

const px = (value) => {
    const match = /^(-?\d+(?:\.\d+)?)px$/.exec(String(value ?? '').trim());
    return match ? parseFloat(match[1]) : null;
};

/** The table's custom properties, as the rules that match it declare them (later rules win). */
function customProperties(el) {
    return Object.fromEntries(Object.entries(declarationsFor(el)).filter(([key]) => key.startsWith('--')));
}

/** The table's min-width above phone width, as px: its calc() of var()s worked out. */
function minWidth(table) {
    const rule = RULES.find(r => r.media && r.media.includes('min-width: 641px')
        && r.selectors.some(s => table.matches(s)) && 'min-width' in r.declarations);
    if (!rule) return null;
    const resolved = resolveVars(rule.declarations['min-width'], table);
    const terms = resolved.replace(/^calc\(|\)$/g, '').split('+').map(term => px(term));
    return terms.reduce((sum, term) => sum + term, 0);
}

/** What the visible header cells other than Description are declared to take. */
function otherColumnsWidth(table) {
    return [...table.querySelectorAll('thead th')]
        .filter(th => th.style.display !== 'none' && !th.classList.contains('description-column'))
        .reduce((sum, th) => sum + px(resolveVars(declarationsFor(th).width, table)), 0);
}

/** A value with its var()s taken from the table's custom properties. */
function resolveVars(value, table) {
    const vars = customProperties(table);
    return String(value ?? '').replace(/var\((--[\w-]+)\)/g, (m, name) => vars[name] ?? 'NaN');
}

function registerRow(tx) {
    const table = document.getElementById('account-transactions-table');
    table.querySelector('tbody').innerHTML = renderTransactionRow({
        id: 1, accountId: 3, date: '2026-10-12', amount: 12.4, type: 'debit', ...tx,
    }, {
        variant: 'register',
        accounts: [{ id: 3, name: 'Lohnkonto' }],
        categories: [{ id: 9, name: 'Modellbau / Renovation' }],
        currency: 'CHF',
        formatCurrency: (v, c) => `${Number(v).toFixed(2)} ${c}`,
        formatDate: (d) => d,
        balance: -437.23,
    });
    return table.querySelector('tbody tr');
}

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the Transactions list', () => {
    const table = () => document.getElementById('transactions-table');

    it('keeps Description at a usable width by a minimum on the table', () => {
        applyTransactionColumnVisibility(table(), ALL_SHOWN, { hasBalances: true });

        // 24 + 90 + 150 + 120 + 100 + 140 + 140 + 120 + 100 = 984 in the report
        expect(otherColumnsWidth(table())).toBe(984);
        expect(minWidth(table())).toBe(984 + DESCRIPTION_MINIMUM);
    });

    it('gives Description no width of its own, so wide screens are unchanged', () => {
        const th = table().querySelector('th.description-column');

        expect(declarationsFor(th).width ?? 'auto').toBe('auto');
        expect(declarationsFor(table()).width).toBe('100%');
    });

    it('leaves Balance out of the minimum without an account filter', () => {
        applyTransactionColumnVisibility(table(), ALL_SHOWN, { hasBalances: false });

        expect(table().classList.contains('hides-balance')).toBe(true);
        expect(minWidth(table())).toBe(984 - 140 + DESCRIPTION_MINIMUM);
    });

    it.each([
        ['tags and account', { tags: false, account: false }],
        ['date and vendor', { date: false, vendor: false }],
        ['category and amount', { category: false, amount: false }],
        ['everything but description', { date: false, vendor: false, category: false, tags: false, amount: false, balance: false, account: false }],
    ])('leaves columns hidden through Configure columns out of it: %s', (_label, hidden) => {
        applyTransactionColumnVisibility(table(), { ...ALL_SHOWN, ...hidden }, { hasBalances: true });

        expect(minWidth(table())).toBe(otherColumnsWidth(table()) + DESCRIPTION_MINIMUM);
    });

    it('asks no minimum for Description when Description is hidden', () => {
        applyTransactionColumnVisibility(table(), { ...ALL_SHOWN, description: false }, { hasBalances: true });

        expect(table().querySelector('th.description-column').style.display).toBe('none');
        expect(minWidth(table())).toBe(otherColumnsWidth(table()));
    });

    it('drops a column\'s class again when it is shown again', () => {
        applyTransactionColumnVisibility(table(), { ...ALL_SHOWN, tags: false }, { hasBalances: true });
        applyTransactionColumnVisibility(table(), ALL_SHOWN, { hasBalances: true });

        expect([...table().classList].filter(c => c.startsWith('hides-'))).toEqual([]);
        expect(table().querySelector('th.tags-column').style.display).toBe('');
        expect(minWidth(table())).toBe(984 + DESCRIPTION_MINIMUM);
    });

    it('scrolls sideways inside its container', () => {
        // In a wrapper of its own, so the bars round it stay put (transactionListBar.test.js)
        expect(table().parentElement.classList.contains('table-scroll')).toBe(true);
        expect(table().parentElement.parentElement.classList.contains('table-container')).toBe(true);
        expect(declarationsFor(table().parentElement)['overflow-x']).toBe('auto');
    });
});

describe('the account register', () => {
    const table = () => document.getElementById('account-transactions-table');

    it('keeps Description at a usable width by a minimum on the table', () => {
        expect(declarationsFor(table().querySelector('th.description-column')).width).toBe('auto');
        expect(minWidth(table())).toBe(otherColumnsWidth(table()) + DESCRIPTION_MINIMUM);
    });

    it('scrolls sideways inside its container instead of running past it', () => {
        const container = table().parentElement;

        expect(container.classList.contains('table-scroll')).toBe(true);
        expect(container.parentElement.classList.contains('transactions-container')).toBe(true);
        expect(declarationsFor(container)['overflow-x']).toBe('auto');
    });

    it('has a Balance column wide enough for "-437.23 CHF"', () => {
        // The report measured that text at 104 px; the cell's padding is 10 px a side
        const th = table().querySelector('th.balance-column');
        const cell = declarationsFor(th);

        expect(px(resolveVars(cell.width, table())) - 2 * px(declarationsFor(registerRow({}).querySelector('td')).padding)).toBeGreaterThanOrEqual(110);
    });

    it('puts the reference on its own muted line under the description', () => {
        const row = registerRow({ description: 'Wassertank Zubehör', reference: 'Kassenbon 4471-0093' });
        const box = row.querySelector('.description-column .transaction-description');
        const main = box.querySelector('.description-main');
        const reference = box.querySelector('.secondary-text');

        expect(reference.textContent).toBe('Kassenbon 4471-0093');
        expect(declarationsFor(box)).toMatchObject({ display: 'flex', 'flex-direction': 'column' });
        expect(declarationsFor(reference)).toMatchObject({
            color: 'var(--color-text-maxcontrast)',
            'font-weight': 'normal',
            overflow: 'hidden',
            'text-overflow': 'ellipsis',
            'white-space': 'nowrap',
        });
        expect(declarationsFor(main)).toMatchObject({ overflow: 'hidden', 'text-overflow': 'ellipsis', 'white-space': 'nowrap' });
    });

    it('ends a long category pill with an ellipsis and names it in full on hover', () => {
        const pill = registerRow({ categoryId: 9 }).querySelector('.category-name');

        expect(pill.textContent).toBe('Modellbau / Renovation');
        expect(pill.getAttribute('title')).toBe('Modellbau / Renovation');
        expect(declarationsFor(pill)).toMatchObject({
            'max-width': '100%',
            overflow: 'hidden',
            'text-overflow': 'ellipsis',
            'white-space': 'nowrap',
        });
    });

    it('gives an uncategorized row no title', () => {
        const pill = registerRow({ categoryId: null }).querySelector('.category-name');

        expect(pill.textContent).toBe('Uncategorized');
        expect(pill.hasAttribute('title')).toBe(false);
    });
});

describe('phones', () => {
    it('keep their card layout: the minimum widths start above phone width', () => {
        expect(PHONE_CARD_QUERY).toBe('(max-width: 640px)');
        const rules = RULES.filter(r => 'min-width' in r.declarations
            && r.selectors.some(s => /^#(account-)?transactions-table$/.test(s)));

        expect(rules.length).toBeGreaterThanOrEqual(2);
        expect(rules.every(r => r.media && r.media.includes('min-width: 641px'))).toBe(true);
    });
});
