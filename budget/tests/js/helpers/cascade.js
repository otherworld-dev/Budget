/**
 * The custom properties an element gets on the page, for tests that check
 * colours: Nextcloud's own theme values, then css/style.css's rules for
 * each element from the root down, then a money colour scheme's stylesheet
 * (amountColours.js), which the app injects after style.css. Each element's
 * own declarations win over what it inherits, in order of specificity,
 * then source order, and a var() in a custom property is filled in where
 * it is declared, as the browser does.
 *
 * The theme is set on <body> the way Nextcloud does it, and 'system dark'
 * is the default theme following a dark system setting, which also brings
 * in style.css's `@media (prefers-color-scheme: dark)` rules.
 */

import { RULES } from './stylesheet.js';

/**
 * Nextcloud 35's own values (apps/theming DefaultTheme and DarkTheme, the
 * hovers worked out with its own colour library on the dev instance)
 */
export const NEXTCLOUD = {
    light: {
        '--color-main-background': '#ffffff',
        '--color-main-text': '#222222',
        '--color-error': '#FFE7E7',
        '--color-error-hover': '#ffc3c3',
        '--color-success': '#D8F3DA',
        '--color-success-hover': '#bdebc0',
        '--color-warning': '#FFEEC5',
    },
    dark: {
        '--color-main-background': '#171717',
        '--color-main-text': '#EBEBEB',
        '--color-error': '#552121',
        '--color-error-hover': '#7a2f2f',
        '--color-success': '#11321A',
        '--color-success-hover': '#1e582e',
        '--color-warning': '#3D3010',
    },
};

export const THEMES = ['light', 'dark', 'system dark'];

const BODY_THEME = {
    light: 'data-theme-default',
    dark: 'data-theme-dark',
    'system dark': 'data-theme-default',
};

const DARK_MEDIA = /^@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)$/;

/** Mark <body> with a theme as Nextcloud does */
export function useTheme(theme) {
    for (const attr of Object.values(BODY_THEME)) {
        document.body.removeAttribute(attr);
    }
    document.body.setAttribute(BODY_THEME[theme], '');
}

/** CSS text as {selectors, declarations, media}, in source order, like stylesheet.js's RULES */
export function parseRules(css, media = null, out = []) {
    const text = css.replace(/\/\*[\s\S]*?\*\//g, '');
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
            parseRules(body, head, out);
        } else if (!head.startsWith('@')) {
            const declarations = {};
            body.split(';').forEach((part) => {
                const colon = part.indexOf(':');
                if (colon > 0) declarations[part.slice(0, colon).trim()] = part.slice(colon + 1).trim();
            });
            out.push({ media, selectors: head.split(',').map(s => s.trim().replace(/\s+/g, ' ')), declarations });
        }
        i = j;
    }
    return out;
}

