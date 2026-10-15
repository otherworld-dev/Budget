/**
 * On a phone the transactions table is laid out as cards, and a tap on a card
 * opens the edit form. On wider screens a click on a cell still edits that
 * field in place.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function mount() {
    document.body.innerHTML = `
        <table id="transactions-table"><tbody>
            <tr class="transaction-row" data-transaction-id="42">
                <td class="select-column"><input type="checkbox" class="transaction-checkbox"></td>
                <td class="description-column editable-cell" data-field="description"><span class="cell-display">Rent</span></td>
                <td class="actions-column"><button class="more-actions-btn">⋮</button></td>
            </tr>
        </tbody></table>`;
}

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.editTransaction = vi.fn();
    mod.startInlineEdit = vi.fn();
    mod.closeAllInlineEditors = vi.fn();
    mod.setupInlineEditingListeners();
    return mod;
}

function setPhone(isPhone) {
    window.matchMedia = vi.fn().mockImplementation(query => ({ matches: isPhone, media: query }));
}

beforeEach(mount);
afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('tapping a transaction card', () => {
    it('opens the edit form on a phone', () => {
        setPhone(true);
        const mod = makeModule();
        document.querySelector('.cell-display').click();
        expect(mod.editTransaction).toHaveBeenCalledWith(42);
        expect(mod.startInlineEdit).not.toHaveBeenCalled();
    });

    it('leaves checkboxes and buttons in the card alone', () => {
        setPhone(true);
        const mod = makeModule();
        document.querySelector('.transaction-checkbox').click();
        document.querySelector('.more-actions-btn').click();
        expect(mod.editTransaction).not.toHaveBeenCalled();
    });

    it('edits the cell in place on a wider screen', () => {
        setPhone(false);
        const mod = makeModule();
        document.querySelector('.cell-display').click();
        expect(mod.editTransaction).not.toHaveBeenCalled();
        expect(mod.startInlineEdit).toHaveBeenCalled();
    });
});
