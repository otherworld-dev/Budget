/**
 * The planned one-offs list and its inline editor, shared by the one-offs
 * tile and the overview.
 *
 * The direction is its own control, so nobody has to type a minus sign, and
 * the amount box is an app amount box (data-amount): it takes "864,27" as
 * well as "864.27". The server only takes a plain decimal, so the amount is
 * tidied again here before it is sent rather than trusting the box's own
 * listener to have run.
 */

import { translate as t } from '@nextcloud/l10n';
import { apiFetch } from '../../../utils/api.js';
import { makeAmountInput, normaliseAmountText } from '../../../utils/amountInput.js';
import { initSingleDatePicker } from '../../../utils/datepicker.js';
import { confirmDialog } from '../../../utils/dialogs.js';
import { showError } from '../../../utils/notifications.js';
import { AS_TEXT, el, button, money, longDate, amountClass, settings } from './runwayData.js';

const URL = '/apps/budget/api/runway/one-offs';

/**
 * The typed amount as a plain unsigned decimal, or null when it isn't one
 * above zero.
 *
 * @param {string} raw
 * @returns {string|null}
 */
export function plainAmount(raw) {
    let value = normaliseAmountText(raw, { allowNegative: false }).value;
    if (value.endsWith('.')) value = value.slice(0, -1);
    if (value.startsWith('.')) value = '0' + value;
    if (!/^\d+(\.\d+)?$/.test(value) || Number(value) <= 0) return null;
    return value;
}

function field(labelText, control) {
    const label = el('label', 'runway-oneoff-field');
    label.appendChild(el('span', 'runway-oneoff-field-label', labelText));
    label.appendChild(control);
    return label;
}

/**
 * The add/edit form. An edit sends only the fields that changed.
 *
 * @param {object|null} oneOff the one being edited, or null to add one
 * @param {object} handlers
 * @param {Function} handlers.onSaved awaited after a save
 * @param {Function} handlers.onCancel
 * @returns {HTMLFormElement}
 */
function editor(oneOff, { onSaved, onCancel }) {
    const form = el('form', 'runway-oneoff-form');
    form.noValidate = true;

    const description = el('input');
    description.type = 'text';
    description.name = 'description';
    description.maxLength = 255;
    description.required = true;
    description.placeholder = t('budget', 'e.g. Car repair');
    description.value = oneOff?.description ?? '';

    const direction = el('select');
    direction.name = 'direction';
    for (const [value, label] of [['out', t('budget', 'Money out')], ['in', t('budget', 'Money in')]]) {
        const option = el('option', '', label);
        option.value = value;
        direction.appendChild(option);
    }
    direction.value = oneOff && Number(oneOff.amount) > 0 ? 'in' : 'out';

    const amount = makeAmountInput(el('input'));
    amount.name = 'amount';
    amount.min = '0';
    amount.required = true;
    amount.placeholder = '0.00';
    amount.value = oneOff ? String(oneOff.amount).replace(/^-/, '') : '';

    const date = el('input');
    date.type = 'date';
    date.name = 'date';
    date.required = true;
    date.value = oneOff?.date ?? '';

    const save = el('button', 'primary', oneOff ? t('budget', 'Save') : t('budget', 'Add'));
    save.type = 'submit';
    const cancel = button(t('budget', 'Cancel'), 'secondary', () => onCancel());

    const fields = el('div', 'runway-oneoff-fields');
    fields.append(
        field(t('budget', 'Description'), description),
        field(t('budget', 'Direction'), direction),
        field(t('budget', 'Amount'), amount),
        field(t('budget', 'Date'), date),
    );
    const actions = el('div', 'runway-oneoff-form-actions');
    actions.append(save, cancel);
    form.append(fields, actions);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = description.value.trim();
        const plain = plainAmount(amount.value);
        const day = date.value;
        if (!text) {
            showError(t('budget', 'Enter a description'));
            description.focus();
            return;
        }
        if (plain === null) {
            showError(t('budget', 'Enter an amount above zero'));
            amount.focus();
            return;
        }
        if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
            showError(t('budget', 'Choose a date'));
            return;
        }
        const signed = direction.value === 'out' ? '-' + plain : plain;

        let body = { description: text, amount: signed, date: day };
        if (oneOff) {
            body = {};
            if (text !== oneOff.description) body.description = text;
            if (Number(signed) !== Number(oneOff.amount)) body.amount = signed;
            if (day !== oneOff.date) body.date = day;
            if (Object.keys(body).length === 0) {
                onCancel();
                return;
            }
        }

        save.disabled = true;
        try {
            await apiFetch(oneOff ? `${URL}/${oneOff.id}` : URL, { method: oneOff ? 'PUT' : 'POST', body });
            await onSaved();
        } catch (error) {
            showError(error?.message || t('budget', 'Failed to save the one-off'));
            save.disabled = false;
        }
    });

    // The app's date box: a picker showing the user's date format
    try {
        initSingleDatePicker(date, settings());
    } catch {
        // A plain date box still works
    }
    return form;
}

