/**
 * Browser side of the money-in / money-out colour setting (#432).
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {
    COLORBLIND,
    COLORBLIND_WARNING_FILL,
    TINT_LEVELS,
    parseCustom,
    resolveScheme,
    contrastRatio,
    onColour,
    buildStyleSheet,
    applyAmountColours,
    readInitialAmountColours,
    semanticColour,
    hasActiveScheme,
} from '../../src/utils/amountColours.js';

const CUSTOM = '{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}';

afterEach(() => {
    applyAmountColours('default', '');
    document.body.removeAttribute('data-theme-dark');
    document.body.removeAttribute('data-theme-default');
    document.getElementById('app-content')?.remove();
});

describe('parseCustom', () => {
    it('accepts exactly four #rrggbb strings and lowercases them', () => {
        const upper = '{"lightPositive":"#0B62A4","lightNegative":"#A84800","darkPositive":"#6CB4EE","darkNegative":"#F5A35C"}';
        expect(parseCustom(upper)).toEqual({
            lightPositive: '#0b62a4',
            lightNegative: '#a84800',
            darkPositive: '#6cb4ee',
            darkNegative: '#f5a35c',
        });
    });

    it.each([
        ['null', null],
        ['empty', ''],
        ['not JSON', '{bad'],
        ['a bare colour', '#fff'],
        ['an array', '["#000000","#000000","#000000","#000000"]'],
        ['a missing key', '{"lightPositive":"#000000","lightNegative":"#000000","darkPositive":"#000000"}'],
        ['an extra key', '{"lightPositive":"#000000","lightNegative":"#000000","darkPositive":"#000000","darkNegative":"#000000","x":"#000000"}'],
        ['a short hex', '{"lightPositive":"#000","lightNegative":"#000000","darkPositive":"#000000","darkNegative":"#000000"}'],
        ['a non-string', '{"lightPositive":0,"lightNegative":"#000000","darkPositive":"#000000","darkNegative":"#000000"}'],
        ['a trailing newline', '{"lightPositive":"#000000\\n","lightNegative":"#000000","darkPositive":"#000000","darkNegative":"#000000"}'],
    ])('rejects %s', (_label, json) => {
        expect(parseCustom(json)).toBeNull();
    });
});

describe('resolveScheme', () => {
    it('returns null for default, unknown and garbage', () => {
        expect(resolveScheme('default', '')).toBeNull();
        expect(resolveScheme('pink', CUSTOM)).toBeNull();
        expect(resolveScheme(null, null)).toBeNull();
        expect(resolveScheme('custom', '{bad')).toBeNull();
        expect(resolveScheme('custom', '#fff')).toBeNull();
    });

    it('colourblind resolves to the preset with a warning', () => {
        expect(resolveScheme('colorblind', '')).toEqual(COLORBLIND);
        expect(resolveScheme('colorblind', '').light.warning).toBe('#8a6a00');
    });

    it('custom resolves without a warning', () => {
        const resolved = resolveScheme('custom', CUSTOM);
        expect(resolved.light).toEqual({ positive: '#0b62a4', negative: '#a84800', warning: null });
        expect(resolved.dark).toEqual({ positive: '#6cb4ee', negative: '#f5a35c', warning: null });
    });

    it('preset meets contrast', () => {
        for (const colour of Object.values(COLORBLIND.light)) {
            expect(contrastRatio(colour, '#ffffff')).toBeGreaterThanOrEqual(4.5);
        }
        for (const colour of Object.values(COLORBLIND.dark)) {
            expect(contrastRatio(colour, '#171717')).toBeGreaterThanOrEqual(4.5);
            expect(contrastRatio(colour, '#292929')).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('preset matches the PHP service', () => {
        const php = fs.readFileSync(path.resolve(__dirname, '../../lib/Service/AmountColours.php'), 'utf8');
        for (const mode of ['light', 'dark']) {
            for (const colour of Object.values(COLORBLIND[mode])) {
                expect(php).toContain(colour);
            }
        }
    });
});

describe('contrast helpers', () => {
    it('measures black on white as 21:1', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
    });

    it('onColour picks dark text on light fills and white on dark', () => {
        expect(onColour('#f5a35c')).toBe('#171717');
        expect(onColour('#fefefe')).toBe('#171717');
        expect(onColour('#a84800')).toBe('#ffffff');
        expect(onColour('#0b62a4')).toBe('#ffffff');
    });
});

describe('buildStyleSheet', () => {
    const css = buildStyleSheet(resolveScheme('colorblind', ''));

    it('overrides the variables for body-level modals too', () => {
        const block = css.split('}')[0];
        expect(block).toMatch(/(^|,\n)body(,|\s*\{)/);
        expect(block).toContain('--color-error: #a84800;');
        expect(block).toContain('--color-success: #0b62a4;');
        expect(block).toContain('--color-error-hover: color-mix(in srgb, #a84800, black 10%);');
        expect(block).toContain('--budget-color-on-error: #ffffff;');
        expect(block).toContain('--color-warning: #8a6a00;');
        expect(block).toContain('--budget-color-on-status: #ffffff;');
    });

    it('beats an explicitly chosen Light theme on body-level elements', () => {
        // Nextcloud injects [data-theme-light] { --color-error: ... } on <body>
        // (0,1,0), which a plain `body` (0,0,1) loses to.
        const block = css.split('}')[0];
        expect(block).toMatch(/(^|,\n)body\[data-theme-light\](,|\s*\{)/);
        expect(block).toMatch(/(^|,\n)body\[data-theme-light-highcontrast\](,|\s*\{)/);
    });

    it('puts the light rule before the dark ones so equal specificity favours dark', () => {
        const lightRule = css.indexOf('body[data-theme-light]');
        expect(lightRule).toBeGreaterThan(-1);
        expect(lightRule).toBeLessThan(css.indexOf('body[data-theme-dark]'));
        expect(lightRule).toBeLessThan(css.indexOf('@media (prefers-color-scheme: dark)'));
    });

    it('sets a tint for every level, both directions', () => {
        for (const level of TINT_LEVELS) {
            expect(css).toContain(`--budget-tint-positive-${level}: color-mix(in srgb, #0b62a4 ${level}%, transparent);`);
            expect(css).toContain(`--budget-tint-negative-${level}: color-mix(in srgb, #a84800 ${level}%, transparent);`);
        }
    });

    it('has dark rules for both dark selectors and the system query', () => {
        expect(css).toContain('body[data-theme-dark],\n');
        expect(css).toContain('body[data-theme-dark-highcontrast] #budget-toast-container');
        expect(css).toContain('@media (prefers-color-scheme: dark) {');
        expect(css).toContain('body[data-theme-default] #app-content');
        expect(css).toContain('--color-error: #f5a35c;');
        expect(css).toContain('--budget-color-on-status: #171717;');
    });

    it('leaves the warning colour alone for custom schemes', () => {
        const custom = buildStyleSheet(resolveScheme('custom', CUSTOM));
        expect(custom).not.toContain('--color-warning');
        expect(custom).not.toContain('--budget-color-on-status');
        expect(custom).not.toContain('--budget-scheme-warning-fill');
    });

    it('gives near-limit bars a lighter fill than the warning text in light mode', () => {
        // The light warning text (#8a6a00) sits too close to the orange
        // over-budget fill for deuteranopes, so bars use a lighter yellow.
        const lightBlock = css.split('}')[0];
        expect(lightBlock).toContain(`--budget-scheme-warning-fill: ${COLORBLIND_WARNING_FILL.light};`);
        expect(css).toContain(`--budget-scheme-warning-fill: ${COLORBLIND_WARNING_FILL.dark};`);
    });

    it('keeps the near-limit fill well apart from the over-budget fill in lightness', () => {
        // Lightness is the cue red-green colour blindness leaves intact.
        expect(contrastRatio(COLORBLIND_WARNING_FILL.light, COLORBLIND.light.negative)).toBeGreaterThanOrEqual(2);
        expect(contrastRatio(COLORBLIND_WARNING_FILL.dark, COLORBLIND.dark.negative)).toBeGreaterThanOrEqual(1.3);
    });
});

describe('applyAmountColours', () => {
    const styleCount = () => document.querySelectorAll('#budget-amount-colours').length;

    it('injects, replaces and removes one style element', () => {
        applyAmountColours('default', '');
        expect(styleCount()).toBe(0);

        applyAmountColours('colorblind', '');
        expect(styleCount()).toBe(1);
        expect(document.getElementById('budget-amount-colours').parentElement).toBe(document.head);
        expect(document.getElementById('budget-amount-colours').textContent).toContain('#a84800');

        applyAmountColours('custom', CUSTOM.replace('#a84800', '#112233'));
        expect(styleCount()).toBe(1);
        expect(document.getElementById('budget-amount-colours').textContent).toContain('#112233');

        applyAmountColours('default', '');
        expect(styleCount()).toBe(0);
    });

    it('falls back to default for an invalid custom value', () => {
        applyAmountColours('custom', '{bad');
        expect(styleCount()).toBe(0);
        expect(hasActiveScheme()).toBe(false);
    });

    it('fires the change event only on a real change', () => {
        const listener = vi.fn();
        window.addEventListener('budget:amount-colours-changed', listener);
        try {
            applyAmountColours('default', '');
            expect(listener).toHaveBeenCalledTimes(0);

            applyAmountColours('colorblind', '');
            expect(listener).toHaveBeenCalledTimes(1);
            expect(hasActiveScheme()).toBe(true);

            applyAmountColours('colorblind', '');
            expect(listener).toHaveBeenCalledTimes(1);

            applyAmountColours('custom', CUSTOM);
            expect(listener).toHaveBeenCalledTimes(2);

            applyAmountColours('default', '');
            expect(listener).toHaveBeenCalledTimes(3);
            expect(hasActiveScheme()).toBe(false);
        } finally {
            window.removeEventListener('budget:amount-colours-changed', listener);
        }
    });
});

describe('semanticColour', () => {
    it('returns the fallback untouched under default', () => {
        expect(semanticColour('positive', 'rgba(46, 125, 50, 0.7)', 0.7)).toBe('rgba(46, 125, 50, 0.7)');
    });

    it('returns the scheme colour with alpha', () => {
        applyAmountColours('colorblind', '');
        expect(semanticColour('positive', 'rgba(46, 125, 50, 0.7)', 0.7)).toBe('rgba(11, 98, 164, 0.7)');
        expect(semanticColour('negative', 'x')).toBe('rgba(168, 72, 0, 1)');

        document.body.setAttribute('data-theme-dark', '');
        expect(semanticColour('positive', 'rgba(46, 125, 50, 0.7)', 0.7)).toBe('rgba(108, 180, 238, 0.7)');
    });

    it('follows the system when the default theme is in use', () => {
        applyAmountColours('colorblind', '');
        document.body.setAttribute('data-theme-default', '');
        const original = window.matchMedia;
        window.matchMedia = (query) => ({ matches: query === '(prefers-color-scheme: dark)' });
        try {
            expect(semanticColour('positive', 'x')).toBe('rgba(108, 180, 238, 1)');
        } finally {
            window.matchMedia = original;
        }
    });
});

describe('readInitialAmountColours', () => {
    it('reads the data attributes and defaults when absent', () => {
        expect(readInitialAmountColours()).toEqual({ scheme: 'default', custom: '' });

        const el = document.createElement('div');
        el.id = 'app-content';
        el.setAttribute('data-amount-color-scheme', 'custom');
        el.setAttribute('data-amount-color-custom', CUSTOM);
        document.body.appendChild(el);

        expect(readInitialAmountColours()).toEqual({ scheme: 'custom', custom: CUSTOM });
    });
});