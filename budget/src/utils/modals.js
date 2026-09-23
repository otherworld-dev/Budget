/**
 * Shared handling for the app's two modal families.
 *
 * `.modal` is the static markup in templates/index.php, shown and hidden by
 * toggling its inline display. `.budget-modal-overlay` is built in JS, appended
 * to <body> and removed when closed. Both are treated the same here, so Escape,
 * the focus trap, focus restore, ARIA state and "close on navigation" apply to
 * every modal no matter which family it belongs to or how it was opened.
 *
 * The in-app confirm/prompt dialogs (utils/dialogs.js) are a third, separate
 * kind: they manage their own keys and focus and are never matched here.
 */
import { translate as t } from '@nextcloud/l10n';

export const MODAL_SELECTOR = '.modal, .budget-modal-overlay';

let labelSeq = 0;

/** True when the modal is attached and not hidden by display:none. */
export function isModalOpen(el) {
    return !!el && el.isConnected && getComputedStyle(el).display !== 'none';
}

/** Every open modal, in DOM order (so the last one is on top). */
export function openModals() {
    return Array.from(document.querySelectorAll(MODAL_SELECTOR)).filter(isModalOpen);
}

/** The topmost open modal, or null. */
export function topModal() {
    const open = openModals();
    return open.length ? open[open.length - 1] : null;
}

/**
 * Close a modal through its own Cancel/Close control, so any per-modal cleanup
 * (a dirty-state refresh, a JS overlay removing itself) still runs. Falls back
 * to hiding or removing it outright only when it has no such control, so a
 * close handler that asks "discard changes?" is never bypassed.
 */
export function closeModal(el) {
    const btn = el.querySelector('.cancel-btn, .close-btn, .modal-close, [data-dismiss]');
    if (btn) {
        btn.click();
        return;
    }
    if (el.classList.contains('budget-modal-overlay')) {
        el.remove();
    } else {
        el.style.display = 'none';
    }
}

/** Close every open modal, topmost first. */
export function closeAllModals() {
    openModals().reverse().forEach(closeModal);
}

/**
 * Give an opening modal the ARIA a dialog needs. The static markup is
 * inconsistent (role missing on some, aria-hidden="true" left on while open on
 * most), so it is normalised here rather than trusted.
 */
export function markModalOpen(el) {
    const dialog = el;
    if (!dialog.getAttribute('role')) dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-hidden', 'false');

    if (!dialog.getAttribute('aria-labelledby') && !dialog.getAttribute('aria-label')) {
        const heading = dialog.querySelector('h1, h2, h3, h4');
        if (heading) {
            if (!heading.id) heading.id = `budget-modal-label-${++labelSeq}`;
            dialog.setAttribute('aria-labelledby', heading.id);
        }
    }

    // Close buttons drawn as a bare "×" need a spoken name.
    dialog.querySelectorAll('.close-btn, .modal-close').forEach(btn => {
        if (!btn.getAttribute('aria-label')) {
            btn.setAttribute('aria-label', btn.getAttribute('title') || t('budget', 'Close'));
        }
    });
}

export function markModalClosed(el) {
    el.setAttribute('aria-hidden', 'true');
}
