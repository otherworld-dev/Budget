/**
 * KeyboardShortcuts — app-wide keyboard controls.
 *
 * Three layers, all driven by a single document-level keydown listener plus a
 * MutationObserver that notices when a modal becomes visible:
 *
 *  1. Modals — Esc closes the open modal (reusing its own Cancel/Close button so
 *     any per-modal cleanup still runs), Tab is trapped inside it, focus lands on
 *     the first field when it opens, and Enter confirms modals that have no <form>
 *     (form modals already submit natively on Enter).
 *  2. Global — "/" or Ctrl/Cmd+K focuses search, "?" toggles a shortcuts cheat
 *     sheet, and "g" then a letter jumps between pages.
 *  3. Transactions list — j/k move a row cursor, e/Enter edit, x toggles select.
 *
 * Shortcuts stay out of the way while the user is typing in a field, and the
 * global/list layers are suppressed whenever a modal is open.
 */
import { translate as t } from '@nextcloud/l10n';

// "g" then a letter → view name. Covers every page in the sidebar; letters are
// chosen for mnemonic where possible and are collision-free. Keep this in sync
// with the sidebar (templates/index.php) and the cheat sheet in _buildOverlay().
const GO_TO = {
    d: 'dashboard',
    a: 'accounts',
    t: 'transactions',
    c: 'categories',
    g: 'tags',
    b: 'budget',
    i: 'income',
    l: 'bills',
    n: 'transfers',
    v: 'savings-goals',
    y: 'debt-payoff',
    p: 'pensions',
    e: 'assets',
    h: 'shared-expenses',
    f: 'forecast',
    r: 'reports',
    m: 'import',
    u: 'rules',
    x: 'exchange-rates',
    w: 'sharing',
    k: 'bank-sync',
    s: 'settings',
};

export default class KeyboardShortcuts {
    constructor(app) {
        this.app = app;
        this._pendingG = false;
        this._gTimer = null;
        this._cursorId = null; // transactions row cursor, tracked by transaction id
        this._lastFocus = null; // element focused before a modal opened
    }

    init() {
        document.addEventListener('keydown', (e) => this._onKeyDown(e));
        this._observeModals();
    }

    // ---- key routing ----------------------------------------------------

    _onKeyDown(e) {
        // Never interfere with modifier combos we don't own (copy/paste, etc.),
        // except the ones we explicitly handle below.
        if (e.altKey) return;

        const overlayOpen = this._overlay && this._overlay.style.display !== 'none';
        const modal = this._visibleModal();

        // Escape: close the shortcuts overlay first, then any open modal.
        if (e.key === 'Escape') {
            if (overlayOpen) { this._closeOverlay(); e.preventDefault(); return; }
            if (modal) { this._closeModal(modal); e.preventDefault(); return; }
            return;
        }

        // While a modal is open, only manage focus/confirm within it.
        if (modal) {
            if (e.key === 'Tab') { this._trapFocus(e, modal); return; }
            if (e.key === 'Enter') this._confirmFormlessModal(e, modal);
            return;
        }

        const typing = this._isTyping(e.target);

        // "?" — toggle the shortcuts cheat sheet (needs Shift; ignore while typing).
        if (!typing && e.key === '?') { this._toggleOverlay(); e.preventDefault(); return; }
        if (overlayOpen) return; // overlay swallows everything else (Esc handled above)

        // "/" or Ctrl/Cmd+K — focus search.
        if (!typing && e.key === '/' && !e.ctrlKey && !e.metaKey) {
            this._focusSearch(); e.preventDefault(); return;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            this._focusSearch(); e.preventDefault(); return;
        }

        if (typing || e.ctrlKey || e.metaKey) return; // remaining shortcuts are plain single keys

        // "g" then a letter — go to a page.
        if (this._pendingG) {
            this._pendingG = false;
            clearTimeout(this._gTimer);
            const view = GO_TO[e.key.toLowerCase()];
            if (view) { this.app.showView(view); e.preventDefault(); }
            return;
        }
        if (e.key === 'g') {
            this._pendingG = true;
            this._gTimer = setTimeout(() => { this._pendingG = false; }, 1500);
            return;
        }

        // Transactions list navigation.
        if (this.app.currentView === 'transactions') this._handleListKey(e);
    }

    /** True when the target is an editable control (or an inline-edit cell). */
    _isTyping(el) {
        if (!el) return false;
        const tag = el.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (el.isContentEditable) return true;
        return !!el.closest('.editable-cell.editing');
    }

    // ---- modals ---------------------------------------------------------

    /** The visible modal (last in DOM order wins if several are shown). */
    _visibleModal() {
        let found = null;
        document.querySelectorAll('.modal').forEach(m => {
            if (getComputedStyle(m).display !== 'none') found = m;
        });
        return found;
    }

