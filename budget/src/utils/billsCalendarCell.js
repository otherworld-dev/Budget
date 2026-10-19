/**
 * One month of a Bills by Month row.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import { roundMoney } from './money.js';

/**
 * What a month of a Bills Calendar row shows, from the row the server sends
 * (BillService::calendarRows()). Two payments for one invoice read as one
 * large paid amount with nothing to say the cell held two, and a month the
 * bill moved past with no payment recorded was struck through like a paid
 * one (#399). The amount is what the month adds to Monthly Totals, and what
 * the export prints, except for an unrecorded month, which adds nothing.
 *
 *  - paid: payments recorded and nothing still owed. The amount is what
 *    was paid; the tooltip lists each payment with its date
 *  - owed: still to pay, from the bill's next due date on. A month the bill
 *    is part way through (a weekly bill) adds what was paid so far
 *  - unrecorded: moved past with no payment recorded (marked paid without
 *    a transaction, or skipped): neither paid nor owed (#333). The amount
 *    is what it was expected to cost, for reference
 *  - expected: it falls in the month and the row says nothing more
 *  - none: the bill doesn't fall in the month
 *
 * marker counts the payments when there are more than the times the bill
 * falls due that month: two for a monthly bill is a payment made twice,
 * four for a weekly one in a four-week month is not.
 *
 * @param {object} row calendar row
 * @param {number} month 1..12
 * @param {object} [settings] user settings, for dates and amounts
 * @param {string|null} [currency] for a row that has no currency of its own
 * @return {{state: string, amount: number|null, payments: number, title: string, marker: string|null}}
 */
export function calendarCell(row, month, settings = {}, currency = null) {
    if (!row.occurrences?.[month]) {
        return { state: 'none', amount: null, payments: 0, title: '', marker: null };
    }

    const code = row.currency || currency;
    const money = amount => formatters.formatCurrency(amount, code, settings);
    const details = row.paidDetails?.[month] || [];
    const paidLines = details.map(payment => t('budget', 'Paid {amount} on {date}', {
        amount: money(payment.amount),
        date: formatters.formatDate(payment.date, settings),
    }));
    const paid = row.paidAmounts?.[month] ?? null;
    const owed = row.owedAmounts?.[month] ?? null;
    const expected = row.expectedAmounts?.[month] ?? row.amount;
    const isPaid = paid !== null || (row.paidMonths || []).includes(month);
    const marker = details.length > (row.occurrenceCounts?.[month] ?? 1) ? `${details.length}×` : null;
    const cell = (state, amount, title) => ({ state, amount: Number(amount), payments: details.length, title, marker });

    if (owed !== null) {
        if (paid === null) {
            return cell('owed', owed, t('budget', 'Due'));
        }
        const total = roundMoney(Number(paid) + Number(owed), formatters.currencyDecimals(code, settings));
        return cell('owed', total, [t('budget', '{amount} still due', { amount: money(owed) }), ...paidLines].join('\n'));
    }
    if (isPaid) {
        return cell('paid', paid ?? expected, paidLines.length > 0 ? paidLines.join('\n') : t('budget', 'Paid'));
    }
    if ((row.unrecordedMonths || []).includes(month)) {
        return cell('unrecorded', expected, t('budget', 'No payment recorded - the bill has moved on from this date'));
    }
    return cell('expected', expected, t('budget', 'Due'));
}
