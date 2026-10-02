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
 * Dates are compared as Y-m-d strings: parsed with new Date() they are UTC
 * midnight, which west of UTC is the evening before.
 *
 * @param {object} item
 * @param {string} item.frequency
 * @param {boolean} item.isActive
 * @param {string|null} item.dueDate next occurrence, Y-m-d
 * @param {string|null} item.lastDate last payment or receipt, Y-m-d
 * @param {string} today the user's local date, Y-m-d
 * @return {{status: string, recentlySettled: boolean}}
 */
export function scheduleState({ frequency, isActive, dueDate, lastDate }, today) {
    const sinceLast = lastDate ? formatters.daysBetweenDates(lastDate, today) : null;
    const recentlySettled = sinceLast !== null && sinceLast >= 0
        && sinceLast <= (CYCLE_DAYS[frequency] ?? 31);

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
