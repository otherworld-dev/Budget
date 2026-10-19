/**
 * Where a scheduled item (bill, transfer, recurring income) stands, judged by
 * its next occurrence rather than by the calendar month.
 */
import * as formatters from './formatters.js';

/** Roughly one interval of each schedule, in days */
const CYCLE_DAYS = {
    daily: 1,
    weekly: 7,
    biweekly: 14,
    'semi-monthly': 16,
    monthly: 31,
    quarterly: 92,
    'semi-annually': 183,
    yearly: 366,
};

/** How close a due date has to be to count as soon */
const SOON_DAYS = 7;

/**
 * How many times a year a Custom schedule falls, read as the server reads
 * it: its months, else its dates. 0 when it has no usable pattern.
 *
 * @param {string|object|null} pattern the custom recurrence pattern, as JSON or parsed
 * @return {number}
 */
function customOccurrencesPerYear(pattern) {
    let parsed = pattern;
    if (typeof parsed === 'string') {
        try {
            parsed = JSON.parse(parsed);
        } catch {
            return 0;
        }
    }
    if (!parsed || typeof parsed !== 'object') {
        return 0;
    }
    if (Array.isArray(parsed.months) && parsed.months.length > 0) {
        return new Set(parsed.months.map(Number)).size;
    }
    if (Array.isArray(parsed.dates) && parsed.dates.length > 0) {
        return new Set(parsed.dates.map(date => `${date?.month}-${date?.day}`)).size;
    }
    return 0;
}

/**
 * Roughly one interval of a schedule, in days. A Custom one shares a year
 * out among the dates it falls on (#399): it fell back to a month, so a bill
 * due once a year turned Upcoming with Mark Paid 31 days after it was paid.
 * Without a usable pattern it runs monthly, as on the server.
 *
 * @param {string} frequency
 * @param {string|object|null} pattern
 * @return {number}
 */
function cycleDays(frequency, pattern) {
    if (frequency === 'custom') {
        const perYear = customOccurrencesPerYear(pattern);
        return perYear > 0 ? Math.ceil(366 / perYear) : CYCLE_DAYS.monthly;
    }
    return CYCLE_DAYS[frequency] ?? 31;
}

/**
 * "Paid (or received) sometime this calendar month" put a Paid badge beside
 * a date still to come, let a weekly bill be paid once a month, and read a
 * late payment for last month as this month's. The next occurrence decides:
 *
 *  - inactive: nothing left to pay or receive
 *  - overdue: its date has passed
 *  - soon: due today or within a week
 *  - settled: the last one was within the past cycle and the next isn't
 *    close yet, so there is nothing to act on
 *  - upcoming: anything further off
 *
 * A one-time item has no cycle: while it is still owed, a payment beside it
 * settles nothing (#399).
 *
 * Dates are compared as Y-m-d strings: parsed with new Date() they are UTC
 * midnight, which west of UTC is the evening before.
 *
 * @param {object} item
 * @param {string} item.frequency
 * @param {boolean} item.isActive
 * @param {string|null} item.dueDate next occurrence, Y-m-d
 * @param {string|null} item.lastDate last payment or receipt, Y-m-d
 * @param {string|object|null} [item.pattern] a Custom schedule's recurrence pattern
 * @param {string} today the user's local date, Y-m-d
 * @return {{status: string, recentlySettled: boolean}}
 */
export function scheduleState({ frequency, isActive, dueDate, lastDate, pattern = null }, today) {
    const sinceLast = lastDate ? formatters.daysBetweenDates(lastDate, today) : null;
    const recentlySettled = frequency !== 'one-time' && sinceLast !== null && sinceLast >= 0
        && sinceLast <= cycleDays(frequency, pattern);

    if (!isActive || !dueDate) {
        return { status: 'inactive', recentlySettled };
    }

    const daysUntil = formatters.daysBetweenDates(today, dueDate);
    let status;
    if (daysUntil < 0) {
        status = 'overdue';
    } else if (daysUntil <= SOON_DAYS) {
        status = 'soon';
    } else if (recentlySettled) {
        status = 'settled';
    } else {
        status = 'upcoming';
    }
    return { status, recentlySettled };
}
