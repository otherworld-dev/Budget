/**
 * The checkmark on Mark Paid and Mark Received (#399, browser round 3).
 *
 * Nextcloud draws .icon-checkmark as a background image in a fixed colour
 * ('body .icon-checkmark { background-image: var(--icon-checkmark-dark) }',
 * white in the dark theme). The dark theme's buttons are a pale green with
 * a dark label, and the checkmark stayed white on them. The icon is now
 * the same shape used as a mask over the button's text colour, so it
 * follows the label in every theme and colour scheme, at rest and under
 * the pointer.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { cascadedDeclarations, useTheme, THEMES } from './helpers/cascade.js';

const BUTTONS = {
    'Mark Paid': '<div class="bill-card"><div class="bill-actions"><button class="bill-action-btn bill-paid-btn"><span class="icon-checkmark" aria-hidden="true"></span> Mark Paid</button></div></div>',
    'Mark Received': '<div class="income-card"><div class="income-actions"><button class="income-action-btn income-received-btn"><span class="icon-checkmark" aria-hidden="true"></span> Mark Received</button></div></div>',
};

function icon(name) {
    const app = document.createElement('div');
    app.id = 'app-content';
    app.innerHTML = BUTTONS[name];
    document.body.appendChild(app);
    return app.querySelector('.icon-checkmark');
}

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

describe.each(Object.keys(BUTTONS))('%s', (name) => {
    for (const theme of THEMES) {
        for (const hover of [false, true]) {
            it(`draws its checkmark in the label's colour in ${theme}${hover ? ', under the pointer' : ''}`, () => {
                useTheme(theme);
                const declared = cascadedDeclarations(icon(name), theme, { hover });

                expect(declared['background-image']).toBe('none');
                expect(declared.background).toBe('currentColor');
                expect(declared['mask-image']).toBe('var(--icon-checkmark-dark)');
                expect(declared['-webkit-mask-image']).toBe('var(--icon-checkmark-dark)');
            });
        }
    }

    it('keeps the checkmark\'s size', () => {
        const declared = cascadedDeclarations(icon(name), 'light');
        expect(declared.width).toBe('16px');
        expect(declared.height).toBe('16px');
        expect(declared['mask-size']).toBe('16px 16px');
    });
});
