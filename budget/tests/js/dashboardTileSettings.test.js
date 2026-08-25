/**
 * The dashboard offers a Date Range dropdown on tiles that cannot act on a past
 * window: bills and forecasts look forward, and Year-over-Year counts years.
 * Those tiles get their own numeric settings instead (see the 2026-08-25 plan).
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { resolveTileNumberSetting, FORWARD_HORIZONS, precedingWindow } from '../../src/utils/formatters.js';
import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = { settings: {}, dashboardConfig: { widgets: { tileSettings } } };
    return mod;
}

describe('resolveTileNumberSetting', () => {
    it('accepts a value the tile offers', () => {
        expect(resolveTileNumberSetting(60, FORWARD_HORIZONS, 30)).toBe(60);
    });

    it('accepts the value as a string, which is how a select reports it', () => {
        expect(resolveTileNumberSetting('90', FORWARD_HORIZONS, 30)).toBe(90);
    });

    it('falls back when nothing is saved', () => {
        expect(resolveTileNumberSetting(undefined, FORWARD_HORIZONS, 30)).toBe(30);
    });

    it('falls back for a value the tile does not offer', () => {
        expect(resolveTileNumberSetting(45, FORWARD_HORIZONS, 30)).toBe(30);
    });

    it('falls back for junk rather than yielding NaN', () => {
        expect(resolveTileNumberSetting('soon', FORWARD_HORIZONS, 30)).toBe(30);
    });
});

describe('DashboardModule._tileNumberSetting', () => {
    it('reads the tile instance setting', () => {
        const dash = makeDashboard({ upcomingBills: { forwardHorizon: 90 } });
        expect(dash._tileNumberSetting('upcomingBills', 'forwardHorizon', FORWARD_HORIZONS, 30)).toBe(90);
    });

    it('resolves a duplicated instance by its own settings', () => {
        const dash = makeDashboard({ 'upcomingBills__2': { forwardHorizon: 60 } });
        expect(dash._tileNumberSetting('upcomingBills__2', 'forwardHorizon', FORWARD_HORIZONS, 30)).toBe(60);
    });

    it('falls back when the tile has no settings at all', () => {
        const dash = makeDashboard({});
        expect(dash._tileNumberSetting('upcomingBills', 'forwardHorizon', FORWARD_HORIZONS, 30)).toBe(30);
    });
});

describe('DashboardModule._numberChoiceField', () => {
    it('renders one option per choice', () => {
        const dash = makeDashboard();
        const html = dash._numberChoiceField('forwardHorizon', 'Horizon', [30, 60, 90], 30, (n) => `Next ${n} days`);
        expect(html).toContain('value="30"');
        expect(html).toContain('value="60"');
        expect(html).toContain('value="90"');
    });

    it('marks the current choice selected', () => {
        const dash = makeDashboard();
        const html = dash._numberChoiceField('forwardHorizon', 'Horizon', [30, 60, 90], 60, (n) => `Next ${n} days`);
        expect(html).toMatch(/value="60"\s+selected/);
    });

    it('names the setting so the settings modal can save it', () => {
        const dash = makeDashboard();
        const html = dash._numberChoiceField('yearsToCompare', 'Years', [2, 3, 5], 2, (n) => `${n} years`);
        expect(html).toContain('data-setting="yearsToCompare"');
    });
});

describe('precedingWindow', () => {
    it('ends the day before the window starts', () => {
        // 2026-08-01..2026-08-31 is 31 days; the one before is 2026-07-01..2026-07-31
        // (also 31 days — July and August both have 31 days).
        expect(precedingWindow('2026-08-01', '2026-08-31')).toEqual({
            start: '2026-07-01',
            end: '2026-07-31',
        });
    });

    it('keeps the same inclusive length', () => {
        // 2026-07-25..2026-08-24 is 31 days; the one before is 2026-06-24..2026-07-24.
        expect(precedingWindow('2026-07-25', '2026-08-24')).toEqual({
            start: '2026-06-24',
            end: '2026-07-24',
        });
    });

    it('handles a single-day window', () => {
        expect(precedingWindow('2026-08-10', '2026-08-10')).toEqual({
            start: '2026-08-09',
            end: '2026-08-09',
        });
    });
});
