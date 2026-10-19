/**
 * The "Exact amount" badge in Link to bill and the "Strong match" badge in
 * Mark Paid's "Existing Transaction Found" (#399).
 *
 * Both dialogs hang off <body>, outside #app-content where the app puts its
 * own status shades back (top of style.css). Nextcloud 32 turned
 * --color-success into a pale fill, #D8F3DA in the light theme, and the
 * badge's white text on it measured about 1.2:1: an empty pale-green pill,
 * where the dialog relies on it to point out the bills of the row's amount.
 * The badge text has to reach 4.5:1 on its fill in both themes, and under a
 * money colour scheme (#432) too.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildStyleSheet, resolveScheme, contrastRatio } from '../../src/utils/amountColours.js';

const CSS = fs.readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../css/style.css'), 'utf8');

/** Every rule as {selectors, body}, media queries flattened. */
function rules(text, out = []) {
    const css = text.replace(/\/\*[\s\S]*?\*\//g, '');
    let i = 0;
    while (i < css.length) {
        const open = css.indexOf('{', i);
        if (open === -1) break;
        const head = css.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (j < css.length && depth) {
            if (css[j] === '{') depth++;
            else if (css[j] === '}') depth--;
            j++;
        }
        const body = css.slice(open + 1, j - 1);
        if (head.startsWith('@media')) {
            rules(body, out);
        } else if (!head.startsWith('@')) {
            out.push({ selectors: head.split(',').map(s => s.trim().replace(/\s+/g, ' ')), body });
        }
        i = j;
    }
    return out;
}

/** A rule body's declarations, in order, as [property, value] */
function declarations(body) {
    return body.split(';').map(d => d.trim()).filter(Boolean).map((d) => {
        const colon = d.indexOf(':');
        return [d.slice(0, colon).trim(), d.slice(colon + 1).trim()];
    });
}

/** Expand var() against the custom properties in scope */
function resolve(value, props, depth = 0) {
    if (depth > 20) throw new Error(`var() loop in ${value}`);
    const start = value.indexOf('var(');
    if (start === -1) return value.trim();
    let nesting = 0;
    let end = -1;
    for (let i = start + 3; i < value.length; i++) {
        if (value[i] === '(') nesting++;
        else if (value[i] === ')' && --nesting === 0) {
            end = i;
            break;
        }
    }
    const inner = value.slice(start + 4, end);
    const comma = inner.indexOf(',');
    const name = (comma === -1 ? inner : inner.slice(0, comma)).trim();
    let replacement;
    if (name in props) {
        replacement = props[name];
    } else if (comma !== -1) {
        replacement = inner.slice(comma + 1);
    } else {
        throw new Error(`${name} is not set where the dialogs are`);
    }
    return resolve(value.slice(0, start) + replacement + value.slice(end + 1), props, depth + 1);
}

/** A colour as #rrggbb */
function hex(colour) {
    const named = { white: '#ffffff', black: '#000000' };
    const c = (named[colour.toLowerCase()] ?? colour).toLowerCase();
    if (/^#[0-9a-f]{3}$/.test(c)) return '#' + [...c.slice(1)].map(d => d + d).join('');
    if (/^#[0-9a-f]{6}$/.test(c)) return c;
    throw new Error(`Can't read the colour ${colour}`);
}

/**
 * Nextcloud 35's own status fills, which apply to everything outside the
 * app's scopes; the app's light shades only sit on #app-content and friends.
 */
const NEXTCLOUD = {
    light: { '--color-success': '#d8f3da', '--color-success-text': '#005416' },
    dark: { '--color-success': '#11321a' },
};
const DARK_THEMES = ['body[data-theme-dark]', 'body[data-theme-dark-highcontrast]', 'body[data-theme-default]'];

/**
 * The badge's fill and text in a dialog appended to <body>: the custom
 * properties set on :root and body (and, dark, on the dark body), then the
 * badge's own rules.
 */
function badge(css, theme) {
    const all = rules(css);
    const scopes = theme === 'dark' ? [':root', 'body', 'body[data-theme-dark]'] : [':root', 'body'];
    const props = { ...NEXTCLOUD[theme] };
    for (const scope of scopes) {
        for (const rule of all.filter(r => r.selectors.includes(scope))) {
            for (const [name, value] of declarations(rule.body)) {
                if (name.startsWith('--')) props[name] = value;
            }
        }
    }
    const own = ['.matching-tx-badge', ...(theme === 'dark' ? ['body[data-theme-dark] .matching-tx-badge'] : [])];
    const style = {};
    for (const selector of own) {
        for (const rule of all.filter(r => r.selectors.includes(selector))) {
            for (const [name, value] of declarations(rule.body)) {
                style[name === 'background-color' ? 'background' : name] = value;
            }
        }
    }
    const fill = hex(resolve(style.background, props));
    const text = hex(resolve(style.color, props));
    return { fill, text, ratio: contrastRatio(fill, text) };
}

describe('the matching badge', () => {
    it('reads at 4.5:1 or better in the light theme', () => {
        expect(badge(CSS, 'light').ratio).toBeGreaterThanOrEqual(4.5);
    });

    it('reads at 4.5:1 or better in the dark theme', () => {
        expect(badge(CSS, 'dark').ratio).toBeGreaterThanOrEqual(4.5);
    });

    it('takes the dark text whichever way the dark theme is chosen', () => {
        const darkRules = rules(CSS).filter(r => r.selectors.some(s => s.endsWith(' .matching-tx-badge')));
        for (const theme of DARK_THEMES) {
            const rule = darkRules.find(r => r.selectors.includes(`${theme} .matching-tx-badge`));
            expect(rule, theme).toBeDefined();
            expect(declarations(rule.body)).toEqual(declarations(
                darkRules.find(r => r.selectors.includes('body[data-theme-dark] .matching-tx-badge')).body
            ));
        }
    });

    it('stays readable under the colour-blind scheme', () => {
        const withScheme = CSS + '\n' + buildStyleSheet(resolveScheme('colorblind'));

        const light = badge(withScheme, 'light');
        const dark = badge(withScheme, 'dark');

        // The badge follows the scheme's green, as the amounts do
        expect(light.fill).toBe('#0b62a4');
        expect(dark.fill).toBe('#6cb4ee');
        expect(light.ratio).toBeGreaterThanOrEqual(4.5);
        expect(dark.ratio).toBeGreaterThanOrEqual(4.5);
    });
});
