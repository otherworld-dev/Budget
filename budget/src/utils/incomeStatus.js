/**
 * What a recurring income row shows: its status, its date text and which
 * actions it offers.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import { scheduleState } from './scheduleStatus.js';
import { isReadOnlyShare } from './accounts.js';

/**
 * The row follows the next expected occurrence, not the calendar month
 * (#399): see scheduleState(). Received is the settled state, when the last
 * payment arrived within the past cycle and the next isn't close yet; Mark
 * Received stays hidden then, so it can't be booked twice by accident. A
 * one-time income that arrived is completed; a paused schedule inactive.
 * Income shared with you read-only can't be received, skipped or edited:
 * the server refuses all three.
 *
 * @param {object} income recurring income row
 * @param {string} today Y-m-d, the user's local date
 * @param {object} settings user settings, for date formatting
 * @return {{status: string, statusText: string, dateText: string, canReceive: boolean, canSkip: boolean, canWrite: boolean}}
 */
export function incomeRowState(income, today, settings) {
    const canWrite = !isReadOnlyShare(income);
    const frequency = income.frequency || 'monthly';
    const isActive = income.isActive ?? income.is_active ?? true;
    const isOneTime = frequency === 'one-time';
    const next = income.nextExpectedDate || income.next_expected_date || null;
    const lastReceived = income.lastReceivedDate || income.last_received_date || null;
    const startDate = income.startDate || income.start_date || null;
    const fmt = (date) => formatters.formatDate(date, settings);

    const { status, recentlySettled } = scheduleState({ frequency, isActive, dueDate: next, lastDate: lastReceived }, today);

    if (status === 'inactive') {
        let dateText;
        if (lastReceived) {
            dateText = t('budget', 'Received {date}', { date: fmt(lastReceived) });
        } else if (isOneTime && startDate) {
            dateText = fmt(startDate);
        } else {
            dateText = t('budget', 'No date set');
        }
        return {
            status: isOneTime ? 'completed' : 'inactive',
            statusText: isOneTime ? t('budget', 'Completed') : t('budget', 'Inactive'),
            dateText,
            canReceive: false,
            canSkip: false,
            canWrite,
        };
    }

    const key = { overdue: 'overdue', soon: 'expected-soon', settled: 'received', upcoming: 'upcoming' }[status];
    const statusText = {
        overdue: t('budget', 'Overdue'),
        'expected-soon': t('budget', 'Expected Soon'),
        received: t('budget', 'Received'),
        upcoming: t('budget', 'Upcoming'),
    }[key];

    const dateText = recentlySettled
        ? t('budget', 'Received {receivedDate}, next expected {expectedDate}', {
            receivedDate: fmt(lastReceived),
            expectedDate: fmt(next),
        })
        : fmt(next);

    const canReceive = key !== 'received' && canWrite;
    return {
        status: key,
        statusText,
        dateText,
        canReceive,
        canSkip: canReceive && !isOneTime,
        canWrite,
    };
}
