/**
 * The per-user money-in / money-out colour setting in the browser (#432).
 *
 * Under Default nothing here does anything: no stylesheet is injected and the
 * app's own CSS variables stay as style.css defines them. Under Colour-blind
 * or Custom, one <style> element overrides those variables for the light and
 * the dark theme, and canvas charts (which can't read CSS) ask semanticColour()
 * for the same colour.
 *
 * The preset mirrors lib/Service/AmountColours.php; a test cross-checks them.
 */

export const SCHEMES = ['default', 'colorblind', 'custom'];

/** Every value is at least 4.5:1 on its mode's surfaces (white, #171717, #292929). */
export const COLORBLIND = {
    light: { positive: '#0b62a4', negative: '#a84800', warning: '#8a6a00' },
    dark: { positive: '#6cb4ee', negative: '#f5a35c', warning: '#f0e442' },
};

/**
 * The alpha percentages style.css uses with its good/bad rgba families
 * (46,125,50 / 198,40,40 / 76,175,80 / 70,186,97 / 233,50,45). Each gets a
 * --budget-tint-{positive|negative}-NN variable.
 */
export const TINT_LEVELS = [6, 8, 10, 15, 20, 30];

export const CHANGE_EVENT = 'budget:amount-colours-changed';

const STYLE_ID = 'budget-amount-colours';
const CUSTOM_KEYS = ['lightPositive', 'lightNegative', 'darkPositive', 'darkNegative'];
const HEX = /^#[0-9a-fA-F]{6}$/;
const DARK_TEXT = '#171717';
const LIGHT_TEXT = '#ffffff';

// The same elements the app's own --color-success/error overrides sit on, plus
// body, so modals and menus appended to <body> pick the scheme up too.
const TARGETS = ['body', '#app-navigation', '#app-content', '#budget-toast-container'];

/** The resolved scheme currently applied, or null under Default. */
let current = null;

/**
 * The four colours, lowercase, or null when the JSON is anything other than
 * exactly those four #rrggbb strings (same rule as the PHP service).
 */
export function parseCustom(json) {
    if (typeof json !== 'string' || json === '') {
        return null;
    }
    let data;
    try {
        data = JSON.parse(json);
    } catch {
        return null;
    }
    if (data === null || typeof data !== 'object' || Array.isArray(data)
        || Object.keys(data).length !== CUSTOM_KEYS.length) {
        return null;
    }
    const out = {};
    for (const key of CUSTOM_KEYS) {
        const value = data[key];
        if (typeof value !== 'string' || !HEX.test(value)) {
            return null;
        }
        out[key] = value.toLowerCase();
    }
    return out;
}

/**
 * The colours for a setting, or null for Default, an unknown scheme or invalid
 * custom JSON. The warning colour is set for the preset only.
 */
export function resolveScheme(scheme, customJson) {
    if (scheme === 'colorblind') {
        return {
            light: { ...COLORBLIND.light },
            dark: { ...COLORBLIND.dark },
        };
    }
    if (scheme === 'custom') {
        const custom = parseCustom(customJson);
        if (custom === null) {
            return null;
        }
        return {
            light: { positive: custom.lightPositive, negative: custom.lightNegative, warning: null },
            dark: { positive: custom.darkPositive, negative: custom.darkNegative, warning: null },
        };
    }
    return null;
}

function hexToRgb(hex) {
    return [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];
}

