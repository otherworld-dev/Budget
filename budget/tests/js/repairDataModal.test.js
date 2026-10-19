/**
 * Settings > Repair Data's report, as the #399 browser checks found it:
 *
 * - The count badges were white on Nextcloud's own pale --color-error
 *   (#FFE7E7) in the light theme. The modal is appended to <body>, outside
 *   #app-content, where the app puts back its darker status shades.
 * - Each category's tick rendered 13x36 and pushed the title below it:
 *   Nextcloud gives every input a 36px height, a 34px minimum, a margin and a
 *   white background.
 * - Dates broke after their hyphens ('2026-10-' / '15') and amounts at the
 *   space, as the long note beside them took the row's width.
 * - A payment with no vendor was listed as '(unnamed)', though it has a name.
 * - The new message after a repair, '1 incomes moved on to their next date',
 *   had no singular.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const fill = (text, params = {}, options = {}) => String(text).replace(/\{(\w+)\}/g,
        (m, k) => (k in params ? (options?.escape === false ? String(params[k]) : esc(params[k])) : m));
    return {
        translate: (_app, text, params, _count, options) => fill(text, params, options),
        // As the library does: the form for the count, %n filled with it
        translatePlural: (_app, singular, plural, count, params, options) =>
            fill((count === 1 ? singular : plural).replace(/%n/g, String(count)), params, options),
        getLanguage: () => 'en',
        getLocale: () => 'en',
    };
});

import { duplicateItem, repairResultMessage, stuckIncomeItem } from '../../src/utils/repairFindings.js';
import { RULES, rulesMatching, declarationsFor } from './helpers/stylesheet.js';

const chf = (amount) => `${Number(amount).toFixed(2)} CHF`;

/** The reporter's March double: a 2.x job row of a deleted bill, no vendor */
const christian = (overrides = {}) => ({
    duplicateId: 1599732, originalId: 1599850, amount: 91.58, originalAmount: 91.59,
    date: '2026-03-26', originalDate: '2026-03-28', vendor: null,
    originalDescription: 'Christian Uhrenschmuck24', originalReconciled: false,
    accountId: 11580, accountName: 'Lohnkonto', billId: null,
    reason: 'auto_gen_duplicates_manual', selected: false,
    ...overrides,
});

const cembra = (overrides = {}) => ({
    duplicateId: 1600840, originalId: 1600677, amount: 151.67, originalAmount: 151.67,
    date: '2026-10-15', originalDate: '2026-09-27', vendor: 'CembraPay Temu',
    originalDescription: 'CembraPay Temu', originalReconciled: false,
    accountId: 11580, accountName: 'Lohnkonto', billId: 13963,
    reason: 'same_occurrence', selected: false, occurrence: '2026-09-28',
    billName: 'CembraPay Temu', transfer: false, removesDeposit: false,
    ...overrides,
});

/** A row as the modal holds it */
function inModal(html) {
    const modal = document.createElement('div');
    modal.id = 'repair-data-modal';
    modal.className = 'budget-modal-overlay';
    modal.innerHTML = `<div class="budget-modal"><div class="budget-modal-body">
        <div class="repair-category" data-category="duplicateTransactions">
            <div class="repair-category-header">
                <h4><input type="checkbox" class="repair-checkbox" checked> Duplicate Auto-Generated Transactions</h4>
                <span class="repair-category-count">14</span>
            </div>
            <div class="repair-category-details">${html}</div>
        </div>
    </div></div>`;
    document.body.appendChild(modal);
    return modal;
}