    /** Close a modal via its own Cancel/Close control so per-modal cleanup runs. */
    _closeModal(modal) {
        const btn = modal.querySelector('.cancel-btn, .close-btn, .modal-close, [data-dismiss]');
        if (btn) { btn.click(); return; }
        if (typeof this.app.hideModals === 'function') this.app.hideModals();
    }

    /** Enter confirms modals with no <form> (form modals submit natively). */
    _confirmFormlessModal(e, modal) {
        const el = e.target;
        // Don't override native activation of a focused button/link.
        if (el.tagName === 'TEXTAREA' || el.tagName === 'BUTTON' || el.tagName === 'A') return;
        if (modal.querySelector('form')) return;
        const primary = modal.querySelector('.modal-actions .primary, .modal-actions button.primary, button.primary');
        if (primary && !primary.disabled) { primary.click(); e.preventDefault(); }
    }

    /** Keep Tab focus inside the open modal. */
    _trapFocus(e, modal) {
        const items = this._focusable(modal);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (e.shiftKey && (active === first || !modal.contains(active))) {
            last.focus(); e.preventDefault();
        } else if (!e.shiftKey && active === last) {
            first.focus(); e.preventDefault();
        }
    }

    _focusable(root) {
        return Array.from(root.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]),' +
            ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement);
    }

    /** Watch every modal's inline style; focus its first field the moment it opens. */
    _observeModals() {
        const obs = new MutationObserver((records) => {
            for (const rec of records) {
                const el = rec.target;
                if (!(el.classList && el.classList.contains('modal'))) continue;
                const open = getComputedStyle(el).display !== 'none';
                if (open && el.dataset.kbdOpen !== '1') {
                    el.dataset.kbdOpen = '1';
                    this._onModalOpen(el);
                } else if (!open && el.dataset.kbdOpen === '1') {
                    el.dataset.kbdOpen = '';
                    this._onModalClose();
                }
            }
        });
        obs.observe(document.body, { attributes: true, attributeFilter: ['style'], subtree: true });
    }

    _onModalOpen(modal) {
        this._lastFocus = document.activeElement;
        // Only take focus if the app hasn't already put it inside this modal, so
        // we don't fight code that focuses a specific field on open.
        if (modal.contains(document.activeElement)) return;
        const field = modal.querySelector(
            'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])'
        ) || modal.querySelector('button.primary');
        // Defer so it wins over any focus the open handler sets synchronously.
        if (field) setTimeout(() => {
            if (getComputedStyle(modal).display !== 'none' && !modal.contains(document.activeElement)) {
                field.focus();
                if (typeof field.select === 'function' && field.tagName === 'INPUT') {
                    try { field.select(); } catch (_) { /* non-text input */ }
                }
            }
        }, 0);
    }

    _onModalClose() {
        const prev = this._lastFocus;
        this._lastFocus = null;
        if (prev && document.body.contains(prev)) {
            const inClosedModal = prev.closest && prev.closest('.modal');
            if (!inClosedModal || getComputedStyle(inClosedModal).display !== 'none') {
                try { prev.focus(); } catch (_) { /* ignore */ }
            }
        }
    }

    // ---- global ---------------------------------------------------------

    _focusSearch() {
        const view = document.querySelector('.view.active');
        let input = view && view.querySelector(
            'input[type="search"], input[id*="search" i], input[placeholder*="search" i]'
        );
        if (!input || !(input.offsetWidth > 0 || input.offsetHeight > 0)) {
            input = document.getElementById('app-navigation-search-input');
        }
        if (input) { input.focus(); if (typeof input.select === 'function') input.select(); }
    }

    // ---- transactions list ---------------------------------------------

    _visibleRows() {
        return Array.from(document.querySelectorAll('#transactions-table tbody tr.transaction-row'))
            .filter(r => r.offsetParent !== null);
    }

    _cursorIndex(rows) {
        if (this._cursorId != null) {
            const i = rows.findIndex(r => r.dataset.transactionId === String(this._cursorId));
            if (i !== -1) return i;
        }
        return rows.findIndex(r => r.classList.contains('kbd-cursor'));
    }

    _setCursor(rows, idx) {
        rows.forEach(r => r.classList.remove('kbd-cursor'));
        const row = rows[idx];
        if (!row) { this._cursorId = null; return; }
        row.classList.add('kbd-cursor');
        this._cursorId = row.dataset.transactionId;
        row.scrollIntoView({ block: 'nearest' });
    }

    _handleListKey(e) {
        // Let Enter activate a focused button/link natively rather than hijacking it.
        if (e.key === 'Enter') {
            const el = e.target;
            if (el && (el.tagName === 'BUTTON' || el.tagName === 'A' ||
                el.tagName === 'SUMMARY' || el.getAttribute('role') === 'button')) return;
        }

        const rows = this._visibleRows();
        if (!rows.length) return;
        const idx = this._cursorIndex(rows);

        switch (e.key) {
            case 'j':
                this._setCursor(rows, idx < 0 ? 0 : Math.min(rows.length - 1, idx + 1));
                e.preventDefault();
                break;
            case 'k':
                this._setCursor(rows, idx < 0 ? 0 : Math.max(0, idx - 1));
                e.preventDefault();
                break;
            case 'e':
            case 'Enter':
                if (idx >= 0) { rows[idx].querySelector('.transaction-edit-btn')?.click(); e.preventDefault(); }
                break;
            case 'x':
                if (idx >= 0) { rows[idx].querySelector('.transaction-checkbox')?.click(); e.preventDefault(); }
                break;
        }
    }

    // ---- shortcuts cheat sheet -----------------------------------------

    /** Public entry point — open the cheat sheet (e.g. from the help panel). */
    openShortcuts() {
        this._openOverlay();
    }

    _toggleOverlay() {
        if (this._overlay && this._overlay.style.display !== 'none') this._closeOverlay();
        else this._openOverlay();
    }

    _openOverlay() {
        if (!this._overlay) this._overlay = this._buildOverlay();
        this._overlay.style.display = 'flex';
    }

    _closeOverlay() {
        if (this._overlay) this._overlay.style.display = 'none';
    }

    _buildOverlay() {
        const groups = [
            {
                title: t('budget', 'Global'),
                rows: [
                    [['/'], t('budget', 'Focus search')],
                    [['Ctrl', 'K'], t('budget', 'Focus search')],
                    [['?'], t('budget', 'Show this help')],
                    [['Esc'], t('budget', 'Close dialog / this help')],
                    [['g', 'then', 'key'], t('budget', 'Go to a page (see below)')],
                ],
            },
            {
                title: t('budget', 'Go to (press g, then)'),
                wide: true,
                rows: [
                    [['d'], t('budget', 'Dashboard')],
                    [['a'], t('budget', 'Accounts')],
                    [['t'], t('budget', 'Transactions')],
                    [['c'], t('budget', 'Categories')],
                    [['g'], t('budget', 'Tags')],
                    [['b'], t('budget', 'Budget')],
                    [['i'], t('budget', 'Income')],
                    [['l'], t('budget', 'Bills')],
                    [['n'], t('budget', 'Transfers')],
                    [['v'], t('budget', 'Savings Goals')],
                    [['y'], t('budget', 'Debt Payoff')],
                    [['p'], t('budget', 'Pensions')],
                    [['e'], t('budget', 'Assets')],
                    [['h'], t('budget', 'Shared Expenses')],
                    [['f'], t('budget', 'Forecast')],
                    [['r'], t('budget', 'Reports')],
                    [['m'], t('budget', 'Import')],
                    [['u'], t('budget', 'Rules')],
                    [['x'], t('budget', 'Exchange Rates')],
                    [['w'], t('budget', 'Sharing')],
                    [['k'], t('budget', 'Bank Sync')],
                    [['s'], t('budget', 'Settings')],
                ],
            },
            {
                title: t('budget', 'Transactions list'),
                rows: [
                    [['j', '/', 'k'], t('budget', 'Move row cursor down / up')],
                    [['e'], t('budget', 'Edit the current row')],
                    [['Enter'], t('budget', 'Edit the current row')],
                    [['x'], t('budget', 'Select / deselect the current row')],
                ],
            },
        ];

        const overlay = document.createElement('div');
        overlay.id = 'kbd-shortcuts-overlay';
        overlay.className = 'kbd-shortcuts-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', t('budget', 'Keyboard shortcuts'));
        overlay.style.display = 'none';

        const sections = groups.map(g => `
            <section class="kbd-group${g.wide ? ' kbd-group--wide' : ''}">
                <h4>${g.title}</h4>
                <dl>
                    ${g.rows.map(([keys, label]) => `
                        <div class="kbd-row">
                            <dt>${keys.map(k => k === 'then' || k === '/'
                                ? `<span class="kbd-sep">${k}</span>`
                                : `<kbd>${k}</kbd>`).join(' ')}</dt>
                            <dd>${label}</dd>
                        </div>`).join('')}
                </dl>
            </section>`).join('');

        overlay.innerHTML = `
            <div class="kbd-shortcuts-card">
                <div class="kbd-shortcuts-header">
                    <h3>${t('budget', 'Keyboard shortcuts')}</h3>
                    <button type="button" class="kbd-shortcuts-close" aria-label="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="kbd-shortcuts-body">${sections}</div>
            </div>`;

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay || e.target.closest('.kbd-shortcuts-close')) this._closeOverlay();
        });

        document.body.appendChild(overlay);
        return overlay;
    }
}
