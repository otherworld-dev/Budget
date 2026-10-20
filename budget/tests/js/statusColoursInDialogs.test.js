/**
 * The app's status colours in its dialogs (#399).
 *
 * Nextcloud 32+ made --color-error, --color-success and --color-warning
 * pale surface tints (#FFE7E7 for error in the light theme, #552121 in the
 * dark one). style.css puts the app's own shades back, but only on
 * #app-navigation, #app-content, the toasts and, since round 2, Repair
 * Data's report. The dialogs in templates/index.php sit in #content beside
 * #app-content, and the ones the scripts build hang off <body>, so a
 * required field's asterisk ('label .required', in --color-error) was
 * #FFE7E7 on white (1.18:1) and #552121 on #171717 (1.39:1): in the
 * transaction form, where it now comes and goes with the Vendor field, and
 * in the import's Save Import Template dialog.
 *
 * Under a money colour scheme (#432) the scheme's colours, and the text to
 * put on them, reach the dialogs from <body>. Round 2 pinned Repair Data's
 * fills to the app's red, so the count badge took the scheme's text on the
 * app's fill: #171717 on #b71c1c (2.73:1) and white on #f28b82 (2.39:1).
 * The dialogs now take the scheme's shades where one is in use, and the
 * app's otherwise, so a fill and its text come from the same place.
 */

import { describe, it, expect, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildStyleSheet, contrastRatio, onColour, resolveScheme } from '../../src/utils/amountColours.js';
import { declarationsFor, RULES } from './helpers/stylesheet.js';
import { THEMES, colour, customProperties, useTheme } from './helpers/cascade.js';

/** templates/index.php as the browser gets it, inside Nextcloud's #content (core/templates/layout.user.php) */
const TEMPLATE = fs.readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'), 'utf8')
    .replace(/<\?php[\s\S]*?\?>/g, '');

function page() {
    document.body.innerHTML = `<div id="content" class="app-budget">${TEMPLATE}</div>`;
}

const APP_RED = { light: '#b71c1c', dark: '#f28b82', 'system dark': '#f28b82' };

/** The colour schemes to try: Settings > Colours */
const SCHEMES = {
    default: '',
    'colour-blind': buildStyleSheet(resolveScheme('colorblind')),
    // The ones the browser check used, which Settings flags as hard to read
    'custom, flagged': buildStyleSheet(resolveScheme('custom', JSON.stringify({
        lightPositive: '#2e7d32', lightNegative: '#ff8a65', darkPositive: '#81c995', darkNegative: '#8e0000',
    }))),
    'custom, accepted': buildStyleSheet(resolveScheme('custom', JSON.stringify({
        lightPositive: '#2e7d32', lightNegative: '#c62828', darkPositive: '#81c995', darkNegative: '#ff8a80',
    }))),
};

/** An element's colour and the colour behind it, worked out */
function textOn(el, theme, scheme = '', background = null) {
    const vars = customProperties(el, theme, SCHEMES[scheme] ?? scheme);
    const style = declarationsFor(el);
    return {
        text: colour(style.color, vars),
        fill: colour(background ?? style.background ?? style['background-color'] ?? 'var(--color-main-background)', vars),
    };
}

afterEach(() => {
    document.body.innerHTML = '';
    for (const attr of [...document.body.attributes]) document.body.removeAttribute(attr.name);
});

describe('the dialogs in index.php', () => {
    it('sit in #content beside #app-content', () => {
        page();
        for (const id of ['transaction-modal', 'import-save-template-modal', 'import-templates-modal', 'factory-reset-modal']) {
            const dialog = document.getElementById(id);
            expect(dialog.parentElement.id, id).toBe('content');
            expect(dialog.closest('#app-content'), id).toBeNull();
            expect(dialog.classList.contains('modal'), id).toBe(true);
        }
    });

    it.each(THEMES)('show the transaction form\'s asterisks in the app\'s red in the %s theme', (theme) => {
        useTheme(theme);
        page();
        const stars = [...document.querySelectorAll('#transaction-modal label .required')];

        expect(stars.length).toBeGreaterThanOrEqual(4);
        for (const star of stars) {
            const { text, fill } = textOn(star, theme);
            expect(text, star.parentElement.textContent).toBe(APP_RED[theme]);
            expect(contrastRatio(text, fill)).toBeGreaterThanOrEqual(4.5);
        }
    });

    it.each(THEMES)('show every required field\'s asterisk at 4.5:1 or better in the %s theme', (theme) => {
        useTheme(theme);
        page();
        const stars = [...document.querySelectorAll('#content > .modal .required')];

        // The transaction form, Save Import Template, the rules, bills, income...
        expect(stars.length).toBeGreaterThan(30);
        expect(stars.some(star => star.closest('#import-save-template-modal'))).toBe(true);
        for (const star of stars) {
            const { text, fill } = textOn(star, theme);
            expect(contrastRatio(text, fill), `${star.closest('.modal').id}: ${text} on ${fill}`).toBeGreaterThanOrEqual(4.5);
        }
    }, 30000); // every asterisk against the whole stylesheet: about 2s on its own

    it.each(THEMES)('keep the factory reset button readable at rest and on hover in the %s theme', (theme) => {
        useTheme(theme);
        page();
        const button = document.getElementById('factory-reset-confirm-btn');
        // Enabled once the confirmation is typed in
        button.disabled = false;
        const hover = RULES.find(rule => rule.selectors.includes('.danger-btn:hover')).declarations;

        const rest = textOn(button, theme);
        const hovered = textOn(button, theme, '', hover.background);
        expect(contrastRatio(rest.text, rest.fill), `${rest.text} on ${rest.fill}`).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio(hovered.text, hovered.fill), `${hovered.text} on ${hovered.fill}`).toBeGreaterThanOrEqual(4.5);
    });
});