const label = (finding) => {
    const row = inModal(duplicateItem(finding, chf));
    return row.querySelector('.repair-item-select span').textContent;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('a duplicate payment row', () => {
    it('is named by its vendor', () => {
        expect(label(cembra())).toBe('CembraPay Temu');
    });

    it('with no vendor, goes by the name the other payment records it under', () => {
        // The scan pairs the two on the bill's name, so the other row's
        // description names this payment too
        expect(label(christian())).toBe('Christian Uhrenschmuck24');
    });

    it('with no vendor, goes by its bill\'s name first', () => {
        expect(label(cembra({ vendor: null, billName: 'CembraPay Temu', originalDescription: 'TEMU.COM 123' }))).toBe('CembraPay Temu');
    });

    it('is unnamed only when nothing names it', () => {
        expect(label(christian({ originalDescription: '' }))).toBe('(unnamed)');
    });

    it('shows a name as text', () => {
        const row = inModal(duplicateItem(christian({ originalDescription: '<img src=x onerror="window.__xss=1">' }), chf));

        expect(row.querySelector('img')).toBeNull();
        expect(row.querySelector('.repair-item-select span').textContent).toBe('<img src=x onerror="window.__xss=1">');
    });

    it('holds the tick, amount, date and note', () => {
        const row = inModal(duplicateItem(cembra({ selected: true }), chf)).querySelector('.repair-item');
        const tick = row.querySelector('input.repair-dup-checkbox');

        expect(tick.dataset.transactionId).toBe('1600840');
        expect(tick.checked).toBe(true);
        expect(row.querySelector('.repair-item-amount').textContent).toBe('151.67 CHF');
        expect(row.querySelector('.repair-item-date').textContent).toBe('2026-10-15');
        expect(row.querySelector('.repair-item-note').textContent)
            .toBe('Looks like a second payment for 2026-09-28 (other payment on 2026-09-27)');
        expect(inModal(duplicateItem(cembra(), chf)).querySelector('.repair-dup-checkbox').checked).toBe(false);
    });
});

describe('the message after a repair', () => {
    it('says one income moved on in the singular', () => {
        const message = repairResultMessage({ duplicateTransactions: { deleted: 1, found: 14 }, stuckIncome: { fixed: 1, found: 1 } });

        expect(message).toMatch(/^Repair complete: /);
        expect(message).toContain('1 income moved on to its next date');
    });

    it('says several incomes moved on in the plural', () => {
        expect(repairResultMessage({ stuckIncome: { fixed: 2, found: 2 } }))
            .toBe('Repair complete: 2 incomes moved on to their next date');
    });

    it('lists each repaired category in turn', () => {
        expect(repairResultMessage({
            duplicateTransactions: { deleted: 2, found: 12 },
            paidOneTimeBills: { fixed: 2, found: 2 },
        })).toBe('Repair complete: 2 duplicate transactions removed, 2 one-time bills deactivated');
    });
});

// ---------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------

/** What Nextcloud 32+ itself puts on <body>, measured on the dev instance */
const NEXTCLOUD = {
    light: { '--color-error': '#FFE7E7' },
    dark: { '--color-error': '#552121' },
};

/**
 * The custom properties inside the modal: Nextcloud's, then the app's for
 * the modal, as the theme picks them. 'system dark' is the default theme
 * following a dark system setting (its rules sit in a media query).
 */
function modalVars(theme) {
    const scopes = {
        light: ['#repair-data-modal'],
        dark: ['#repair-data-modal', 'body[data-theme-dark] #repair-data-modal'],
        'system dark': ['#repair-data-modal', 'body[data-theme-default] #repair-data-modal'],
    }[theme];
    const vars = { ...NEXTCLOUD[theme === 'light' ? 'light' : 'dark'] };
    for (const scope of scopes) {
        RULES.filter(rule => rule.selectors.includes(scope)).forEach(rule => {
            Object.entries(rule.declarations).filter(([k]) => k.startsWith('--')).forEach(([k, v]) => { vars[k] = v; });
        });
    }
    return vars;
}

/** A value with its var()s filled in, innermost first */
function resolve(value, vars) {
    let out = value;
    for (let i = 0; i < 10 && out.includes('var('); i++) {
        out = out.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*))?\)/g,
            (m, name, fallback) => vars[name] ?? (fallback !== undefined ? fallback.trim() : m));
    }
    return out;
}

function luminance(colour) {
    const hex = { white: '#ffffff', black: '#000000' }[colour.toLowerCase()] ?? colour;
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex);
    if (!m) throw new Error(`not a colour: ${colour}`);
    const full = m[1].length === 3 ? m[1].replace(/./g, c => c + c) : m[1];
    const [r, g, b] = [0, 2, 4].map(i => parseInt(full.slice(i, i + 2), 16) / 255)
        .map(c => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

const contrast = (a, b) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
};

/** Specificity of a selector without :not()/:is(), as one comparable number */
function specificity(selector) {
    const ids = (selector.match(/#[\w-]+/g) || []).length;
    const classes = (selector.match(/\.[\w-]+|\[[^\]]*\]|:(?!:)[\w-]+/g) || []).length;
    const types = (selector.replace(/#[\w-]+|\.[\w-]+|\[[^\]]*\]|::?[\w-]+/g, ' ').match(/[a-z][\w-]*/gi) || []).length;
    return ids * 10000 + classes * 100 + types;
}

describe('the Repair Data modal', () => {
    it.each(['light', 'dark', 'system dark'])('has readable count badges in the %s theme', (theme) => {
        const badge = inModal('').querySelector('.repair-category-count');
        const style = declarationsFor(badge);
        const vars = modalVars(theme);

        const background = resolve(style.background, vars);
        const text = resolve(style.color, vars);
        expect(contrast(background, text), `${text} on ${background}`).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps each category\'s tick on the title\'s line, at a tick\'s size', () => {
        const modal = inModal('');
        const title = modal.querySelector('.repair-category-header h4');
        const tick = modal.querySelector('.repair-category-header .repair-checkbox');

        expect(declarationsFor(title)).toMatchObject({ display: 'flex', 'align-items': 'center' });
        expect(declarationsFor(tick)).toMatchObject({ height: 'auto', 'min-height': '0', margin: '0' });
        // Over Nextcloud's own input rule, input:not([type="range"], .input-field__input, …): 0-1-1
        const sizing = rulesMatching(tick).filter(r => r.declarations['min-height'] === '0');
        expect(Math.max(...sizing.flatMap(r => r.selectors.filter(s => tick.matches(s)).map(specificity)))).toBeGreaterThan(101);
    });

    it('keeps dates and amounts whole', () => {
        const modal = inModal(duplicateItem(cembra(), chf) + stuckIncomeItem({
            incomeId: 9, name: 'Gutschrift SBB Abo Leon', amount: 100, transactionId: 1293,
            lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-09-29', settledNextExpectedDate: '2026-10-29',
        }, chf));
        const values = [...modal.querySelectorAll('.repair-item-amount, .repair-item-date')];

        expect(values.map(v => v.textContent)).toEqual(['151.67 CHF', '2026-10-15', '100.00 CHF', 'Received 2026-09-28']);
        values.forEach(v => expect(declarationsFor(v)['white-space'], v.textContent).toBe('nowrap'));
    });
});
