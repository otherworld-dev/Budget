/**
 * On a phone, opening an existing transaction kept popping the keyboard up
 * over half the form, as the dialog put the cursor in Amount, although it is
 * often opened just to look (a tap on a card, or the Android app's link).
 * Focus now goes to the form's title instead, which the dialog handler takes
 * as focus already placed; adding a transaction still starts in Amount.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function setPhone(isPhone) {
    window.matchMedia = vi.fn().mockImplementation(query => ({ matches: isPhone, media: query }));
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transaction-modal" class="modal" style="display: flex;">
            <div class="modal-content">
                <h3 id="transaction-modal-title">Edit transaction</h3>
                <input type="number" id="transaction-amount">
            </div>
        </div>`;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

const mod = () => Object.create(TransactionsModule.prototype);
const title = () => document.getElementById('transaction-modal-title');

describe('the keyboard when the transaction form opens', () => {
    it('stays down on a phone when an existing transaction is opened', () => {
        setPhone(true);

        mod()._keepKeyboardDownWhenViewing({ id: 5358 });

        expect(document.activeElement).toBe(title());
        expect(document.getElementById('transaction-modal').contains(document.activeElement)).toBe(true);
    });

    it('is left to the dialog when adding, so Amount still gets the cursor', () => {
        setPhone(true);

        mod()._keepKeyboardDownWhenViewing(null);
        mod()._keepKeyboardDownWhenViewing({ id: null });

        expect(document.activeElement).toBe(document.body);
    });

    it('is left to the dialog on a wider screen', () => {
        setPhone(false);

        mod()._keepKeyboardDownWhenViewing({ id: 5358 });

        expect(document.activeElement).toBe(document.body);
    });
});
