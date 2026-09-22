/**
 * Date text for a bill or transfer row in the lists.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';

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
