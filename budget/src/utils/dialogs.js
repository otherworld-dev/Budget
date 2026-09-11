/**
 * In-app confirm / prompt / alert dialogs (#381).
 *
 * These replace window.confirm(), window.prompt() and window.alert() app-wide.
 * The native ones are a silent single point of failure: after a few modal
 * dialogs in a row, Chrome and Firefox offer "Prevent this page from creating
 * additional dialogs", and once that is ticked every later confirm() returns
 * false immediately without drawing anything. Every destructive action in the
 * app then becomes a no-op with no dialog, no error and no toast — the user
 * clicks Delete and nothing whatsoever happens, which is what #381 reported
 * after deleting a couple of accounts from a bad import. The suppression lasts
 * until the page is reloaded, and nothing in the page can detect or undo it.
 *
 * Being our own DOM, these cannot be suppressed. They are async, so callers
 * must await them — that is the one behavioural difference from the natives.
 *
 * Deliberately dependency-free and built with createElement/textContent (never
 * innerHTML): messages interpolate account, category and contact names the
 * user typed.
 */

import { translate as t } from '@nextcloud/l10n';
import { plainText } from './helpers.js';

/** The dialog currently on screen, or null. */
let active = null;

/** Dialogs asked for while another was open, shown in turn. */
const queue = [];

/**
 * Split a message into display lines. Call sites compose multi-paragraph
 * warnings with '\n\n', which native confirm() rendered as line breaks.
 */
function messageLines(message) {
    return plainText(message)
        .split('\n')
        .map(line => line.trim())
        .filter(line => line !== '');
}

function buildDialog(spec) {
    const backdrop = document.createElement('div');
    backdrop.className = 'budget-dialog';

    const panel = document.createElement('div');
    panel.className = 'budget-dialog-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    const titleId = `budget-dialog-title-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const title = document.createElement('h3');
    title.className = 'budget-dialog-title';
    title.id = titleId;
    title.textContent = plainText(spec.title);
    panel.setAttribute('aria-labelledby', titleId);
    panel.appendChild(title);

    const body = document.createElement('div');
    body.className = 'budget-dialog-message';
    for (const line of messageLines(spec.message)) {
        const p = document.createElement('p');
        p.textContent = line;
        body.appendChild(p);
    }
    panel.appendChild(body);

    let input = null;
    if (spec.kind === 'prompt') {
        input = document.createElement('input');
        input.type = 'text';
        input.className = 'budget-dialog-input';
        input.value = spec.defaultValue ?? '';
        input.setAttribute('aria-labelledby', titleId);
        panel.appendChild(input);
    }

    const buttons = document.createElement('div');
    buttons.className = 'budget-dialog-buttons';

    let cancel = null;
    if (spec.kind !== 'alert') {
        cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'budget-dialog-btn budget-dialog-cancel';
        cancel.textContent = plainText(spec.cancelLabel);
        buttons.appendChild(cancel);
    }

    const confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'budget-dialog-btn budget-dialog-confirm'
        + (spec.destructive ? ' budget-dialog-btn--destructive' : ' primary');
    confirm.textContent = plainText(spec.confirmLabel);
    buttons.appendChild(confirm);

    panel.appendChild(buttons);
    backdrop.appendChild(panel);

    return { backdrop, panel, input, cancel, confirm };
}

/**
 * Render one dialog and wire it up. Resolves with the caller's answer.
 */
