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
 * The days a Custom schedule falls on each year, read as the server reads
 * its pattern: its months on the due day, else its dates. Empty when it has
 * no usable pattern; the server then runs it monthly.
 *
 * @param {string|object|null} pattern the custom recurrence pattern, as JSON or parsed
 * @param {number} dueDay
 * @return {Array<Array<number>>} [month, day] pairs
 */
function customDays(pattern, dueDay) {
    let parsed = pattern;
    if (typeof parsed === 'string') {
        try {
            parsed = JSON.parse(parsed);
        } catch {
            return [];
        }
    }
    const month = value => Math.trunc(Number(value));
    const inYear = value => month(value) >= 1 && month(value) <= 12;
    if (Array.isArray(parsed?.months) && parsed.months.length > 0) {
        return parsed.months.filter(inYear).map(value => [month(value), dueDay]);
    }
    if (Array.isArray(parsed?.dates) && parsed.dates.length > 0) {
        return parsed.dates
            .filter(date => inYear(date?.month) && date.day !== undefined && date.day !== null)
            .map(date => [month(date.month), Math.trunc(Number(date.day))]);
    }
    return [];
}

/**
 * $day of a month as Y-m-d, or the month's last day when it has fewer
 *
 * @param {number} year
 * @param {number} month 1-12
 * @param {number} day
 * @return {string}
 */
function clampedDate(year, month, day) {
    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const clamped = Math.max(1, Math.min(day || 1, last));
    return `${year}-${String(month).padStart(2, '0')}-${String(clamped).padStart(2, '0')}`;
}

/**
 * The last date a Custom schedule fell on before $date
 *
 * @param {Array<Array<number>>} days [month, day] pairs, not empty
 * @param {string} date Y-m-d
 * @return {string} Y-m-d
 */
function customOccurrenceBefore(days, date) {
    const year = Number(date.slice(0, 4));
    let before = days.map(([month, day]) => clampedDate(year, month, day)).filter(day => day < date);
    if (before.length === 0) {
        before = days.map(([month, day]) => clampedDate(year - 1, month, day));
    }
    return before.sort().pop();
}

/**
 * Whether a Custom bill's last payment settled the occurrence before its
 * next due date (#399): made on or after it, or nearer it than the
 * occurrence before that, so a payment made early still counts. A year
 * shared out evenly among an uneven pattern's dates held a January and
 * February bill paid in February as paid only until August, and offered
 * Mark Paid for next January from then on. Null when the pattern can't be
 * read.
 *
 * @param {string|object|null} pattern
 * @param {number|null} dueDay its due day, else the next due date's
 * @param {string} dueDate the next occurrence, Y-m-d
 * @param {string} lastDate the last payment, Y-m-d
 * @return {boolean|null}
 */
function customSettled(pattern, dueDay, dueDate, lastDate) {
    const days = customDays(pattern, Number(dueDay) || Number(dueDate.slice(8, 10)));
    if (days.length === 0) {
        return null;
    }
    const before = customOccurrenceBefore(days, dueDate);
    const ahead = formatters.daysBetweenDates(lastDate, before);
    if (ahead <= 0) {
        return true;
    }
    const earlier = customOccurrenceBefore(days, before);
    return ahead * 2 <= formatters.daysBetweenDates(earlier, before);
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
 * A skip moves the next date on without a payment, so the last payment can
 * be within the past cycle while the occurrence before the next one was
 * skipped: that read as settled, a green Paid badge with no buttons (#399).
 * Dates alone can't tell a skip from a payment made early, so the server
 * says when the occurrence before the next one was skipped (skipped), and
 * nothing is settled then.
 *
 * A Custom schedule's dates can be months apart and uneven, so its last
 * payment is judged by the dates its pattern falls on (customSettled());
 * one with no usable pattern runs monthly, as on the server. A one-time item
 * has no cycle: while it is still owed, a payment beside it settles nothing
 * (#399).
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
 * @param {number|null} [item.dueDay] a Custom schedule's due day; read off dueDate when left out
 * @param {boolean} [item.skipped] the occurrence before the next one was skipped
 * @param {string} today the user's local date, Y-m-d
 * @return {{status: string, recentlySettled: boolean}}
 */
export function scheduleState({ frequency, isActive, dueDate, lastDate, pattern = null, dueDay = null, skipped = false }, today) {
    const sinceLast = lastDate ? formatters.daysBetweenDates(lastDate, today) : null;
    const custom = frequency === 'custom' && dueDate && lastDate ? customSettled(pattern, dueDay, dueDate, lastDate) : null;
    const recentlySettled = !skipped && frequency !== 'one-time' && sinceLast !== null && sinceLast >= 0
        && (custom ?? sinceLast <= (CYCLE_DAYS[frequency] ?? 31));

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
