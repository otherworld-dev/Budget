/**
 * "More" dropdowns in view headers.
 *
 * Markup: a `.header-menu` holding a `.header-menu-toggle` button and a
 * `.header-menu-list` (hidden) of ordinary buttons. The buttons keep their own
 * ids and click handlers; this only opens and closes the list around them, so
 * moving a header button into a menu needs no change to its handler.
 *
 * Wired once, by delegation, so menus rendered later work too.
 */

function listOf(toggle) {
    return toggle.closest('.header-menu')?.querySelector('.header-menu-list') || null;
}

function close(menuRoot, restoreFocus = false) {
    const toggle = menuRoot.querySelector('.header-menu-toggle');
    const list = menuRoot.querySelector('.header-menu-list');
    if (!list || list.hidden) return;
    list.hidden = true;
    toggle?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle?.focus();
}

function closeAll(except = null) {
    document.querySelectorAll('.header-menu').forEach(root => {
        if (root !== except) close(root);
    });
}

function items(list) {
    return [...list.querySelectorAll('button:not([disabled])')]
        .filter(b => b.offsetParent !== null);
}

export function setupHeaderMenus() {
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('.header-menu-toggle');
        if (toggle) {
            const root = toggle.closest('.header-menu');
            const list = listOf(toggle);
            if (!list) return;
            closeAll(root);
            const opening = list.hidden;
            list.hidden = !opening;
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            if (opening) items(list)[0]?.focus();
            return;
        }
        // A click on an item runs the item's own handler and closes the menu;
        // a click anywhere else just closes it.
        closeAll();
    });

    document.addEventListener('keydown', (e) => {
        const root = e.target.closest?.('.header-menu');
        if (!root) return;
        const list = root.querySelector('.header-menu-list');
        if (!list || list.hidden) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close(root, true);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const all = items(list);
            const i = all.indexOf(document.activeElement);
            const next = e.key === 'ArrowDown' ? i + 1 : i - 1;
            all[(next + all.length) % all.length]?.focus();
        }
    }, true);

    document.addEventListener('focusout', (e) => {
        const root = e.target.closest?.('.header-menu');
        if (root && e.relatedTarget && !root.contains(e.relatedTarget)) close(root);
    });
}
