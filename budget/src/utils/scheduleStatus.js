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

/** Months between two occurrences of the calendar schedules */
const STEP_MONTHS = {
    monthly: 1,
    quarterly: 3,
    'semi-annually': 6,
    yearly: 12,
};

/** Days between two occurrences of the schedules that run on a fixed step */
const STEP_DAYS = {
    daily: 1,
    weekly: 7,
    biweekly: 14,
};

/**
 * The occurrence a schedule fell on before $date, as the server counts
 * them: the due day in the month(s) before, clamped to shorter months; a
 * semi-monthly schedule on its day and fifteen days on; a Custom one on its
 * pattern's dates, else monthly.
 *
 * @param {string} frequency
 * @param {number} dueDay the due day
 * @param {Array<Array<number>>} days a Custom schedule's [month, day] pairs, possibly empty
 * @param {string} date Y-m-d
 * @return {string} Y-m-d
 */
function occurrenceBefore(frequency, dueDay, days, date) {
    if (frequency === 'custom' && days.length > 0) {
        return customOccurrenceBefore(days, date);
    }
    if (STEP_DAYS[frequency]) {
        const [y, m, d] = date.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, d - STEP_DAYS[frequency])).toISOString().slice(0, 10);
    }
    const year = Number(date.slice(0, 4));
    const month = Number(date.slice(5, 7));
    const inMonth = (back, day) => {
        const index = year * 12 + (month - 1) - back;
        return clampedDate(Math.floor(index / 12), (index % 12) + 1, day);
    };
    if (frequency === 'semi-monthly') {
        const pair = dueDay <= 15 ? [dueDay, dueDay + 15] : [dueDay - 15, dueDay];
        return [0, 1].flatMap(back => pair.map(day => inMonth(back, day)))
            .filter(day => day < date)
            .sort()
            .pop();
    }
    return inMonth(STEP_MONTHS[frequency] ?? 1, dueDay);
}

/**
 * Whether the last payment settled the occurrence before the next due date
 * (#399): made on or after it, or nearer it than the occurrence before
 * that, so a payment made early still counts. Anything else is an older
 * payment, and the occurrence in between was skipped: "paid within the past
 * cycle" put a Paid badge, and no buttons, on a bill whose October was
 * skipped a month after September was paid. A Custom schedule is read off
 * its pattern: a year shared out evenly among an uneven pattern's dates
 * held a January and February bill paid in February as paid only until
 * August, and offered Mark Paid for next January from then on.
 *
 * @param {string} frequency
 * @param {string|object|null} pattern a Custom schedule's recurrence pattern
 * @param {number|null} dueDay its due day, else the next due date's
 * @param {string} dueDate the next occurrence, Y-m-d
 * @param {string} lastDate the last payment, Y-m-d
 * @return {boolean}
 */
function settledBefore(frequency, pattern, dueDay, dueDate, lastDate) {
    const day = Number(dueDay) || Number(dueDate.slice(8, 10));
    const days = frequency === 'custom' ? customDays(pattern, day) : [];
    const before = occurrenceBefore(frequency, day, days, dueDate);
    const ahead = formatters.daysBetweenDates(lastDate, before);
    if (ahead <= 0) {
        return true;
    }
    const earlier = occurrenceBefore(frequency, day, days, before);
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
 *  - settled: the last payment paid the occurrence before the next one
 *    (settledBefore()) and the next isn't close yet, so there is nothing
 *    to act on. A skipped occurrence paid nothing, so it doesn't count
 *  - upcoming: anything further off
 *
 * A Custom schedule's dates can be months apart and uneven, so its last
 * payment is judged by the dates its pattern falls on; one with no usable
 * pattern runs monthly, as on the server. A one-time item
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
 * @param {string} today the user's local date, Y-m-d
 * @return {{status: string, recentlySettled: boolean}}
 */
export function scheduleState({ frequency, isActive, dueDate, lastDate, pattern = null, dueDay = null }, today) {
    const sinceLast = lastDate ? formatters.daysBetweenDates(lastDate, today) : null;
    const recentlySettled = frequency !== 'one-time' && sinceLast !== null && sinceLast >= 0
        && (dueDate ? settledBefore(frequency, pattern, dueDay, dueDate, lastDate) : sinceLast <= (CYCLE_DAYS[frequency] ?? 31));

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
