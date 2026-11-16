/**
 * What-if scenarios on the Forecast page (#487). Scenario modelling was
 * listed as a feature from 1.0 but the page never had any; these cover the
 * sum it runs and the section that shows it.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/chart.js', () => ({ default: class {} }));

import {
    applyScenario,
    EMPTY_SCENARIO,
    isEmptyScenario,
    parseScenario,
} from '../../src/utils/forecastScenario.js';
import ForecastModule from '../../src/modules/forecast/ForecastModule.js';

const projections = [
    { yearMonth: '2026-12', month: 'Dec 2026', income: 2000, expenses: 1500 },
    { yearMonth: '2027-01', month: 'Jan 2027', income: 2000, expenses: 1800 },
    { yearMonth: '2027-02', month: 'Feb 2027', income: 2000, expenses: 1600 },
];

describe('applyScenario', () => {
    it('lands on the forecast when nothing is changed', () => {
        const result = applyScenario(1000, projections, EMPTY_SCENARIO);
        expect(result.months.map(m => m.balance)).toEqual([1500, 1700, 2100]);
        expect(result.projectedBalance).toBe(2100);
    });

    it('scales income and spending by their percentages', () => {
        const result = applyScenario(1000, projections, { ...EMPTY_SCENARIO, incomePercent: -10, spendingPercent: 5 });
        // 1800 in, 1575 / 1890 / 1680 out
        expect(result.months.map(m => m.balance)).toEqual([1225, 1135, 1255]);
        expect(result.months[0].income).toBe(1800);
        expect(result.months[0].expenses).toBe(1575);
    });

    it('adds a monthly amount every month and a one-off once', () => {
        const result = applyScenario(1000, projections, {
            ...EMPTY_SCENARIO,
            monthlyAmount: -200,
            oneOffAmount: -1500,
            oneOffMonth: 1,
        });
        expect(result.months.map(m => m.balance)).toEqual([1300, -200, 0]);
        expect(result.lowest).toEqual({ balance: -200, index: 1 });
    });

    it('leaves out a one-off past the end of the forecast', () => {
        const result = applyScenario(1000, projections, { ...EMPTY_SCENARIO, oneOffAmount: -500, oneOffMonth: 7 });
        expect(result.projectedBalance).toBe(2100);
    });

    it('never cuts income or spending below nothing', () => {
        const result = applyScenario(0, projections, { ...EMPTY_SCENARIO, incomePercent: -150, spendingPercent: -100 });
        expect(result.months.every(m => m.income === 0 && m.expenses === 0)).toBe(true);
        expect(result.projectedBalance).toBe(0);
    });

    it('does not drift over two years of percentages', () => {
        const months = Array.from({ length: 24 }, () => ({ income: 1234.56, expenses: 987.65 }));
        const result = applyScenario(0.1, months, { ...EMPTY_SCENARIO, incomePercent: 3.3 });
        // 1275.30 in (1234.56 x 1.033, to the cent) and 987.65 out, 24 times
        expect(result.projectedBalance).toBe(6903.7);
    });
});

describe('parseScenario / isEmptyScenario', () => {
    it('treats empty and unreadable boxes as no change', () => {
        const scenario = parseScenario({ incomePercent: '', spendingPercent: 'abc', monthlyAmount: '-', oneOffAmount: undefined, oneOffMonth: '2' });
        expect(scenario).toEqual({ ...EMPTY_SCENARIO, oneOffMonth: 2 });
        expect(isEmptyScenario(scenario)).toBe(true);
    });

    it('counts any change as a scenario', () => {
        expect(isEmptyScenario(parseScenario({ spendingPercent: '-5' }))).toBe(false);
        expect(isEmptyScenario(parseScenario({ oneOffAmount: '0.01' }))).toBe(false);
    });
});

describe('ForecastModule what-if section', () => {
    let mod;
    let chart;

    beforeEach(() => {
        document.body.innerHTML = `
            <input id="forecast-scenario-income">
            <input id="forecast-scenario-spending">
            <input id="forecast-scenario-monthly">
            <input id="forecast-scenario-oneoff">
            <select id="forecast-scenario-oneoff-month"></select>
            <button id="forecast-scenario-reset"></button>
            <div id="forecast-scenario-result"></div>
        `;
        chart = {
            data: { datasets: [{ label: 'Projected Balance' }] },
            update: vi.fn(),
        };
        mod = Object.create(ForecastModule.prototype);
        mod.app = { settings: {}, accounts: [] };
        mod.forecastCurrency = 'GBP';
        mod.balanceChart = chart;
        mod.forecastData = { currentBalance: 1000, monthlyProjections: projections };
        mod.populateScenarioMonths(projections);
    });

    it('lists the forecast months for the one-off and keeps the choice', () => {
        const select = document.getElementById('forecast-scenario-oneoff-month');
        expect(select.options.length).toBe(3);
        select.value = '2';
        mod.populateScenarioMonths(projections.slice(0, 2));
        expect(select.options.length).toBe(2);
        expect(select.value).toBe('0');
        select.value = '1';
        mod.populateScenarioMonths(projections);
        expect(select.value).toBe('1');
    });

    it('shows nothing and draws no line with nothing changed', () => {
        mod.refreshScenario();
        expect(document.getElementById('forecast-scenario-result').children.length).toBe(0);
        expect(chart.data.datasets).toHaveLength(1);
        expect(document.getElementById('forecast-scenario-reset').style.display).toBe('none');
    });

    it('draws one dashed line and warns when the balance goes below zero', () => {
        document.getElementById('forecast-scenario-monthly').value = '-200';
        document.getElementById('forecast-scenario-oneoff').value = '-1500';
        document.getElementById('forecast-scenario-oneoff-month').value = '1';
        mod.refreshScenario();
        mod.refreshScenario();

        const lines = chart.data.datasets.filter(d => d.whatIf);
        expect(lines).toHaveLength(1);
        expect(lines[0].data).toEqual([1300, -200, 0]);

        const text = document.getElementById('forecast-scenario-result').textContent;
        expect(text).toContain('compared with the forecast');
        expect(text).toContain('below zero');
        expect(document.querySelector('.forecast-scenario-change').classList.contains('negative')).toBe(true);
    });

    it('takes the line off again when reset to nothing', () => {
        document.getElementById('forecast-scenario-income').value = '10';
        mod.refreshScenario();
        expect(chart.data.datasets.filter(d => d.whatIf)).toHaveLength(1);

        document.getElementById('forecast-scenario-income').value = '';
        mod.refreshScenario();
        expect(chart.data.datasets.filter(d => d.whatIf)).toHaveLength(0);
        expect(document.getElementById('forecast-scenario-result').children.length).toBe(0);
    });
});
