/**
 * Toast notification utilities
 * Replaces deprecated OC.Notification.showTemporary()
 */

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
    toast.textContent = message;
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
