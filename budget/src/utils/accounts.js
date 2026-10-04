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

/**
 * An account someone shared with you read-only: the server refuses anything
 * posted into it. An account with no _canWrite flag counts as writable.
 * Shared bills and recurring income carry the same flags, and read the
 * same way.
 */
export function isReadOnlyShare(account) {
    return !!(account && account._shared && account._canWrite === false);
}

function takesNewActivity(account) {
    return !isClosedAccount(account) && !isReadOnlyShare(account);
}

/** Accounts that can take new activity. */
export function openAccounts(accounts) {
    return list(accounts).filter(takesNewActivity);
}

/**
 * The accounts a picker for new activity should list: the open ones you can
 * write to, plus any closed or read-only account the record being edited
 * already points at. Without that, an
 * old record's account would have no <option>, the select would silently read
 * "" and the save would strip the account off the record (the #370 failure).
 *
 * @param {Array} accounts
 * @param {number|string|Array|null} keepIds The edited record's account id(s)
 * @param {object} [options]
 * @param {boolean} [options.readOnlyShares=false] Also list accounts shared
 *   read-only, for a picker that only reads the account (a savings goal
 *   tracking its balance) rather than posting into it
 */
export function pickableAccounts(accounts, keepIds = [], { readOnlyShares = false } = {}) {
    const keep = new Set(
        (Array.isArray(keepIds) ? keepIds : [keepIds])
            .filter(id => id !== null && id !== undefined && id !== '')
            .map(String)
    );
    const offered = readOnlyShares
        ? account => !isClosedAccount(account)
        : takesNewActivity;
    return list(accounts).filter(account => offered(account) || keep.has(String(account.id)));
}

/**
 * The owner of an account someone shared with you; null for your own.
 */
export function sharedAccountOwner(account) {
    return account && account._shared && account.userId ? account.userId : null;
}

/**
 * The categories a transaction in these accounts can be filed under. A row
 * is filed in its account owner's ledger, and the server accepts only a
 * category that owner can see; from your side that is the owner's own
 * categories shared with you. Your own accounts take every category listed,
 * so this returns null for them (no restriction). Rows in two other
 * people's accounts at once (a bulk edit) have none in common.
 *
 * @param {Array} categories Flat list, each with id, parentId and userId
 * @param {Array} accounts The account(s) the row or rows are in
 * @param {Array<number|string>} [keepIds] Categories the record already has,
 *   kept so a save doesn't silently drop them
 * @returns {Array|null} The usable categories, or null for no restriction
 */
export function usableCategories(categories, accounts, keepIds = []) {
    const owners = new Set(list(accounts).map(sharedAccountOwner).filter(Boolean));
    if (owners.size === 0) {
        return null;
    }
    const owner = owners.size === 1 ? [...owners][0] : null;
    const keep = new Set((keepIds || []).filter(id => id !== null && id !== undefined && id !== '').map(String));
    return list(categories).filter(category => (owner !== null && category.userId === owner) || keep.has(String(category.id)));
}

/**
 * A category tree built from a flat list by parentId, for the pickers. A
 * category whose parent isn't in the list sits at the top level.
 *
 * @param {Array} categories
 * @returns {Array}
 */
export function categoryTreeOf(categories) {
    const nodes = new Map(list(categories).map(category => [String(category.id), { ...category, children: [] }]));
    const parentOf = node => (node.parentId !== null && node.parentId !== undefined ? nodes.get(String(node.parentId)) : null);
    // A damaged parent loop would leave its categories under nobody
    const inLoop = (node) => {
        const seen = new Set([node]);
        for (let p = parentOf(node); p; p = parentOf(p)) {
            if (seen.has(p)) return true;
            seen.add(p);
        }
        return false;
    };
    const roots = [];
    nodes.forEach(node => {
        const parent = parentOf(node);
        (parent && !inLoop(node) ? parent.children : roots).push(node);
    });
    return roots;
}

/**
 * The accounts a rule's Set Account may move rows into: the rule owner's
 * own open accounts, as the server refuses any other when the rule is
 * saved. For your own rule that is your accounts, not ones shared with
 * you; for a rule shared with you, its owner's (those you can see). The
 * account the rule already names stays listed, or editing the rule would
 * silently drop it.
 *
 * @param {Array} accounts
 * @param {string|null} ownerId The rule's owner; null for a new rule (yours)
 * @param {number|string|null} keepId The account the rule names now
 * @returns {Array}
 */
export function ruleTargetAccounts(accounts, ownerId, keepId) {
    const keep = keepId === null || keepId === undefined ? '' : String(keepId);
    const owners = ownerId
        ? account => account.userId === ownerId
        : account => !account._shared;
    return list(accounts).filter(account => (keep !== '' && String(account.id) === keep)
        || (owners(account) && !isClosedAccount(account)));
}

/**
 * The rows Mark Paid may offer to link the payment to. Linking writes the
 * payment onto an existing row, which the server refuses in an account
 * shared with you read-only, so only rows in accounts that take new
 * activity are offered. A row in an account missing from the list is left
 * for the server to judge, rather than booking a second payment beside it.
 *
 * @param {Array<{transaction: object}>|null} candidates
 * @param {Array} accounts
 * @returns {Array}
 */
export function linkableCandidates(candidates, accounts) {
    const byId = new Map(list(accounts).map(account => [String(account.id), account]));
    return (Array.isArray(candidates) ? candidates : []).filter(candidate => {
        const account = byId.get(String(candidate?.transaction?.accountId));
        return !account || takesNewActivity(account);
    });
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

/**
 * The currency of the account with this id, or null when it isn't one we
 * know, so formatCurrency falls back to the default. Amounts that carry only
 * an account id (detected bills, income and transfers) were all shown with
 * the default currency's symbol, euro accounts included.
 *
 * @param {Array} accounts
 * @param {number|string|null} accountId
 * @returns {string|null}
 */
export function accountCurrency(accounts, accountId) {
    if (accountId === null || accountId === undefined || accountId === '') {
        return null;
    }
    const account = list(accounts).find(candidate => String(candidate.id) === String(accountId));
    return account?.currency || null;
}
