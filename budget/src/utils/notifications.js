/**
 * Toast notification utilities
 * Replaces deprecated OC.Notification.showTemporary()
 */

import { translate as t } from '@nextcloud/l10n';
import { plainText } from './helpers.js';

const TOAST_TIMEOUT = 7000;

function getContainer() {
    let container = document.getElementById('budget-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'budget-toast-container';
        document.body.appendChild(container);
    }
    return container;
}

function showToast(message, type) {
    const container = getContainer();

    const toast = document.createElement('div');
    toast.className = `budget-toast budget-toast-${type}`;
    toast.textContent = plainText(message);
    toast.addEventListener('click', () => dismiss(toast));

    container.appendChild(toast);

    // Trigger entrance animation on next frame
    requestAnimationFrame(() => toast.classList.add('budget-toast--visible'));

    setTimeout(() => dismiss(toast), TOAST_TIMEOUT);
}

function dismiss(toast) {
    if (toast.classList.contains('budget-toast--dismissing')) return;
    toast.classList.add('budget-toast--dismissing');
    toast.classList.remove('budget-toast--visible');
    toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    // Fallback removal if transitionend doesn't fire
    setTimeout(() => toast.remove(), 500);
}

export function showSuccess(message) {
    showToast(message, 'success');
}

export function showError(message) {
    showToast(message, 'error');
}

export function showWarning(message) {
    showToast(message, 'warning');
}

export function showInfo(message) {
    showToast(message, 'info');
}

/**
 * A short-lived toast with an Undo button. The undo callback fires at most
 * once; onExpire fires instead when the toast times out without it, so the
 * caller can drop whatever state the undo needed.
 */
export function showUndoNotification(message, undoCallback, onExpire) {
    const notification = document.createElement('div');
    notification.className = 'undo-notification';
    let expired = false;
    notification.innerHTML = `
        <span class="undo-message"></span>
        <button class="undo-btn">${t('budget', 'Undo')}</button>
    `;
    // The message is plain text; set it as such so it can never be parsed as markup.
    notification.querySelector('.undo-message').textContent = plainText(message);

    Object.assign(notification.style, {
        position: 'fixed',
        bottom: '20px',
        left: '50%',
        transform: 'translateX(-50%)',
        backgroundColor: '#333',
        color: '#fff',
        padding: '12px 20px',
        borderRadius: '4px',
        display: 'flex',
        alignItems: 'center',
        gap: '15px',
        zIndex: '10000',
        boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
        animation: 'slideUp 0.3s ease-out'
    });

    const undoBtn = notification.querySelector('.undo-btn');
    Object.assign(undoBtn.style, {
        backgroundColor: '#fff',
        color: '#333',
        border: 'none',
        padding: '6px 12px',
        borderRadius: '3px',
        cursor: 'pointer',
        fontWeight: 'bold',
        fontSize: '13px'
    });

    undoBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!expired) {
            expired = true;
            undoCallback();
        }
        notification.remove();
    });

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'slideDown 0.3s ease-in';
        setTimeout(() => {
            notification.remove();
            if (!expired && onExpire) {
                expired = true;
                onExpire();
            }
        }, 300);
    }, 5000);
}
