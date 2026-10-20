/**
 * The status pills on Bills and Income read at 4.5:1 or better (#399).
 *
 * In the light theme Due Soon and Expected Soon were white on the app's
 * warning orange #e65100, 3.79:1. Paid, Received, Completed and Inactive
 * are white on #2e7d32, 5.13:1, but the whole card was drawn at opacity
 * 0.8, so the pill came out as about #58975b, 3.5:1. The soon pills now
 * take a darker orange in the light theme (the theme's own warning shade in
 * the dark one, and the colour-blind preset's warning colour under it), and
 * a finished card fades its other parts but not its status pill.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { RULES, declarationsFor, rulesMatching } from './helpers/stylesheet.js';
import { COLORBLIND, contrastRatio, onColour } from '../../src/utils/amountColours.js';

const WHITE = '#ffffff';
const DARK_TEXT = '#171717';

/** A card as the page renders it, inside the app */
function card(kind, status) {
    const app = document.createElement('div');
    app.id = 'app-content';
    app.innerHTML = `
        <div class="${kind}-card ${status}" data-status="${status}">
            <div class="${kind}-header"><div class="${kind}-info"><h4>Name</h4></div><div class="${kind}-amount">1.00</div></div>
            <div class="${kind}-details">
                <div class="${kind}-next-date ${kind}-due-date">date</div>
                <div class="${kind}-status ${status}"><span class="status-badge">${status}</span></div>
            </div>
            <div class="${kind}-actions"><button>Edit</button></div>
        </div>`;
    document.body.appendChild(app);
    return app.querySelector(`.${kind}-card`);
}

/** The opacity rules reaching the pill: on it or on any element around it in the card */
function fadesOnPill(cardEl) {
    const out = [];
    for (let el = cardEl.querySelector('.status-badge'); el && el !== cardEl.parentElement; el = el.parentElement) {
        const opacity = declarationsFor(el).opacity;
        if (opacity !== undefined && Number(opacity) < 1) {
            out.push(el.className);
        }
    }
    return out;
}

const FINISHED = [['bill', 'paid'], ['income', 'received'], ['income', 'completed'], ['income', 'inactive']];
const SOON = [['bill', 'due-soon'], ['income', 'expected-soon']];

afterEach(() => {
    document.body.innerHTML = '';
});

describe('a finished card', () => {
    it.each(FINISHED)('%s %s fades, but not its status pill', (kind, status) => {
        const el = card(kind, status);

        expect(fadesOnPill(el)).toEqual([]);
        expect(declarationsFor(el.querySelector(`.${kind}-header`)).opacity).toBe('0.8');
        expect(declarationsFor(el.querySelector(`.${kind}-actions`)).opacity).toBe('0.8');
    });

    it('keeps its pill at 4.5:1 under Default and the colour-blind preset, light and dark', () => {
        expect(contrastRatio('#2e7d32', WHITE)).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio('#81c995', DARK_TEXT)).toBeGreaterThanOrEqual(4.5);
        for (const mode of ['light', 'dark']) {
            const fill = COLORBLIND[mode].positive;
            expect(contrastRatio(fill, onColour(fill))).toBeGreaterThanOrEqual(4.5);
        }
    });
});

describe('a soon pill', () => {
    it.each(SOON)('%s %s takes the darker orange in the light theme', (kind, status) => {
        const badge = card(kind, status).querySelector('.status-badge');
        const pill = declarationsFor(badge);

        expect(pill.background).toBe('var(--budget-scheme-warning, #bf360c)');
        expect(pill.color.replace(/\bwhite\b/, '#fff')).toBe('var(--budget-color-on-status, #fff)');
        expect(contrastRatio('#bf360c', WHITE)).toBeGreaterThanOrEqual(4.5);
    });

    it.each(SOON)('%s %s takes the theme\'s warning shade in the dark theme', (kind, status) => {
        const selector = `.${kind}-status.${status} .status-badge`;
        const dark = RULES.filter(rule => rule.selectors.some(s => s === `body[data-theme-dark] ${selector}`)
            || rule.selectors.some(s => s === `body[data-theme-default] ${selector}` && rule.media?.includes('dark')));

        expect(dark).toHaveLength(2);
        for (const rule of dark) {
            expect(rule.declarations.background).toBe('var(--color-warning)');
        }
        // The dark theme's warning and the text it sets on fills
        expect(contrastRatio('#ffb74d', DARK_TEXT)).toBeGreaterThanOrEqual(4.5);
    });

    it('reads at 4.5:1 under the colour-blind preset, light and dark', () => {
        // The preset sets --budget-scheme-warning and the text that goes on it
        for (const mode of ['light', 'dark']) {
            const fill = COLORBLIND[mode].warning;
            expect(contrastRatio(fill, onColour(fill))).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('is still the plain warning orange on the card edge', () => {
        const el = card('bill', 'due-soon');

        expect(rulesMatching(el).some(rule => rule.declarations['border-left'] === '4px solid var(--color-warning)')).toBe(true);
    });
});
