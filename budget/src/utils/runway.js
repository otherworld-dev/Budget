/**
 * Runway helpers: the phrases the dashboard tiles show and the Chart.js
 * datasets for the balance projection. Pure, no DOM, so the six tiles share
 * one wording.
 *
 * Input is the camelCase payload of GET /api/runway.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { userLocale } from './formatters.js';

const DASH = [6, 4];

/** 10 years: past this the exact figure means nothing to anyone. */
const MAX_MONTHS = 120;

/**
 * How long the money lasts, in words.
 *
 * @param {{state: string, months?: number|null}} scenario
 * @returns {string}
 */
export function monthsPhrase(scenario) {
    switch (scenario?.state) {
        case 'running_out': {
            const months = Number(scenario.months);
            if (months >= MAX_MONTHS) return t('budget', 'More than 10 years');
            if (months < 1) return t('budget', 'Less than a month');
            const whole = Math.round(months);
            return n('budget', 'About {count} month', 'About {count} months', whole, { count: whole });
        }
        case 'not_running_out':
            return t('budget', 'Not running out');
        case 'at_zero':
            return t('budget', 'Already at or below zero');
        case 'insufficient_data':
            return t('budget', 'Not enough history yet');
        default:
            return t('budget', 'Set category budgets to see this');
    }
}

/**
 * "until around Jul 2028", or '' when the money is not running out.
 *
 * @param {{state: string, runsOutOn?: string|null}} scenario
 * @param {string} [locale] defaults to the user's Nextcloud locale
 * @returns {string}
 */
export function untilPhrase(scenario, locale) {
    if (scenario?.state !== 'running_out') return '';
    const match = /^(\d{4})-(\d{2})/.exec(String(scenario.runsOutOn ?? ''));
    if (!match) return '';
    const month = new Date(Number(match[1]), Number(match[2]) - 1, 1)
        .toLocaleDateString(locale || userLocale(), { month: 'short', year: 'numeric' });
    return t('budget', 'until around {month}', { month });
}

/**
 * How many months a budget adds or costs against current spending, rounded
 * to the nearest half month.
 *
 * @param {number|string|null} diff months, positive = longer on budget
 * @returns {string}
 */
export function differencePhrase(diff) {
    if (diff === null || diff === undefined || diff === '' || Number.isNaN(Number(diff))) return '';
    const halves = Math.round(Math.abs(Number(diff)) * 2) / 2;
    if (halves === 0) return t('budget', 'About the same');

    const whole = Math.floor(halves);
    const count = halves % 1 === 0 ? String(whole) : (whole > 0 ? String(whole) : '') + '½';
    // Only an exact 1 is singular; 1½ and ½ read as plural.
    const plural = halves === 1 ? 1 : 2;

    return Number(diff) > 0
        ? n('budget', 'About {count} more month on budget', 'About {count} more months on budget', plural, { count })
        : n('budget', 'About {count} fewer month on budget', 'About {count} fewer months on budget', plural, { count });
}

const toPoints = (rows) => (rows || []).map((row) => ({ x: row.date, y: Number(row.total) }));

/**
 * Chart.js datasets: history solid, current spending dashed, on-budget
 * dashed in the second colour (left out when it has no points).
 *
 * @param {{history?: Array, current?: Array, onBudget?: Array}} series
 * @param {string[]} colours [history/current, on-budget]
 * @returns {object[]}
 */
export function chartDatasets(series, colours) {
    const [first, second] = colours;
    const base = { pointRadius: 0, tension: 0, fill: false };
    const sets = [
        { ...base, label: t('budget', 'Balance'), data: toPoints(series?.history), borderColor: first },
        { ...base, label: t('budget', 'At current spending'), data: toPoints(series?.current), borderColor: first, borderDash: DASH },
    ];
    if (series?.onBudget?.length) {
        sets.push({ ...base, label: t('budget', 'On budget'), data: toPoints(series.onBudget), borderColor: second, borderDash: DASH });
    }
    return sets;
}

/**
 * Chart points for the one-off events (scheduled ones are already in the
 * projection, so a marker would double them).
 *
 * @param {Array<{date: string, amount: string|number, description: string, source: string}>} events
 * @returns {Array<{x: string, y: number, label: string}>}
 */
export function eventMarkers(events) {
    return (events || [])
        .filter((e) => e.source === 'oneOff')
        .map((e) => ({ x: e.date, y: Number(e.amount), label: e.description }));
}
