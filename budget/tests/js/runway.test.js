/**
 * Runway text and chart helpers: the phrases the dashboard tiles show and
 * the Chart.js datasets for the balance projection.
 */

import { describe, it, expect, vi } from 'vitest';

const fill = vi.hoisted(() => (text, params) =>
    String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)));

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) => fill(text, params),
    translatePlural: (_app, singular, plural, count, params = {}) => fill(count === 1 ? singular : plural, params),
    getCanonicalLocale: () => 'en',
}));

import { monthsPhrase, untilPhrase, differencePhrase, chartDatasets, eventMarkers } from '../../src/utils/runway.js';

const running = (months, runsOutOn = null) => ({ state: 'running_out', months, runsOutOn, monthlyDrain: '100.00' });

describe('monthsPhrase', () => {
    it('reads each state', () => {
        expect(monthsPhrase({ state: 'not_running_out' })).toBe('Not running out');
        expect(monthsPhrase({ state: 'at_zero' })).toBe('Already at or below zero');
        expect(monthsPhrase({ state: 'insufficient_data' })).toBe('Not enough history yet');
        expect(monthsPhrase({ state: 'unavailable' })).toBe('Set category budgets to see this');
    });

    it('words a running-out scenario by its months', () => {
        expect(monthsPhrase(running(0.4))).toBe('Less than a month');
        expect(monthsPhrase(running(1.2))).toBe('About 1 month');
        expect(monthsPhrase(running(20.7))).toBe('About 21 months');
        expect(monthsPhrase(running(120))).toBe('More than 10 years');
    });
});

describe('untilPhrase', () => {
    it('names the month the money runs out', () => {
        expect(untilPhrase(running(20.7, '2028-07-19'), 'en')).toBe('until around Jul 2028');
    });

    it('is empty unless the scenario is running out', () => {
        expect(untilPhrase({ state: 'not_running_out', runsOutOn: null }, 'en')).toBe('');
        expect(untilPhrase(running(3, null), 'en')).toBe('');
    });
});

describe('differencePhrase', () => {
    it('rounds to the nearest half month', () => {
        expect(differencePhrase(4.6)).toBe('About 4½ more months on budget');
        expect(differencePhrase(-2.2)).toBe('About 2 fewer months on budget');
        expect(differencePhrase(1)).toBe('About 1 more month on budget');
    });

    it('calls a negligible difference the same', () => {
        expect(differencePhrase(0.2)).toBe('About the same');
        expect(differencePhrase(-0.2)).toBe('About the same');
    });

    it('is empty without a difference', () => {
        expect(differencePhrase(null)).toBe('');
        expect(differencePhrase(undefined)).toBe('');
    });
});

describe('chartDatasets', () => {
    const series = {
        history: [{ date: '2026-10-01', total: '1000.00' }],
        current: [{ date: '2026-11-01', total: 900 }],
        onBudget: [{ date: '2026-11-01', total: '950.50' }],
    };
    const colours = ['#111111', '#222222', '#333333'];

    it('builds history solid and the projections dashed', () => {
        const sets = chartDatasets(series, colours);

        expect(sets).toHaveLength(3);
        expect(sets[0].borderDash).toBeUndefined();
        expect(sets[1].borderDash).toEqual([6, 4]);
        expect(sets[2].borderDash).toEqual([6, 4]);
        expect(sets[0].borderColor).toBe('#111111');
        expect(sets[1].borderColor).toBe('#111111');
        expect(sets[2].borderColor).toBe('#222222');
        expect(sets[2].data).toEqual([{ x: '2026-11-01', y: 950.5 }]);
    });

    it('omits on-budget when its series is empty', () => {
        expect(chartDatasets({ ...series, onBudget: [] }, colours)).toHaveLength(2);
        expect(chartDatasets({ history: series.history, current: series.current }, colours)).toHaveLength(2);
    });
});

describe('eventMarkers', () => {
    it('keeps the one-off events as points', () => {
        const pts = eventMarkers([
            { date: '2026-12-01', amount: '-500.00', description: 'Boiler', source: 'oneOff' },
            { date: '2026-12-05', amount: '-20.00', description: 'Bill', source: 'scheduled' },
        ]);

        expect(pts).toEqual([{ x: '2026-12-01', y: -500, label: 'Boiler' }]);
        expect(eventMarkers(null)).toEqual([]);
    });
});
