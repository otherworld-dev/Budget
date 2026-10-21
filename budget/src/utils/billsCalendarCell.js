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
 *    is what it was expected to cost, for reference. So is a date paid by
 *    a payment of the other year (paidElsewhere: 5 January paid on
 *    28 December), counted where the money went out; its tooltip names
 *    that payment (#399)
 *  - expected: it falls in the month and the row says nothing more
 *  - none: the bill doesn't fall in the month
 *
 * marker says how many times a date was paid when one of the month's was
 * paid more than once: a weekly bill's four payments in a four-week month
 * are no double payment, nor is December's paid late on 3 January. A
 * payment made on another day than the date it paid names that date in
 * the tooltip. It used to only for a date in another month, so October
 * paid on 30 September for 28 October never said what it was for (#399).
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
    const line = payment => {
        const params = {
            amount: money(payment.amount),
            date: formatters.formatDate(payment.date, settings),
        };
        if (payment.due && String(payment.due).slice(0, 10) !== String(payment.date).slice(0, 10)) {
            // TRANSLATORS: {amount}, {date} and {due} are placeholders, do NOT translate them. {due} is the date the bill was due that the payment was for.
            return t('budget', 'Paid {amount} on {date} for {due}', { ...params, due: formatters.formatDate(payment.due, settings) });
        }
        return t('budget', 'Paid {amount} on {date}', params);
    };
    // A date of the month paid in the other year is listed with the month's own payments, in date order
    const paidLines = [...details, ...(row.paidElsewhere?.[month] || [])]
        .sort((a, b) => String(a.date).localeCompare(String(b.date)))
        .map(line);
    const paid = row.paidAmounts?.[month] ?? null;
    const owed = row.owedAmounts?.[month] ?? null;
    const expected = row.expectedAmounts?.[month] ?? row.amount;
    const isPaid = paid !== null || (row.paidMonths || []).includes(month);
    const times = row.paidMoreThanOnce?.[month] ?? 0;
    const marker = times > 1 ? `${times}×` : null;
    const cell = (state, amount, title) => ({ state, amount: Number(amount), payments: details.length, title, marker });

    if (owed !== null) {
        if (paid === null) {
            // A weekly bill's other dates still owed beside one paid in the other year
            return cell('owed', owed, [t('budget', 'Due'), ...paidLines].join('\n'));
        }
        const total = roundMoney(Number(paid) + Number(owed), formatters.currencyDecimals(code, settings));
        return cell('owed', total, [t('budget', '{amount} still due', { amount: money(owed) }), ...paidLines].join('\n'));
    }
    if (isPaid) {
        return cell('paid', paid ?? expected, paidLines.length > 0 ? paidLines.join('\n') : t('budget', 'Paid'));
    }
    if (paidLines.length > 0) {
        return cell('unrecorded', expected, paidLines.join('\n'));
    }
    if ((row.unrecordedMonths || []).includes(month)) {
        return cell('unrecorded', expected, t('budget', 'No payment recorded - the bill has moved on from this date'));
    }
    return cell('expected', expected, t('budget', 'Due'));
}
