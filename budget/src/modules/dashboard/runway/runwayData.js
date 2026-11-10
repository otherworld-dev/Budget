/**
 * What every runway tile shares: the one request for GET /api/runway, the
 * dashboard state the tiles read (accounts, settings, the chart registry),
 * and small DOM and formatting helpers.
 *
 * Every runway tile shows the same numbers because they all render from the
 * same payload. The request is shared, so a dashboard with six runway tiles
 * still asks the server once.
 */

import { apiFetch } from '../../../utils/api.js';
import * as formatters from '../../../utils/formatters.js';

/**
 * Options for a t()/n() whose result is written with textContent. The
 * library escapes placeholders and runs the result through DOMPurify by
 * default, which is right for innerHTML; as text, "B&Q" would read
 * "B&amp;Q" and a description holding markup would lose it.
 */
export const AS_TEXT = { escape: false, sanitize: false };

let context = {
    accounts: () => [],
    settings: () => ({}),
    charts: () => ({}),
    openTransaction: null,
};

/**
 * Point the tiles at the dashboard they live in.
 *
 * @param {object} ctx
 * @param {Function} [ctx.accounts] every account the user can see
 * @param {Function} [ctx.settings] the user's settings (number and date format)
 * @param {Function} [ctx.charts] the dashboard's chart registry, so charts are resized and replaced like the others
 * @param {Function} [ctx.openTransaction] opens one transaction's edit form
 */
export function setRunwayContext(ctx) {
    context = { ...context, ...ctx };
}

export function runwayContext() {
    return context;
}

let pending = null;

/**
 * The runway payload, fetched once and shared by every tile that asks.
 *
 * @param {boolean} [force] ask the server again (after a change)
 * @returns {Promise<object>}
 */
export function loadRunway(force = false) {
    if (!pending || force) {
        const request = apiFetch('/apps/budget/api/runway');
        pending = request;
        // A failure is not kept: the next tile, or the next load, asks again.
        request.catch(() => {
            if (pending === request) pending = null;
        });
    }
    return pending;
}

/** Forget the shared payload, so the next dashboard load asks again. */
export function resetRunway() {
    pending = null;
}

export function settings() {
    return context.settings() || {};
}

/**
 * @param {string|number} amount
 * @param {string} [currency] defaults to the user's
 * @returns {string}
 */
export function money(amount, currency) {
    return formatters.formatCurrency(Number(amount) || 0, currency, settings());
}

export function moneyCompact(amount, currency) {
    return formatters.formatCurrencyCompact(Number(amount) || 0, currency, settings());
}

export function longDate(date) {
    return formatters.formatDate(date, settings());
}

/**
 * "Jul 28" for a chart axis.
 *
 * @param {string} date Y-m-d
 * @returns {string}
 */
export function shortMonth(date) {
    const match = /^(\d{4})-(\d{2})/.exec(String(date ?? ''));
    if (!match) return '';
    return new Date(Number(match[1]), Number(match[2]) - 1, 1)
        .toLocaleDateString(formatters.userLocale(), { month: 'short', year: '2-digit' });
}

/**
 * Today in the browser's time zone, Y-m-d.
 *
 * @returns {string}
 */
export function today() {
    return formatters.formatDateForAPI(new Date());
}

/**
 * A theme colour for a canvas, which CSS cannot reach. Read from the element
 * the chart sits in, so the app's own overrides (and the dark theme's) apply.
 *
 * @param {Element} element
 * @param {string} name CSS custom property
 * @param {string} fallback
 * @returns {string}
 */
export function cssVar(element, name, fallback) {
    try {
        const value = getComputedStyle(element || document.body).getPropertyValue(name).trim();
        return value || fallback;
    } catch {
        return fallback;
    }
}

/**
 * Builds an element. Text always goes in as text.
 *
 * @param {string} tag
 * @param {string} [className]
 * @param {string} [text]
 * @returns {HTMLElement}
 */
export function el(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
}

/**
 * A plain button.
 *
 * @param {string} label
 * @param {string} className
 * @param {Function} onClick
 * @returns {HTMLButtonElement}
 */
export function button(label, className, onClick) {
    const node = el('button', className, label);
    node.type = 'button';
    node.addEventListener('click', (event) => {
        event.stopPropagation();
        onClick(event);
    });
    return node;
}

/**
 * An amount's direction: money out of the runway is negative.
 *
 * @param {string|number} amount
 * @returns {string} 'negative' or 'positive'
 */
export function amountClass(amount) {
    return Number(amount) < 0 ? 'negative' : 'positive';
}

/**
 * A signed amount with its sign spelled out, "+£250.00" / "-£2,000.00".
 *
 * @param {string|number} amount
 * @param {string} currency
 * @returns {string}
 */
export function signedMoney(amount, currency) {
    const value = Number(amount) || 0;
    return (value > 0 ? '+' : '') + money(value, currency);
}
