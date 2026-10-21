/**
 * The Bills Calendar's heatmap view (#399).
 *
 * Switching View to Calendar Heatmap left the card empty until Generate was
 * pressed: the switch only swapped which container was shown, and nothing
 * had drawn the heatmap. It draws on the switch now, from the report already
 * loaded.
 *
 * Each month's box was painted red at up to 80% opacity, and its count was
 * grey rgb(107,107,107) on it, barely readable on the busiest months. The
 * fill is now a tint of the app's red over the page background, deeper the
 * more the month costs, and every line of text in it is the page's own text
 * colour, which reads at 4.5:1 or better on every fill in both themes.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { RULES } from './helpers/stylesheet.js';
import { contrastRatio, COLORBLIND } from '../../src/utils/amountColours.js';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/api.js', () => ({ apiFetch: vi.fn() }));

import { apiFetch } from '../../src/utils/api.js';
import ReportsModule from '../../src/modules/reports/ReportsModule.js';

const months = list => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, list.includes(i + 1)]));

const overview = {
    year: 2026,
    bills: [{
        name: 'Rent', amount: 1200, currency: 'CHF', frequency: 'monthly',
        occurrences: months([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]),
        paidMonths: [], paidAmounts: {}, paidDetails: {}, paidMoreThanOnce: {},
        unrecordedMonths: [], expectedAmounts: {}, owedAmounts: [],
    }],
    monthlyTotals: { 1: 1200, 2: 1200, 3: 1200, 4: 1200, 5: 1200, 6: 2400, 7: 1200, 8: 1200, 9: 1200, 10: 1200, 11: 1200, 12: 0 },
    account: null,
};

function module() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.reportCharts = {};
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    mod.renderBillsCalendarChart = vi.fn();
    return mod;
}

describe('switching the Bills Calendar to the heatmap', () => {
    beforeEach(() => {
        apiFetch.mockReset();
        apiFetch.mockResolvedValue(overview);
        document.body.innerHTML = `
            <div id="report-loading"></div>
            <select id="bills-calendar-year"><option value="2026" selected>2026</option></select>
            <select id="bills-calendar-account"><option value="">All Accounts</option></select>
            <select id="bills-calendar-status"><option value="active">Active</option></select>
            <input type="checkbox" id="bills-calendar-include-transfers" checked>
            <select id="bills-calendar-view"><option value="table" selected>Table</option><option value="heatmap">Heatmap</option></select>
            <button id="generate-bills-calendar-btn"></button>
            <div id="bills-calendar-chart-container"></div>
            <div id="bills-calendar-table-container">
                <table><tbody id="bills-calendar-table-body"></tbody><tfoot id="bills-calendar-table-footer"></tfoot></table>
            </div>
            <div id="bills-calendar-heatmap-container" style="display: none;">
                <div id="bills-calendar-heatmap"></div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('draws the heatmap straight away, from the report already loaded', async () => {
        const mod = module();
        mod.setupBillsCalendarControls();
        await mod.generateBillsCalendar();
        expect(document.querySelectorAll('.heatmap-month')).toHaveLength(0);

        const view = document.getElementById('bills-calendar-view');
        view.value = 'heatmap';
        view.dispatchEvent(new Event('change'));
        await Promise.resolve();

        expect(document.querySelectorAll('.heatmap-month')).toHaveLength(12);
        expect(document.getElementById('bills-calendar-heatmap-container').style.display).toBe('');
        expect(document.getElementById('bills-calendar-table-container').style.display).toBe('none');
        expect(apiFetch).toHaveBeenCalledTimes(1);
    });

    it('loads the report when none has been loaded yet', async () => {
        const mod = module();
        mod.setupBillsCalendarControls();

        const view = document.getElementById('bills-calendar-view');
        view.value = 'heatmap';
        view.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(document.querySelectorAll('.heatmap-month')).toHaveLength(12));

        expect(apiFetch).toHaveBeenCalledTimes(1);
    });

    it('goes back to the table the same way', async () => {
        const mod = module();
        mod.setupBillsCalendarControls();
        const view = document.getElementById('bills-calendar-view');
        view.value = 'heatmap';
        await mod.generateBillsCalendar();
        document.getElementById('bills-calendar-table-body').innerHTML = '';

        view.value = 'table';
        view.dispatchEvent(new Event('change'));
        await Promise.resolve();

        expect(document.querySelectorAll('#bills-calendar-table-body tr')).toHaveLength(1);
        expect(document.getElementById('bills-calendar-table-container').style.display).toBe('');
        expect(document.getElementById('bills-calendar-heatmap-container').style.display).toBe('none');
    });

    it('gives each box how hot its month is, for the stylesheet to tint', () => {
        const mod = module();
        mod.renderBillsCalendarHeatmap(overview.bills, overview.monthlyTotals);
        const boxes = [...document.querySelectorAll('.heatmap-month')];

        expect(boxes[5].style.getPropertyValue('--heat')).toBe('1');
        expect(boxes[0].style.getPropertyValue('--heat')).toBe('0.5');
        expect(boxes[11].style.getPropertyValue('--heat')).toBe('0');
        // The old inline red fill is gone
        expect(boxes.every(box => box.style.backgroundColor === '')).toBe(true);
    });
});

/* ---- the colours, from css/style.css ---- */

