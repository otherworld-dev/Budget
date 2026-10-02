/**
 * What a recurring income row shows: its status, its date text and which
 * actions it offers.
 */
import { translate as t } from '@nextcloud/l10n';
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

/** How close an expected date has to be to count as soon */
const SOON_DAYS = 7;

/**
 * The row follows the next expected occurrence, not the calendar month
 * (#399). "Received in this calendar month" put a Received badge beside a
 * date still to come, let weekly pay be marked only once a month, and left
 * a payment that never arrived reading "Upcoming".
 *
 *  - overdue: the expected date has passed and nothing was received
 *  - expected-soon: due today or within a week
 *  - received: the last payment arrived within the past cycle and the next
 *    one isn't close yet; Mark Received stays hidden so it can't be booked
 *    twice by accident
 *  - upcoming: anything further off
 *  - completed: a one-time income that arrived; inactive: a paused schedule
 *
 * @param {object} income recurring income row
 * @param {string} today Y-m-d, the user's local date
 * @param {object} settings user settings, for date formatting
 * @return {{status: string, statusText: string, dateText: string, canReceive: boolean, canSkip: boolean}}
 */
export function incomeRowState(income, today, settings) {
    const frequency = income.frequency || 'monthly';
    const isActive = income.isActive ?? income.is_active ?? true;
    const isOneTime = frequency === 'one-time';
    const next = income.nextExpectedDate || income.next_expected_date || null;
    const lastReceived = income.lastReceivedDate || income.last_received_date || null;
    const startDate = income.startDate || income.start_date || null;
    const fmt = (date) => formatters.formatDate(date, settings);

    if (!isActive || !next) {
        const completed = isOneTime;
        let dateText;
        if (lastReceived) {
            dateText = t('budget', 'Received {date}', { date: fmt(lastReceived) });
        } else if (isOneTime && startDate) {
            dateText = fmt(startDate);
        } else {
            dateText = t('budget', 'No date set');
        }
        return {
            status: completed ? 'completed' : 'inactive',
            statusText: completed ? t('budget', 'Completed') : t('budget', 'Inactive'),
            dateText,
            canReceive: false,
            canSkip: false,
        };
    }

    const daysUntil = formatters.daysBetweenDates(today, next);
    const sinceReceived = lastReceived ? formatters.daysBetweenDates(lastReceived, today) : null;
    const recentlyReceived = sinceReceived !== null && sinceReceived >= 0
        && sinceReceived <= (CYCLE_DAYS[frequency] ?? 31);

    let status;
    if (daysUntil < 0) {
        status = 'overdue';
    } else if (daysUntil <= SOON_DAYS) {
        status = 'expected-soon';
    } else if (recentlyReceived) {
        status = 'received';
    } else {
        status = 'upcoming';
    }

    const statusText = {
        overdue: t('budget', 'Overdue'),
        'expected-soon': t('budget', 'Expected Soon'),
        received: t('budget', 'Received'),
        upcoming: t('budget', 'Upcoming'),
    }[status];

    const dateText = recentlyReceived
        ? t('budget', 'Received {receivedDate}, next expected {expectedDate}', {
            receivedDate: fmt(lastReceived),
            expectedDate: fmt(next),
        })
        : fmt(next);

    const canReceive = status !== 'received';
    return {
        status,
        statusText,
        dateText,
        canReceive,
        canSkip: canReceive && !isOneTime,
    };
}
