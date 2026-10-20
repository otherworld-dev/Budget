/**
 * A dropdown that an inline editor opens in a list cell (the category and
 * tag pickers), fixed to the window rather than placed in the cell.
 *
 * The Transactions list scrolls sideways in its own box (.table-scroll),
 * and that box cuts off anything placed inside it that runs past its
 * edge: in the last rows of a short list a picker showed only its first
 * item or two (#399). Fixed, it can't be cut off. It stays in the cell's
 * DOM, so focus, the keyboard and the click-outside checks work as before;
 * only its place on screen comes from the cell, as for the row's ⋮ menu
 * (main.js), above the cell when there's no room below it.
 *
 * The `cell-popup` class turns the fixing on (css/style.css), so the same
 * dropdowns elsewhere (the filters, the edit form) are left as they are.
 */

/** Distance kept from the window's edges, as the ⋮ menu keeps */
const MARGIN = 8;

/**
 * Put `popup` under `anchor`, or above it when it would run off the bottom
 * of the window and there's more room above, and inside the window at the
 * sides. Call it again whenever the popup's content changes its height.
 */
export function placeCellPopup(popup, anchor) {
    const rect = anchor.getBoundingClientRect();
    // As wide as the cell (the stylesheet keeps its 250 px minimum), before
    // measuring, as the width decides how the items wrap
    popup.style.setProperty('--cell-popup-width', `${rect.width}px`);
    const height = popup.offsetHeight;
    const width = popup.offsetWidth;
    const roomBelow = window.innerHeight - MARGIN - rect.bottom;
    const roomAbove = rect.top - MARGIN;
    const above = height > roomBelow && roomAbove > roomBelow;

    popup.classList.toggle('cell-popup--above', above);
    popup.style.top = `${above ? Math.max(MARGIN, rect.top - height) : rect.bottom}px`;
    popup.style.left = `${Math.max(MARGIN, Math.min(rect.left, window.innerWidth - MARGIN - width))}px`;
}

/**
 * Whether `anchor` has scrolled right out of the list's box (.table-scroll)
 * round it: past its top, under the header row stuck there, or past its
 * bottom or sides. The page scrolling the whole box along doesn't count,
 * nor does a box that isn't laid out (a phone's cards scroll with the page).
 */
export function scrolledOutOfBox(anchor) {
    const box = anchor.closest('.table-scroll');
    if (!box) return false;
    const area = box.getBoundingClientRect();
    if (!area.height || !area.width) return false;

    let top = area.top;
    const th = [...box.querySelectorAll('thead th')].find(el => el.getBoundingClientRect().height > 0);
    if (th) {
        // The header row is stuck at the top of the box above phone width
        const head = th.getBoundingClientRect();
        if (head.top >= area.top - 1 && head.bottom > top) top = head.bottom;
    }

    const cell = anchor.getBoundingClientRect();
    return cell.bottom <= top || cell.top >= area.bottom
        || cell.right <= area.left || cell.left >= area.right;
}

/**
 * Fix `popup` to the window next to `anchor`, and keep it there while
 * anything scrolls (the page, the table's box) or the window is resized.
 * It lets go of those events once the popup has left the page, which it
 * does when the table is drawn again at the end of the edit.
 *
 * Once the rows scroll the anchor right out of the list's box, `onHidden`
 * is called, once, instead: the editors close without saving, as Escape
 * does, rather than leave the popup over the bar, the header row or the
 * paging (#399).
 *
 * @returns {() => void} places the popup again, for after its content changes
 */
export function floatCellPopup(popup, anchor, { onHidden = null } = {}) {
    popup.classList.add('cell-popup');

    let hidden = false;
    const follow = () => {
        if (!popup.isConnected || hidden) {
            window.removeEventListener('scroll', follow, true);
            window.removeEventListener('resize', follow);
            return;
        }
        if (onHidden && scrolledOutOfBox(anchor)) {
            hidden = true;
            popup.style.display = 'none';
            onHidden();
            return;
        }
        if (popup.style.display !== 'none') placeCellPopup(popup, anchor);
    };
    window.addEventListener('scroll', follow, true);
    window.addEventListener('resize', follow);

    return () => placeCellPopup(popup, anchor);
}