/** Specificity of a selector without :not()/:is(), as one comparable number */
export function specificity(selector) {
    const ids = (selector.match(/#[\w-]+/g) || []).length;
    const classes = (selector.match(/\.[\w-]+|\[[^\]]*\]|:(?!:)[\w-]+/g) || []).length;
    const types = (selector.replace(/#[\w-]+|\.[\w-]+|\[[^\]]*\]|::?[\w-]+/g, ' ').match(/[a-z][\w-]*/gi) || []).length;
    return ids * 10000 + classes * 100 + types;
}

function inEffect(rule, theme, media = []) {
    return rule.media === null || (theme === 'system dark' && DARK_MEDIA.test(rule.media))
        || media.some(query => rule.media.replace(/\s+/g, ' ') === `@media ${query}`);
}

function matchingSpecificity(el, rule) {
    let best = null;
    for (const selector of rule.selectors) {
        let hit = false;
        try {
            hit = el.matches(selector);
        } catch {
            hit = false;
        }
        if (hit) best = Math.max(best ?? 0, specificity(selector));
    }
    return best;
}

/**
 * What style.css's rules declare for `el` in `theme`, as the cascade picks
 * them: by specificity, then source order, !important first. With `hover`,
 * the rules for `el:hover` take part too, which jsdom can't match itself.
 * `media` lists other media queries to count as in effect, written as in
 * the stylesheet after '@media ' (e.g. '(min-width: 641px)'), for a window
 * that wide. Pseudo-elements are left out.
 */
export function cascadedDeclarations(el, theme, { hover = false, media = [] } = {}) {
    const hits = [];
    RULES.forEach((rule, order) => {
        if (!inEffect(rule, theme, media)) return;
        let best = null;
        for (const selector of rule.selectors) {
            if (selector.includes('::') || (!hover && selector.includes(':hover'))) continue;
            let hit = false;
            try {
                hit = el.matches(selector.replace(/:hover/g, ''));
            } catch {
                hit = false;
            }
            if (hit) best = Math.max(best ?? 0, specificity(selector));
        }
        if (best !== null) hits.push({ rule, order, spec: best });
    });
    hits.sort((a, b) => a.spec - b.spec || a.order - b.order);

    const normal = {};
    const important = {};
    for (const { rule } of hits) {
        for (const [name, value] of Object.entries(rule.declarations)) {
            // One name for the fill, whichever way a rule spells it
            const key = name === 'background-color' ? 'background' : name;
            if (/!important\s*$/.test(value)) {
                important[key] = value.replace(/\s*!important\s*$/, '');
            } else {
                normal[key] = value;
            }
        }
    }
    return { ...normal, ...important };
}

/** A value with its var()s filled in from `vars`, innermost first */
export function resolve(value, vars) {
    let out = String(value);
    for (let i = 0; i < 20 && out.includes('var('); i++) {
        out = out.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)/g,
            (m, name, fallback) => vars[name] ?? (fallback !== undefined ? fallback.trim() : m));
    }
    return out;
}

/**
 * The custom properties `el` gets in `theme`, with `extraCss` (a scheme's
 * stylesheet) after style.css. The theme must already be on <body>
 * (useTheme()).
 */
export function customProperties(el, theme, extraCss = '') {
    // Only the rules that set a custom property have anything to give here
    const rules = [
        ...RULES.map((rule, order) => ({ ...rule, order })),
        ...parseRules(extraCss).map((rule, order) => ({ ...rule, order: RULES.length + order })),
    ].filter(rule => inEffect(rule, theme) && Object.keys(rule.declarations).some(k => k.startsWith('--')));

    const chain = [];
    for (let node = el; node; node = node.parentElement) chain.unshift(node);

    let vars = { ...NEXTCLOUD[theme === 'light' ? 'light' : 'dark'] };
    for (const node of chain) {
        const own = {};
        rules.map(rule => ({ rule, spec: matchingSpecificity(node, rule) }))
            .filter(hit => hit.spec !== null)
            .sort((a, b) => a.spec - b.spec || a.rule.order - b.rule.order)
            .forEach(({ rule }) => {
                Object.entries(rule.declarations).filter(([k]) => k.startsWith('--')).forEach(([k, v]) => { own[k] = v; });
            });
        const scope = { ...vars, ...own };
        vars = { ...vars };
        for (const [k, v] of Object.entries(own)) {
            vars[k] = resolve(v, { ...scope, [k]: undefined });
        }
    }
    return vars;
}

/** #rrggbb, lower case, for a hex colour or white/black */
export function hex(colour) {
    const named = { white: '#ffffff', black: '#000000' };
    const c = (named[String(colour).trim().toLowerCase()] ?? String(colour).trim()).toLowerCase();
    if (/^#[0-9a-f]{3}$/.test(c)) return '#' + [...c.slice(1)].map(d => d + d).join('');
    if (/^#[0-9a-f]{6}$/.test(c)) return c;
    throw new Error(`not a colour: ${colour}`);
}

/**
 * color-mix(in srgb, A, B N%) with two plain colours, as the browser
 * mixes them (A takes the rest of the 100%).
 */
export function mix(value) {
    const m = /^color-mix\(\s*in srgb\s*,\s*([^,]+?)\s*,\s*([^,]+?)\s+(\d+(?:\.\d+)?)%\s*\)$/.exec(String(value).trim());
    if (!m) return value;
    const [a, b] = [hex(m[1]), hex(m[2])];
    const share = Number(m[3]) / 100;
    const channel = (h, i) => parseInt(h.slice(1 + i * 2, 3 + i * 2), 16);
    return '#' + [0, 1, 2].map(i => Math.round(channel(a, i) * (1 - share) + channel(b, i) * share)
        .toString(16).padStart(2, '0')).join('');
}

/** A colour value as #rrggbb once its var()s are filled in */
export function colour(value, vars) {
    return hex(mix(resolve(value, vars)));
}
