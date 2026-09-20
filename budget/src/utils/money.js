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
