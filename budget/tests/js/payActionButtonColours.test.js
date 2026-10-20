/**
 * Mark Paid and Mark Received, at rest and under the pointer (#399).
 *
 * - Under the pointer, Bills' Mark Paid took Nextcloud's own
 *   --color-success-hover, a pale tint (#bdebc0), under its white label:
 *   1.33:1, close to the look of a disabled button. It also shows whenever
 *   the list redraws under a resting pointer. Income's Mark Received had
 *   the same hover.
 * - Income's Mark Received kept a white label in the dark theme, on the
 *   light green #81c995 (1.96:1). Its colour stopped at
 *   --budget-color-on-success, which only a colour scheme sets, where Mark
 *   Paid goes on to --budget-color-on-status, #171717 in the dark theme.
 *
 * The hover is now a darker shade of the button's own green under the
 * same label, and Mark Received's label comes from the same chain as Mark
 * Paid's.
 */

import { describe, it, expect, afterEach } from 'vitest';

import { buildStyleSheet, contrastRatio, resolveScheme } from '../../src/utils/amountColours.js';
import { THEMES, cascadedDeclarations, colour, customProperties, useTheme } from './helpers/cascade.js';

const SCHEMES = {
    default: '',
    'colour-blind': buildStyleSheet(resolveScheme('colorblind')),
    custom: buildStyleSheet(resolveScheme('custom', JSON.stringify({
        lightPositive: '#1b5e20', lightNegative: '#c62828', darkPositive: '#a5d6a7', darkNegative: '#ff8a80',
    }))),
};

/** The buttons as BillsModule and IncomeModule render them, inside the app */
const BUTTONS = {
    'Mark Paid': '<div class="bill-card"><div class="bill-actions"><button class="bill-action-btn bill-paid-btn">Mark Paid</button></div></div>',
    'Mark Received': '<div class="income-card"><div class="income-actions"><button class="income-action-btn income-received-btn">Mark Received</button></div></div>',
};

function button(name) {
    const app = document.createElement('div');
    app.id = 'app-content';
    app.innerHTML = BUTTONS[name];
    document.body.appendChild(app);
    return app.querySelector('button');
}

/** The button's fill and label, resting or under the pointer */
function look(name, scheme, theme, hover = false) {
    useTheme(theme);
    const el = button(name);
    const vars = customProperties(el, theme, SCHEMES[scheme]);
    const style = cascadedDeclarations(el, theme, { hover });
    return { style, vars, fill: colour(style.background, vars), text: colour(style.color, vars) };
}

const MIXED_WITH_BLACK = /^color-mix\(\s*in srgb\s*,\s*(.+?)\s*,\s*black\s+\d+(?:\.\d+)?%\s*\)$/;

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

const CASES = Object.keys(BUTTONS).flatMap(name =>
    Object.keys(SCHEMES).flatMap(scheme => THEMES.map(theme => [name, scheme, theme])));

describe.each(CASES)('%s, %s colours, %s theme', (name, scheme, theme) => {
    it('reads at 4.5:1 or better at rest', () => {
        const { text, fill } = look(name, scheme, theme);

        expect(contrastRatio(text, fill), `${text} on ${fill}`).toBeGreaterThanOrEqual(4.5);
    });

    it('reads at 4.5:1 or better under the pointer', () => {
        const { text, fill } = look(name, scheme, theme, true);

        expect(contrastRatio(text, fill), `${text} on ${fill}`).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps its label under the pointer, on a darker shade of its own green', () => {
        const rest = look(name, scheme, theme);
        const hovered = look(name, scheme, theme, true);
        const shade = MIXED_WITH_BLACK.exec(hovered.style.background);

        expect(hovered.text).toBe(rest.text);
        expect(shade, hovered.style.background).not.toBeNull();
        expect(colour(shade[1], hovered.vars)).toBe(rest.fill);
        expect(contrastRatio(hovered.fill, '#000000')).toBeLessThan(contrastRatio(rest.fill, '#000000'));
    });
});

describe('Mark Received', () => {
    it.each(THEMES)('takes its label colour the way Mark Paid does, in the %s theme', (theme) => {
        const paid = look('Mark Paid', 'default', theme);
        document.body.innerHTML = '';
        const received = look('Mark Received', 'default', theme);

        expect(received.style.color).toBe(paid.style.color);
        expect(received.text).toBe(theme === 'light' ? '#ffffff' : '#171717');
    });
});
