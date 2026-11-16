/**
 * What-if scenarios on the Forecast page (#487).
 *
 * The live forecast projects each month's income and spending and carries
 * the balance on from today's: every month's balance is the one before plus
 * that month's income less its spending. A scenario reworks those same
 * months, so with nothing changed it lands on the forecast's own balances
 * and differs only by what the user changed.
 *
 * Worked in whole cents. The server rounds each month's income and spending
 * on their own, so its balances can be a penny or two off a sum of them:
 * compare a scenario with this run with nothing changed, never with the
 * server's balances, or an empty scenario shows a difference.
 */

/**
 * @typedef {object} Scenario
 * @property {number} incomePercent   -10 for a 10% pay cut
 * @property {number} spendingPercent -5 to spend 5% less
 * @property {number} monthlyAmount   added every month; negative for a new bill
 * @property {number} oneOffAmount    added once; negative for a big purchase
 * @property {number} oneOffMonth     index of the projected month it lands in
 */

/** @type {Scenario} */
export const EMPTY_SCENARIO = Object.freeze({
    incomePercent: 0,
    spendingPercent: 0,
    monthlyAmount: 0,
    oneOffAmount: 0,
    oneOffMonth: 0,
});

function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function toCents(value) {
    return Math.round(toNumber(value) * 100);
}

/**
 * Reads a scenario out of the boxes' text, treating an empty or unreadable
 * box as no change.
 *
 * @param {object} raw
 * @returns {Scenario}
 */
export function parseScenario(raw = {}) {
    return {
        incomePercent: toNumber(raw.incomePercent),
        spendingPercent: toNumber(raw.spendingPercent),
        monthlyAmount: toNumber(raw.monthlyAmount),
        oneOffAmount: toNumber(raw.oneOffAmount),
        oneOffMonth: Math.max(0, Math.trunc(toNumber(raw.oneOffMonth))),
    };
}

/**
 * Whether the scenario changes anything. The month a one-off lands in
 * changes nothing on its own.
 *
 * @param {Scenario} scenario
 * @returns {boolean}
 */
export function isEmptyScenario(scenario) {
    return !toNumber(scenario.incomePercent)
        && !toNumber(scenario.spendingPercent)
        && !toCents(scenario.monthlyAmount)
        && !toCents(scenario.oneOffAmount);
}

/**
 * Runs the forecast's months again with the scenario applied.
 *
 * Income and spending can be cut by up to 100%, never below nothing. A
 * one-off whose month is past the end of the forecast is left out, as it
 * would be on the chart.
 *
 * @param {number} currentBalance the forecast's starting balance
 * @param {Array<{yearMonth?: string, month?: string, income: number, expenses: number}>} projections
 * @param {Scenario} scenario
 * @returns {{months: Array<{yearMonth?: string, month?: string, income: number, expenses: number, balance: number}>,
 *   projectedBalance: number, lowest: {balance: number, index: number}|null}}
 */
export function applyScenario(currentBalance, projections, scenario) {
    const incomeFactor = Math.max(0, 1 + toNumber(scenario.incomePercent) / 100);
    const spendingFactor = Math.max(0, 1 + toNumber(scenario.spendingPercent) / 100);
    const monthly = toCents(scenario.monthlyAmount);
    const oneOff = toCents(scenario.oneOffAmount);
    const oneOffMonth = Math.trunc(toNumber(scenario.oneOffMonth));

    let balance = toCents(currentBalance);
    let lowest = null;

    const months = (projections || []).map((projection, index) => {
        const income = Math.round(toCents(projection.income) * incomeFactor);
        const expenses = Math.round(toCents(projection.expenses) * spendingFactor);
        balance += income - expenses + monthly + (index === oneOffMonth ? oneOff : 0);

        if (lowest === null || balance < lowest.balance) {
            lowest = { balance, index };
        }

        return {
            yearMonth: projection.yearMonth,
            month: projection.month,
            income: income / 100,
            expenses: expenses / 100,
            balance: balance / 100,
        };
    });

    return {
        months,
        projectedBalance: balance / 100,
        lowest: lowest === null ? null : { balance: lowest.balance / 100, index: lowest.index },
    };
}