function render(spec) {
    const { backdrop, panel, input, cancel, confirm } = buildDialog(spec);
    const previousFocus = document.activeElement;

    const close = (answer) => {
        if (active !== state) return;
        document.removeEventListener('keydown', onKeydown, true);
        backdrop.remove();
        active = null;

        // Put focus back where the user left it, unless that element went away
        // with the action they just confirmed.
        if (previousFocus && document.contains(previousFocus) && previousFocus.focus) {
            previousFocus.focus();
        }

        spec.resolve(answer);
        showNext();
    };

    const onKeydown = (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close(spec.cancelAnswer);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            // Enter activates whatever is focused, so on a destructive dialog
            // — which opens on Cancel — it dismisses rather than deletes.
            if (cancel && document.activeElement === cancel) {
                close(spec.cancelAnswer);
            } else {
                close(spec.confirmAnswer(input));
            }
        } else if (e.key === 'Tab') {
            trapFocus(e, panel);
        }
    };

    const state = { close };
    active = state;

    confirm.addEventListener('click', () => close(spec.confirmAnswer(input)));
    if (cancel) {
        cancel.addEventListener('click', () => close(spec.cancelAnswer));
    }
    // Only a click on the backdrop itself — one that started and ended outside
    // the panel — dismisses. A drag that ends on the backdrop (selecting the
    // message text, say) must not count as a cancel.
    backdrop.addEventListener('mousedown', (e) => {
        if (e.target === backdrop) {
            backdrop.dataset.pressedBackdrop = 'true';
        }
    });
    backdrop.addEventListener('click', (e) => {
        if (e.target !== backdrop) return;
        if (backdrop.dataset.pressedBackdrop === undefined && e.detail !== 0) return;
        close(spec.cancelAnswer);
    });
    document.addEventListener('keydown', onKeydown, true);

    // Visible the moment it is in the document. The entrance is a pure CSS
    // animation on purpose: gating opacity on a requestAnimationFrame callback
    // would leave an invisible-but-modal dialog on any frame that never runs,
    // which is the same "nothing happens" failure this whole change removes.
    document.body.appendChild(backdrop);

    // A prompt starts in its field; a destructive confirm starts on Cancel, so
    // the keystroke that arrives before the user has read the question cannot
    // be the one that deletes something.
    const initial = input || (spec.destructive && cancel ? cancel : confirm);
    initial.focus();
    if (input) input.select();
}

function trapFocus(e, panel) {
    const focusable = panel.querySelectorAll('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function showNext() {
    if (active || queue.length === 0) return;
    render(queue.shift());
}

/**
 * Queue a dialog. Two dialogs never share the screen: a second request waits
 * for the first to be answered. Several call sites ask a follow-up question
 * (delete the account's transactions too?) straight after the first.
 */
function open(spec) {
    return new Promise(resolve => {
        queue.push({ ...spec, resolve });
        showNext();
    });
}

/**
 * Ask the user to confirm an action. Replaces window.confirm().
 *
 * @param {string} message Question to put to the user.
 * @param {object} [options]
 * @param {string} [options.title] Heading; defaults to a neutral one.
 * @param {string} [options.confirmLabel] Text of the confirming button.
 * @param {string} [options.cancelLabel] Text of the dismissing button.
 * @param {boolean} [options.destructive] Style the confirm button as dangerous.
 * @return {Promise<boolean>} true if confirmed, false if dismissed.
 */
export function confirmDialog(message, options = {}) {
    return open({
        kind: 'confirm',
        message,
        title: options.title || t('budget', 'Please confirm'),
        confirmLabel: options.confirmLabel || t('budget', 'Confirm'),
        cancelLabel: options.cancelLabel || t('budget', 'Cancel'),
        destructive: options.destructive === true,
        confirmAnswer: () => true,
        cancelAnswer: false,
    });
}

/**
 * Ask the user for a value. Replaces window.prompt().
 *
 * @param {string} message Label for the input.
 * @param {object} [options]
 * @param {string} [options.title] Heading; defaults to the message's own role.
 * @param {string} [options.defaultValue] Value the input starts with.
 * @param {string} [options.confirmLabel] Text of the confirming button.
 * @param {string} [options.cancelLabel] Text of the dismissing button.
 * @return {Promise<string|null>} the typed value, or null if dismissed.
 */
export function promptDialog(message, options = {}) {
    return open({
        kind: 'prompt',
        message,
        title: options.title || t('budget', 'Enter a value'),
        defaultValue: options.defaultValue ?? '',
        confirmLabel: options.confirmLabel || t('budget', 'OK'),
        cancelLabel: options.cancelLabel || t('budget', 'Cancel'),
        destructive: false,
        confirmAnswer: (input) => (input ? input.value : ''),
        cancelAnswer: null,
    });
}

/**
 * Tell the user something. Replaces window.alert().
 *
 * @param {string} message What to say.
 * @param {object} [options]
 * @param {string} [options.title] Heading; defaults to a neutral one.
 * @param {string} [options.confirmLabel] Text of the acknowledging button.
 * @return {Promise<void>} resolves once acknowledged.
 */
export function alertDialog(message, options = {}) {
    return open({
        kind: 'alert',
        message,
        title: options.title || t('budget', 'Notice'),
        confirmLabel: options.confirmLabel || t('budget', 'OK'),
        destructive: false,
        confirmAnswer: () => undefined,
        cancelAnswer: undefined,
    });
}
