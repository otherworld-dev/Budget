/**
 * The badges beside a soon status pill in the dark theme (#399, browser
 * round 3). The dark theme's Due Soon and Expected Soon fills
 * ('body[data-theme-dark] .bill-status.due-soon .status-badge' and the
 * system-dark variant) reached every .status-badge in the status, and
 * outranked '.bill-status .status-badge.auto-pay': a Due Soon bill's
 * Auto-pay badge took the warning fill. They now reach the status pill
 * only, and the extras keep their own look in every theme.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { cascadedDeclarations, useTheme, THEMES } from './helpers/cascade.js';

function status(kind, state, extra) {
    document.body.insertAdjacentHTML('beforeend', `
        <div id="app-content"><div class="${kind}-card ${state}">
            <div class="${kind}-status ${state}">
                <span class="status-badge">Soon</span>
                <span class="status-badge badge-extra ${extra}"><span class="icon-checkmark"></span> Extra</span>
            </div>
        </div></div>`);
    const root = document.querySelector(`.${kind}-status`);
    return { pill: root.querySelector('.status-badge:not(.badge-extra)'), extra: root.querySelector('.badge-extra') };
}

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

const CASES = [
    ['bill', 'due-soon', 'auto-pay'],
    ['income', 'expected-soon', 'auto-create'],
];

describe.each(CASES)('a %s %s with %s', (kind, state, extra) => {
    for (const theme of THEMES) {
        it(`keeps the extra badge's own fill in ${theme}`, () => {
            useTheme(theme);
            const { extra: badge } = status(kind, state, extra);
            const declared = cascadedDeclarations(badge, theme);
            expect(declared.background).toBe('var(--color-primary-element)');
            expect(declared.color).toBe('var(--color-primary-element-text)');
        });

        it(`still gives the status pill its warning fill in ${theme}`, () => {
            useTheme(theme);
            const { pill } = status(kind, state, extra);
            const expected = theme === 'light' ? 'var(--budget-scheme-warning, #bf360c)' : 'var(--color-warning)';
            expect(cascadedDeclarations(pill, theme).background).toBe(expected);
        });
    }
});
