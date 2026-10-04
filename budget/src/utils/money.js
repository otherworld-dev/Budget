/**
 * Money read as whole pennies, so sums never pick up floating-point dust.
 */

/**
 * @param {number|string|null} value an amount in currency units
 * @returns {number} whole pennies, or NaN when it is not a number
 */
export function toCents(value) {
    if (value === null || value === undefined || String(value).trim() === '') {
        return NaN;
    }
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n * 100) : NaN;
}

/**
 * An amount rounded half away from zero to a currency's smallest unit, the
 * way the server rounds money. Floating-point dust is cleared first, so a
 * sum that should be 665.625 but lands on 665.62499999999 still rounds up,
 * and a total that should be zero never shows as minus zero.
 *
 * @param {number|string} value an amount in currency units
 * @param {number} decimals the currency's decimal places
 * @returns {number}
 */
export function roundMoney(value, decimals = 2) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return 0;
    }
    const factor = 10 ** decimals;
    const rounded = Math.round(Number((Math.abs(n) * factor).toPrecision(14))) / factor;
    return n < 0 && rounded !== 0 ? -rounded : rounded;
}
