/**
 * Status colours on the parts of Budget that sit outside #app-content.
 *
 * Nextcloud 32 turned --color-error / --color-success / --color-warning into
 * pale fills (#FFE7E7, #D8F3DA, #FFEEC5 in the light theme), meant to carry
 * the matching --color-*-text. The app puts its own darker shades back, but
 * only on #app-navigation and #app-content. The standalone Quick Add page
 * and the toast container (appended to <body>) are outside both, so text set
 * in --color-error came out pale pink on white, and a toast's white text sat
 * on a pale fill.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const CSS = fs.readFileSync(path.resolve(__dirname, '../../css/style.css'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/** Every rule as {selectors, body}, media queries flattened. */
function rules(text, out = []) {
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
            rules(body, out);
        } else if (!head.startsWith('@')) {
            out.push({ selectors: head.split(',').map(s => s.trim().replace(/\s+/g, ' ')), body });
        }
        i = j;
    }
    return out;
}

const RULES = rules(CSS);

/** A text colour taken straight from a status fill. */
const BARE_STATUS_TEXT = /(?:^|[;\s])color:\s*var\(--color-(?:error|success|warning)\s*[,)]/;

describe('status colours outside #app-content', () => {
    it('sets no Quick Add text in a bare status fill colour', () => {
        const quickAdd = RULES.filter(r => r.selectors.some(s => /(^|[\s.#])(qa-|quick-add-page|quick-add-standalone)/.test(s)));
        expect(quickAdd.length).toBeGreaterThan(10);
        const offenders = quickAdd.filter(r => BARE_STATUS_TEXT.test(r.body)).map(r => r.selectors.join(', '));
        expect(offenders).toEqual([]);
    });

    it('reads Quick Add messages in the theme text colours, with a fallback for older servers', () => {
        const colour = (selector) => {
            const rule = RULES.find(r => r.selectors.includes(selector));
            return /(?:^|[;\s])color:\s*([^;]+)/.exec(rule.body)[1].trim();
        };
        expect(colour('.qa-error')).toMatch(/^var\(--color-error-text, var\(--budget-color-negative\)\)$/);
        expect(colour('.qa-receipt-note-error')).toMatch(/^var\(--color-error-text, var\(--budget-color-negative\)\)$/);
        expect(colour('.qa-success')).toMatch(/^var\(--color-success-text, var\(--budget-color-positive\)\)$/);
    });

    it('gives the toasts the app\'s own status shades, light and dark', () => {
        const scopes = RULES.filter(r => /--color-error:/.test(r.body)).map(r => r.selectors);
        // Light, and both ways of choosing the dark theme.
        expect(scopes.some(s => s.includes('#budget-toast-container'))).toBe(true);
        expect(scopes.some(s => s.includes('body[data-theme-dark] #budget-toast-container'))).toBe(true);
        expect(scopes.some(s => s.includes('body[data-theme-default] #budget-toast-container'))).toBe(true);

        const toast = RULES.find(r => r.selectors.includes('.budget-toast'));
        expect(toast.body).toMatch(/(?:^|[;\s])color:\s*var\(--budget-color-on-status/);
    });
});