function relativeLuminance(rgb) {
    const [r, g, b] = rgb.map((c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.x contrast ratio between two #rrggbb colours (1 to 21). */
export function contrastRatio(a, b) {
    const la = relativeLuminance(hexToRgb(a));
    const lb = relativeLuminance(hexToRgb(b));
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** The text colour that reads best on a fill: near-black or white. */
export function onColour(fill) {
    return contrastRatio(fill, DARK_TEXT) >= contrastRatio(fill, LIGHT_TEXT) ? DARK_TEXT : LIGHT_TEXT;
}

function declarations(colours) {
    const { positive, negative, warning } = colours;
    const lines = [
        `--budget-color-positive: ${positive};`,
        `--budget-color-negative: ${negative};`,
        `--color-success: ${positive};`,
        `--color-error: ${negative};`,
        `--color-success-hover: color-mix(in srgb, ${positive}, black 10%);`,
        `--color-error-hover: color-mix(in srgb, ${negative}, black 10%);`,
        `--budget-scheme-positive: ${positive};`,
        `--budget-scheme-negative: ${negative};`,
    ];
    for (const level of TINT_LEVELS) {
        lines.push(`--budget-tint-positive-${level}: color-mix(in srgb, ${positive} ${level}%, transparent);`);
        lines.push(`--budget-tint-negative-${level}: color-mix(in srgb, ${negative} ${level}%, transparent);`);
    }
    lines.push(`--budget-color-on-success: ${onColour(positive)};`);
    lines.push(`--budget-color-on-error: ${onColour(negative)};`);
    if (warning) {
        lines.push(`--color-warning: ${warning};`);
        lines.push(`--budget-scheme-warning: ${warning};`);
        lines.push(`--budget-color-on-status: ${onColour(warning)};`);
    }
    return lines;
}

function rule(selectors, lines, indent = '') {
    const body = lines.map((line) => `${indent}    ${line}`).join('\n');
    return `${indent}${selectors.join(',\n' + indent)} {\n${body}\n${indent}}`;
}

function underPrefixes(prefixes) {
    return prefixes.flatMap((prefix) => TARGETS.map((target) => (target === 'body' ? prefix : `${prefix} ${target}`)));
}

/**
 * CSS text overriding the variables for both themes. Light rules are plain;
 * dark rules use the selectors style.css's own dark block uses (chosen dark
 * theme, or the default theme following the system).
 */
export function buildStyleSheet(resolved) {
    const light = declarations(resolved.light);
    const dark = declarations(resolved.dark);
    return [
        rule(TARGETS, light),
        rule(underPrefixes(['body[data-theme-dark]', 'body[data-theme-dark-highcontrast]']), dark),
        '@media (prefers-color-scheme: dark) {\n'
            + rule(underPrefixes(['body[data-theme-default]']), dark, '    ')
            + '\n}',
    ].join('\n\n') + '\n';
}

/**
 * Apply a setting: inject, replace or remove the one <style> element, remember
 * the resolved scheme, and tell the app when it really changed.
 */
export function applyAmountColours(scheme, customJson, doc = document) {
    const resolved = resolveScheme(scheme, customJson);
    const existing = doc.getElementById(STYLE_ID);

    if (resolved === null) {
        existing?.remove();
    } else {
        const style = existing ?? doc.createElement('style');
        style.id = STYLE_ID;
        style.textContent = buildStyleSheet(resolved);
        if (!existing) {
            doc.head.appendChild(style);
        }
    }

    const changed = JSON.stringify(resolved) !== JSON.stringify(current);
    current = resolved;
    if (changed) {
        window.dispatchEvent(new CustomEvent(CHANGE_EVENT));
    }
}

/** The setting the page was served with, from #app-content's data attributes. */
export function readInitialAmountColours(doc = document) {
    const el = doc.getElementById('app-content');
    return {
        scheme: el?.getAttribute('data-amount-color-scheme') || 'default',
        custom: el?.getAttribute('data-amount-color-custom') || '',
    };
}

/** Mirrors the dark rules in style.css: chosen dark, or default following the system. */
export function isDarkTheme(doc = document) {
    const body = doc.body;
    if (!body) {
        return false;
    }
    if (body.hasAttribute('data-theme-dark') || body.hasAttribute('data-theme-dark-highcontrast')) {
        return true;
    }
    const win = doc.defaultView;
    return body.hasAttribute('data-theme-default')
        && typeof win?.matchMedia === 'function'
        && win.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function hasActiveScheme() {
    return current !== null;
}

/**
 * For canvas charts. Under Default returns `fallback` verbatim so the chart is
 * unchanged; otherwise the current mode's colour as rgba() with `alpha`.
 */
export function semanticColour(kind, fallback, alpha = 1) {
    if (current === null) {
        return fallback;
    }
    const [r, g, b] = hexToRgb(current[isDarkTheme() ? 'dark' : 'light'][kind]);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}