/**
 * The runway setup, opened from the gear on any runway tile. There is one
 * setup per user, shared by every runway tile, the API and the alert, so
 * they all show the same number.
 *
 * The account list is the accounts the dashboard has loaded, ticked where
 * the runway counts them. Until the user changes a tick the runway uses its
 * default set, and saving without touching the ticks keeps it that way
 * rather than freezing today's default into a list.
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { isLiabilityType } from '../../../utils/helpers.js';
import { AS_TEXT, el, button, money, runwayContext, settings } from './runwayData.js';

const WINDOW_CHOICES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
const ALERT_CHOICES = [1, 2, 3, 4, 5, 6];

function select(name, label, choices, current, labelFor) {
    const group = el('div', 'form-group runway-setup-field');
    const id = `runway-setup-${name}`;
    const caption = el('label', '', label);
    caption.htmlFor = id;
    const control = el('select');
    control.id = id;
    control.dataset.runwaySetting = name;
    for (const choice of choices) {
        const option = el('option', '', labelFor(choice));
        option.value = String(choice);
        control.appendChild(option);
    }
    control.value = String(choices.includes(current) ? current : choices[0]);
    group.append(caption, control);
    return group;
}

/**
 * What an account adds to the runway, as the tick list shows it: a card or
 * loan balance owed is "taken off".
 *
 * @param {object} account
 * @returns {string}
 */
function balanceText(account) {
    const balance = Number(account.balance) || 0;
    const amount = money(Math.abs(balance), account.currency);
    if (isLiabilityType(account.type) && balance < 0) {
        return t('budget', '{amount} taken off', { amount }, undefined, AS_TEXT);
    }
    return money(balance, account.currency);
}

function accountRow(account, ticked) {
    const label = el('label', 'runway-setup-account');
    const box = el('input');
    box.type = 'checkbox';
    box.dataset.runwayAccount = String(account.id);
    box.checked = ticked;
    const name = el('span', 'runway-setup-account-name', account.name);
    if (account.closed) {
        name.appendChild(el('span', 'runway-setup-account-note', ' ' + t('budget', '(closed)')));
    }
    const balance = el('span', 'runway-setup-account-balance', balanceText(account));
    if (isLiabilityType(account.type) && Number(account.balance) < 0) {
        balance.classList.add('negative');
    }
    label.append(box, name, balance);
    return label;
}

const sameSet = (a, b) => a.length === b.length && a.every((id) => b.includes(id));

/**
 * Renders the setup into `container`.
 *
 * @param {HTMLElement} container
 * @param {object} data the runway payload (its settings and skipped accounts)
 * @param {object} options
 * @param {Function} options.onSave async (body) => void, given only the keys to write
 * @returns {HTMLElement} the panel
 */
export function renderRunwaySetup(container, data, { onSave }) {
    container.textContent = '';
    const runway = data?.settings || {};
    const chosen = (runway.accountIds || []).map(Number);
    const accounts = (runwayContext().accounts() || []).filter(Boolean);

    const panel = el('div', 'runway-setup');
    panel.appendChild(el('p', 'tile-config-hint', t('budget', 'Every runway tile uses this setup.')));

    // Accounts, savings and current first, then cards and loans
    const fieldset = el('fieldset', 'runway-setup-accounts');
    fieldset.appendChild(el('legend', '', t('budget', 'Accounts to count')));
    if (runway.accountIdsAreDefault) {
        fieldset.appendChild(el('p', 'tile-config-hint', t('budget', 'Using the default accounts: your current, savings and cash accounts and credit cards. Change a tick to choose your own.')));
    } else {
        const reset = button(t('budget', 'Use the default accounts'), 'btn-link-small runway-setup-default', async () => {
            reset.disabled = true;
            try {
                // Saved with the other controls, so a change made to them
                // first isn't thrown away by the redraw
                await onSave({ ...controlValues(), runway_account_ids: '' });
            } finally {
                reset.disabled = false;
            }
        });
        reset.dataset.runwayAction = 'default';
        fieldset.appendChild(reset);
    }
    const groups = [
        [t('budget', 'Money you have'), accounts.filter((a) => !isLiabilityType(a.type))],
        [t('budget', 'Cards and loans'), accounts.filter((a) => isLiabilityType(a.type))],
    ];
    for (const [title, members] of groups) {
        if (!members.length) continue;
        const group = el('div', 'runway-setup-group');
        group.appendChild(el('div', 'runway-setup-group-title', title));
        members.forEach((account) => group.appendChild(accountRow(account, chosen.includes(Number(account.id)))));
        fieldset.appendChild(group);
    }
    if (!accounts.length) {
        fieldset.appendChild(el('p', 'tile-config-hint', t('budget', 'No accounts yet.')));
    }
    for (const skipped of data?.skippedAccounts || []) {
        fieldset.appendChild(el('p', 'runway-warning', t('budget', '{name} is left out: there is no exchange rate for its currency yet.', { name: skipped.name }, undefined, AS_TEXT)));
    }
    panel.appendChild(fieldset);

    const fields = el('div', 'runway-setup-fields');
    fields.appendChild(select('window', t('budget', 'Months to average'), WINDOW_CHOICES, Number(runway.windowMonths) || 3,
        (count) => n('budget', 'Last {count} full month', 'Last {count} full months', count, { count })));

    const include = el('label', 'runway-setup-check');
    const includeBox = el('input');
    includeBox.type = 'checkbox';
    includeBox.dataset.runwaySetting = 'includeScheduled';
    includeBox.checked = runway.includeScheduled !== false;
    include.append(includeBox, el('span', '', t('budget', 'Include scheduled payments')));
    fields.appendChild(include);

    const alertMonths = parseInt(settings().runway_alert_months, 10) || 1;
    fields.appendChild(select('alert', t('budget', 'Notify me if the runway drops by more than'), ALERT_CHOICES, alertMonths,
        (count) => n('budget', '{count} month', '{count} months', count, { count })));
    panel.appendChild(fields);

    const actions = el('div', 'runway-setup-actions');
    const save = el('button', 'primary', t('budget', 'Save'));
    save.type = 'button';
    save.dataset.runwayAction = 'save';
    actions.appendChild(save);
    panel.appendChild(actions);

    function controlValues() {
        return {
            runway_window_months: panel.querySelector('[data-runway-setting="window"]').value,
            runway_include_scheduled: includeBox.checked ? 'true' : 'false',
            runway_alert_months: panel.querySelector('[data-runway-setting="alert"]').value,
        };
    }

    save.addEventListener('click', async () => {
        const ticked = [...panel.querySelectorAll('input[data-runway-account]')]
            .filter((box) => box.checked)
            .map((box) => Number(box.dataset.runwayAccount));
        const initial = accounts.map((a) => Number(a.id)).filter((id) => chosen.includes(id));

        const body = controlValues();
        if (!sameSet(ticked, initial)) {
            if (!ticked.length) {
                fieldset.querySelector('.runway-setup-none')?.remove();
                const none = el('p', 'runway-warning runway-setup-none', t('budget', 'Tick at least one account.'));
                none.setAttribute('role', 'alert');
                fieldset.appendChild(none);
                return;
            }
            body.runway_account_ids = JSON.stringify([...ticked].sort((a, b) => a - b));
        }

        save.disabled = true;
        try {
            await onSave(body);
        } finally {
            save.disabled = false;
        }
    });

    container.appendChild(panel);
    return panel;
}
