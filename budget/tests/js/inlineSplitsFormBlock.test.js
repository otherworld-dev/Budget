/**
 * Regression coverage for #380 — the transaction form silently refusing to save.
 *
 * The inline split editor builds its rows inside #transaction-form. Hiding the
 * editor only set #inline-splits-section to display:none; the rows stayed in
 * the DOM. A CSS-hidden control is still a candidate for constraint validation,
 * so an empty `required` split amount left the whole form invalid — and because
 * the browser cannot focus a display:none control, it refuses to submit and
 * shows NO message. Chrome logs "An invalid form control ... is not focusable";
 * Safari logs nothing at all. To the user, Save simply stops working, with no
 * error, no request, and no way back except reloading the page.
 *
 * Two things keep that from coming back:
 *   - hiding the editor empties it, so nothing lingers inside the form, and
 *   - the split amount is not `required`, so even a lingering row could not
 *     block a submit. validateInlineSplits() already guards the save path with
 *     a message the user can actually see.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

/** The parts of the transaction form the split editor actually touches. */
function mountForm() {
    document.body.innerHTML = `
        <form id="transaction-form">
            <input type="hidden" id="transaction-id" value="">
            <input type="date" id="transaction-date" value="2026-09-10" required>
            <select id="transaction-account" required>
                <option value="">Choose an account</option>
                <option value="1" selected>Current</option>
            </select>
            <select id="transaction-type" required>
                <option value="debit" selected>Expense</option>
                <option value="transfer">Transfer</option>
            </select>
            <input type="number" id="transaction-amount" value="10" required>
            <input type="text" id="transaction-description" value="Weekly shop" required>
            <div id="transaction-category-group"></div>
            <div id="split-toggle-group">
                <input type="checkbox" id="transaction-split-toggle">
            </div>
            <div id="inline-splits-section" style="display: none;">
                <span id="inline-split-remaining"></span>
                <div id="inline-splits-container"></div>
                <button type="button" id="inline-add-split-btn"></button>
            </div>
            <button type="submit">Save</button>
        </form>
    `;
}

/**
 * The real methods, without running the 200-line constructor (which wires the
 * whole transactions view). Only the collaborators the split code reaches for
 * are provided.
 */
function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        getCategoryOptions: () => '<option value="1">Groceries</option>',
        transactions: [],
    };
    mod._allowNegativeRemainder = false;
    return mod;
}

function splitRows() {
    return [...document.querySelectorAll('#inline-splits-container .split-row')];
}

/** Flip the toggle the way a click does — the handler is an onchange property. */
function setToggle(checked) {
    const toggle = document.getElementById('transaction-split-toggle');
    toggle.checked = checked;
    toggle.dispatchEvent(new Event('change'));
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'test-token' };
    mountForm();
});

afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
    document.body.innerHTML = '';
});

describe('hiding the inline split editor', () => {
    it('leaves nothing behind that can block the form when split is switched off', () => {
        const mod = makeModule();
        mod.setupInlineSplitToggle();

        setToggle(true);
        expect(splitRows()).toHaveLength(2);

        setToggle(false);

        expect(splitRows()).toHaveLength(0);
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });

    it('leaves nothing behind when the type is switched to Transfer', () => {
        const mod = makeModule();
        mod.setupInlineSplitToggle();

        setToggle(true);
        expect(splitRows()).toHaveLength(2);

        const typeSelect = document.getElementById('transaction-type');
        typeSelect.value = 'transfer';
        typeSelect.onchange();

        expect(splitRows()).toHaveLength(0);
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });

    it('leaves nothing behind when the modal is reopened for a new transaction', () => {
        const mod = makeModule();
        mod.setupInlineSplitToggle();
        setToggle(true);
        expect(splitRows()).toHaveLength(2);

        // Reopening runs the setup again, which resets the editor to "off".
        mod.setupInlineSplitToggle();

        expect(splitRows()).toHaveLength(0);
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });
});

describe('split amount rows', () => {
    it('are never `required`, so a hidden row can never silently block a submit', () => {
        const mod = makeModule();
        mod.setupInlineSplitToggle();
        setToggle(true);

        const amounts = [...document.querySelectorAll('.inline-split-amount')];
        expect(amounts).not.toHaveLength(0);
        amounts.forEach(input => expect(input.required).toBe(false));

        // Blank the editable row and hide the section: still submittable.
        amounts[0].value = '';
        document.getElementById('inline-splits-section').style.display = 'none';
        expect(document.getElementById('transaction-form').checkValidity()).toBe(true);
    });

    it('still refuses to save a split that has fewer than two filled parts', () => {
        const mod = makeModule();
        mod.setupInlineSplitToggle();
        setToggle(true);

        // Only the auto-computed remainder carries a figure.
        const check = mod.validateInlineSplits();
        expect(check.ok).toBe(false);
        expect(check.message).toBeTruthy();
    });
});
