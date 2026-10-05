/**
 * Date text for a bill or transfer row in the lists.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import { scheduleState } from './scheduleStatus.js';
import { isReadOnlyShare, billAccountsWritable } from './accounts.js';

/**
 * Status, date text and actions of a bill or transfer row, from its next
 * occurrence (see scheduleState()). An inactive bill only stays in the list
 * to be reverted (#365): it reads as paid, never with Mark Paid or Skip,
 * which would still execute on it. A bill shared with you read-only can't
 * be paid, skipped or edited: the server refuses all three.
 *
 * Paying, skipping and reverting also write into the bill's accounts, so
 * they need its account (and a transfer's destination) to be yours or
 * shared with you to write; see billAccountsWritable(). When that is all
 * that stands in the way on your own bill, accountHint says how to fix it.
 *
 * @param {object} bill bill or transfer
 * @param {string} today the user's local date, Y-m-d
 * @param {object} settings user settings, for date formatting
 * @param {Array} [accounts] your accounts; left out, the bill's accounts
 *   aren't checked
 * @return {{status: string, statusText: string, dateText: string, dueDate: string|null, canPay: boolean, canSkip: boolean, canUnpay: boolean, canWrite: boolean, accountHint: string|null}}
 */
export function billRowState(bill, today, settings, accounts = undefined) {
    const frequency = bill.frequency || 'monthly';
    // A paid one-time bill has no next occurrence, but it still has the
    // date it was due, kept as its start date (#333, #375)
    const dueDate = bill.nextDueDate || bill.next_due_date
        || (frequency === 'one-time' ? (bill.startDate || bill.start_date || null) : null);
    const isActive = bill.isActive ?? bill.is_active ?? true;
    const { status, recentlySettled } = scheduleState({
        frequency,
        isActive,
        dueDate: isActive ? (bill.nextDueDate || bill.next_due_date || null) : null,
        lastDate: bill.lastPaidDate || bill.last_paid_date || null,
    }, today);

    const key = { inactive: 'paid', settled: 'paid', overdue: 'overdue', soon: 'due-soon', upcoming: 'upcoming' }[status];
    const statusText = {
        paid: t('budget', 'Paid'),
        overdue: t('budget', 'Overdue'),
        'due-soon': t('budget', 'Due Soon'),
        upcoming: t('budget', 'Upcoming'),
    }[key];
    const canWrite = !isReadOnlyShare(bill);
    const accountsWritable = accounts === undefined || billAccountsWritable(bill, accounts);
    const canAct = canWrite && accountsWritable;
    const canPay = key !== 'paid' && canAct;
    const canMarkUnpaid = !!(bill.canMarkUnpaid ?? false);
    // Same words as the server's refusal. Only on your own bill: one shared
    // with you may post into its owner's account, which isn't yours to pick
    const accountHint = canWrite && !accountsWritable && !bill._shared && (key !== 'paid' || canMarkUnpaid)
        ? t('budget', 'This bill uses an account you can no longer change. Edit the bill and choose another account.')
        : null;
    return {
        status: key,
        statusText,
        dateText: billRowDateText(bill, dueDate, key === 'paid' || recentlySettled, settings),
        dueDate,
        canPay,
        canSkip: canPay && frequency !== 'one-time',
        canUnpay: canAct && canMarkUnpaid,
        canWrite,
        accountHint,
        dateUnconfirmed: oneTimeDateUnconfirmed(bill),
    };
}

/**
 * Whether a one-time bill's date was filled in for it rather than entered
 * (#399 review). Before the Due Date field a one-time bill could be saved
 * with no month, and the server made a date up: 1 January next year, or
 * the invoice's day rolled into the next year. An upgrade, or paying it,
 * then kept that guess as the bill's date. Saving the form always sets a
 * due month, so a one-time bill without one never had its date confirmed.
 * There is no telling a guess from a real 1 January, so the page asks.
 *
 * @param {object} bill
 * @return {boolean}
 */
export function oneTimeDateUnconfirmed(bill) {
    if ((bill.frequency || 'monthly') !== 'one-time') {
        return false;
    }
    const month = bill.dueMonth ?? bill.due_month ?? null;
    const date = bill.startDate || bill.start_date || bill.nextDueDate || bill.next_due_date || null;
    return month === null && date !== null;
}

/**
 * The date shown on a bill or transfer row (#399). Paying a recurring bill
 * moves its next_due_date on to the following occurrence at once, so a row
 * that only showed that date put next month's date beside a Paid badge, and
 * the Paid tab gave no way to tell which payments had been recorded when. A
 * paid row leads with the date the payment was recorded and keeps the next
 * due date after it; a row with nothing recorded shows the due date alone.
 *
 * @param {object} item bill or transfer
 * @param {string|null} dueDate the row's due date (Y-m-d)
 * @param {boolean} isPaid whether the row renders as paid
 * @param {object} settings user settings, for date formatting
 * @return {string}
 */
export function billRowDateText(item, dueDate, isPaid, settings) {
    const lastPaid = item.lastPaidDate || item.last_paid_date || null;
    if (isPaid && lastPaid) {
        const paid = formatters.formatDate(lastPaid, settings);
        const isActive = item.isActive ?? item.is_active ?? true;
        // An inactive row (a paid one-time bill, an ended schedule) has no
        // next occurrence: its due date is the one it was paid for
        if (isActive && dueDate) {
            return t('budget', 'Paid {paidDate}, next due {dueDate}', {
                paidDate: paid,
                dueDate: formatters.formatDate(dueDate, settings),
            });
        }
        return t('budget', 'Paid {date}', { date: paid });
    }
    return dueDate ? formatters.formatDate(dueDate, settings) : t('budget', 'No due date');
}
