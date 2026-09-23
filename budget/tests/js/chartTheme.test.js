/**
 * Chart.js draws on a canvas, so its text and grid colours come from its own
 * defaults rather than CSS. applyChartTheme() points them at Nextcloud's theme
 * variables so charts stay readable on the dark theme.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { applyChartTheme } from '../../src/utils/chartTheme.js';

afterEach(() => {
    document.body.removeAttribute('style');
});

describe('applyChartTheme', () => {
    it('takes text and border colours from the theme variables', () => {
        document.body.style.setProperty('--color-text-maxcontrast', '#a1a1a1');
        document.body.style.setProperty('--color-border', '#3c3c3c');
        const Chart = { defaults: {} };

        applyChartTheme(Chart);

        expect(Chart.defaults.color).toBe('#a1a1a1');
        expect(Chart.defaults.borderColor).toBe('#3c3c3c');
    });

    it('falls back to a readable grey when the theme sets nothing', () => {
        const Chart = { defaults: {} };

        applyChartTheme(Chart);

        expect(Chart.defaults.color).toMatch(/^#[0-9a-f]{6}$/i);
        expect(Chart.defaults.borderColor).toMatch(/^#[0-9a-f]{6}$/i);
    });
});
