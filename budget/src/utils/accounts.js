/**
 * Account pickers and closed accounts (#372).
 *
 * A closed account keeps its history and still counts in every total, but no
 * picker for NEW activity offers it: the transaction and quick-add forms,
 * transfers, bills, income, imports, rules, bank sync, savings goals and
 * pension contributions. Pickers that FILTER history — the transactions
 * filter, reports, charts, dashboard tile settings, rule criteria — keep
 * listing everything, or old activity becomes unreachable.
 *
 * Every picker for new activity goes through these helpers, so the rule lives
 * in one place.
 */

import { translate as t } from '@nextcloud/l10n';

function list(accounts) {
    return Array.isArray(accounts) ? accounts.filter(Boolean) : [];
}

export function isClosedAccount(account) {
    return !!(account && account.closed);
}

/** Accounts that can take new activity. */
export function openAccounts(accounts) {
    return list(accounts).filter(account => !isClosedAccount(account));
}

/**
 * The accounts a picker for new activity should list: the open ones, plus any
 * closed account the record being edited already points at. Without that, an
 * old record's account would have no <option>, the select would silently read
 * "" and the save would strip the account off the record (the #370 failure).
 *
 * @param {Array} accounts
 * @param {number|string|Array|null} keepIds The edited record's account id(s)
 */
export function pickableAccounts(accounts, keepIds = []) {
    const keep = new Set(
        (Array.isArray(keepIds) ? keepIds : [keepIds])
            .filter(id => id !== null && id !== undefined && id !== '')
            .map(String)
    );
    return list(accounts).filter(account => !isClosedAccount(account) || keep.has(String(account.id)));
}

/** Display text for an account option; a closed one says so. */
export function accountOptionLabel(account) {
    const name = account?.name ?? '';
    return isClosedAccount(account) ? t('budget', '{name} (closed)', { name }) : name;
}

/**
 * Select `value` on an account dropdown even when the dropdown holds no option
 * for it, by appending one labelled as closed. Returns false only when the id
 * matches no known account at all.
 */
export function selectAccountValue(select, accounts, value) {
    if (!select) {
        return false;
    }
    if (value === null || value === undefined || value === '') {
        select.value = '';
        return true;
    }

    const wanted = String(value);
    select.value = wanted;
    if (select.value === wanted) {
        return true;
    }

    const account = list(accounts).find(candidate => String(candidate.id) === wanted);
    if (!account) {
        select.value = '';
        return false;
    }

    const option = document.createElement('option');
    option.value = wanted;
    option.textContent = accountOptionLabel(account);
    option.dataset.closedAccount = '1';
    select.appendChild(option);
    select.value = wanted;
    return true;
}