async function remove(oneOff, onSaved) {
    const ok = await confirmDialog(
        t('budget', 'Delete the one-off "{description}"?', { description: oneOff.description }),
        { confirmLabel: t('budget', 'Delete'), destructive: true },
    );
    if (!ok) return;
    try {
        await apiFetch(`${URL}/${oneOff.id}`, { method: 'DELETE' });
        await onSaved();
    } catch (error) {
        showError(error?.message || t('budget', 'Failed to delete the one-off'));
    }
}

function row(oneOff, currency, { onEdit, onSaved }) {
    const item = el('li', 'runway-oneoff' + (oneOff.past ? ' runway-oneoff--past' : ''));
    const main = el('div', 'runway-oneoff-main');
    main.appendChild(el('span', 'runway-oneoff-description', oneOff.description));
    const when = oneOff.past
        ? t('budget', '{date} · past', { date: longDate(oneOff.date) }, undefined, AS_TEXT)
        : longDate(oneOff.date);
    main.appendChild(el('span', 'runway-oneoff-date', when));
    item.appendChild(main);

    const value = Number(oneOff.amount);
    item.appendChild(el('span', `runway-oneoff-amount ${amountClass(value)}`, (value > 0 ? '+' : '') + money(value, currency)));

    const actions = el('span', 'runway-oneoff-actions');
    const edit = button('', 'action-btn edit-btn', () => onEdit(item));
    edit.dataset.runwayAction = 'edit';
    edit.title = t('budget', 'Edit one-off');
    edit.setAttribute('aria-label', edit.title);
    edit.appendChild(el('span', 'icon-rename'));
    const del = button('', 'action-btn delete-btn', () => remove(oneOff, onSaved));
    del.dataset.runwayAction = 'delete';
    del.title = t('budget', 'Delete one-off');
    del.setAttribute('aria-label', del.title);
    del.appendChild(el('span', 'icon-delete'));
    actions.append(edit, del);
    item.appendChild(actions);
    return item;
}

/**
 * The one-offs list with add, edit and delete.
 *
 * @param {HTMLElement} host
 * @param {object} data the runway payload
 * @param {object} options
 * @param {Function} options.onChanged awaited after any change; refreshes every runway tile
 */
export function renderOneOffs(host, data, { onChanged }) {
    const wrap = el('div', 'runway-oneoffs');
    const list = el('ul', 'runway-oneoff-list');
    const oneOffs = data.oneOffs || [];
    const addButton = button(t('budget', '+ Add one-off'), 'btn-link-small runway-oneoff-add', () => openEditor(null));
    addButton.dataset.runwayAction = 'add';

    const empty = el('li', 'runway-oneoff-empty', t('budget', 'No planned one-offs. Add money you expect to spend or receive once, such as a car repair or a tax refund.'));

    function openEditor(oneOff, item = null) {
        wrap.querySelectorAll('.runway-oneoff-form').forEach((form) => form.closest('li')?.remove());
        list.querySelectorAll('.runway-oneoff').forEach((node) => { node.hidden = false; });
        empty.hidden = true;
        addButton.hidden = true;

        const holder = el('li', 'runway-oneoff-editing');
        const close = () => {
            holder.remove();
            if (item) item.hidden = false;
            empty.hidden = oneOffs.length > 0;
            addButton.hidden = false;
        };
        holder.appendChild(editor(oneOff, { onSaved: onChanged, onCancel: close }));
        if (item) {
            item.hidden = true;
            item.after(holder);
        } else {
            list.appendChild(holder);
        }
        holder.querySelector('input[name="description"]')?.focus();
    }

    empty.hidden = oneOffs.length > 0;
    list.appendChild(empty);
    for (const oneOff of oneOffs) {
        list.appendChild(row(oneOff, data.currency, {
            onEdit: (item) => openEditor(oneOff, item),
            onSaved: onChanged,
        }));
    }
    wrap.append(list, addButton);
    host.appendChild(wrap);
}
