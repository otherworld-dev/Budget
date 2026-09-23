/**
 * An account's register is laid out as cards on a phone, like the main
 * transactions list, and a tap on a card opens the edit form. Buttons inside
 * the card keep their own behaviour, and wider screens are unaffected.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

function mount() {
    document.body.innerHTML = `
        <table id="account-transactions-table"><tbody id="account-transactions-body">
            <tr class="transaction-row" data-transaction-id="42">
                <td class="description-column"><span class="description-main">Rent</span></td>
                <td class="actions-column"><button class="icon-delete delete-transaction-btn" data-transaction-id="42"></button></td>
            </tr>
        </tbody></table>`;
}

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = {};
    mod.editTransaction = vi.fn();
    mod.deleteTransaction = vi.fn();
    mod.setupAccountTransactionActionListeners();
    return mod;
}

const setPhone = (isPhone) => {
    window.matchMedia = vi.fn().mockImplementation(query => ({ matches: isPhone, media: query }));
};

beforeEach(mount);
afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('account register cards', () => {
    it('opens the edit form when a card is tapped on a phone', () => {
        setPhone(true);
        const mod = makeModule();

        document.querySelector('.description-main').click();

        expect(mod.editTransaction).toHaveBeenCalledWith(42);
    });

    it('leaves the buttons inside the card alone', () => {
        setPhone(true);
        const mod = makeModule();

        document.querySelector('.delete-transaction-btn').click();

        expect(mod.deleteTransaction).toHaveBeenCalledWith(42);
        expect(mod.editTransaction).not.toHaveBeenCalled();
    });

    it('does nothing on a wider screen', () => {
        setPhone(false);
        const mod = makeModule();

        document.querySelector('.description-main').click();

        expect(mod.editTransaction).not.toHaveBeenCalled();
    });

    it('binds the tap once, however often the rows are re-rendered', () => {
        setPhone(true);
        const mod = makeModule();
        mod.setupAccountTransactionActionListeners();
        mod.setupAccountTransactionActionListeners();

        document.querySelector('.description-main').click();

        expect(mod.editTransaction).toHaveBeenCalledTimes(1);
    });
});
