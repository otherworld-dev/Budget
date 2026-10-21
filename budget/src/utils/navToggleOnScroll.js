/**
 * The navigation button on narrow screens steps aside while the page
 * scrolls down.
 *
 * Below 1024px the button that opens the navigation floats in the top left
 * corner, so whatever scrolled under it was covered: the Bills by Month
 * year picker, a table's first column, a card's title. It now fades out
 * while the view scrolls down and comes back when it scrolls up or reaches
 * the top, which is where people look for it. It stays while the
 * navigation is open, and keyboard focus brings it back (see style.css).
 */

/** How far down the view has to be before the button steps aside */
const TOP_ZONE_PX = 50;

/** Scroll movements smaller than this (a trackpad's jitter) change nothing */
const NOISE_PX = 4;

export const SCROLLED_AWAY_CLASS = 'scrolled-away';

/**
 * @param {HTMLElement} wrapper the button's wrapper (#budget-nav-toggle-wrapper)
 * @param {Document} [doc]
 */
export function hideNavToggleOnScroll(wrapper, doc = document) {
    const lastTop = new WeakMap();

    doc.addEventListener('scroll', (e) => {
        // Only the view itself: tables and dialogs scroll on their own
        const target = e.target === doc ? doc.scrollingElement : e.target;
        if (!target || (target !== doc.scrollingElement && target.id !== 'app-content')) {
            return;
        }
        const top = target.scrollTop;
        const last = lastTop.get(target) ?? 0;
        if (top <= TOP_ZONE_PX || top < last - NOISE_PX) {
            wrapper.classList.remove(SCROLLED_AWAY_CLASS);
        } else if (top > last + NOISE_PX && !wrapper.classList.contains('nav-open')) {
            wrapper.classList.add(SCROLLED_AWAY_CLASS);
        } else {
            return; // too small a move to count; measure the next from here on
        }
        lastTop.set(target, top);
    }, { capture: true, passive: true });
}
