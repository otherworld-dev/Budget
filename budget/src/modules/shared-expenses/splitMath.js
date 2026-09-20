/**
 * Working out who owes what when a transaction is split between several
 * people (#391).
 *
 * Everything is done in whole pennies, so the parts always add back up to the
 * total. The penny that will not divide evenly stays with you when you are in
 * the split, and otherwise goes to the first people listed.
 */
import { toCents } from '../../utils/money.js';

/** Percentages are compared in hundredths of a percent; 33.33 x 3 is close enough */
const FULL_PERCENT = 10000;
const PERCENT_SLACK = 1;

export { toCents };

/** A percentage to two places, as whole hundredths of a percent */
const toHundredths = toCents;

/**
 * Split $totalCents between you and the people ticked.
 *
 * @param {object} split
 * @param {number} split.totalCents what there is to split, above zero
 * @param {'equal'|'percent'|'amount'} split.method
 * @param {boolean} [split.includeMe] take a share yourself (equal and percent)
 * @param {string|number|null} [split.myValue] your percentage (percent)
 * @param {Array<{id: number, value: string|number|null}>} split.people everyone
 *        else in the split, value being their percentage or amount
 * @returns {{shares: Array<{id: number, cents: number}>, mineCents: number, error: ?object}}
 *          error codes: nobody, too-small, missing {id}, percent-total {percent},
 *          over-total {overCents}
 */
export function computeSplit({ totalCents, method, includeMe = false, myValue = null, people }) {
    const fail = (error) => ({ shares: [], mineCents: 0, error });

    if (people.length === 0) {
        return fail({ code: 'nobody' });
    }

    if (method === 'amount') {
        const shares = [];
        for (const person of people) {
            const cents = toCents(person.value);
            if (!(cents > 0)) {
                return fail({ code: 'missing', id: person.id });
            }
            shares.push({ id: person.id, cents });
        }
        const owed = shares.reduce((sum, s) => sum + s.cents, 0);
        if (owed > totalCents) {
            return fail({ code: 'over-total', overCents: owed - totalCents });
        }
        return { shares, mineCents: totalCents - owed, error: null };
    }

    if (method === 'percent') {
        const parts = [];
        for (const person of people) {
            const hundredths = toHundredths(person.value);
            if (!(hundredths > 0)) {
                return fail({ code: 'missing', id: person.id });
            }
            parts.push({ id: person.id, hundredths });
        }
        let mine = 0;
        if (includeMe) {
            mine = toHundredths(myValue);
            if (!(mine >= 0)) {
                return fail({ code: 'missing', id: 'me' });
            }
        }
        const sum = parts.reduce((s, p) => s + p.hundredths, 0) + mine;
        if (Math.abs(sum - FULL_PERCENT) > PERCENT_SLACK) {
            return fail({ code: 'percent-total', percent: sum / 100 });
        }
        // Scaled by the actual sum so 99.99% still divides the whole total
        const shares = parts.map(p => ({ id: p.id, cents: Math.floor(totalCents * p.hundredths / sum) }));
        return settleRemainder(totalCents, shares, includeMe);
    }

    const heads = people.length + (includeMe ? 1 : 0);
    const each = Math.floor(totalCents / heads);
    return settleRemainder(totalCents, people.map(p => ({ id: p.id, cents: each })), includeMe);
}

/**
 * Give the pennies rounding left over to you, or failing that one each to
 * the first people, and refuse a split that leaves anyone owing nothing.
 */
function settleRemainder(totalCents, shares, includeMe) {
    let remainder = totalCents - shares.reduce((sum, s) => sum + s.cents, 0);
    let mineCents = 0;
    if (includeMe) {
        mineCents = remainder;
    } else {
        for (let i = 0; remainder > 0; i = (i + 1) % shares.length, remainder--) {
            shares[i].cents++;
        }
    }
    if (shares.some(s => s.cents <= 0)) {
        return { shares: [], mineCents: 0, error: { code: 'too-small' } };
    }
    return { shares, mineCents, error: null };
}