describe('a dialog built by the scripts', () => {
    function transferDialog() {
        // TransfersModule.showTransferModal()'s markup, which has no id
        document.body.insertAdjacentHTML('beforeend', `
            <div class="budget-modal-overlay modal-columns modal-columns-2">
                <div class="budget-modal modal-content">
                    <form id="transfer-form" class="wide-form">
                        <div class="form-group"><label for="transfer-name">Name <span class="required">*</span></label></div>
                    </form>
                </div>
            </div>`);
        return document.querySelector('#transfer-form .required');
    }

    it.each(THEMES)('shows the Add Transfer form\'s asterisks in the app\'s red in the %s theme', (theme) => {
        useTheme(theme);
        const { text, fill } = textOn(transferDialog(), theme);

        expect(text).toBe(APP_RED[theme]);
        expect(contrastRatio(text, fill)).toBeGreaterThanOrEqual(4.5);
    });
});

describe('Nextcloud\'s own interface', () => {
    it.each(THEMES)('keeps Nextcloud\'s colours in the %s theme', (theme) => {
        useTheme(theme);
        page();
        document.body.insertAdjacentHTML('afterbegin', '<header id="header"><div class="modal-mask"><div class="modal-container"><span class="probe"></span></div></div></header>');
        document.body.insertAdjacentHTML('beforeend', '<div class="modal-mask"><span class="probe"></span></div>');

        for (const probe of document.querySelectorAll('.probe')) {
            expect(customProperties(probe, theme)['--color-error']).toBe(theme === 'light' ? '#FFE7E7' : '#552121');
        }
    });
});

describe('under a money colour scheme', () => {
    it.each(THEMES)('a dialog\'s asterisk follows the colour-blind scheme, as the page does, in the %s theme', (theme) => {
        useTheme(theme);
        page();
        const star = document.querySelector('#transaction-modal label .required');
        const negative = resolveScheme('colorblind')[theme === 'light' ? 'light' : 'dark'].negative;

        expect(textOn(star, theme, 'colour-blind').text).toBe(negative);
        expect(customProperties(document.getElementById('app-content'), theme, SCHEMES['colour-blind'])['--color-error']).toBe(negative);
    });
});

describe('Repair Data\'s count badge', () => {
    /** The report, appended to <body> as main.js does */
    function badge() {
        const modal = document.createElement('div');
        modal.id = 'repair-data-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `<div class="budget-modal"><div class="budget-modal-body">
            <div class="repair-category"><div class="repair-category-header">
                <h4><input type="checkbox" class="repair-checkbox" checked> Duplicate Auto-Generated Transactions</h4>
                <span class="repair-category-count">14</span>
            </div></div>
        </div></div>`;
        document.body.appendChild(modal);
        return modal.querySelector('.repair-category-count');
    }

    const CASES = Object.keys(SCHEMES).flatMap(scheme => THEMES.map(theme => [scheme, theme]));

    it.each(CASES)('takes its text from where its fill comes from: %s scheme, %s theme', (scheme, theme) => {
        useTheme(theme);
        const { text, fill } = textOn(badge(), theme, scheme);

        expect(text, `${text} on ${fill}`).toBe(onColour(fill));
        expect(contrastRatio(text, fill), `${text} on ${fill}`).toBeGreaterThanOrEqual(4.5);
    });

    it.each(CASES.filter(([scheme]) => scheme !== 'default'))('is the scheme\'s colour, like the Overdue pills: %s scheme, %s theme', (scheme, theme) => {
        useTheme(theme);
        const fill = textOn(badge(), theme, scheme).fill;
        const app = document.createElement('div');
        app.id = 'app-content';
        document.body.appendChild(app);

        expect(fill).toBe(customProperties(app, theme, SCHEMES[scheme])['--color-error']);
    });

    it.each(THEMES)('is the app\'s red without a scheme in the %s theme', (theme) => {
        useTheme(theme);

        expect(textOn(badge(), theme).fill).toBe(APP_RED[theme]);
    });
});