/** Nextcloud's own page colours, and the app's red in each theme. */
const THEMES = {
    light: { '--color-main-background': '#ffffff', '--color-main-text': '#222222', '--budget-color-negative': '#b71c1c' },
    dark: { '--color-main-background': '#171717', '--color-main-text': '#ebebeb', '--budget-color-negative': '#f28b82' },
    'colour-blind light': { '--color-main-background': '#ffffff', '--color-main-text': '#222222', '--budget-color-negative': COLORBLIND.light.negative },
    'colour-blind dark': { '--color-main-background': '#171717', '--color-main-text': '#ebebeb', '--budget-color-negative': COLORBLIND.dark.negative },
};

/** What the rules outside media queries declare for exactly this selector. */
function declared(selector) {
    return Object.assign({}, ...RULES.filter(r => !r.media && r.selectors.includes(selector)).map(r => r.declarations));
}

function colour(value, props) {
    const m = /^var\((--[\w-]+)(?:,\s*([^)]+))?\)$/.exec(value.trim());
    if (m) return props[m[1]] ?? colour(m[2], props);
    return value.trim().toLowerCase();
}

const channels = hex => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16));

/** color-mix(in srgb, A p%, B) */
function mix(a, b, share) {
    const [ca, cb] = [channels(a), channels(b)];
    return '#' + ca.map((v, i) => Math.round(v * share + cb[i] * (1 - share)).toString(16).padStart(2, '0')).join('');
}

/** The box's fill at a heat between 0 and 1, from its background-color declaration. */
function fill(heat, props) {
    const value = declared('.heatmap-month')['background-color'];
    const m = /^color-mix\(in srgb, (var\([^)]+\)) calc\(var\(--heat, 0\) \* (\d+(?:\.\d+)?)%\), (var\([^)]+\))\)$/.exec(value || '');
    expect(m, `.heatmap-month background-color: ${value}`).not.toBeNull();
    return mix(colour(m[1], props), colour(m[3], props), heat * Number(m[2]) / 100);
}

describe('the heatmap colours', () => {
    for (const [theme, props] of Object.entries(THEMES)) {
        it(`keeps every line readable on every fill (${theme})`, () => {
            for (const part of ['.heatmap-month-name', '.heatmap-month-count', '.heatmap-month-total']) {
                const text = colour(declared(part).color, props);
                for (const heat of [0, 0.25, 0.5, 0.75, 1]) {
                    expect(contrastRatio(fill(heat, props), text), `${part} at ${heat}`).toBeGreaterThanOrEqual(4.5);
                }
            }
        });
    }

    it('still tells a busy month from a quiet one', () => {
        const props = THEMES.light;
        expect(contrastRatio(fill(0, props), fill(1, props))).toBeGreaterThan(1.5);
    });
});
